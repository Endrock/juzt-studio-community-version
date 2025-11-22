<?php

namespace Juztstack\JuztStudio\Community;

use Timber\Timber;

class ThemeRuntime
{
  const COOKIE_NAME = 'juzt_preview_template';
  const COOKIE_DURATION = DAY_IN_SECONDS;

  private $current_template_file = null; // NUEVO

  public function __construct()
  {
    // Capturar template actual ANTES de que se ejecute
    add_filter('template_include', [$this, 'capture_current_template'], 1);

    // Query params handlers
    add_action('template_redirect', [$this, 'handle_view_param'], 10);

    // Template override (después de capturar)
    add_filter('template_include', [$this, 'override_template'], 999);

    // Enqueue global data
    add_action('wp_enqueue_scripts', [$this, 'enqueue_global_data']);

    // Section rendering
    add_action('template_redirect', [$this, 'handle_sections_param'], 5);
    add_action('wp_ajax_render_sections', [$this, 'ajax_render_sections']);
    add_action('wp_ajax_nopriv_render_sections', [$this, 'ajax_render_sections']);
    add_action('wp_ajax_get_template_info', [$this, 'ajax_get_template_info']);
    add_action('wp_ajax_nopriv_get_template_info', [$this, 'ajax_get_template_info']);

    add_action('wp_ajax_set_preview', [$this, 'ajax_set_preview']);
    add_action('wp_ajax_nopriv_set_preview', [$this, 'ajax_set_preview']);
    add_action('wp_ajax_clear_preview', [$this, 'ajax_clear_preview']);
    add_action('wp_ajax_nopriv_clear_preview', [$this, 'ajax_clear_preview']);

    add_action('wp_ajax_get_template_html', [$this, 'ajax_get_template_html']);
    add_action('wp_ajax_nopriv_get_template_html', [$this, 'ajax_get_template_html']);

    // Mostrar barra de preview
    add_action('wp_footer', [$this, 'add_preview_bar'], 9999);
  }

  /**
   * Handle ?view=template-name parameter
   */
  public function handle_view_param()
  {
    if (!isset($_GET['view'])) {
      return;
    }

    $view_param = $_GET['view'];

    // Si view está vacío, borrar cookie
    if ($view_param === '') {
      $this->clear_preview_cookie();
      wp_redirect(remove_query_arg('view'));
      exit;
    }

    // Sanitizar nombre del template
    $template_name = sanitize_file_name($view_param);

    // Validar que el template PHP existe
    $template_file = get_template_directory() . '/' . $template_name . '.php';
    if (!file_exists($template_file)) {
      wp_die(
        'Template not found: ' . esc_html($template_name),
        'Template Not Found',
        ['response' => 404]
      );
    }

    // Validar que el template JSON existe
    $template_loader = new Templates();
    $template_data = $template_loader->get_json_template($template_name);

    if (!$template_data) {
      wp_die(
        'Template JSON not found: ' . esc_html($template_name) . '.json',
        'Template JSON Not Found',
        ['response' => 404]
      );
    }

    // Guardar en cookie CON PATH ESPECÍFICO
    $this->set_preview_cookie($template_name);

    // Redirect sin el parámetro (la cookie persiste)
    wp_redirect(remove_query_arg('view'));
    exit;
  }

  /**
   * Override template si hay cookie activa
   */
  public function override_template($template)
  {
    $preview_template = $this->get_preview_template();

    if (!$preview_template) {
      return $template;
    }

    $template_file = get_template_directory() . '/' . $preview_template . '.php';

    if (file_exists($template_file)) {
      return $template_file;
    }

    // Si no existe, limpiar cookie y usar original
    $this->clear_preview_cookie();
    return $template;
  }

  /**
   * Handle ?sections=hero,cta parameter
   */
  public function handle_sections_param()
  {
    if (!isset($_GET['sections'])) {
      return;
    }

    $sections = sanitize_text_field($_GET['sections']);
    $section_ids = array_map('trim', explode(',', $sections));

    // Detectar template actual
    $current_template = $this->get_current_template_name();

    if (!$current_template) {
      wp_send_json_error(['message' => 'No template detected'], 404);
    }

    // Cargar JSON del template
    $template_loader = new \Juztstack\JuztStudio\Community\Templates();
    $template_data = $template_loader->get_json_template($current_template);

    if (!$template_data) {
      wp_send_json_error(['message' => 'Template JSON not found'], 404);
    }

    // Renderizar solo las secciones solicitadas
    $rendered = [];

    foreach ($section_ids as $request_section) {
      // Buscar sección usando el helper
      $found = $this->find_section_by_id($template_data, $request_section);

      if (!$found) {
        $rendered[$request_section] = ['error' => 'Section not found'];
        continue;
      }

      $section = $found['data'];
      $section_key = $found['key'];

      try {
        $context = Timber::context();
        $context['section'] = $section;

        $html = Timber::compile("sections/{$section['section_id']}.twig", $context);

        $rendered[$request_section] = [
          'html' => $html,
          'section_type' => $section['section_id'],
          'section_id' => $section_key,
        ];
      } catch (\Exception $e) {
        $rendered[$request_section] = ['error' => $e->getMessage()];
      }
    }

    wp_send_json_success([
      'sections' => $rendered,
      'template' => $current_template
    ]);
  }

  /**
   * Enqueue datos globales
   */
  /**
   * Exponer datos globales en JavaScript
   */
  public function enqueue_global_data()
  {
    $current_template = $this->get_current_template_name();
    $preview_active = $this->get_preview_template();

    // Obtener lista de secciones del template actual
    $sections_list = [];
    if ($current_template) {
      $sections_list = $this->get_template_sections_list($current_template);
    }

    $juzt_data = [
      'template' => [
        'name' => $current_template,
        'file' => $current_template . '.php',
        'sections' => $sections_list,
      ],
      'preview' => [
        'active' => (bool) $preview_active,
        'template_name' => $preview_active ?: null,
        'path' => $this->get_current_path(),
      ],
      'api' => [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('juzt_api_nonce'),
        'endpoints' => [
          'render_sections' => admin_url('admin-ajax.php?action=render_sections'),
        ],
      ],
    ];

    wp_register_script('juzt-theme-data', '', [], false, false);
    wp_enqueue_script('juzt-theme-data');
    wp_localize_script('juzt-theme-data', 'JuztTheme', $juzt_data);

    // Usar NOWDOC (<<<'EOD') para evitar interpretación de variables PHP
    $cookie_duration = self::COOKIE_DURATION;
    $inline_script = <<<'EOD'
// ============================================
// Helper: Setear cookie desde JavaScript
// ============================================
function setCookie(name, value, maxAge, path) {
    let cookie = name + '=' + encodeURIComponent(value);
    cookie += '; max-age=' + maxAge;
    cookie += '; path=' + path;
    cookie += '; SameSite=Lax';
    if (window.location.protocol === 'https:') {
        cookie += '; Secure';
    }
    document.cookie = cookie;
}

function deleteCookie(name, path) {
    setCookie(name, '', -1, path);
}

function getCookie(name) {
    const value = '; ' + document.cookie;
    const parts = value.split('; ' + name + '=');
    if (parts.length === 2) {
        return decodeURIComponent(parts.pop().split(';').shift());
    }
    return null;
}

// ============================================
// Cambiar template SIN reload
// ============================================

window.JuztTheme.switchTemplate = async function(templateName, options = {}) {
    const { 
        transition = true,
        transitionDuration = 300,
        preserveScroll = false
    } = options;
    
    console.log('🔄 Switching to template:', templateName);
    
    try {
        const scrollY = window.scrollY;
        
        if (transition) {
            document.body.style.transition = `opacity ${transitionDuration}ms ease`;
            document.body.style.opacity = '0.3';
            await new Promise(resolve => setTimeout(resolve, transitionDuration));
        }
        
        const path = window.location.pathname;
        const pathWithSlash = path.endsWith('/') ? path : path + '/';
        
        setCookie(
            'juzt_preview_template',
            templateName,
            COOKIE_DURATION_VALUE,
            pathWithSlash
        );
        
        JuztTheme.preview.active = true;
        JuztTheme.preview.template_name = templateName;
        
        const response = await fetch(window.location.href, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            throw new Error('Failed to fetch template');
        }
        
        const html = await response.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const newBody = doc.body;
        const newHead = doc.head;
        
        if (doc.title) {
            document.title = doc.title;
        }
        
        const currentScripts = Array.from(document.body.querySelectorAll('script[data-preserve]'));
        document.body.innerHTML = newBody.innerHTML;
        
        currentScripts.forEach(script => {
            document.body.appendChild(script);
        });
        
        const newMetas = newHead.querySelectorAll('meta');
        newMetas.forEach(meta => {
            const name = meta.getAttribute('name') || meta.getAttribute('property');
            if (name) {
                const existing = document.querySelector(`meta[name=${name}], meta[property=${name}]`);
                if (existing) {
                    existing.replaceWith(meta.cloneNode(true));
                }
            }
        });
        
        if (typeof Alpine !== 'undefined') {
            Alpine.start();
        }
        
        if (preserveScroll) {
            window.scrollTo(0, scrollY);
        } else {
            window.scrollTo(0, 0);
        }
        
        if (transition) {
            await new Promise(resolve => setTimeout(resolve, 50));
            document.body.style.opacity = '1';
        }
        
        window.dispatchEvent(new CustomEvent('juzt:template-switched', {
            detail: { template: templateName }
        }));
        
        console.log('✅ Template switched successfully:', templateName);
        
        return {
            success: true,
            template: templateName
        };
        
    } catch (error) {
        console.error('❌ Error switching template:', error);
        document.body.style.opacity = '1';
        throw error;
    }
};

// ============================================
// Volver al template original SIN reload
// ============================================

window.JuztTheme.switchToOriginal = async function(options = {}) {
    const { 
        transition = true,
        transitionDuration = 300,
        preserveScroll = false
    } = options;
    
    console.log('🔄 Switching back to original template');
    
    try {
        const scrollY = window.scrollY;
        
        if (transition) {
            document.body.style.transition = `opacity ${transitionDuration}ms ease`;
            document.body.style.opacity = '0.3';
            await new Promise(resolve => setTimeout(resolve, transitionDuration));
        }
        
        const path = window.location.pathname;
        const pathWithSlash = path.endsWith('/') ? path : path + '/';
        
        deleteCookie('juzt_preview_template', pathWithSlash);
        
        JuztTheme.preview.active = false;
        JuztTheme.preview.template_name = null;
        
        const response = await fetch(window.location.href, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            throw new Error('Failed to fetch original template');
        }
        
        const html = await response.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const newBody = doc.body;
        const newHead = doc.head;
        
        if (doc.title) {
            document.title = doc.title;
        }
        
        const currentScripts = Array.from(document.body.querySelectorAll('script[data-preserve]'));
        document.body.innerHTML = newBody.innerHTML;
        
        currentScripts.forEach(script => {
            document.body.appendChild(script);
        });
        
        const newMetas = newHead.querySelectorAll('meta');
        newMetas.forEach(meta => {
            const name = meta.getAttribute('name') || meta.getAttribute('property');
            if (name) {
                const existing = document.querySelector(`meta[name=${name}], meta[property=${name}]`);
                if (existing) {
                    existing.replaceWith(meta.cloneNode(true));
                }
            }
        });
        
        if (typeof Alpine !== 'undefined') {
            Alpine.start();
        }
        
        if (preserveScroll) {
            window.scrollTo(0, scrollY);
        } else {
            window.scrollTo(0, 0);
        }
        
        if (transition) {
            await new Promise(resolve => setTimeout(resolve, 50));
            document.body.style.opacity = '1';
        }
        
        window.dispatchEvent(new CustomEvent('juzt:template-switched', {
            detail: { template: 'original' }
        }));
        
        console.log('✅ Switched back to original template');
        
        return {
            success: true,
            template: 'original'
        };
        
    } catch (error) {
        console.error('❌ Error switching to original:', error);
        document.body.style.opacity = '1';
        throw error;
    }
};

// ============================================
// Métodos sincrónico (para Growthbook con reload)
// ============================================

window.JuztTheme.setPreviewSync = function(templateName) {
    const path = window.location.pathname;
    const pathWithSlash = path.endsWith('/') ? path : path + '/';
    
    setCookie(
        'juzt_preview_template',
        templateName,
        COOKIE_DURATION_VALUE,
        pathWithSlash
    );
    
    console.log('✅ Preview cookie set synchronously:', {
        template: templateName,
        path: pathWithSlash,
        cookie_value: getCookie('juzt_preview_template')
    });
    
    JuztTheme.preview.active = true;
    JuztTheme.preview.template_name = templateName;
    
    return true;
};

window.JuztTheme.clearPreviewSync = function() {
    const path = window.location.pathname;
    const pathWithSlash = path.endsWith('/') ? path : path + '/';
    
    deleteCookie('juzt_preview_template', pathWithSlash);
    
    console.log('✅ Preview cookie cleared synchronously');
    
    JuztTheme.preview.active = false;
    JuztTheme.preview.template_name = null;
    
    return true;
};

// ============================================
// Métodos CON reload
// ============================================

window.JuztTheme.setVariant = function(templateName) {
    window.location.href = window.location.pathname + '?view=' + templateName;
};

window.JuztTheme.clearVariant = function() {
    window.location.href = window.location.pathname + '?view=';
};

// ============================================
// Utilidades
// ============================================

window.JuztTheme.checkPreviewCookie = function() {
    const cookieValue = getCookie('juzt_preview_template');
    console.log('Current preview cookie:', cookieValue || 'Not set');
    return cookieValue;
};

window.JuztTheme.getVariant = function() {
    return JuztTheme.preview.template_name;
};

window.JuztTheme.isPreviewActive = function() {
    return JuztTheme.preview.active;
};

window.JuztTheme.loadSection = async function(sectionId) {
    const response = await fetch(window.location.pathname + '?sections=' + sectionId);
    const data = await response.json();
    return data.data.sections[sectionId];
};

window.JuztTheme.loadSections = async function(sectionIds) {
    const ids = Array.isArray(sectionIds) ? sectionIds.join(',') : sectionIds;
    const response = await fetch(window.location.pathname + '?sections=' + ids);
    const data = await response.json();
    return data.data.sections;
};

window.JuztTheme.getTemplateInfo = async function(templateName) {
    const formData = new FormData();
    formData.append('action', 'get_template_info');
    formData.append('nonce', JuztTheme.api.nonce);
    formData.append('template', templateName);
    
    const response = await fetch(JuztTheme.api.ajax_url, {
        method: 'POST',
        body: formData
    });
    const data = await response.json();
    return data.data;
};

window.JuztTheme.debug = function() {
    console.group('🎨 Juzt Theme Debug');
    console.log('Current Template:', JuztTheme.template);
    console.log('Preview Active:', JuztTheme.preview);
    console.log('Preview Cookie:', getCookie('juzt_preview_template'));
    console.log('Available Sections:', JuztTheme.template.sections);
    console.log('All Cookies:', document.cookie);
    console.groupEnd();
};
EOD;

    // Reemplazar el placeholder con el valor real de PHP
    $inline_script = str_replace('COOKIE_DURATION_VALUE', $cookie_duration, $inline_script);

    wp_add_inline_script('juzt-theme-data', $inline_script, 'after');
  }

  /**
   * Helpers
   */
  /*private function template_php_exists($template_name)
  {
    $template_file = get_template_directory() . '/' . $template_name . '.php';
    return file_exists($template_file);
  }*/

  /**
   * Capturar el template actual ANTES de sobrescribirlo
   */
  public function capture_current_template($template)
  {
    $this->current_template_file = $template;
    return $template;
  }

  /**
   * Obtener nombre del template actual
   */
  private function get_current_template_name()
  {
    // Si hay preview activo
    $preview = $this->get_preview_template();
    if ($preview) {
      return $preview;
    }

    // Usar el template capturado
    if ($this->current_template_file && is_string($this->current_template_file)) {
      return basename($this->current_template_file, '.php');
    }

    // Detectar desde template slug
    $template_slug = get_page_template_slug();
    if ($template_slug) {
      return basename($template_slug, '.php');
    }

    // Fallbacks por tipo de página
    if (is_front_page()) return 'front-page';
    if (is_home()) return 'home';
    if (is_single()) return 'single';
    if (is_page()) return 'page';
    if (is_archive()) return 'archive';

    return null;
  }

  private function get_preview_template()
  {
    return isset($_COOKIE[self::COOKIE_NAME])
      ? sanitize_file_name($_COOKIE[self::COOKIE_NAME])
      : null;
  }

  /**
   * AJAX: Renderizar secciones
   */
  public function ajax_render_sections()
  {
    check_ajax_referer('juzt_api_nonce', 'nonce');

    $sections = isset($_POST['sections']) ? sanitize_text_field($_POST['sections']) : '';
    $template_name = isset($_POST['template']) ? sanitize_file_name($_POST['template']) : '';
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

    if (empty($sections) || empty($template_name)) {
      wp_send_json_error(['message' => 'Missing parameters'], 400);
    }

    $section_ids = array_map('trim', explode(',', $sections));

    // Cargar template JSON
    $template_loader = new \Juztstack\JuztStudio\Community\Templates();
    $template_data = $template_loader->get_json_template($template_name);

    if (!$template_data) {
      wp_send_json_error(['message' => 'Template not found'], 404);
    }

    // Setup post context si se proporciona
    if ($post_id) {
      global $post;
      $post = get_post($post_id);
      setup_postdata($post);
    }

    // Renderizar solo las secciones solicitadas
    $rendered = [];

    foreach ($section_ids as $request_section) {
      // Buscar sección usando el helper
      $found = $this->find_section_by_id($template_data, $request_section);

      if (!$found) {
        $rendered[$request_section] = ['error' => 'Section not found'];
        continue;
      }

      $section = $found['data'];
      $section_key = $found['key'];

      try {
        $context = Timber::context();
        $context['section'] = $section;

        $html = Timber::compile("sections/{$section['section_id']}.twig", $context);

        $rendered[$request_section] = [
          'html' => $html,
          'section_type' => $section['section_id'],
          'section_id' => $section_key,
        ];
      } catch (\Exception $e) {
        $rendered[$request_section] = ['error' => $e->getMessage()];
      }
    }

    if ($post_id) {
      wp_reset_postdata();
    }

    wp_send_json_success([
      'sections' => $rendered,
      'template' => $template_name,
      'post_id' => $post_id,
    ]);
  }

  /**
   * Buscar una sección por su section_id (nombre del Twig)
   * 
   * @param array $template_data Datos del template JSON
   * @param string $section_id Nombre de la sección (ej: 'hello-orbit')
   * @return array|null ['key' => 'section_123', 'data' => [...]] o null si no existe
   */
  private function find_section_by_id($template_data, $section_id)
  {
    if (!isset($template_data['sections']) || !is_array($template_data['sections'])) {
      return null;
    }

    foreach ($template_data['sections'] as $section_key => $section) {
      if (isset($section['section_id']) && $section['section_id'] === $section_id) {
        return [
          'key' => $section_key,
          'data' => $section
        ];
      }
    }

    return null;
  }

  private function get_template_sections_list($template_name)
  {
    $template_loader = new \Juztstack\JuztStudio\Community\Templates();
    $template_data = $template_loader->get_json_template($template_name);

    if (!$template_data || !isset($template_data['sections'])) {
      return [];
    }

    $sections_list = [];

    foreach ($template_data['sections'] as $section_key => $section) {
      $sections_list[] = [
        'id' => $section_key,
        'section_id' => $section['section_id'] ?? '',
        'type' => $section['section_id'] ?? 'unknown',
        'has_blocks' => isset($section['blocks']) && !empty($section['blocks']),
        'blocks_count' => isset($section['blocks']) ? count($section['blocks']) : 0,
        'has_settings' => isset($section['settings']) && !empty($section['settings']),
      ];
    }

    return $sections_list;
  }

  /**
   * AJAX: Obtener información de un template específico
   */
  public function ajax_get_template_info()
  {
    check_ajax_referer('juzt_api_nonce', 'nonce');

    $template_name = isset($_POST['template']) ? sanitize_file_name($_POST['template']) : '';

    if (empty($template_name)) {
      wp_send_json_error(['message' => 'Template name required'], 400);
    }

    // Cargar template JSON
    $template_loader = new \Juztstack\JuztStudio\Community\Templates();
    $template_data = $template_loader->get_json_template($template_name);

    if (!$template_data) {
      wp_send_json_error(['message' => 'Template not found'], 404);
    }

    // Verificar que el archivo PHP existe
    $template_file = get_template_directory() . '/' . $template_name . '.php';
    $php_exists = file_exists($template_file);

    // Obtener lista de secciones
    $sections_list = $this->get_template_sections_list($template_name);

    // Preparar respuesta
    $response = [
      'name' => $template_data['name'] ?? $template_name,
      'description' => $template_data['description'] ?? '',
      'template' => $template_data['template'] ?? $template_name,
      'post_type' => $template_data['post_type'] ?? null,
      'file' => $template_name . '.php',
      'php_exists' => $php_exists,
      'sections_count' => count($sections_list),
      'sections' => $sections_list,
      'order' => $template_data['order'] ?? [],
    ];

    wp_send_json_success($response);
  }

  /**
   * Obtener path actual de la URL (para cookie scope)
   */
  private function get_current_path()
  {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    // Asegurar que termina en /
    if (substr($path, -1) !== '/') {
      $path .= '/';
    }

    return $path;
  }

  /**
   * Guardar cookie CON PATH ESPECÍFICO (accesible desde JS)
   */
  private function set_preview_cookie($template_name)
  {
    $current_path = $this->get_current_path();

    setcookie(
      self::COOKIE_NAME,
      $template_name,
      time() + self::COOKIE_DURATION,
      $current_path,
      COOKIE_DOMAIN,
      is_ssl(),
      false // ← CAMBIAR A FALSE (no httponly)
    );

    // Setear en $_COOKIE para disponibilidad inmediata
    $_COOKIE[self::COOKIE_NAME] = $template_name;
  }

  /**
   * Borrar cookie CON PATH ESPECÍFICO
   */
  private function clear_preview_cookie()
  {
    $current_path = $this->get_current_path();

    setcookie(
      self::COOKIE_NAME,
      '',
      time() - HOUR_IN_SECONDS,
      $current_path,
      COOKIE_DOMAIN,
      is_ssl(),
      false // ← CAMBIAR A FALSE
    );

    unset($_COOKIE[self::COOKIE_NAME]);
  }

  /**
   * Mostrar barra de preview
   */
  public function add_preview_bar()
  {
    $preview_template = $this->get_preview_template();

    if (!$preview_template) {
      return;
    }

    $exit_url = add_query_arg('view', '', $_SERVER['REQUEST_URI']);
    $current_path = $this->get_current_path();

    echo '<div id="juzt-preview-bar" style="
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 20px;
            z-index: 999999;
            font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            font-size: 13px;
        ">';
    echo '<div style="display: flex; align-items: center; justify-content: space-between; max-width: 1200px; margin: 0 auto;">';
    echo '<div style="display: flex; align-items: center; gap: 15px;">';
    echo '<span style="background: rgba(255,255,255,0.2); padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">PREVIEW MODE</span>';
    echo '<strong>📄 Template:</strong> <code style="background: rgba(0,0,0,0.2); padding: 2px 8px; border-radius: 4px;">' . esc_html($preview_template) . '</code>';
    echo '<span style="opacity: 0.8; font-size: 11px;">Scope: ' . esc_html($current_path) . '</span>';
    echo '</div>';
    echo '<div>';
    echo '<a href="' . esc_url($exit_url) . '" style="color: white; text-decoration: none; padding: 6px 14px; background: rgba(255,255,255,0.2); border-radius: 4px; font-weight: 500; transition: background 0.2s;" onmouseover="this.style.background=\'rgba(255,255,255,0.3)\'" onmouseout="this.style.background=\'rgba(255,255,255,0.2)\'">✕ Exit Preview</a>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    echo '<script>
            document.body.style.marginTop = "48px";
            
            console.log("✨ Juzt Preview Active:", {
                template: "' . esc_js($preview_template) . '",
                path: "' . esc_js($current_path) . '",
                cookie: document.cookie.split(";").find(c => c.trim().startsWith("' . self::COOKIE_NAME . '="))
            });
        </script>';
  }

  /**
   * AJAX: Setear preview (sin reload)
   */
  public function ajax_set_preview()
  {
    check_ajax_referer('juzt_api_nonce', 'nonce');

    $template_name = isset($_POST['template']) ? sanitize_file_name($_POST['template']) : '';

    if (empty($template_name)) {
      wp_send_json_error(['message' => 'Template name required'], 400);
    }

    // Validar que el template existe
    $template_file = get_template_directory() . '/' . $template_name . '.php';
    if (!file_exists($template_file)) {
      wp_send_json_error(['message' => 'Template PHP not found'], 404);
    }

    // Validar JSON
    $template_loader = new Templates();
    $template_data = $template_loader->get_json_template($template_name);

    if (!$template_data) {
      wp_send_json_error(['message' => 'Template JSON not found'], 404);
    }

    // Setear cookie en servidor
    $this->set_preview_cookie($template_name);

    $current_path = $this->get_current_path();

    wp_send_json_success([
      'message' => 'Preview activated',
      'template' => $template_name,
      'path' => $current_path,
      'cookie_set' => true,
      // Instrucciones para JavaScript
      'cookie_data' => [
        'name' => self::COOKIE_NAME,
        'value' => $template_name,
        'path' => $current_path,
        'max_age' => self::COOKIE_DURATION
      ]
    ]);
  }

  /**
   * AJAX: Limpiar preview (sin reload)
   */
  public function ajax_clear_preview()
  {
    check_ajax_referer('juzt_api_nonce', 'nonce');

    $this->clear_preview_cookie();

    $current_path = $this->get_current_path();

    wp_send_json_success([
      'message' => 'Preview cleared',
      'cookie_cleared' => true,
      'path' => $current_path,
      // Instrucciones para JavaScript
      'cookie_data' => [
        'name' => self::COOKIE_NAME,
        'value' => '',
        'path' => $current_path,
        'max_age' => -1
      ]
    ]);
  }

  /**
   * AJAX: Obtener HTML completo de un template
   */
  public function ajax_get_template_html()
  {
    check_ajax_referer('juzt_api_nonce', 'nonce');

    $template_name = isset($_POST['template']) ? sanitize_file_name($_POST['template']) : '';

    if (empty($template_name)) {
      wp_send_json_error(['message' => 'Template name required'], 400);
    }

    // Validar que existe
    $template_file = get_template_directory() . '/' . $template_name . '.php';
    if (!file_exists($template_file)) {
      wp_send_json_error(['message' => 'Template not found'], 404);
    }

    // Setear cookie temporalmente para este request
    $this->set_preview_cookie($template_name);

    // Capturar el output del template
    ob_start();

    // Ejecutar el template
    global $wp_query;
    include($template_file);

    $html = ob_get_clean();

    wp_send_json_success([
      'html' => $html,
      'template' => $template_name
    ]);
  }
}

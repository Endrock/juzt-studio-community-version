<?php

namespace Juztstack\JuztStudio\Community;

use Juztstack\JuztStudio\Community\Core;

/**
 * Extension Registry
 * 
 * Sistema unificado de descubrimiento de recursos:
 * - Tema activo
 * - Extensiones de terceros
 * - Core de Juzt Studio
 * 
 * @package Juztstack\JuztStudio\Community
 * @since 1.1.0
 */
class ExtensionRegistry
{
    /**
     * Índice unificado de recursos
     * 
     * @var array
     */
    private $index = [
        'sections' => [],
        'templates' => [],
        'snippets' => [],
    ];

    /**
     * Extensiones registradas
     * 
     * @var array
     */
    private $extensions = [];

    /**
     * Cache key
     */
    const CACHE_KEY = 'juzt_registry_index_' . JUZTSTUDIO_CM_VERSION;
    const CACHE_DURATION = HOUR_IN_SECONDS;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Cargar desde cache si existe
        $cached = $this->get_from_cache();

        if ($cached !== false) {
            $this->index = $cached['index'];
            $this->extensions = $cached['extensions'];
        }

        add_action('wp_footer', [$this, 'debugPanel'], 100);
    }

    public function reset_cache()
    {
        $this->clear_cache();
        $this->index = [
            'sections' => [],
            'templates' => [],
            'snippets' => [],
        ];
        $this->extensions = [];
    }

    /**
     * Registrar extensión
     * 
     * @param array $config Configuración de la extensión
     * @return bool
     */
    public function register_extension($config)
    {
        // Validar configuración mínima
        if (empty($config['id']) || empty($config['name'])) {
            error_log("❌ Invalid extension config: missing id or name");
            return false;
        }

        $ext_id = sanitize_key($config['id']);

        // Evitar duplicados
        if (isset($this->extensions[$ext_id])) {
            error_log("⚠️ Extension already registered: {$ext_id}");
            return false;
        }

        // Registrar
        $this->extensions[$ext_id] = $config;

        error_log("✅ Extension registered: {$ext_id}");
        error_log("   - Name: {$config['name']}");
        error_log("   - Version: " . ($config['version'] ?? 'not specified'));
        error_log("   - Has assets: " . (empty($config['assets']) ? 'NO' : 'YES'));

        // NUEVO: Registrar assets inmediatamente
        if (!empty($config['assets'])) {
            // Si ya pasó wp_enqueue_scripts, cargar directamente
            if (did_action('wp_enqueue_scripts')) {
                error_log("⚠️ wp_enqueue_scripts already fired, loading assets directly");
                $this->load_assets($config);
            } else {
                // Si no, registrar en el hook
                add_action('wp_enqueue_scripts', function () use ($config) {
                    $this->load_assets($config);
                }, 20); // Prioridad 20 para después de los assets del tema
            }
        }

        // Invalidar cache
        $this->clear_cache();

        return true;
    }

    /**
     * Construir índice completo
     * 
     * Escanea todas las fuentes y construye el índice unificado
     */
    public function build_index()
    {
        // 1. Escanear tema activo (PRIORIDAD ALTA)
        $this->scan_theme();

        // 2. Escanear extensiones registradas
        $this->scan_extensions();

        // 3. Escanear core (FALLBACK)
        $this->scan_core();

        // Guardar en cache
        $this->save_to_cache();

        do_action('juzt_registry_index_built', $this->index);
    }

    /**
     * Limpiar extensión específica del índice - NUEVO
     * 
     * @param string $ext_id
     */
    public function remove_extension($ext_id)
    {
        if (!isset($this->extensions[$ext_id])) {
            error_log("⚠️ Extension not found in registry: {$ext_id}");
            return false;
        }

        error_log("🗑️ Removing extension from registry: {$ext_id}");

        // Eliminar de extensions
        unset($this->extensions[$ext_id]);

        // Eliminar del índice
        if (isset($this->index['sections'][$ext_id])) {
            unset($this->index['sections'][$ext_id]);
        }

        if (isset($this->index['templates'][$ext_id])) {
            unset($this->index['templates'][$ext_id]);
        }

        if (isset($this->index['snippets'][$ext_id])) {
            unset($this->index['snippets'][$ext_id]);
        }

        // Limpiar cache y guardar
        $this->clear_cache();
        $this->save_to_cache();

        error_log("✅ Extension removed from registry: {$ext_id}");

        return true;
    }

    /**
     * Escanear tema activo
     */
    private function scan_theme()
    {
        $theme_dir = get_template_directory();

        // 1. Escanear schemas (en /schemas/)
        $schemas_dir = $theme_dir . '/schemas';
        $schemas = [];

        if (is_dir($schemas_dir)) {
            $schemas = $this->scan_schemas_directory($schemas_dir);
        }

        // 2. Escanear secciones Twig (en /views/sections/)
        $sections_dir = $theme_dir . '/views/sections';
        $sections_twig = [];

        if (is_dir($sections_dir)) {
            $sections_twig = $this->scan_twig_directory($sections_dir);
        }

        // 3. Vincular schemas con Twig
        foreach ($schemas as $schema_name => $schema_file) {
            $twig_file = $sections_twig[$schema_name] ?? null;

            if ($twig_file) {
                $schema_data = $this->parse_schema($schema_file);

                $this->index['sections']['theme'][$schema_name] = [
                    'id' => $schema_name,
                    'name' => $schema_data['name'] ?? ucfirst(str_replace('-', ' ', $schema_name)),
                    'schema_file' => $schema_file,
                    'twig_file' => $twig_file,
                    'source' => 'theme',
                    'source_name' => 'Tema Activo',
                    'category' => $schema_data['category'] ?? 'general',
                    'icon' => $schema_data['icon'] ?? 'dashicons-layout',
                    'preview' => $schema_data['preview'] ?? null,
                ];
            }
        }

        // 4. Escanear templates JSON (en /templates/)
        $templates_dir = $theme_dir . '/templates';

        if (is_dir($templates_dir)) {
            $this->scan_json_templates($templates_dir, 'theme');
        }

        // 5. Escanear snippets (en /views/snippets/)
        $snippets_dir = $theme_dir . '/views/snippets';

        if (is_dir($snippets_dir)) {
            $this->scan_snippets($snippets_dir, 'theme');
        }
    }

    /**
     * Escanear extensiones registradas
     */
    private function scan_extensions()
    {
        foreach ($this->extensions as $ext_id => $ext_config) {
            $this->scan_extension($ext_id, $ext_config);
        }
    }

    /**
     * Escanear una extensión específica
     * 
     * @param string $ext_id
     * @param array $ext_config
     */
    private function scan_extension($ext_id, $ext_config)
    {
        $paths = $ext_config['paths'] ?? [];
        $schema_location = $ext_config['schema_location'] ?? 'inside_sections';

        if ($schema_location === 'separate') {
            // Estructura tipo tema (schemas separados)
            $this->scan_extension_separate($ext_id, $ext_config, $paths);
        } else {
            // Estructura consolidada (schema + twig juntos)
            $this->scan_extension_consolidated($ext_id, $ext_config, $paths);
        }

        // Templates (igual para ambas estructuras)
        if (!empty($paths['templates_dir'])) {
            $this->scan_json_templates($paths['templates_dir'], $ext_id);
        }

        // Snippets (si los hay)
        if (!empty($paths['snippets_dir'])) {
            $this->scan_snippets($paths['snippets_dir'], $ext_id);
        }
    }

    /**
     * Escanear extensión con estructura separada (como el tema)
     */
    private function scan_extension_separate($ext_id, $ext_config, $paths)
    {
        // 1. Escanear schemas
        $schemas = [];
        if (!empty($paths['schemas_dir'])) {
            $schemas = $this->scan_schemas_directory($paths['schemas_dir']);
        }

        // 2. Escanear secciones Twig
        $sections_twig = [];
        if (!empty($paths['sections_dir'])) {
            $sections_twig = $this->scan_twig_directory($paths['sections_dir']);
        }

        // 3. Vincular
        foreach ($schemas as $name => $schema_file) {
            $twig_file = $sections_twig[$name] ?? null;

            if ($twig_file) {
                $schema_data = $this->parse_schema($schema_file);

                $this->index['sections'][$ext_id][$name] = [
                    'id' => $name,
                    'name' => $schema_data['name'] ?? ucfirst(str_replace('-', ' ', $name)),
                    'schema_file' => $schema_file,
                    'twig_file' => $twig_file,
                    'source' => $ext_id,
                    'source_name' => $ext_config['name'] ?? $ext_id,
                    'category' => $schema_data['category'] ?? 'general',
                    'icon' => $schema_data['icon'] ?? 'dashicons-layout',
                    'preview' => $schema_data['preview'] ?? null,
                ];
            }
        }
    }

    /**
     * Escanear extensión con estructura consolidada
     */
    private function scan_extension_consolidated($ext_id, $ext_config, $paths)
    {
        if (empty($paths['sections_dir'])) {
            return;
        }

        $sections_dir = $paths['sections_dir'];

        if (!is_dir($sections_dir)) {
            return;
        }

        $section_folders = glob($sections_dir . '/*', GLOB_ONLYDIR);

        foreach ($section_folders as $folder) {
            $section_name = basename($folder);

            $schema_file = $folder . '/schema.php';
            $twig_file = $folder . '/' . $section_name . '.twig';

            if (file_exists($schema_file) && file_exists($twig_file)) {
                $schema_data = $this->parse_schema($schema_file);

                $this->index['sections'][$ext_id][$section_name] = [
                    'id' => $section_name,
                    'name' => $schema_data['name'] ?? ucfirst(str_replace('-', ' ', $section_name)),
                    'schema_file' => $schema_file,
                    'twig_file' => $twig_file,
                    'source' => $ext_id,
                    'source_name' => $ext_config['name'] ?? $ext_id,
                    'category' => $schema_data['category'] ?? 'general',
                    'icon' => $schema_data['icon'] ?? 'dashicons-layout',
                    'preview' => $schema_data['preview'] ?? null,
                ];
            }
        }
    }

    /**
     * Escanear core del plugin
     */
    private function scan_core()
    {
        // Por ahora, el core no tiene secciones
        // Pero está preparado para el futuro

        $core_sections = JUZTSTUDIO_CM_PLUGIN_PATH . 'sections';

        if (is_dir($core_sections)) {
            // Escanear estructura consolidada del core
            $section_folders = glob($core_sections . '/*', GLOB_ONLYDIR);

            foreach ($section_folders as $folder) {
                $section_name = basename($folder);

                $schema_file = $folder . '/schema.php';
                $twig_file = $folder . '/' . $section_name . '.twig';

                if (file_exists($schema_file) && file_exists($twig_file)) {
                    $schema_data = $this->parse_schema($schema_file);

                    $this->index['sections']['core'][$section_name] = [
                        'id' => $section_name,
                        'name' => $schema_data['name'] ?? ucfirst(str_replace('-', ' ', $section_name)),
                        'schema_file' => $schema_file,
                        'twig_file' => $twig_file,
                        'source' => 'core',
                        'source_name' => 'Juzt Studio Core',
                        'category' => $schema_data['category'] ?? 'general',
                        'icon' => $schema_data['icon'] ?? 'dashicons-layout',
                        'preview' => $schema_data['preview'] ?? null,
                    ];
                }
            }
        }
    }

    // ==========================================
    // MÉTODOS DE ESCANEO AUXILIARES
    // ==========================================

    /**
     * Escanear directorio de schemas PHP
     * 
     * @param string $directory
     * @return array ['section-name' => '/path/to/schema.php']
     */
    private function scan_schemas_directory($directory)
    {
        $schemas = [];

        if (!is_dir($directory)) {
            return $schemas;
        }

        $files = glob($directory . '/*.php');

        foreach ($files as $file) {
            $schema_name = basename($file, '.php');
            $schemas[$schema_name] = $file;
        }

        return $schemas;
    }

    /**
     * Escanear directorio de archivos Twig
     * 
     * @param string $directory
     * @return array ['section-name' => '/path/to/section.twig']
     */
    private function scan_twig_directory($directory)
    {
        $twigs = [];

        if (!is_dir($directory)) {
            return $twigs;
        }

        $files = glob($directory . '/*.twig');

        foreach ($files as $file) {
            $twig_name = basename($file, '.twig');
            $twigs[$twig_name] = $file;
        }

        return $twigs;
    }

    /**
     * Escanear templates JSON
     * 
     * @param string $directory
     * @param string $source
     */
    private function scan_json_templates($directory, $source)
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = glob($directory . '/*.json');

        foreach ($files as $file) {
            $template_name = basename($file, '.json');

            $this->index['templates'][$source][$template_name] = [
                'id' => $template_name,
                'json_file' => $file,
                'source' => $source,
            ];
        }
    }

    /**
     * Escanear snippets
     * 
     * @param string $directory
     * @param string $source
     */
    private function scan_snippets($directory, $source)
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = glob($directory . '/*.twig');

        foreach ($files as $file) {
            $snippet_name = basename($file, '.twig');

            $this->index['snippets'][$source][$snippet_name] = [
                'id' => $snippet_name,
                'twig_file' => $file,
                'source' => $source,
            ];
        }
    }

    /**
     * Parsear schema PHP para extraer metadata
     * 
     * @param string $schema_file
     * @return array
     */
    private function parse_schema($schema_file)
    {
        if (!file_exists($schema_file)) {
            return [];
        }

        try {
            $schema = include $schema_file;

            if (!is_array($schema)) {
                return [];
            }

            return $schema;
        } catch (\Exception $e) {
            error_log('Error parsing schema: ' . $schema_file . ' - ' . $e->getMessage());
            return [];
        }
    }

    // ==========================================
    // API PÚBLICA
    // ==========================================

    /**
     * Obtener todas las secciones disponibles
     * 
     * @return array
     */
    public function get_all_sections()
    {
        $all_sections = [];

        foreach ($this->index['sections'] as $source => $sections) {
            $all_sections = array_merge($all_sections, $sections);
        }

        return $all_sections;
    }

    /**
     * Obtener sección específica (respeta prioridades)
     * 
     * @param string $section_id
     * @return array|null
     */
    public function get_section($section_id)
    {
        // 1. Buscar en tema (PRIORIDAD ALTA)
        if (isset($this->index['sections']['theme'][$section_id])) {
            return $this->index['sections']['theme'][$section_id];
        }

        // 2. Buscar en extensiones
        foreach ($this->index['sections'] as $source => $sections) {
            if ($source === 'theme' || $source === 'core') {
                continue;
            }

            if (isset($sections[$section_id])) {
                return $sections[$section_id];
            }
        }

        // 3. Buscar en core (FALLBACK)
        if (isset($this->index['sections']['core'][$section_id])) {
            return $this->index['sections']['core'][$section_id];
        }

        return null;
    }

    /**
     * Obtener secciones por fuente
     * 
     * @param string $source 'theme', 'extension-id', 'core'
     * @return array
     */
    public function get_sections_by_source($source)
    {
        return $this->index['sections'][$source] ?? [];
    }

    /**
     * Obtener secciones por categoría
     * 
     * @param string $category
     * @return array
     */
    public function get_sections_by_category($category)
    {
        $sections = [];

        foreach ($this->index['sections'] as $source => $source_sections) {
            foreach ($source_sections as $section_id => $section_data) {
                if (($section_data['category'] ?? 'general') === $category) {
                    $sections[$section_id] = $section_data;
                }
            }
        }

        return $sections;
    }

    /**
     * Obtener template específico (respeta prioridades)
     * 
     * @param string $template_name
     * @return array|null
     */
    public function get_template($template_name)
    {
        // 1. Buscar en tema
        if (isset($this->index['templates']['theme'][$template_name])) {
            return $this->index['templates']['theme'][$template_name];
        }

        // 2. Buscar en extensiones
        foreach ($this->index['templates'] as $source => $templates) {
            if ($source === 'theme' || $source === 'core') {
                continue;
            }

            if (isset($templates[$template_name])) {
                return $templates[$template_name];
            }
        }

        // 3. Buscar en core
        if (isset($this->index['templates']['core'][$template_name])) {
            return $this->index['templates']['core'][$template_name];
        }

        return null;
    }

    /**
     * Obtener todos los templates
     * 
     * @return array
     */
    public function get_all_templates()
    {
        $all_templates = [];

        foreach ($this->index['templates'] as $source => $templates) {
            $all_templates = array_merge($all_templates, $templates);
        }

        return $all_templates;
    }

    /**
     * Obtener extensiones registradas
     * 
     * @return array
     */
    public function get_extensions()
    {
        return $this->extensions;
    }

    /**
     * Obtener extensión específica
     * 
     * @param string $ext_id
     * @return array|null
     */
    public function get_extension($ext_id)
    {
        return $this->extensions[$ext_id] ?? null;
    }

    // ==========================================
    // CACHE
    // ==========================================

    /**
     * Guardar en cache
     */
    private function save_to_cache()
    {
        $data = [
            'index' => $this->index,
            'extensions' => $this->extensions,
        ];

        set_transient(self::CACHE_KEY, $data, self::CACHE_DURATION);
    }

    /**
     * Load Assets - ACTUALIZADO con soporte para Vite
     */
    private function load_assets($config)
    {
        if (empty($config['assets'])) {
            error_log("⚠️ No assets defined for extension: " . ($config['id'] ?? 'unknown'));
            return;
        }

        $ext_id = $config['id'] ?? 'unknown';

        // Determinar si estamos en modo desarrollo
        $is_dev_mode = defined('JUZT_EXTENSION_DEVELOPMENT_MODE') && JUZT_EXTENSION_DEVELOPMENT_MODE;

        error_log("=== LOADING ASSETS FOR: {$ext_id} ===");
        error_log("Development mode: " . ($is_dev_mode ? 'YES' : 'NO'));

        // Seleccionar assets según el modo
        $assets = $is_dev_mode
            ? ($config['assets']['development'] ?? [])
            : ($config['assets']['production'] ?? []);

        if (empty($assets)) {
            error_log("⚠️ No assets defined for current mode");
            return;
        }

        // En modo desarrollo, cargar desde Vite
        if ($is_dev_mode) {
            $this->load_vite_assets($config, $assets);
        }
        // En producción, cargar assets compilados
        else {
            $this->load_production_assets($config, $assets);
        }

        error_log("=== ASSETS LOADED ===");
    }

    /**
     * Cargar assets desde Vite (desarrollo) - NUEVO
     */
    private function load_vite_assets($config, $assets)
    {
        $ext_id = $config['id'];
        $vite_server = $config['paths']['vite_dev_server'] ?? 'http://localhost:5173';

        error_log("Loading assets from Vite dev server: {$vite_server}");

        // 1. Cargar el cliente de Vite (necesario para HMR)
        wp_enqueue_script(
            "{$ext_id}-vite-client",
            "{$vite_server}/@vite/client",
            [],
            null,
            false
        );

        // Marcar como módulo ES
        add_filter('script_loader_tag', function ($tag, $handle) use ($ext_id) {
            if (
                strpos($handle, "{$ext_id}-vite-client") !== false ||
                strpos($handle, "{$ext_id}-") !== false
            ) {
                return str_replace('<script', '<script type="module"', $tag);
            }
            return $tag;
        }, 10, 2);

        // 2. Cargar JS desde Vite
        if (!empty($assets['js']) && is_array($assets['js'])) {
            error_log("Loading JS from Vite: " . count($assets['js']));

            foreach ($assets['js'] as $handle => $path) {
                $full_url = $vite_server . $path;

                error_log("  - Handle: {$handle}");
                error_log("    URL: {$full_url}");

                wp_enqueue_script(
                    $handle,
                    $full_url,
                    [],
                    null,
                    true
                );

                error_log("    ✅ Enqueued from Vite");
            }
        }

        // En desarrollo, CSS se importa desde JS (no necesita enqueue separado)
        error_log("CSS loaded via Vite HMR (imported in JS)");
    }

    /**
     * Cargar assets de producción (compilados) - NUEVO
     */
    private function load_production_assets($config, $assets)
    {
        $ext_id = $config['id'];

        error_log("Loading production assets");

        // Cargar JS
        if (!empty($assets['js']) && is_array($assets['js'])) {
            error_log("Loading JS assets: " . count($assets['js']));

            foreach ($assets['js'] as $handle => $path) {
                $full_url = $config['paths']['assets_url'] . $path;

                error_log("  - Handle: {$handle}");
                error_log("    URL: {$full_url}");

                wp_enqueue_script(
                    $handle,
                    $full_url,
                    [],
                    $config['version'] ?? null,
                    true
                );

                error_log("    ✅ Enqueued");
            }
        }

        // Cargar CSS
        if (!empty($assets['css']) && is_array($assets['css'])) {
            error_log("Loading CSS assets: " . count($assets['css']));

            foreach ($assets['css'] as $handle => $path) {
                $full_url = $config['paths']['assets_url'] . $path;

                error_log("  - Handle: {$handle}");
                error_log("    URL: {$full_url}");

                wp_enqueue_style(
                    $handle,
                    $full_url,
                    [],
                    $config['version'] ?? null
                );

                error_log("    ✅ Enqueued");
            }
        }
    }

    /**
     * Obtener de cache
     */
    private function get_from_cache()
    {
        return get_transient(self::CACHE_KEY);
    }

    /**
     * Limpiar cache
     */
    public function clear_cache()
    {
        global $wpdb;

        // Borrar transient específico
        delete_transient(self::CACHE_KEY);

        // Borrar TODOS los transients relacionados (por si hay versiones antiguas)
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_juzt_registry_%' 
         OR option_name LIKE '_transient_timeout_juzt_registry_%'"
        );

        // Limpiar object cache si existe
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        error_log('🧹 All registry cache cleared');
    }

    public function debugPanel()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (is_admin() == true || JUZT_STACK_DEBUG === false) {
            return;
        }

        $core = Core::get_instance();

        if (!$core || !$core->get_registry()) {
            return;
        }

        $registry = $core->get_registry();

        echo '
    <script>
    document.addEventListener("DOMContentLoaded", function() {
      const toggle = document.querySelector(".juzt-stack-debug__toggle");
      if (toggle) {
        toggle.addEventListener("click", function() {
          const panel = document.querySelector(".juzt-stack-debug__panel");
          panel.classList.toggle("open");
        });
      }
    });
    </script>

    <style>
    .juzt-stack-debug__container {
      position: fixed;
      bottom: 0;
      left: 0;
      width: 100%;
      max-height: 300px;
      overflow: hidden;
      font-family: monospace;
      z-index: 9999;
    }
    .juzt-stack-debug__toggle {
      background: #222;
      color: #fff;
      padding: 5px 10px;
      cursor: pointer;
      font-size: 12px;
      text-align: center;
    }
    .juzt-stack-debug__panel {
      background: #2d2d2d;
      color: #0f0;
      padding: 0px;
      font-size: 12px;
      line-height: 1.4;
      max-height: 0;
      overflow: hidden;
      transition: max-height 0.3s ease;
      white-space: pre-wrap;
    }
    .juzt-stack-debug__panel.open {
      max-height: 300px;
      overflow: auto;
      padding: 10px;
    } 
    </style>
    <div class="juzt-stack-debug__container">
      <div class="juzt-stack-debug__toggle">JUZT STACK DEBUG</div>
        <pre class="juzt-stack-debug__panel">';
        echo "=== JUZT REGISTRY DEBUG ===\n\n";
        if (isset($registry)) {
            $sections = $registry->get_all_sections();
            echo "Available sections: " . count($sections) . "\n";

            foreach ($sections as $id => $data) {
                echo sprintf(
                    "- %s [%s] (source: %s)\n",
                    $data['name'],
                    $id,
                    $data['source_name']
                );
            }

            echo "\n";

            $templates = $registry->get_all_templates();
            echo "Available templates: " . count($templates) . "\n";

            foreach ($templates as $id => $data) {
                echo sprintf("- %s (source: %s)\n", $id, $data['source']);
            }
        } else {
            echo "Error: The \$registry variable is not defined for debugging.\n";
        }

        echo '</pre></div>';
    }

    /**
     * Auto-detectar extensiones en plugins activos
     */
    public function auto_discover_extensions()
    {
        // Obtener plugins activos
        $active_plugins = get_option('active_plugins', []);

        foreach ($active_plugins as $plugin_file) {
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            $plugin_dir = dirname($plugin_path);

            // Buscar juzt-extension.php en la raíz del plugin
            $extension_file = $plugin_dir . '/juzt-extension.php';

            if (file_exists($extension_file)) {
                error_log("🔍 Found juzt-extension.php in: {$plugin_dir}");

                try {
                    $config = include $extension_file;

                    if (is_array($config)) {
                        // Validar configuración mínima
                        if (empty($config['id']) || empty($config['name'])) {
                            error_log("⚠️ Invalid extension config in: {$extension_file}");
                            continue;
                        }

                        $ext_id = sanitize_key($config['id']);

                        // NUEVO: Si ya existe (del cache), solo registrar assets
                        if (isset($this->extensions[$ext_id])) {
                            error_log("📦 Extension loaded from cache: {$ext_id}, registering assets...");

                            // Actualizar config
                            $this->extensions[$ext_id] = array_merge($this->extensions[$ext_id], $config);

                            // Registrar assets
                            if (!empty($config['assets'])) {
                                add_action('wp_enqueue_scripts', function () use ($config) {
                                    $this->load_assets($config);
                                }, 20);
                            }

                            continue; // ← No intentar registrar de nuevo
                        }

                        // Registrar nueva extensión
                        $registered = $this->register_extension($config);

                        if ($registered) {
                            error_log("✅ Extension registered: {$config['id']}");
                        } else {
                            error_log("❌ Failed to register extension: {$config['id']}");
                        }
                    } else {
                        error_log("⚠️ juzt-extension.php didn't return an array in: {$extension_file}");
                    }
                } catch (\Exception $e) {
                    error_log("❌ Error loading extension: {$extension_file} - " . $e->getMessage());
                }
            }
        }

        error_log("🔍 Auto-discovery completed. Extensions found: " . count($this->extensions));
    }

    /**
     * Refrescar el índice de una extensión específica - NUEVO
     * 
     * @param string $ext_id
     */
    public function refresh_extension($ext_id)
    {
        if (!isset($this->extensions[$ext_id])) {
            return false;
        }

        $ext_config = $this->extensions[$ext_id];

        // Limpiar índices existentes de esta extensión
        if (isset($this->index['templates'][$ext_id])) {
            unset($this->index['templates'][$ext_id]);
        }
        if (isset($this->index['sections'][$ext_id])) {
            unset($this->index['sections'][$ext_id]);
        }

        // Re-escanear
        $this->scan_extension($ext_id, $ext_config);

        error_log("✅ Extension {$ext_id} refreshed");

        return true;
    }
}

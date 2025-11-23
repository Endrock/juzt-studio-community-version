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
    const CACHE_KEY = 'juzt_registry_index_v1';
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
            return false;
        }

        $ext_id = sanitize_key($config['id']);

        // Evitar duplicados
        if (isset($this->extensions[$ext_id])) {
            return false;
        }

        // Registrar
        $this->extensions[$ext_id] = $config;

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
        delete_transient(self::CACHE_KEY);
    }

    public function debugPanel()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (JUZT_STACK_DEBUG === false) {
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
}

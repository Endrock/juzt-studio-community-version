<?php

namespace Juztstack\JuztStudio\Community;

class Core
{
    /**
     * Instancia de la clase (singleton)
     */
    private static $instance = null;

    /**
     * Instancias de los componentes principales
     */
    public $templates;
    public $sections;
    public $snippets;
    public $compatibility;
    public $builder;
    public $theme_runtime;
    public $extension_registry; // NUEVO

    /**
     * Constructor
     */
    public function __construct()
    {
        // Inicializar una sola vez
        if (self::$instance) {
            return self::$instance;
        }

        self::$instance = $this;
    }

    /**
     * Registrar templates de extensiones en WordPress
     * 
     * @param array $templates
     * @return array
     */
    public function register_extension_templates($templates)
    {
        if (!$this->extension_registry) {
            return $templates;
        }

        $extensions = $this->extension_registry->get_extensions();

        foreach ($extensions as $ext_id => $ext_config) {
            if (empty($ext_config['paths']['templates_dir'])) {
                continue;
            }

            $templates_dir = $ext_config['paths']['templates_dir'];

            // Buscar archivos PHP en el directorio de templates
            $php_files = glob($templates_dir . '/*.php');

            foreach ($php_files as $file) {
                // Leer el header del archivo
                $file_data = get_file_data($file, [
                    'Template Name' => 'Template Name',
                    'Description' => 'Description',
                ]);

                if (!empty($file_data['Template Name'])) {
                    $template_key = basename($file);
                    $template_label = $file_data['Template Name'];

                    // Agregar badge de extensión
                    $ext_name = $ext_config['name'] ?? $ext_id;
                    $template_label .= ' [' . $ext_name . ']';

                    $templates[$template_key] = $template_label;
                }
            }
        }

        return $templates;
    }

    /**
     * Cargar template de extensión cuando se selecciona
     * 
     * @param string $template
     * @return string
     */
    public function load_extension_template($template)
    {
        global $post;

        if (!$post) {
            return $template;
        }

        // Obtener el template seleccionado
        $page_template = get_post_meta($post->ID, '_wp_page_template', true);

        if (empty($page_template) || $page_template === 'default') {
            return $template;
        }

        // Buscar en extensiones
        if (!$this->extension_registry) {
            return $template;
        }

        $extensions = $this->extension_registry->get_extensions();

        foreach ($extensions as $ext_id => $ext_config) {
            if (empty($ext_config['paths']['templates_dir'])) {
                continue;
            }

            $template_file = $ext_config['paths']['templates_dir'] . '/' . $page_template;

            if (file_exists($template_file)) {
                return $template_file;
            }
        }

        return $template;
    }

    /**
     * Obtener instancia (singleton)
     * 
     * @return Core
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function find_snippet_file($name)
    {
        return $this->snippets->find_snippet_file($name);
    }

    /**
     * Manejar desactivación de plugin con extensión - NUEVO
     * 
     * @param string $plugin_file Ruta del plugin (ej: 'juzt-extension/plugin.php')
     */
    public function handle_plugin_deactivation($plugin_file)
    {
        if (!$this->extension_registry) {
            return;
        }

        error_log("=== PLUGIN DEACTIVATION: {$plugin_file} ===");

        // Obtener directorio del plugin
        $plugin_dir = dirname(WP_PLUGIN_DIR . '/' . $plugin_file);

        // Buscar si tiene juzt-extension.php
        $extension_file = $plugin_dir . '/juzt-extension.php';

        if (!file_exists($extension_file)) {
            error_log("No juzt-extension.php found, skipping");
            return;
        }

        // Leer el config para obtener el ID
        try {
            $config = include $extension_file;

            if (is_array($config) && !empty($config['id'])) {
                $ext_id = sanitize_key($config['id']);

                error_log("Extension ID found: {$ext_id}");

                // Eliminar la extensión del Registry
                $this->extension_registry->remove_extension($ext_id);

                error_log("✅ Extension removed on plugin deactivation");
            }
        } catch (\Exception $e) {
            error_log("Error reading extension config: " . $e->getMessage());
        }
    }

    /**
     * Inicializar el plugin
     */
    public function init()
    {
        // 1. Inicializar Extension Registry
        $this->extension_registry = new ExtensionRegistry();

        // 2. Auto-detectar y registrar extensiones
        error_log('🔍 Starting auto-discovery...');
        $this->extension_registry->auto_discover_extensions();

        // 3. Construir índice unificado
        $this->extension_registry->build_index();
        error_log('✅ Registry index built');

        // 4. Cargar componentes principales
        $this->load_components();

        // 5. Verificar compatibilidad del tema
        $this->setup_theme_compatibility();

        // 6. Registrar hooks
        $this->register_hooks();

        // 7. Registrar hooks de invalidación de cache
        $this->register_cache_hooks();

        // 8. Hacer accesible la instancia globalmente
        global $sections_builder_theme;
        $sections_builder_theme = $this;

        // 9. Proteger acceso a templates JSON
        //add_action('template_redirect', [$this, 'protected_templates_json']);
    }

    public function find_section_file($section)
    {
        return $this->sections->find_section_file($section);
    }

    public function find_json_template_file($template)
    {
        return $this->templates->find_json_template_file($template);
    }

    /**
     * Cargar componentes principales
     */
    private function load_components()
    {
        // Cargar componentes principales
        $this->templates = new Templates();
        $this->sections = new Sections();
        $this->snippets = new Snippets();
        $this->compatibility = new Compatibility();
        $this->theme_runtime = new ThemeRuntime();

        // Cargar el builder (solo en admin)
        if (is_admin()) {
            $this->builder = new Builder();
        }
    }

    /**
     * Configurar compatibilidad con el tema
     */
    private function setup_theme_compatibility()
    {
        // Verificar si el tema actual tiene soporte para sections-builder
        if (current_theme_supports('sections-builder')) {
            // Obtener la configuración del tema
            $config = get_theme_support('sections-builder');

            if (is_array($config) && !empty($config[0])) {
                // Configurar los directorios personalizados
                $this->setup_custom_directories($config[0]);
            }
        }
    }

    /**
     * Configurar directorios personalizados
     */
    private function setup_custom_directories($config)
    {
        // Directorio de secciones
        if (isset($config['sections_directory'])) {
            $this->sections->set_theme_directory($config['sections_directory']);
        }

        // Directorio de templates
        if (isset($config['templates_directory'])) {
            $this->templates->set_theme_directory($config['templates_directory']);
        }

        // Directorio de snippets
        if (isset($config['snippets_directory'])) {
            $this->snippets->set_theme_directory($config['snippets_directory']);
        }
    }

    /**
     * Registrar hooks
     */
    private function register_hooks()
    {
        // Hooks para templates
        add_filter('template_include', [$this->templates, 'template_include']);

        // Hooks para metaboxes
        if (is_admin()) {
            add_action('add_meta_boxes', [$this->templates, 'register_meta_boxes']);
            add_action('save_post', [$this->templates, 'save_meta_boxes']);
        }

        // NUEVO: Registrar templates de extensiones
        add_filter('theme_page_templates', [$this, 'register_extension_templates']);
        add_filter('template_include', [$this, 'load_extension_template']);
    }

    /**
     * Registrar hooks de invalidación de cache
     */
    private function register_cache_hooks()
    {
        // Invalidar cache al activar plugins
        add_action('activated_plugin', [$this, 'invalidate_registry_cache']);

        // ACTUALIZADO: Manejar desactivación con limpieza específica
        add_action('deactivated_plugin', function ($plugin_file) {
            $this->handle_plugin_deactivation($plugin_file);
        });

        // Invalidar cache al cambiar de tema
        add_action('switch_theme', [$this, 'invalidate_registry_cache']);

        // Invalidar cache al actualizar el plugin
        add_action('upgrader_process_complete', [$this, 'invalidate_registry_cache'], 10, 2);
    }

    /**
     * Invalidar cache del registry
     */
    public function invalidate_registry_cache()
    {
        if ($this->extension_registry) {
            $this->extension_registry->clear_cache();

            // Rebuild index
            $this->extension_registry->build_index();
        }
    }

    /**
     * Helper: Obtener registry
     * 
     * @return ExtensionRegistry
     */
    public function get_registry()
    {
        return $this->extension_registry;
    }

    /***
     * Protected access to templates JSON
     */

    public function protected_templates_json()
    {
        var_dump($_SERVER['REQUEST_URI']);
        if (
            strpos($_SERVER['REQUEST_URI'], '/templates/') !== false
            && pathinfo($_SERVER['REQUEST_URI'], PATHINFO_EXTENSION) === 'json'
        ) {
            wp_die('Access denied');
        }
    }
}

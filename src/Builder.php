<?php

namespace Juztstack\JuztStudio\Community;

/**
 * Clase para el builder de plantillas JSON - Actualizada para Timber/Twig
 */
class Builder
{
    /**
     * Directorio para los assets del builder
     */
    private $assets_url;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->assets_url = JUZTSTUDIO_CM_PLUGIN_URL . '/assets/builder/';

        // Registrar hooks para el admin
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Registrar endpoints REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        $this->register_ajax_endpoints();

        // TEMPORAL: Descomentar para limpiar templates una sola vez
        add_action('init', function () {
            if (current_user_can('manage_options') && isset($_GET['fix_templates'])) {
                echo $this->fix_existing_templates();
                exit;
            }
        });
    }

    /**
     * Registrar endpoints AJAX
     */
    public function register_ajax_endpoints()
    {
        add_action('wp_ajax_get_templates', [$this, 'ajax_get_templates']);
        add_action('wp_ajax_get_sections', [$this, 'ajax_get_sections']);
        add_action('wp_ajax_get_section_schemas', [$this, 'ajax_get_section_schemas']);
        add_action('wp_ajax_get_template', [$this, 'ajax_get_template']);
        add_action('wp_ajax_save_template', [$this, 'ajax_save_template']);

        // NUEVOS endpoints para General Settings
        add_action('wp_ajax_get_general_settings_schema', [$this, 'ajax_get_general_settings_schema']);
        add_action('wp_ajax_get_general_settings', [$this, 'ajax_get_general_settings']);
        add_action('wp_ajax_save_general_settings', [$this, 'ajax_save_general_settings']);

        add_action('wp_ajax_preview_template', [$this, 'ajax_preview_template']);

        add_action('wp_ajax_search_posts', [$this, 'ajax_search_posts']);
        add_action('wp_ajax_search_terms', [$this, 'ajax_search_terms']);
        add_action('wp_ajax_get_menus', [$this, 'ajax_get_menus']);
        add_action('wp_ajax_get_sidebars', [$this, 'ajax_get_sidebars']);

        add_action('wp_ajax_get_attachment_data', [$this, 'ajax_get_attachment_data']);
        add_action('wp_ajax_get_post_types', [$this, 'ajax_get_post_types']);

        add_action('wp_ajax_juzt_get_sections_by_source', [$this, 'ajax_get_sections_by_source']);
        add_action('wp_ajax_juzt_get_all_sections', [$this, 'ajax_get_all_sections']);

        add_action('wp_ajax_get_all_section_schemas', [$this, 'ajax_get_all_section_schemas']);

        add_action('wp_ajax_get_templates_by_source', [$this, 'ajax_get_templates_by_source']);
    }

    /**
     * Endpoint AJAX para obtener plantillas
     */
    public function ajax_get_templates()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        $templates = [];

        // Buscar en el tema
        $theme_dir = get_template_directory() . '/templates';

        // Verificar configuración personalizada
        if (current_theme_supports('sections-builder')) {
            $config = get_theme_support('sections-builder');
            if (is_array($config) && !empty($config[0]) && isset($config[0]['templates_directory'])) {
                $theme_dir = get_template_directory() . '/' . $config[0]['templates_directory'];
            }
        }

        if (is_dir($theme_dir)) {
            $files = glob($theme_dir . '/*.json');

            foreach ($files as $file) {
                $template_id = basename($file, '.json');
                $content = file_get_contents($file);
                $template_data = json_decode($content, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $templates[$template_id] = [
                        'name' => isset($template_data['name']) ? $template_data['name'] : ucfirst(str_replace('-', ' ', $template_id)),
                        'description' => isset($template_data['description']) ? $template_data['description'] : '',
                        'path' => $file,
                        'source' => 'theme',
                        'sections_count' => count($template_data['sections'] ?? [])
                    ];
                }
            }
        }

        wp_send_json_success($templates);
        exit;
    }

    /**
     * Endpoint AJAX para obtener secciones disponibles - ACTUALIZADO para usar Registry
     */
    public function ajax_get_sections()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        $core = \Juztstack\JuztStudio\Community\Core::get_instance();

        if (!$core || !isset($core->extension_registry)) {
            wp_send_json_success([]);
            exit;
        }

        $all_sections = $core->extension_registry->get_all_sections();
        $sections = [];

        foreach ($all_sections as $section_id => $section_data) {
            $sections[$section_id] = [
                'id' => $section_id,
                'name' => $section_data['name'] ?? ucfirst(str_replace('-', ' ', $section_id)),
                'description' => $section_data['description'] ?? '',
                'category' => $section_data['category'] ?? 'general',
                'icon' => $section_data['icon'] ?? 'admin-generic',
                'template_file' => basename($section_data['twig_file'] ?? ''),
                'template_path' => $section_data['twig_file'] ?? '',
                'has_schema' => !empty($section_data['schema_file']),
                'schema_path' => $section_data['schema_file'] ?? '',
                'source' => $section_data['source'] ?? 'theme',
                'source_name' => $section_data['source_name'] ?? 'Theme'
            ];
        }

        wp_send_json_success($sections);
        exit;
    }

    /**
     * Endpoint AJAX para obtener esquemas de secciones (actualizado para nuevo formato)
     */
    public function ajax_get_section_schemas()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        $schemas = [];

        // Buscar archivos de esquema en schemas/
        $schemas_dir = get_template_directory() . '/schemas';

        // Verificar configuración personalizada
        if (current_theme_supports('sections-builder')) {
            $config = get_theme_support('sections-builder');
            if (is_array($config) && !empty($config[0]) && isset($config[0]['schemas_directory'])) {
                $schemas_dir = get_template_directory() . '/' . $config[0]['schemas_directory'];
            }
        }

        error_log('=== DEBUG ESQUEMAS ===');
        error_log('Buscando esquemas en directorio: ' . $schemas_dir);
        error_log('¿Directorio existe? ' . (is_dir($schemas_dir) ? 'SÍ' : 'NO'));

        if (is_dir($schemas_dir)) {
            $files = glob($schemas_dir . '/*.php');
            error_log('Archivos .php encontrados en schemas: ' . count($files));

            foreach ($files as $file) {
                $section_id = basename($file, '.php');

                // Ignorar archivos que comienzan con _ (helpers, etc)
                if (strpos($section_id, '_') === 0) {
                    continue;
                }

                error_log('Procesando esquema: ' . $file . ' para sección: ' . $section_id);

                $schema_data = $this->parse_section_schema($section_id, $file);

                if ($schema_data) {
                    $schemas[$section_id] = [
                        'id' => $section_id,
                        'name' => $schema_data['name'],
                        'schema' => $schema_data,
                        'file' => $file,
                        'source' => 'theme'
                    ];

                    error_log('Esquema procesado exitosamente: ' . $section_id . ' con ' . count($schema_data['properties']) . ' propiedades');
                } else {
                    error_log('ERROR: No se pudo procesar el esquema para: ' . $section_id);
                }
            }
        } else {
            error_log('ERROR: Directorio de schemas no existe: ' . $schemas_dir);
        }

        error_log('Total schemas procesados exitosamente: ' . count($schemas));

        // Log detallado de cada esquema
        foreach ($schemas as $section_id => $schema_data) {
            error_log("- Esquema: " . $section_id);
            error_log("  - Nombre: " . $schema_data['schema']['name']);
            error_log("  - Propiedades: " . count($schema_data['schema']['properties']));

            // Log de propiedades individuales
            foreach ($schema_data['schema']['properties'] as $prop_key => $prop_data) {
                error_log("    * $prop_key: " . $prop_data['type'] . ' (' . $prop_data['title'] . ')');
            }
        }

        error_log('=== FIN DEBUG ESQUEMAS ===');

        wp_send_json_success($schemas);
        exit;
    }


    /**
     * Procesar definiciones de bloques del esquema
     */
    private function process_schema_blocks($blocks_config, $section_id)
    {
        $processed_blocks = [];

        foreach ($blocks_config as $block_id => $block_config) {
            if (!is_array($block_config)) {
                error_log("ADVERTENCIA: Configuración de bloque '$block_id' no es un array, ignorando");
                continue;
            }

            $block = [
                'id' => $block_id,
                'name' => $block_config['name'] ?? ucfirst(str_replace('-', ' ', $block_id)),
                'description' => $block_config['description'] ?? '',
                'icon' => $block_config['icon'] ?? 'admin-generic',
                'properties' => []
            ];

            // Convertir settings del bloque a properties
            if (isset($block_config['settings']) && is_array($block_config['settings'])) {
                $block['properties'] = $this->convert_settings_to_properties($block_config['settings']);
                error_log("Propiedades de bloque '$block_id' convertidas: " . count($block['properties']) . " campos");
            }

            // Configuraciones adicionales del bloque
            if (isset($block_config['limit'])) {
                $block['limit'] = (int)$block_config['limit'];
            }

            if (isset($block_config['min'])) {
                $block['min'] = (int)$block_config['min'];
            }

            if (isset($block_config['max'])) {
                $block['max'] = (int)$block_config['max'];
            }

            $processed_blocks[$block_id] = $block;
        }

        return $processed_blocks;
    }

    /**
     * Parsear un archivo de esquema para extraer configuración (actualizado con soporte completo para bloques)
     */
    private function parse_section_schema($section_id, $schema_path)
    {
        if (!file_exists($schema_path)) {
            error_log("ERROR: Archivo de esquema no encontrado: $schema_path");
            return null;
        }

        try {
            // Incluir el archivo PHP y obtener el array de configuración
            $schema_config = include $schema_path;

            // Verificar que el archivo retorna un array válido
            if (!is_array($schema_config)) {
                error_log("ERROR: El esquema en $schema_path no retorna un array válido. Tipo retornado: " . gettype($schema_config));
                return null;
            }

            error_log("Esquema cargado correctamente para $section_id: " . json_encode($schema_config, JSON_UNESCAPED_UNICODE));

            // Estructura base del esquema
            $schema = [
                'name' => $schema_config['name'] ?? ucfirst(str_replace('-', ' ', $section_id)),
                'description' => $schema_config['description'] ?? '',
                'category' => $schema_config['category'] ?? 'general',
                'icon' => $schema_config['icon'] ?? 'admin-generic',
                'tag' => $schema_config['tag'] ?? 'section',
                'properties' => [],
                'blocks' => []
            ];

            // Convertir settings de la sección a properties
            if (isset($schema_config['settings']) && is_array($schema_config['settings'])) {
                $schema['properties'] = $this->convert_settings_to_properties($schema_config['settings']);
                error_log("Propiedades de sección convertidas para $section_id: " . count($schema['properties']) . " campos");
            } else {
                error_log("ADVERTENCIA: No se encontraron 'settings' en el esquema de $section_id");
            }

            // Procesar bloques si existen
            if (isset($schema_config['blocks']) && is_array($schema_config['blocks'])) {
                $schema['blocks'] = $this->process_schema_blocks($schema_config['blocks'], $section_id);
                error_log("Bloques procesados para $section_id: " . count($schema['blocks']) . " bloques definidos");
            }

            return $schema;
        } catch (\Exception $e) {
            error_log("ERROR al parsear esquema $schema_path: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return null;
        } catch (\ParseError $e) {
            error_log("ERROR de sintaxis en esquema $schema_path: " . $e->getMessage());
            error_log("En línea: " . $e->getLine());
            return null;
        } catch (\Error $e) {
            error_log("ERROR fatal en esquema $schema_path: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Convertir configuración de settings al formato de properties esperado
     */
    private function convert_settings_to_properties($settings)
    {
        $properties = [];

        foreach ($settings as $key => $setting) {
            if (!is_array($setting)) {
                error_log("ADVERTENCIA: Setting '$key' no es un array, ignorando");
                continue;
            }

            // Mapear tipos del formato shopify al formato del builder
            $original_type = $setting['type'] ?? 'text';
            $type = $this->map_setting_type($original_type);

            $property = [
                'title' => $setting['label'] ?? $setting['title'] ?? $this->generate_field_title($key),
                'type' => $type,
                'description' => $setting['info'] ?? $setting['description'] ?? '',
                'default' => $setting['default'] ?? $this->get_default_value_by_type($type)
            ];

            // Manejar opciones/enum para select, radio, etc.
            if (isset($setting['options']) && is_array($setting['options'])) {
                // Si options es un array asociativo, extraer keys y values
                if ($this->is_associative_array($setting['options'])) {
                    $property['enum'] = array_keys($setting['options']);
                    $property['enumNames'] = array_values($setting['options']);
                } else {
                    // Si es array simple, usar los mismos valores
                    $property['enum'] = $setting['options'];
                }
            }

            // Configuraciones específicas según el tipo
            switch ($original_type) {
                case 'image':
                    $property['format'] = 'image';
                    break;

                case 'color':
                    $property['type'] = 'color';
                    break;

                case 'range':
                    $property['type'] = 'number';
                    if (isset($setting['min'])) $property['minimum'] = (float)$setting['min'];
                    if (isset($setting['max'])) $property['maximum'] = (float)$setting['max'];
                    if (isset($setting['step'])) $property['multipleOf'] = (float)$setting['step'];
                    break;

                case 'number':
                    if (isset($setting['min'])) $property['minimum'] = (float)$setting['min'];
                    if (isset($setting['max'])) $property['maximum'] = (float)$setting['max'];
                    break;

                case 'textarea':
                    $property['format'] = 'textarea';
                    break;

                case 'checkbox':
                    $property['type'] = 'boolean';
                    break;

                // NUEVOS tipos de WordPress
                case 'menu':
                case 'post':
                case 'page':
                case 'product':
                case 'taxonomy':
                case 'widget':
                case 'file':
                case 'video_url':
                case 'shortcode':
                    $property['format'] = $original_type;
                    break;
            }

            // Placeholder o ayuda
            if (isset($setting['placeholder'])) {
                $property['placeholder'] = $setting['placeholder'];
            }

            // Campos requeridos
            if (isset($setting['required']) && $setting['required']) {
                $property['required'] = true;
            }

            error_log("Propiedad convertida: $key -> " . json_encode($property, JSON_UNESCAPED_UNICODE));

            $properties[$key] = $property;
        }

        return $properties;
    }

    /**
     * Mapear tipos de Shopify/settings al formato del builder
     */
    private function map_setting_type($shopify_type)
    {
        $type_map = [
            // Básicos
            'text' => 'string',
            'textarea' => 'string',
            'number' => 'number',
            'range' => 'number',
            'checkbox' => 'boolean',
            'color' => 'color',

            // Selects
            'select' => 'string',
            'radio' => 'string',

            // Media
            'image' => 'string',
            'file' => 'string',
            'video_url' => 'string',

            // Texto enriquecido
            'richtext' => 'string',
            'html' => 'string',

            // URLs
            'url' => 'string',
            'link_list' => 'string',

            // WordPress específicos - NUEVOS
            'post' => 'string',
            'page' => 'string',
            'product' => 'string',
            'collection' => 'string',
            'blog' => 'string',
            'taxonomy' => 'string',
            'menu' => 'string',
            'widget' => 'string',
            'shortcode' => 'string',


        ];

        return isset($type_map[$shopify_type]) ? $type_map[$shopify_type] : 'string';
    }

    /**
     * Obtener valor por defecto según el tipo
     */
    private function get_default_value_by_type($type)
    {
        switch ($type) {
            case 'number':
                return 0;
            case 'boolean':
                return false;
            case 'array':
                return [];
            default:
                return '';
        }
    }

    /**
     * Verificar si un array es asociativo
     */
    private function is_associative_array($array)
    {
        if (!is_array($array) || array() === $array) {
            return false;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Generar título legible para el campo
     */
    private function generate_field_title($field_name)
    {
        // Convertir snake_case a título legible
        $title = str_replace('_', ' ', $field_name);
        return ucwords($title);
    }

    /**
     * Verificar si existe un esquema para la sección
     */
    private function section_schema_exists($section_id)
    {
        $schema_path = $this->get_schema_path($section_id);
        $exists = file_exists($schema_path);

        error_log("Verificando esquema para '$section_id':");
        error_log("  - Ruta: $schema_path");
        error_log("  - Existe: " . ($exists ? 'SÍ' : 'NO'));

        // Si no existe, verificar también si hay archivos con guiones o guiones bajos
        if (!$exists) {
            $alternatives = [
                str_replace('-', '_', $section_id),
                str_replace('_', '-', $section_id)
            ];

            foreach ($alternatives as $alt_id) {
                $alt_path = $this->get_schema_path($alt_id);
                if (file_exists($alt_path)) {
                    error_log("  - Encontrado esquema alternativo: $alt_path");
                    return true;
                }
            }
        }

        return $exists;
    }

    /**
     * Obtener la ruta completa al archivo de esquema
     */
    private function get_schema_path($section_id)
    {
        $schemas_dir = get_template_directory() . '/schemas';

        // Verificar configuración personalizada
        if (current_theme_supports('sections-builder')) {
            $config = get_theme_support('sections-builder');
            if (is_array($config) && !empty($config[0]) && isset($config[0]['schemas_directory'])) {
                $schemas_dir = get_template_directory() . '/' . $config[0]['schemas_directory'];
            }
        }

        return $schemas_dir . '/' . $section_id . '.php';
    }

    /**
     * Obtener información básica de un esquema (actualizado)
     */
    private function get_section_schema_info($section_id)
    {
        $schema_path = $this->get_schema_path($section_id);

        if (!file_exists($schema_path)) {
            error_log("get_section_schema_info: Esquema no encontrado para $section_id en $schema_path");
            return [];
        }

        try {
            $schema_config = include $schema_path;

            if (!is_array($schema_config)) {
                error_log("get_section_schema_info: Esquema inválido para $section_id");
                return [];
            }

            return [
                'name' => $schema_config['name'] ?? ucfirst(str_replace('-', ' ', $section_id)),
                'description' => $schema_config['description'] ?? '',
                'category' => $schema_config['category'] ?? 'general',
                'icon' => $schema_config['icon'] ?? 'admin-generic'
            ];
        } catch (\Exception $e) {
            error_log("ERROR en get_section_schema_info para $section_id: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Endpoint AJAX para obtener una plantilla específica - ACTUALIZADO
     */
    public function ajax_get_template()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        $template_name = isset($_POST['template_name']) ? sanitize_text_field($_POST['template_name']) : '';

        if (empty($template_name)) {
            wp_send_json_error(['message' => 'Nombre de plantilla no proporcionado']);
            exit;
        }

        error_log("=== LOADING TEMPLATE: {$template_name} ===");

        // NUEVO: Intentar cargar desde el Registry primero
        $core = \Juztstack\JuztStudio\Community\Core::get_instance();

        if ($core && isset($core->extension_registry)) {
            $all_templates = $core->extension_registry->get_all_templates();

            if (isset($all_templates[$template_name])) {
                $template_data = $all_templates[$template_name];
                $json_file = $template_data['json_file'] ?? null;

                error_log("Template found in registry: {$json_file}");

                if ($json_file && file_exists($json_file)) {
                    $json_content = file_get_contents($json_file);
                    $template_json = json_decode($json_content, true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        error_log("✅ Template loaded successfully from: " . $template_data['source']);
                        wp_send_json_success($template_json);
                        exit;
                    } else {
                        error_log("❌ JSON decode error: " . json_last_error_msg());
                    }
                } else {
                    error_log("❌ JSON file not found: {$json_file}");
                }
            }
        }

        // FALLBACK: Buscar en el tema (método original)
        error_log("Fallback: searching in theme directory");

        $theme_dir = get_template_directory() . '/templates';

        if (current_theme_supports('sections-builder')) {
            $config = get_theme_support('sections-builder');
            if (is_array($config) && !empty($config[0]) && isset($config[0]['templates_directory'])) {
                $theme_dir = get_template_directory() . '/' . $config[0]['templates_directory'];
            }
        }

        $template_file = $theme_dir . '/' . $template_name . '.json';

        error_log("Looking for template in: {$template_file}");

        if (!file_exists($template_file)) {
            error_log("❌ Template not found in theme");
            wp_send_json_error(['message' => 'Plantilla no encontrada: ' . $template_name]);
            exit;
        }

        $json_content = file_get_contents($template_file);
        $template_data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("❌ JSON decode error: " . json_last_error_msg());
            wp_send_json_error(['message' => 'Error al decodificar el JSON de la plantilla']);
            exit;
        }

        error_log("✅ Template loaded from theme");
        wp_send_json_success($template_data);
        exit;
    }

    private function normalize_template_structure($template_data)
    {
        error_log('=== NORMALIZANDO ESTRUCTURA ===');

        // Asegurar que sections es objeto
        if (!isset($template_data['sections']) || !is_array($template_data['sections'])) {
            $template_data['sections'] = [];
        }

        // Asegurar que order existe y es array
        if (!isset($template_data['order']) || !is_array($template_data['order'])) {
            $template_data['order'] = array_keys($template_data['sections']);
        }

        // Normalizar cada sección
        foreach ($template_data['sections'] as $section_key => &$section) {
            error_log("Procesando sección: $section_key");

            // Asegurar section_id
            if (!isset($section['section_id'])) {
                $section['section_id'] = $section_key;
            }

            // **CRÍTICO: Normalizar settings - convertir array indexado a asociativo preservando datos**
            if (!isset($section['settings'])) {
                $section['settings'] = [];
                error_log("  - Settings no existía, creado como array vacío");
            } elseif (is_array($section['settings'])) {
                // Si es array indexado, convertirlo preservando los valores
                if (array_keys($section['settings']) === range(0, count($section['settings']) - 1)) {
                    error_log("  - Settings era array indexado, convirtiendo a asociativo");
                    error_log("  - Datos originales: " . json_encode($section['settings']));

                    // Crear nuevo array asociativo
                    $new_settings = [];
                    foreach ($section['settings'] as $index => $value) {
                        // Preservar los valores pero con claves numéricas como strings
                        $new_settings[(string)$index] = $value;
                    }
                    $section['settings'] = $new_settings;
                    error_log("  - Datos convertidos: " . json_encode($section['settings']));
                }
                // Si ya es array asociativo, dejarlo como está
            } else {
                // Si no es array, convertirlo a array vacío
                error_log("  - Settings no era array, convirtiendo a array vacío");
                $section['settings'] = [];
            }

            // Asegurar que blocks existe y es array
            if (!isset($section['blocks']) || !is_array($section['blocks'])) {
                $section['blocks'] = [];
            }

            // Normalizar cada bloque
            foreach ($section['blocks'] as &$block) {
                // **CRÍTICO: Normalizar settings de bloques - misma lógica**
                if (!isset($block['settings'])) {
                    $block['settings'] = [];
                } elseif (is_array($block['settings'])) {
                    // Si es array indexado, convertirlo preservando valores
                    if (array_keys($block['settings']) === range(0, count($block['settings']) - 1)) {
                        $new_block_settings = [];
                        foreach ($block['settings'] as $index => $value) {
                            $new_block_settings[(string)$index] = $value;
                        }
                        $block['settings'] = $new_block_settings;
                    }
                } else {
                    $block['settings'] = [];
                }
            }
        }

        error_log('=== ESTRUCTURA NORMALIZADA ===');
        return $template_data;
    }

    /**
     * Determinar dónde guardar el template (tema o extensión) - NUEVO
     * 
     * @param string $template_name
     * @return array ['type' => 'theme'|'extension', 'dir' => '/path/to/templates', 'root_dir' => '/root', 'name' => 'Source Name']
     */
    private function determine_template_save_location($template_name)
    {
        $core = \Juztstack\JuztStudio\Community\Core::get_instance();

        // Intentar encontrar el template en el Registry
        if ($core && isset($core->extension_registry)) {
            $all_templates = $core->extension_registry->get_all_templates();

            if (isset($all_templates[$template_name])) {
                $template_data = $all_templates[$template_name];
                $source = $template_data['source'];

                error_log("Template exists in registry, source: {$source}");

                // Si es del tema
                if ($source === 'theme') {
                    $theme_dir = get_template_directory() . '/templates';

                    if (current_theme_supports('sections-builder')) {
                        $config = get_theme_support('sections-builder');
                        if (is_array($config) && !empty($config[0]) && isset($config[0]['templates_directory'])) {
                            $theme_dir = get_template_directory() . '/' . $config[0]['templates_directory'];
                        }
                    }

                    return [
                        'type' => 'theme',
                        'dir' => $theme_dir,
                        'root_dir' => get_template_directory(),
                        'name' => 'Theme',
                    ];
                }

                // Si es de una extensión
                $extensions = $core->extension_registry->get_extensions();

                if (isset($extensions[$source])) {
                    $ext_config = $extensions[$source];
                    $templates_dir = $ext_config['paths']['templates_dir'] ?? null;

                    if ($templates_dir && is_dir($templates_dir)) {
                        // Obtener root dir de la extensión (subir un nivel desde templates/)
                        $root_dir = dirname($templates_dir);

                        return [
                            'type' => 'extension',
                            'dir' => $templates_dir,
                            'root_dir' => $root_dir,
                            'name' => $ext_config['name'] ?? $source,
                            'source_id' => $source,
                        ];
                    }
                }
            }
        }

        // Default: guardar en el tema
        error_log("Template not found in registry, defaulting to theme");

        $theme_dir = get_template_directory() . '/templates';

        if (current_theme_supports('sections-builder')) {
            $config = get_theme_support('sections-builder');
            if (is_array($config) && !empty($config[0]) && isset($config[0]['templates_directory'])) {
                $theme_dir = get_template_directory() . '/' . $config[0]['templates_directory'];
            }
        }

        return [
            'type' => 'theme',
            'dir' => $theme_dir,
            'root_dir' => get_template_directory(),
            'name' => 'Theme',
        ];
    }

    /**
     * Endpoint AJAX para guardar una plantilla - ACTUALIZADO para extensiones
     */
    public function ajax_save_template()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        $template_name = isset($_POST['template_name']) ? sanitize_text_field($_POST['template_name']) : '';
        $template_data = isset($_POST['template_data']) ? $_POST['template_data'] : '';
        $create_files = isset($_POST['create_files']) && $_POST['create_files'] === '1';

        error_log('=== SAVE TEMPLATE ===');
        error_log('Template name: ' . $template_name);
        error_log('Create files: ' . ($create_files ? 'YES' : 'NO'));

        if (empty($template_name)) {
            wp_send_json_error(['message' => 'Template name not provided']);
            exit;
        }

        if (empty($template_data)) {
            wp_send_json_error(['message' => 'Template data not provided']);
            exit;
        }

        // Decodificar datos
        if (is_string($template_data)) {
            $template_data = json_decode(stripslashes($template_data), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('❌ JSON Error: ' . json_last_error_msg());
                wp_send_json_error(['message' => 'Error decoding JSON: ' . json_last_error_msg()]);
                exit;
            }
        }

        error_log('Template data decoded: ' . json_encode($template_data, JSON_UNESCAPED_UNICODE));

        // Normalizar estructura
        $template_data = $this->sanitize_template_data($template_data);

        // NUEVO: Determinar dónde guardar (tema o extensión)
        $save_location = $this->determine_template_save_location($template_name);

        error_log('Save location determined: ' . $save_location['type'] . ' (' . $save_location['dir'] . ')');

        // Asegurarse de que el directorio existe
        if (!is_dir($save_location['dir'])) {
            $dir_created = wp_mkdir_p($save_location['dir']);
            if (!$dir_created) {
                wp_send_json_error(['message' => 'Could not create templates directory']);
                exit;
            }
        }

        // Verificar permisos de escritura
        if (!is_writable($save_location['dir'])) {
            wp_send_json_error(['message' => 'Directory is not writable: ' . $save_location['dir']]);
            exit;
        }

        // Guardar JSON
        $template_file = $save_location['dir'] . '/' . $template_name . '.json';
        $json_content = json_encode($template_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $result = file_put_contents($template_file, $json_content);

        if ($result === false) {
            $error = error_get_last();
            error_log('❌ Error writing file: ' . print_r($error, true));
            wp_send_json_error(['message' => 'Error saving template. Check write permissions.']);
            exit;
        }

        error_log('✅ JSON file saved successfully. Bytes written: ' . $result);

        $response_data = [
            'message' => 'Template saved successfully',
            'file_path' => $template_file,
            'bytes_written' => $result,
            'location_type' => $save_location['type'],
            'location_name' => $save_location['name'],
        ];

        // NUEVO: Crear archivos PHP y Twig solo si es del TEMA y se solicitó
        // Para extensiones, no crear archivos automáticamente
        if ($create_files && $save_location['type'] === 'theme') {
            $files_created = $this->create_template_files($template_name, $template_data);
            $response_data['files_created'] = $files_created;
        } elseif ($create_files && $save_location['type'] === 'extension') {
            $response_data['note'] = 'Files not created automatically for extensions. Manage them manually in the extension directory.';
        }

        // Commit con Git si está disponible
        $commit = apply_filters('wpvtp_queue_commit', null, $save_location['root_dir'], 'Update from Section builder: ' . $template_name);
        if ($commit) {
            $response_data['commit'] = $commit;
        }

        wp_send_json_success($response_data);
        exit;
    }

    /**
     * Obtener esquema de una sección por su ID
     */
    private function get_section_schema_by_id($section_id)
    {
        $schema_path = $this->get_schema_path($section_id);

        if (!file_exists($schema_path)) {
            return null;
        }

        return $this->parse_section_schema($section_id, $schema_path);
    }

    /**
     * Adaptar estructura de plantilla para Timber/Twig (actualizado con soporte para bloques)
     */
    private function adapt_template_for_timber($template)
    {
        // Asegurarse de que existe la estructura correcta
        if (!isset($template['order'])) {
            $template['order'] = array_keys($template['sections'] ?? []);
        }

        if (!isset($template['sections'])) {
            $template['sections'] = [];
        }

        // Convertir secciones al formato esperado por Timber
        foreach ($template['sections'] as $section_key => &$section) {
            // Asegurarse de que cada sección tiene section_id
            if (!isset($section['section_id'])) {
                $section['section_id'] = $section_key;
            }

            // CRÍTICO: Forzar settings como objeto asociativo, NUNCA como array indexado
            if (!isset($section['settings'])) {
                $section['settings'] = new \stdClass(); // ← Objeto vacío en JSON
            } elseif (is_array($section['settings'])) {
                // Si es array indexado, convertir a objeto vacío
                if (array_keys($section['settings']) === range(0, count($section['settings']) - 1)) {
                    error_log("⚠️ Sección $section_key tenía settings como array indexado, convirtiendo a objeto");
                    $section['settings'] = new \stdClass(); // ← Forzar objeto vacío
                } elseif (empty($section['settings'])) {
                    $section['settings'] = new \stdClass(); // ← Objeto vacío si está vacío
                }
                // Si ya es array asociativo, dejarlo como está
            }

            // Si hay configuraciones directas en la sección, moverlas a 'settings'
            $section_schema = $this->get_section_schema_by_id($section['section_id']);
            if ($section_schema && isset($section_schema['properties'])) {
                // Asegurar que settings es un objeto
                if (!is_object($section['settings']) && !is_array($section['settings'])) {
                    $section['settings'] = new \stdClass();
                } elseif (is_array($section['settings']) && empty($section['settings'])) {
                    $section['settings'] = new \stdClass();
                }

                // Convertir a array para manipulación
                $settings_array = is_object($section['settings']) ? [] : (array)$section['settings'];

                // Mover configuraciones que corresponden al esquema de la sección a 'settings'
                foreach ($section_schema['properties'] as $prop_key => $prop_config) {
                    if (isset($section[$prop_key]) && !isset($settings_array[$prop_key])) {
                        $settings_array[$prop_key] = $section[$prop_key];
                    }
                }

                // Si hay settings, guardarlo como array asociativo, si no como objeto vacío
                $section['settings'] = !empty($settings_array) ? $settings_array : new \stdClass();
            }

            // Asegurar que blocks existe como array
            if (!isset($section['blocks'])) {
                $section['blocks'] = [];
            }

            // Procesar bloques existentes para mantener estructura correcta
            if (is_array($section['blocks'])) {
                foreach ($section['blocks'] as $block_index => &$block) {
                    // Asegurar que cada bloque tiene un type/block_id
                    if (!isset($block['type']) && !isset($block['block_id'])) {
                        $block['type'] = 'default';
                    }

                    // CRÍTICO: Forzar settings del bloque como objeto
                    if (!isset($block['settings'])) {
                        $block['settings'] = new \stdClass();
                    } elseif (is_array($block['settings'])) {
                        // Si es array indexado, convertir a objeto vacío
                        if (array_keys($block['settings']) === range(0, count($block['settings']) - 1)) {
                            $block['settings'] = new \stdClass();
                        } elseif (empty($block['settings'])) {
                            $block['settings'] = new \stdClass();
                        }
                    }
                }
            }
        }

        return $template;
    }

    /**
     * TEMPORAL: Función para limpiar templates con settings como array
     * Llamar una vez para arreglar templates existentes
     */
    public function fix_templates_settings()
    {
        $theme_dir = get_template_directory() . '/templates';

        if (current_theme_supports('sections-builder')) {
            $config = get_theme_support('sections-builder');
            if (is_array($config) && !empty($config[0]) && isset($config[0]['templates_directory'])) {
                $theme_dir = get_template_directory() . '/' . $config[0]['templates_directory'];
            }
        }

        if (!is_dir($theme_dir)) {
            return;
        }

        $files = glob($theme_dir . '/*.json');
        $fixed_count = 0;

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $template = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }

            $needs_fix = false;

            // Verificar cada sección
            if (isset($template['sections'])) {
                foreach ($template['sections'] as $key => &$section) {
                    // Arreglar settings si es array indexado
                    if (isset($section['settings']) && is_array($section['settings'])) {
                        if (array_keys($section['settings']) === range(0, count($section['settings']) - 1)) {
                            $section['settings'] = new \stdClass();
                            $needs_fix = true;
                            error_log("Arreglando settings en: " . basename($file) . " - Sección: $key");
                        }
                    }

                    // Arreglar blocks
                    if (isset($section['blocks']) && is_array($section['blocks'])) {
                        foreach ($section['blocks'] as &$block) {
                            if (isset($block['settings']) && is_array($block['settings'])) {
                                if (array_keys($block['settings']) === range(0, count($block['settings']) - 1)) {
                                    $block['settings'] = new \stdClass();
                                    $needs_fix = true;
                                }
                            }
                        }
                    }
                }
            }

            if ($needs_fix) {
                $json_content = json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                file_put_contents($file, $json_content);
                $fixed_count++;
                error_log("✅ Arreglado: " . basename($file));
            }
        }

        return "Arreglados $fixed_count templates";
    }

    /**
     * Registrar menú de administración
     */
    public function register_admin_menu()
    {
        add_menu_page(
            __('Template Builder', 'sections-builder'),
            __('Template Builder', 'sections-builder'),
            'manage_options',
            'sections-builder-templates',
            [$this, 'render_admin_page'],
            'dashicons-layout',
            30
        );
    }

    /**
     * Renderizar página de administración
     */
    public function render_admin_page()
    {
?>
        <div class="wrap juzt-studio-wrap">
            <header class="juzt-studio-header">
                <div>
                    <h1><?php echo esc_html__('JuztStudio Community Version', 'sections-builder'); ?></h1>
                    <p>Customizer themes created by <a href="www.juztstack.dev" target="_blank">JuztStack</a></p>
                </div>
                <button class="juzt-studio-close-app">
                    Exit
                </button>
            </header>
            <div id="juzt-studio-app"></div>
        </div>
    <?php
    }

    /**
     * Cargar scripts y estilos para el admin
     */
    public function enqueue_admin_assets($hook)
    {
        // Solo cargar en la página del builder
        if ($hook !== 'toplevel_page_sections-builder-templates') {
            return;
        }

        error_log('JUZTSTUDIO_CM_PLUGIN_URL: ' . JUZTSTUDIO_CM_PLUGIN_URL);
        error_log('assets_url: ' . $this->assets_url);
        error_log('Ruta completa CSS: ' . $this->assets_url . 'css/builder.css');
        error_log('Ruta completa JS: ' . $this->assets_url . 'js/builder.js');

        // ELIMINAR EL var_dump - esto rompe todo

        // Registrar y cargar estilos
        wp_enqueue_style(
            'sections-builder-styles',
            $this->assets_url . 'css/builder.css',
            [],
            JUZTSTUDIO_CM_VERSION
        );

        // Cargar media uploader de WordPress
        wp_enqueue_media();

        // Cargar script principal del builder
        wp_enqueue_script(
            'sections-builder-app',
            $this->assets_url . 'js/builder.js',
            [], // ← SOLO wp-media, sin jQuery ni wp-api-fetch
            JUZTSTUDIO_CM_VERSION,
            false
        );

        // Pasar datos al script
        wp_localize_script('sections-builder-app', 'sectionsBuilderData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sections_builder_nonce'),
            'timberEnabled' => class_exists('Timber\\Timber'),
            'adminUrl' => admin_url(),
        ]);
    }


    /**
     * Registrar rutas de la REST API
     */
    public function register_rest_routes()
    {
        register_rest_route('sections-builder/v1', '/sections', [
            'methods' => 'GET',
            'callback' => function () {
                return ['message' => 'Secciones endpoint funcionando correctamente - Timber/Twig Edition'];
            },
            'permission_callback' => function () {
                return true;
            }
        ]);
    }

    /**
     * Verificar permisos de administrador
     */
    public function check_admin_permission()
    {
        return current_user_can('manage_options');
    }

    /**
     * Mapear tipos de campos del formato antiguo al formato nuevo
     */
    private function map_type($type)
    {
        $type_map = [
            'text' => 'string',
            'textarea' => 'string',
            'image' => 'string',
            'number' => 'number',
            'checkbox' => 'boolean',
            'select' => 'string',
            'color' => 'color',
            'radio' => 'string',
            'file' => 'string',
            'gallery' => 'array',
            'repeater' => 'array'
        ];

        return isset($type_map[$type]) ? $type_map[$type] : 'string';
    }

    // TEMPORAL: Función para limpiar templates existentes
    public function fix_existing_templates()
    {
        $theme_dir = get_template_directory() . '/templates';

        if (current_theme_supports('sections-builder')) {
            $config = get_theme_support('sections-builder');
            if (is_array($config) && !empty($config[0]) && isset($config[0]['templates_directory'])) {
                $theme_dir = get_template_directory() . '/' . $config[0]['templates_directory'];
            }
        }

        $files = glob($theme_dir . '/*.json');
        $fixed_count = 0;

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $template = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }

            $fixed_template = $this->normalize_template_structure($template);

            // Solo guardar si hubo cambios
            if ($template !== $fixed_template) {
                $json_content = json_encode($fixed_template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                file_put_contents($file, $json_content);
                $fixed_count++;
                error_log("✅ Arreglado: " . basename($file));
            }
        }

        return "Arreglados $fixed_count templates";
    }

    /**
     * MÉTODO OBSOLETO - Ya no se usa extract_schema_properties
     * Mantenido para compatibilidad, pero devuelve array vacío
     */
    private function extract_schema_properties($content)
    {
        error_log("ADVERTENCIA: extract_schema_properties está obsoleto, usar convert_settings_to_properties");
        return [];
    }

    /**
     * Convertir valor por defecto según el tipo
     */
    private function convert_default_value($default_value, $type)
    {
        switch ($type) {
            case 'number':
                return is_numeric($default_value) ? (float)$default_value : 0;
            case 'boolean':
                return in_array(strtolower($default_value), ['true', '1', 'yes']);
            case 'array':
                if (strpos($default_value, 'array(') === 0) {
                    return [];
                }
                return $default_value;
            default:
                return (string)$default_value;
        }
    }

    /**
     * Determinar el tipo de campo basado en el nombre y valor por defecto
     */
    private function determine_field_type($field_name, $default_value)
    {
        // Casos específicos basados en el nombre
        if (strpos($field_name, 'color') !== false) {
            return 'color';
        }

        if (strpos($field_name, 'imagen') !== false || strpos($field_name, 'image') !== false) {
            return 'string'; // Se añadirá format: 'image' después
        }

        // Basado en el valor por defecto
        if (is_numeric($default_value)) {
            return 'number';
        }

        if (in_array(strtolower($default_value), ['true', 'false'])) {
            return 'boolean';
        }

        if (strpos($default_value, 'array(') === 0 || strpos($default_value, '[') === 0) {
            return 'array';
        }

        return 'string';
    }

    /**
     * Obtener opciones para ciertos campos
     */
    private function get_field_options($field_name)
    {
        $options_map = [
            'alineacion' => ['left', 'center', 'right'],
            'estilo_grid' => ['grid-2', 'grid-3', 'grid-4', 'grid-6'],
            'altura_seccion' => ['small', 'medium', 'large', 'screen'],
            'tipo_boton' => ['primary', 'secondary', 'outline'],
            'tamaño_texto' => ['small', 'medium', 'large', 'xl'],
            'posicion' => ['top', 'center', 'bottom'],
            'orientacion' => ['horizontal', 'vertical']
        ];

        foreach ($options_map as $pattern => $options) {
            if (strpos($field_name, $pattern) !== false) {
                return $options;
            }
        }

        return [];
    }


    /**
     * AJAX: Obtener schema de settings generales
     */
    public function ajax_get_general_settings_schema()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'No tienes permisos suficientes']);
            return;
        }

        try {
            $schema_file = get_template_directory() . '/config/settings_schema.php';

            if (!file_exists($schema_file)) {
                wp_send_json_success(null);
                return;
            }

            $schema = include $schema_file;

            if (!is_array($schema)) {
                wp_send_json_error(['message' => 'Schema inválido']);
                return;
            }

            wp_send_json_success($schema);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Obtener settings generales guardados
     */
    public function ajax_get_general_settings()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'No tienes permisos suficientes']);
            return;
        }

        try {
            $settings_file = get_template_directory() . '/config/settings_data.json';

            if (!file_exists($settings_file)) {
                wp_send_json_success([]);
                return;
            }

            $content = file_get_contents($settings_file);
            $settings = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error(['message' => 'Error al leer settings: JSON inválido']);
                return;
            }

            wp_send_json_success($settings);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Guardar settings generales
     */
    public function ajax_save_general_settings()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'No tienes permisos suficientes']);
            return;
        }

        $settings_data = isset($_POST['settings_data']) ? $_POST['settings_data'] : '';

        if (empty($settings_data)) {
            wp_send_json_error(['message' => 'Datos de settings requeridos']);
            return;
        }

        $data = json_decode(stripslashes($settings_data), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => 'JSON inválido']);
            return;
        }

        try {
            $config_dir = get_template_directory() . '/config';

            if (!file_exists($config_dir)) {
                wp_mkdir_p($config_dir);
            }

            $settings_file = $config_dir . '/settings_data.json';

            $json_content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $result = file_put_contents($settings_file, $json_content);

            if ($result === false) {
                wp_send_json_error(['message' => 'Error al escribir el archivo']);
                return;
            }

            wp_send_json_success([
                'message' => 'Settings guardados correctamente',
                'file' => $settings_file
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Preview de template - ACTUALIZADO para extensiones
     */
    public function ajax_preview_template()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_die('No tienes permisos suficientes', 403);
        }

        $template_id = isset($_GET['template_id']) ? sanitize_text_field($_GET['template_id']) : '';

        if (empty($template_id)) {
            wp_die('No se especificó template_id', 400);
        }

        error_log("=== PREVIEW TEMPLATE: {$template_id} ===");

        // NUEVO: Intentar cargar desde el Registry primero
        $core = \Juztstack\JuztStudio\Community\Core::get_instance();
        $template_data = null;
        $template_file = null;

        if ($core && isset($core->extension_registry)) {
            $all_templates = $core->extension_registry->get_all_templates();

            if (isset($all_templates[$template_id])) {
                $template_info = $all_templates[$template_id];
                $template_file = $template_info['json_file'] ?? null;

                error_log("Template found in registry");
                error_log("Source: " . ($template_info['source'] ?? 'unknown'));
                error_log("JSON file: " . $template_file);

                if ($template_file && file_exists($template_file)) {
                    $json_content = file_get_contents($template_file);
                    $template_data = json_decode($json_content, true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        error_log("✅ Template loaded from Registry for preview");
                    } else {
                        error_log("❌ JSON decode error: " . json_last_error_msg());
                        $template_data = null;
                    }
                } else {
                    error_log("❌ JSON file not found: {$template_file}");
                }
            } else {
                error_log("Template not found in registry");
            }
        }

        // FALLBACK: Buscar en el tema (método original)
        if (!$template_data) {
            error_log("Fallback: searching in theme directory");

            $theme_dir = get_template_directory() . '/templates';

            if (current_theme_supports('sections-builder')) {
                $config = get_theme_support('sections-builder');
                if (is_array($config) && !empty($config[0]) && isset($config[0]['templates_directory'])) {
                    $theme_dir = get_template_directory() . '/' . $config[0]['templates_directory'];
                }
            }

            $template_file = $theme_dir . '/' . $template_id . '.json';

            error_log("Looking for template in: {$template_file}");

            if (!file_exists($template_file)) {
                error_log("❌ Template not found in theme");
                wp_die('Template no encontrado: ' . $template_id, 404);
            }

            $json_content = file_get_contents($template_file);
            $template_data = json_decode($json_content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("❌ JSON decode error: " . json_last_error_msg());
                wp_die('Error al decodificar el JSON del template', 500);
            }

            error_log("✅ Template loaded from theme for preview");
        }

        // Renderizar el preview
        $this->render_template_preview($template_data, $template_id);
        exit;
    }

    /**
     * Renderizar preview del template
     */
    private function render_template_preview($template_data, $template_id)
    {
    ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>

        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Preview: <?php echo esc_html($template_data['name'] ?? $template_id); ?></title>

            <?php
            // Cargar estilos del tema
            wp_head();
            ?>

            <style>
                body {
                    margin: 0;
                }

                .preview-header {
                    background: #fff;
                    padding: 15px 20px;
                    margin: -20px -20px 20px -20px;
                    border-bottom: 2px solid #0073aa;
                    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                }

                .preview-header h1 {
                    margin: 0;
                    font-size: 18px;
                    color: #23282d;
                }

                .preview-header .template-info {
                    font-size: 13px;
                    color: #666;
                    margin-top: 5px;
                }

                .template-sections {
                    max-width: 100%;
                    margin: 0 auto;
                }

                .section-preview-header {
                    border-bottom: 1px solid #ddd;
                    padding-bottom: 10px;
                    margin-bottom: 15px;
                    font-size: 14px;
                    color: #0073aa;
                    font-weight: 600;
                }

                .preview-warning {
                    background: #fff8e5;
                    border-left: 4px solid #ffb900;
                    padding: 12px 15px;
                    margin-bottom: 20px;
                    font-size: 13px;
                }
            </style>
        </head>

        <body>

            <div class="template-sections">
                <?php
                // Verificar si Timber está disponible
                if (!class_exists('Timber\Timber')) {
                    echo '<div class="section-preview">';
                    echo '<p><strong>⚠️ Timber no está disponible.</strong></p>';
                    echo '<p>Este preview requiere Timber/Twig para renderizar las secciones correctamente.</p>';
                    echo '</div>';
                } else {
                    // Obtener Registry
                    $core = \Juztstack\JuztStudio\Community\Core::get_instance();

                    if (!$core || !isset($core->extension_registry)) {
                        echo '<div class="preview-warning">';
                        echo '<p>⚠️ Registry no disponible. No se pueden cargar secciones de extensiones.</p>';
                        echo '</div>';
                    }

                    // Renderizar cada sección
                    $sections_order = $template_data['order'] ?? array_keys($template_data['sections'] ?? []);

                    foreach ($sections_order as $section_key) {
                        if (!isset($template_data['sections'][$section_key])) {
                            continue;
                        }

                        $section = $template_data['sections'][$section_key];
                        $section_id = $section['section_id'] ?? $section_key;

                        echo '<div class="section-preview">';

                        // Intentar renderizar la sección
                        try {
                            // NUEVO: Obtener información de la sección desde el Registry
                            $section_info = null;
                            if ($core && isset($core->extension_registry)) {
                                $section_info = $core->extension_registry->get_section($section_id);
                            }

                            // Preparar contexto para Timber
                            $context = [
                                'section' => $section,
                            ];

                            // Renderizar la sección
                            if ($section_info && !empty($section_info['twig_file'])) {
                                $twig_file = $section_info['twig_file'];

                                error_log("Rendering section from: {$twig_file}");

                                if (file_exists($twig_file)) {
                                    // Leer el contenido del archivo Twig
                                    $twig_content = file_get_contents($twig_file);

                                    // Renderizar usando compile_string (funciona con cualquier ruta)
                                    echo \Timber\Timber::compile_string($twig_content, $context);

                                    error_log("✅ Section rendered successfully: {$section_id}");
                                } else {
                                    throw new \Exception("Twig file not found: {$twig_file}");
                                }
                            } else {
                                // Fallback: intentar con ruta relativa (tema)
                                $template_path = 'sections/' . $section_id . '.twig';
                                echo \Timber\Timber::compile($template_path, $context);
                            }
                        } catch (\Exception $e) {
                            echo '<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">';
                            echo '<p style="margin: 0 0 10px; color: #856404;"><strong>⚠️ Error al renderizar la sección:</strong> ' . esc_html($section_id) . '</p>';
                            echo '<p style="margin: 0; font-size: 13px; color: #856404;">' . esc_html($e->getMessage()) . '</p>';
                            echo '<details style="margin-top: 10px;">';
                            echo '<summary style="cursor: pointer; font-size: 12px; color: #666;">Ver datos de la sección</summary>';
                            echo '<pre style="background: #f5f5f5; padding: 10px; overflow: auto; font-size: 11px; margin-top: 10px;">';
                            echo esc_html(json_encode($section, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                            echo '</pre>';
                            echo '</details>';
                            echo '</div>';
                        }

                        echo '</div>';
                    }
                }
                ?>
            </div>

            <?php wp_footer(); ?>
        </body>

        </html>
<?php
    }

    /**
     * Sanitizar completamente el template
     */
    private function sanitize_template_data($template_data)
    {
        if (!is_array($template_data)) {
            return $template_data;
        }

        // Sanitizar secciones
        if (isset($template_data['sections']) && is_array($template_data['sections'])) {
            foreach ($template_data['sections'] as $section_key => &$section) {
                // Sanitizar settings de sección
                if (isset($section['settings'])) {
                    $section['settings'] = $this->sanitize_settings($section['settings'], "sección $section_key");
                } else {
                    $section['settings'] = new \stdClass();
                }

                // Sanitizar bloques
                if (isset($section['blocks']) && is_array($section['blocks'])) {
                    foreach ($section['blocks'] as &$block) {
                        if (isset($block['settings'])) {
                            $block['settings'] = $this->sanitize_settings($block['settings'], "bloque en $section_key");
                        } else {
                            $block['settings'] = new \stdClass();
                        }
                    }
                }
            }
        }

        return $template_data;
    }

    /**
     * Sanitizar settings - prevenir conversión automática a array indexado
     */
    private function sanitize_settings($settings, $context = "")
    {
        if (!is_array($settings)) {
            error_log("  - Settings $context: no es array, retornando vacío");
            return [];
        }

        // Si está vacío, retornar como está
        if (empty($settings)) {
            return [];
        }

        // Verificar si las claves son numéricas secuenciales empezando desde 0
        $keys = array_keys($settings);
        $is_sequential = ($keys === range(0, count($settings) - 1));

        if ($is_sequential) {
            error_log("  - Settings $context: tiene claves numéricas secuenciales, convirtiendo a asociativo con prefijos");

            // Convertir a objeto asociativo con prefijos para evitar conversión automática
            $new_settings = [];
            foreach ($settings as $index => $value) {
                // Usar prefijo para evitar que JSON lo convierta a array
                $new_settings["field_" . $index] = $value;
            }
            return $new_settings;
        }

        // Si ya es asociativo con claves no numéricas, retornar tal cual
        return $settings;
    }

    /**
     * AJAX: Buscar posts (post, page, product, etc)
     */
    public function ajax_search_posts()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 100;

        $args = [
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'title',
            'order' => 'ASC'
        ];

        if (!empty($search)) {
            $args['s'] = $search;
        }

        $query = new \WP_Query($args);
        $posts = [];

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $posts[] = [
                    'id' => get_the_ID(),
                    'title' => get_the_title(),
                    'url' => get_permalink(),
                    'thumbnail' => get_the_post_thumbnail_url(get_the_ID(), 'thumbnail'),
                    'post_type' => get_post_type(),
                    'status' => get_post_status()
                ];
            }
            wp_reset_postdata();
        }

        wp_send_json_success($posts);
    }

    /**
     * AJAX: Buscar términos de taxonomía
     */
    public function ajax_search_terms()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : 'category';
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';

        $args = [
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ];

        if (!empty($search)) {
            $args['search'] = $search;
        }

        $terms = get_terms($args);
        $result = [];

        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $result[] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'count' => $term->count,
                    'taxonomy' => $term->taxonomy
                ];
            }
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Obtener menús registrados
     */
    public function ajax_get_menus()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        $menus = wp_get_nav_menus();
        $result = [];

        foreach ($menus as $menu) {
            $result[] = [
                'id' => $menu->term_id,
                'name' => $menu->name,
                'slug' => $menu->slug,
                'count' => $menu->count
            ];
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Obtener sidebars/widgets registrados
     */
    public function ajax_get_sidebars()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        global $wp_registered_sidebars;

        $result = [];

        foreach ($wp_registered_sidebars as $sidebar) {
            $result[] = [
                'id' => $sidebar['id'],
                'name' => $sidebar['name'],
                'description' => isset($sidebar['description']) ? $sidebar['description'] : ''
            ];
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Obtener datos de attachment por ID
     */
    public function ajax_get_attachment_data()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;

        if (!$attachment_id) {
            wp_send_json_error(['message' => 'ID de attachment no proporcionado']);
            return;
        }

        // Verificar que el attachment existe
        if (!wp_attachment_is_image($attachment_id) && get_post_type($attachment_id) !== 'attachment') {
            wp_send_json_error(['message' => 'Attachment no encontrado']);
            return;
        }

        // Obtener datos del attachment
        $attachment = get_post($attachment_id);

        if (!$attachment) {
            wp_send_json_error(['message' => 'Attachment no encontrado']);
            return;
        }

        // Construir respuesta con formato similar a wp.media
        $data = [
            'id' => $attachment_id,
            'title' => get_the_title($attachment_id),
            'filename' => basename(get_attached_file($attachment_id)),
            'url' => wp_get_attachment_url($attachment_id),
            'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'caption' => $attachment->post_excerpt,
            'description' => $attachment->post_content,
            'mime' => get_post_mime_type($attachment_id),
            'type' => wp_attachment_is_image($attachment_id) ? 'image' : 'file',
            'subtype' => '',
            'filesizeHumanReadable' => size_format(filesize(get_attached_file($attachment_id))),
        ];

        // Detectar subtipo
        $mime_parts = explode('/', $data['mime']);
        if (count($mime_parts) > 1) {
            $data['subtype'] = $mime_parts[1];
        }

        // Si es imagen, agregar tamaños
        if (wp_attachment_is_image($attachment_id)) {
            $data['sizes'] = [];
            $sizes = get_intermediate_image_sizes();

            foreach ($sizes as $size) {
                $image = wp_get_attachment_image_src($attachment_id, $size);
                if ($image) {
                    $data['sizes'][$size] = [
                        'url' => $image[0],
                        'width' => $image[1],
                        'height' => $image[2],
                    ];
                }
            }
        }

        wp_send_json_success($data);
    }

    /**
     * Crear archivos PHP y Twig para la plantilla
     */
    private function create_template_files($template_name, $template_data)
    {
        $files_created = [];
        $theme_root = get_template_directory();

        // 1. Crear archivo PHP en la raíz del tema
        $php_file = $theme_root . '/' . $template_name . '.php';

        if (!file_exists($php_file)) {
            $template_label = $this->convert_template_name_to_label($template_name);
            $description = isset($template_data['description']) ? $template_data['description'] : '';
            $post_type = isset($template_data['post_type']) ? $template_data['post_type'] : ''; // ← NUEVO

            error_log("Creating PHP file with post_type: " . ($post_type ?: 'none'));

            $php_content = $this->generate_php_template($template_name, $template_label, $description, $post_type); // ← PASARLO

            if (file_put_contents($php_file, $php_content)) {
                $files_created[] = basename($php_file);
                error_log("✅ PHP file created: " . $php_file);
            } else {
                error_log("❌ Failed to create PHP file: " . $php_file);
            }
        } else {
            error_log("ℹ️ PHP file already exists, skipping: " . $php_file);
        }

        // 2. Crear archivo Twig en views/templates/
        $twig_dir = $theme_root . '/views/templates';

        if (!is_dir($twig_dir)) {
            wp_mkdir_p($twig_dir);
        }

        $twig_file = $twig_dir . '/' . $template_name . '.twig';

        if (!file_exists($twig_file)) {
            $twig_content = $this->generate_twig_template($template_name);

            if (file_put_contents($twig_file, $twig_content)) {
                $files_created[] = 'views/templates/' . basename($twig_file);
                error_log("✅ Twig file created: " . $twig_file);
            } else {
                error_log("❌ Failed to create Twig file: " . $twig_file);
            }
        } else {
            error_log("ℹ️ Twig file already exists, skipping: " . $twig_file);
        }

        return $files_created;
    }

    /**
     * Convertir nombre de template a label legible
     * Ejemplo: "page-landing-discount" → "Landing Discount"
     */
    private function convert_template_name_to_label($template_name)
    {
        // Remover prefijos comunes
        $name = preg_replace('/^(page|single|archive|taxonomy|category|tag)-/', '', $template_name);

        // Convertir guiones/underscores a espacios
        $name = str_replace(['-', '_'], ' ', $name);

        // Capitalizar cada palabra
        $name = ucwords($name);

        return $name;
    }

    /**
     * Generar contenido del archivo PHP
     */
    private function generate_php_template($template_name, $template_label, $description = '', $post_type = '')
    {
        $description_line = !empty($description) ? "\n * Description: {$description}" : '';

        // Agregar Template Post Type si se especificó
        $post_type_line = '';
        if (!empty($post_type)) {
            $post_type_line = "\n * Template Post Type: {$post_type}";
        }

        return <<<PHP
<?php
/**
 * Template Name: {$template_label}{$post_type_line}{$description_line}
 *
 * @package  JuztTheme
 * @subpackage  Timber
 * @since   Timber 0.1
 */

use \Juztstack\JuztStudio\Community\Templates;
use Timber\Timber;

\$template = new Templates();
\$template_content = \$template->get_json_template('{$template_name}');
\$context = Timber::context();
\$context['order'] = \$template_content['order'];
\$context['sections'] = \$template_content['sections'];

Timber::render('templates/{$template_name}.twig', \$context); 

PHP;
    }

    /**
     * Generar contenido del archivo Twig
     */
    private function generate_twig_template($template_name)
    {
        return <<<TWIG
{% extends "layouts/base.twig" %}

{% block content %}
    {% for item in order %}
        {% include "sections/" ~ sections[item].section_id ~ ".twig" ignore missing with {'section': sections[item] } %}
    {% endfor %}
{% endblock %}

TWIG;
    }

    /**
     * AJAX: Obtener post types disponibles
     */
    public function ajax_get_post_types()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        $post_types = get_post_types(['public' => true], 'objects');
        $result = [];

        foreach ($post_types as $post_type) {
            // Excluir tipos internos
            if (in_array($post_type->name, ['attachment', 'revision', 'nav_menu_item'])) {
                continue;
            }

            $result[] = [
                'name' => $post_type->name,
                'label' => $post_type->label,
                'singular_label' => $post_type->labels->singular_name,
            ];
        }

        wp_send_json_success($result);
    }

    /**
     * Obtener secciones agrupadas por fuente - NUEVO
     */
    public function get_sections_by_source()
    {
        $core = \Juztstack\JuztStudio\Community\Core::get_instance();

        if (!$core || !isset($core->extension_registry)) {
            return [];
        }

        $grouped = [];
        $extensions = $core->extension_registry->get_extensions();

        // Nombres legibles
        $source_names = [
            'theme' => 'Tema Activo',
            'core' => 'Juzt Studio Core',
        ];

        foreach ($extensions as $ext_id => $ext_config) {
            $source_names[$ext_id] = $ext_config['name'] ?? $ext_id;
        }

        $all_sections = $core->extension_registry->get_all_sections();

        foreach ($all_sections as $section_id => $section_data) {
            $source = $section_data['source'];
            $source_name = $source_names[$source] ?? $source;

            if (!isset($grouped[$source])) {
                $grouped[$source] = [
                    'id' => $source,
                    'name' => $source_name,
                    'sections' => [],
                ];
            }

            $grouped[$source]['sections'][] = [
                'id' => $section_id,
                'name' => $section_data['name'] ?? ucfirst(str_replace('-', ' ', $section_id)),
                'category' => $section_data['category'] ?? 'general',
                'icon' => $section_data['icon'] ?? 'dashicons-layout',
                'description' => $section_data['description'] ?? '',
                'preview' => $section_data['preview'] ?? null,
            ];
        }

        return $grouped;
    }

    /**
     * AJAX: Obtener secciones agrupadas - NUEVO
     */
    public function ajax_get_sections_by_source()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $sections = $this->get_sections_by_source();

        wp_send_json_success([
            'sections_by_source' => $sections,
        ]);
    }

    /**
     * AJAX: Obtener todas las secciones (formato plano) - NUEVO
     */
    public function ajax_get_all_sections()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $core = \Juztstack\JuztStudio\Community\Core::get_instance();

        if (!$core || !isset($core->extension_registry)) {
            wp_send_json_success(['sections' => []]);
            return;
        }

        $all_sections = $core->extension_registry->get_all_sections();
        $formatted = [];

        foreach ($all_sections as $section_id => $section_data) {
            $formatted[] = [
                'id' => $section_id,
                'name' => $section_data['name'] ?? ucfirst(str_replace('-', ' ', $section_id)),
                'category' => $section_data['category'] ?? 'general',
                'icon' => $section_data['icon'] ?? 'dashicons-layout',
                'source' => $section_data['source'],
                'source_name' => $section_data['source_name'] ?? $section_data['source'],
                'description' => $section_data['description'] ?? '',
                'preview' => $section_data['preview'] ?? null,
            ];
        }

        wp_send_json_success(['sections' => $formatted]);
    }

    /**
     * AJAX: Obtener todos los schemas (tema + extensiones) - NUEVO
     */
    public function ajax_get_all_section_schemas()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $core = \Juztstack\JuztStudio\Community\Core::get_instance();

        if (!$core || !isset($core->extension_registry)) {
            // Fallback: schemas solo del tema
            $this->ajax_get_section_schemas();
            return;
        }

        $all_sections = $core->extension_registry->get_all_sections();
        $schemas = [];

        foreach ($all_sections as $section_id => $section_data) {
            if (empty($section_data['schema_file'])) {
                continue;
            }

            $schema_file = $section_data['schema_file'];

            if (!file_exists($schema_file)) {
                continue;
            }

            // Parsear el schema
            $schema_parsed = $this->parse_section_schema($section_id, $schema_file);

            if ($schema_parsed) {
                $schemas[$section_id] = [
                    'id' => $section_id,
                    'name' => $schema_parsed['name'],
                    'schema' => $schema_parsed,
                    'file' => $schema_file,
                    'source' => $section_data['source'] ?? 'unknown',
                    'source_name' => $section_data['source_name'] ?? 'Unknown'
                ];
            }
        }

        error_log('=== ALL SCHEMAS FROM REGISTRY ===');
        error_log('Total schemas found: ' . count($schemas));
        error_log('Schema IDs: ' . implode(', ', array_keys($schemas)));

        wp_send_json_success($schemas);
    }

    /**
     * AJAX: Obtener templates agrupados por fuente
     */
    public function ajax_get_templates_by_source()
    {
        check_ajax_referer('sections_builder_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $core = \Juztstack\JuztStudio\Community\Core::get_instance();

        if (!$core || !isset($core->extension_registry)) {
            // Fallback: solo templates del tema
            $this->ajax_get_templates();
            return;
        }

        $grouped = [];
        $extensions = $core->extension_registry->get_extensions();

        // Nombres legibles
        $source_names = [
            'theme' => 'Tema Activo',
            'core' => 'Juzt Studio Core',
        ];

        foreach ($extensions as $ext_id => $ext_config) {
            $source_names[$ext_id] = $ext_config['name'] ?? $ext_id;
        }

        $all_templates = $core->extension_registry->get_all_templates();

        error_log('=== TEMPLATES FROM REGISTRY ===');
        error_log('Total templates: ' . count($all_templates));

        foreach ($all_templates as $template_id => $template_data) {
            $source = $template_data['source'];
            $source_name = $source_names[$source] ?? $source;

            if (!isset($grouped[$source])) {
                $grouped[$source] = [
                    'id' => $source,
                    'name' => $source_name,
                    'templates' => [],
                ];
            }

            // Leer el contenido del template JSON
            $json_file = $template_data['json_file'] ?? null;
            $sections_count = 0;

            if ($json_file && file_exists($json_file)) {
                $content = file_get_contents($json_file);
                $json_data = json_decode($content, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $sections_count = count($json_data['sections'] ?? []);
                }
            }

            $grouped[$source]['templates'][] = [
                'id' => $template_id,
                'name' => $template_data['name'] ?? ucfirst(str_replace('-', ' ', $template_id)),
                'description' => $template_data['description'] ?? '',
                'path' => $json_file ?? '',
                'sections_count' => $sections_count,
                'post_type' => $template_data['post_type'] ?? '',
            ];

            error_log("Template: {$template_id} (source: {$source})");
        }

        error_log('Grouped templates: ' . count($grouped) . ' sources');

        wp_send_json_success([
            'templates_by_source' => $grouped,
        ]);
    }
}

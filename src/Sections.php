<?php

namespace Juztstack\JuztStudio\Community;

use Juztstack\JuztStudio\Community\Core;


/**
 * Clase para gestionar secciones
 */
class Sections
{
    /**
     * Directorio de secciones en el tema
     */
    private $theme_directory = 'sections';

    /**
     * Constructor
     */
    public function __construct()
    {
        // Inicialización
    }

    /**
     * Establecer directorio de secciones en el tema
     */
    public function set_theme_directory($directory)
    {
        $this->theme_directory = $directory;
    }

    /**
     * Encontrar archivo de sección
     */
    public function find_section_file($section_id)
    {
        // Usar Registry para buscar en todas las fuentes
        $core = Core::get_instance();

        if ($core && $core->extension_registry) {
            $section_data = $core->extension_registry->get_section($section_id);

            if ($section_data && !empty($section_data['twig_file'])) {
                $twig_file = $section_data['twig_file'];

                if (file_exists($twig_file)) {
                    return $twig_file;
                }
            }
        }

        // Fallback: método original
        $possible_paths = [
            // Buscar en el tema activo
            get_template_directory() . "/{$this->theme_directory}/{$section_id}.php",
            get_template_directory() . "/{$this->theme_directory}/{$section_id}/{$section_id}.php",

            // Buscar en el plugin
            JUZTSTUDIO_CM_PLUGIN_PATH . "sections/{$section_id}.php",
            JUZTSTUDIO_CM_PLUGIN_PATH . "sections/{$section_id}/{$section_id}.php",
        ];

        $possible_paths = apply_filters('sections_builder_section_paths', $possible_paths, $section_id);

        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return false;
    }

    /**
     * Renderizar una sección
     */
    public static function render_section($section)
    {
        $instance = sections_builder()->sections;

        if (!isset($section['section_id'])) {
            return '';
        }

        $section_id = $section['section_id'];
        $settings = isset($section['settings']) ? $section['settings'] : [];
        $blocks = isset($section['blocks']) ? $section['blocks'] : [];

        // Buscar el archivo de sección
        $section_file = $instance->find_section_file($section_id);

        if (!$section_file) {
            return '<div class="section-not-found">Sección no encontrada: ' . esc_html($section_id) . '</div>';
        }

        ob_start();

        echo '<div class="section section-' . esc_attr($section_id) . '" id="section-' . esc_attr($section['id']) . '">';
        echo '<div class="container">';

        // Incluir el archivo de sección
        include $section_file;

        echo '</div>'; // .container
        echo '</div>'; // .section

        return ob_get_clean();
    }
}

/**
 * Helper para acceder a la instancia de Sections
 */
function sections_builder()
{
    return Core::$instance;
}

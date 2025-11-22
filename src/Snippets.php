<?php
namespace Juztstack\JuztStudio\Community;

/**
 * Clase para gestionar snippets
 */
class Snippets {
    /**
     * Directorio de snippets en el tema
     */
    private $theme_directory = 'snippets';
    
    /**
     * Constructor
     */
    public function __construct() {
        // Inicialización
    }
    
    /**
     * Establecer directorio de snippets en el tema
     */
    public function set_theme_directory($directory) {
        $this->theme_directory = $directory;
    }
    
    /**
     * Encontrar archivo de snippet
     */
    public function find_snippet_file($name) {
        $possible_paths = [
            // Buscar en el tema activo
            get_template_directory() . "/{$this->theme_directory}/{$name}.php",
            
            // Buscar en el plugin
            JUZTSTUDIO_CM_PLUGIN_PATH . "snippets/{$name}.php",
        ];
        
        // Permitir filtrar rutas
        $possible_paths = apply_filters('sections_builder_snippet_paths', $possible_paths, $name);
        
        // Encontrar el primer archivo que exista
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        return false;
    }
    
    /**
     * Renderizar un snippet
     */
    public static function render($name, $data = [], $return = false) {
        $instance = sections_builder()->snippets;
        
        // Buscar el archivo de snippet
        $snippet_file = $instance->find_snippet_file($name);
        
        if (!$snippet_file) {
            $error_message = "Error: El snippet '$name' no existe.";
            if ($return) {
                return $error_message;
            } else {
                echo $error_message;
                return;
            }
        }
        
        // Extraer variables para que estén disponibles en el snippet
        extract($data, EXTR_SKIP);
        
        ob_start();
        include $snippet_file;
        $output = ob_get_clean();
        
        if ($return) {
            return $output;
        } else {
            echo $output;
        }
    }
}
<?php
/**
 * Plugin Name: Juzt Studio (Community version)
 * Plugin URI: https://juztstack.com
 * Description: Customizer editor for general settings and templates json.
 * Version: 1.2.0
 * Author: JuztStack
 * License: MIT
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: juzt-studio-community
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes
define('JUZTSTUDIO_CM_VERSION', '1.2.0');
define('JUZTSTUDIO_CM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('JUZTSTUDIO_CM_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Legacy constant for backward compatibility

// Cargar autoloader de Composer
if (file_exists(JUZTSTUDIO_CM_PLUGIN_PATH . 'vendor/autoload.php')) {
    require_once JUZTSTUDIO_CM_PLUGIN_PATH . 'vendor/autoload.php';
} else {
    // Fallback: cargar clases manualmente si composer no está instalado
    spl_autoload_register(function ($class) {
        $prefix = 'Juztstack\\JuztStudio\\Community\\';
        $base_dir = JUZTSTUDIO_CM_PLUGIN_PATH . 'src/';
        
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        
        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        
        if (file_exists($file)) {
            require $file;
        }
    });
}

use Juztstack\JuztStudio\Community\Core;

/**
 * Función principal para inicializar el plugin
 */
function juzt_studio_community_init() {
    // Verificar si Timber/Twig está disponible para extensiones opcionales
    if (class_exists('Timber\Timber')) {
        // Registrar extensión de Timber si está disponible
        add_filter('timber/twig', function($twig) {
            $twig->addExtension(new \Juztstack\JuztStudio\Community\TimberExtension());
            return $twig;
        });
    }
    
    // Inicializar el core del plugin
    $juzt_studio = new Core();
    $juzt_studio->init();
    
    return $juzt_studio;
}

// Inicializar después de que WordPress cargue
add_action('init', 'juzt_studio_community_init');

/**
 * Helper function para acceder a la instancia del plugin
 * 
 * @return Core|null
 */
function juzt_studio() {
    global $sections_builder_theme;
    return $sections_builder_theme ?? null;
}

/**
 * Hook de activación
 */
register_activation_hook(__FILE__, function() {
    // Verificar requisitos
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('Juzt Studio Community requiere PHP 7.4 o superior.');
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
});

/**
 * Hook de desactivación
 */
register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});
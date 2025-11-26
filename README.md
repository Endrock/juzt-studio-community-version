# Juzt Studio (Community Version)

**Juzt Studio: The Template Customizer for Data-Driven WordPress Themes.**

> Simplify theme development with a structured, data-driven architecture managed directly from WordPress. Accelerate your workflow using a unified editor and native Twig extensions.

Juzt Studio provides a powerful, administrative interface and a robust architecture for building complex, flexible page layouts using JSON data and Twig sections, designed for high-performance themes utilizing [Timber](https://upstatement.com/timber/).

## ✨ Key Features

  * **Visual Template Builder:** Create, edit, and manage entire page templates composed of reusable sections directly from the WordPress admin panel.
  * **Unified Resource Registry:** Automatically discovers and indexes Sections, Templates, and Snippets from your **Theme**, **Juzt Studio Extensions**, and the plugin's Core.
  * **Template Preview Mode:** Enable real-time, cookied previewing of alternative templates on any frontend page for testing new layouts.
  * **Schema-Driven Editor:** Supports configurable sections and blocks by reading your PHP schema definitions (`schemas/*.php`).
  * **Native Twig Extensions:** Extends Timber/Twig with essential filters for simplified WordPress media handling (`|attachment_url`, `|attachment_img`).
  * **General Settings Editor:** Manage global theme variables (colors, logo, API keys) via a dedicated editor linked to a central JSON file.

## ⚡ Requirements

  * **WordPress:** 5.8+
  * **PHP:** 7.4+
  * **Recommended:** [Timber/Timber](https://upstatement.com/timber/) 2.3+ (Required for core Twig/Section functionality)

## 🚀 Installation

1.  Download the latest ZIP file of the plugin.
2.  Go to **WordPress Admin \> Plugins \> Add New \> Upload Plugin**.
3.  Upload the ZIP file and **Activate the Plugin**.

## 📁 Project Structure

```
juzt-studio-community/
├── composer.json               # Composer configuration
├── juzt-studio-community.php   # Main plugin file
├── .gitignore
├── README.md
└── src/                        # Source files (PSR-4 autoloaded)
    ├── Core.php                # Main Singleton Orchestrator
    ├── Builder.php             # Admin Builder UI & AJAX Endpoints
    ├── ExtensionRegistry.php   # Resource discovery and indexing
    ├── ThemeRuntime.php        # Frontend Preview Mode & AJAX Rendering
    ├── Compatibility.php       # Legacy function compatibility
    ├── Sections.php            # Section file management
    ├── Snippets.php            # Snippet file management
    ├── Templates.php           # JSON template file management
    └── TimberExtension.php     # Twig/Timber extensions
```

## 🎯 Usage: Theme Integration

To use Juzt Studio with your theme, ensure your directories are correctly set up and optionally define custom paths.

### 1\. Define Theme Support

In your theme's `functions.php` file, register theme support, optionally customizing resource directories:

```php
// Define directories for JSON templates, PHP sections, and PHP snippets
add_theme_support('sections-builder', [
    'sections_directory' => 'sections', // Default: sections
    'templates_directory' => 'templates', // Default: templates
    'snippets_directory' => 'snippets', // Default: snippets
]);
```

### 2\. Template Structure (Theme Files)

Your theme should rely on the following files for the system to work:

| File Type | Path Example | Purpose |
| :--- | :--- | :--- |
| **JSON Template (Data)** | `your-theme/templates/home-page.json` | Stores the structure (`order` and `sections` settings) edited in the Builder. |
| **Section Schema (Config)** | `your-theme/schemas/hero-section.php` | Defines the configuration fields and blocks for the Builder. |
| **Section Twig (View)** | `your-theme/views/sections/hero-section.twig` | The actual view file where section data is rendered. |

### 3\. Creating a JSON Template

Create your JSON template in your theme's configured `templates` directory. This is the file the Builder will read and write to.

```json
{
    "name": "Home Page",
    "description": "Custom home page template",
    "sections": {
        "hero_id_123": {
            "section_id": "hero-section",
            "settings": {
                "title": "Welcome to Juzt Studio"
            },
            "blocks": []
        }
    },
    "order": ["hero_id_123"]
}
```

### 4\. Rendering Templates (PHP)

In your theme's page template (`page-home.php`), use the global compatibility function:

```php
<?php
/**
 * Template Name: Home Page
 */

// The plugin automatically handles loading the JSON data,
// populating the Timber context, and rendering the associated
// Twig template (e.g., views/templates/home-page.twig).

// If you are using the compatibility layer:
render_json_template();
?>
```

### 5\. Accessing the Builder UI

Navigate to **WordPress Admin \> JuztStudio CM** to visually edit and create templates, sections, and global settings.

## 🔧 Timber/Twig Integration

When Timber is active, the plugin automatically registers a suite of utility filters to simplify media handling, typically accessed in your `views/sections/*.twig` files.

### Media Filters

| Twig Filter | Purpose | Example |
| :--- | :--- | :--- |
| `attachment_url` | Get the URL for a WordPress Attachment ID. | `<img src="{{ section.settings.logo|attachment_url('medium') }}">` |
| `attachment_alt` | Get the Alt text for an Attachment ID. | `alt="{{ section.settings.logo|attachment_alt }}"` |
| `attachment_img` | Generate the full HTML `<img>` tag with srcset, alt, and size attributes. | `{{ section.settings.logo|attachment_img('large', {'class': 'my-logo'}) }}` |
| `attachment` (Function) | Get a comprehensive data object for an Attachment ID. | `{% set data = attachment(id) %}` |

## 📝 Development

### Composer & Autoloading

The plugin uses PSR-4 autoloading for all classes:

  * **Namespace:** `Juztstack\JuztStudio\Community`
  * **Base directory:** `src/`

To manage dependencies and update the autoloader:

```bash
# Install required dependencies (including Timber)
composer install

# Update dependencies
composer update

# Dump autoload (after adding new classes manually to src/)
composer dump-autoload
```

### Global Functions (Compatibility Layer)

The following functions are defined globally for backward compatibility:

  * `render_snippet($name, $data, $return)`: Render a snippet file (`snippets/name.php`).
  * `get_snippet($name, $data)`: Get snippet output as string.
  * `sections_theme_render_section($section)`: Render a section.
  * `render_json_template()`: Render the template selected for the current page.
  * `juzt_studio()`: Get the core instance of the plugin.
  * `sections_builder()`: Alias for `juzt_studio()`.

## 🤝 Contributing

This is the community version of Juzt Studio. Contributions, feedback, and bug reports are highly welcome\!

## 📄 License

Juzt Studio (Community Version) is open-sourced software licensed under the **MIT License**.

## 🔗 Links

  * [Juzt Stack](https://juztstack.com)
  * [Documentation](https://juztstack.com/docs)

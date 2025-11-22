<?php

namespace Juztstack\JuztStudio\Community;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Timber\Timber;

/**
 * Twig Extension para manejar Attachments al estilo Shopify
 * 
 * Uso en Twig:
 * {{ section.settings.logo|attachment_url }}
 * {{ section.settings.logo|attachment_url('thumbnail') }}
 * {{ section.settings.logo|attachment_alt }}
 */
class TimberExtension extends AbstractExtension
{
    /**
     * Registrar filtros
     */
    public function getFilters()
    {
        return [
            new TwigFilter('attachment_url', [$this, 'attachmentUrl']),
            new TwigFilter('attachment_alt', [$this, 'attachmentAlt']),
            new TwigFilter('attachment_title', [$this, 'attachmentTitle']),
            new TwigFilter('attachment_caption', [$this, 'attachmentCaption']),
            new TwigFilter('attachment_srcset', [$this, 'attachmentSrcset']),
            new TwigFilter('attachment_img', [$this, 'attachmentImg'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Registrar funciones
     */
    public function getFunctions()
    {
        return [
            new TwigFunction('attachment', [$this, 'getAttachment']),
            new TwigFunction('get_menu_items', [$this, 'getMenuItems']),
        ];
    }

    public function getMenuItems ( $menu_name ) {
        $menu = Timber::get_menu( $menu_name );
        return $menu ? $menu->items : [];
    }

    /**
     * Obtener URL del attachment
     * 
     * @param mixed $attachment_id ID del attachment o URL (backward compatible)
     * @param string $size Tamaño de imagen (thumbnail, medium, large, full)
     * @return string URL del archivo
     */
    public function attachmentUrl($attachment_id, $size = 'full')
    {
        // Backward compatibility: si viene una URL, retornarla tal cual
        if (is_string($attachment_id) && filter_var($attachment_id, FILTER_VALIDATE_URL)) {
            return $attachment_id;
        }

        // Convertir a int
        $attachment_id = intval($attachment_id);

        if (!$attachment_id) {
            return '';
        }

        // Si es imagen, obtener tamaño específico
        if (wp_attachment_is_image($attachment_id)) {
            $image = wp_get_attachment_image_src($attachment_id, $size);
            return $image ? $image[0] : '';
        }

        // Si es otro tipo de archivo, retornar URL directa
        return wp_get_attachment_url($attachment_id) ?: '';
    }

    /**
     * Obtener texto alternativo
     */
    public function attachmentAlt($attachment_id)
    {
        if (is_string($attachment_id) && filter_var($attachment_id, FILTER_VALIDATE_URL)) {
            return '';
        }

        $attachment_id = intval($attachment_id);
        
        if (!$attachment_id) {
            return '';
        }

        return get_post_meta($attachment_id, '_wp_attachment_image_alt', true) ?: '';
    }

    /**
     * Obtener título del attachment
     */
    public function attachmentTitle($attachment_id)
    {
        if (is_string($attachment_id) && filter_var($attachment_id, FILTER_VALIDATE_URL)) {
            return '';
        }

        $attachment_id = intval($attachment_id);
        
        if (!$attachment_id) {
            return '';
        }

        return get_the_title($attachment_id) ?: '';
    }

    /**
     * Obtener caption/leyenda
     */
    public function attachmentCaption($attachment_id)
    {
        if (is_string($attachment_id) && filter_var($attachment_id, FILTER_VALIDATE_URL)) {
            return '';
        }

        $attachment_id = intval($attachment_id);
        
        if (!$attachment_id) {
            return '';
        }

        $attachment = get_post($attachment_id);
        return $attachment ? $attachment->post_excerpt : '';
    }

    /**
     * Obtener srcset para imágenes responsive
     */
    public function attachmentSrcset($attachment_id)
    {
        if (is_string($attachment_id) && filter_var($attachment_id, FILTER_VALIDATE_URL)) {
            return '';
        }

        $attachment_id = intval($attachment_id);
        
        if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
            return '';
        }

        return wp_get_attachment_image_srcset($attachment_id) ?: '';
    }

    /**
     * Generar tag <img> completo
     * 
     * @param mixed $attachment_id
     * @param string $size
     * @param array $attr Atributos adicionales ['class' => 'mi-clase', 'loading' => 'lazy']
     * @return string HTML del tag img
     */
    public function attachmentImg($attachment_id, $size = 'full', $attr = [])
    {
        if (is_string($attachment_id) && filter_var($attachment_id, FILTER_VALIDATE_URL)) {
            // Backward compatibility: generar img básico desde URL
            $class = isset($attr['class']) ? esc_attr($attr['class']) : '';
            return '<img src="' . esc_url($attachment_id) . '" class="' . $class . '" />';
        }

        $attachment_id = intval($attachment_id);
        
        if (!$attachment_id) {
            return '';
        }

        return wp_get_attachment_image($attachment_id, $size, false, $attr);
    }

    /**
     * Función helper: obtener todos los datos del attachment
     * 
     * Uso: {% set logo = attachment(section.settings.logo) %}
     *      <img src="{{ logo.url }}" alt="{{ logo.alt }}">
     */
    public function getAttachment($attachment_id)
    {
        if (is_string($attachment_id) && filter_var($attachment_id, FILTER_VALIDATE_URL)) {
            return [
                'url' => $attachment_id,
                'alt' => '',
                'title' => '',
            ];
        }

        $attachment_id = intval($attachment_id);
        
        if (!$attachment_id) {
            return null;
        }

        $attachment = get_post($attachment_id);
        
        if (!$attachment) {
            return null;
        }

        $data = [
            'id' => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
            'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'title' => get_the_title($attachment_id),
            'caption' => $attachment->post_excerpt,
            'description' => $attachment->post_content,
            'mime_type' => get_post_mime_type($attachment_id),
            'is_image' => wp_attachment_is_image($attachment_id),
        ];

        // Si es imagen, agregar dimensiones y srcset
        if ($data['is_image']) {
            $metadata = wp_get_attachment_metadata($attachment_id);
            $data['width'] = isset($metadata['width']) ? $metadata['width'] : 0;
            $data['height'] = isset($metadata['height']) ? $metadata['height'] : 0;
            $data['srcset'] = wp_get_attachment_image_srcset($attachment_id);
            
            // Tamaños disponibles
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

        return $data;
    }
}
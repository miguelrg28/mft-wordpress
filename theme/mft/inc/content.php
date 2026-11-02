<?php
/**
 * Contenido que se administra en WordPress y que el tema solo lee:
 *   - Logo global (Ajustes → Logo del sitio) y logo por producto/página.
 *   - Datos de contacto (Ajustes → Contacto MFT).
 *   - Slides del hero por sección (CPT mft_slide).
 * Los CPT, metaboxes y páginas de ajustes los registra el plugin
 * mft-store-api-extension; aquí no se registra nada.
 */

defined('ABSPATH') || exit;

/**
 * Logo del header. En un producto o página con "Logo del header" propio se usa
 * ese; si no, el logo global; si tampoco hay, el del tema.
 */
function mft_logo_url(): string
{
    if (is_singular(['product', 'page']) && function_exists('mft_header_logo_url')) {
        $override = mft_header_logo_url(get_queried_object_id());
        if ($override) {
            return $override;
        }
    }
    $branding = get_option('mft_branding', []);
    $logo_id = is_array($branding) ? (int) ($branding['logo_id'] ?? 0) : 0;
    $global = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';
    return $global ?: get_theme_file_uri('assets/img/logo-full.webp');
}

/** Datos de contacto con los valores por defecto del plugin para lo que no se haya llenado. */
function mft_contact(): array
{
    if (function_exists('mft_contact_get')) {
        return mft_contact_get();
    }
    $saved = get_option('mft_contact', []);
    return wp_parse_args(is_array($saved) ? $saved : [], [
        'whatsapp' => '18299067022',
        'phone_display' => '829-906-7022',
        'phone_office' => '809-770-4568',
        'email' => 'info@masflowteam.net',
        'address' => '',
        'schedule' => '',
        'maps_url' => '',
        'facebook' => '',
        'instagram' => '',
        'tiktok' => '',
        'youtube' => '',
        'twitter' => '',
    ]);
}

function mft_whatsapp_url(string $text = ''): string
{
    $number = preg_replace('/\D+/', '', mft_contact()['whatsapp']);
    return 'https://wa.me/' . $number . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

/**
 * Slides del hero de una sección (home, events, mftsports, tours, tienda,
 * itinerarios, hoteleria, blog, contacto). Si la sección no tiene slides propios
 * se usan los de home, igual que hacía el front en Next.js.
 */
function mft_hero_slides(string $group = 'home'): array
{
    $slides = mft_query_slides($group);
    return ($slides || $group === 'home') ? $slides : mft_query_slides('home');
}

function mft_query_slides(string $group): array
{
    $args = [
        'post_type' => 'mft_slide',
        'post_status' => 'publish',
        'numberposts' => 50,
        'orderby' => 'menu_order',
        'order' => 'ASC',
    ];
    // Los slides creados antes de existir el campo de sección cuentan como "home".
    $args['meta_query'] = $group === 'home'
        ? ['relation' => 'OR', ['key' => 'mft_slide_group', 'value' => 'home'], ['key' => 'mft_slide_group', 'compare' => 'NOT EXISTS']]
        : [['key' => 'mft_slide_group', 'value' => $group]];

    $slides = [];
    foreach (get_posts($args) as $post) {
        $thumb = get_post_thumbnail_id($post->ID);
        $image = $thumb ? wp_get_attachment_image_url($thumb, 'mft_hero') : '';
        if (!$image) {
            continue;
        }
        $slides[] = [
            'image' => $image,
            'title' => get_the_title($post),
            'subtitle' => (string) get_post_meta($post->ID, 'mft_subtitle', true),
            'badge' => (string) get_post_meta($post->ID, 'mft_badge', true),
            'link' => (string) get_post_meta($post->ID, 'mft_link', true),
        ];
    }
    return $slides;
}

<?php
/**
 * Itinerarios (/itinerarios/ y /itinerarios/<categoría>/). Los viajes y sus
 * categorías se editan en el admin (CPT mft_itinerary + taxonomía
 * mft_itinerary_cat, del plugin mft-store-api-extension).
 */

defined('ABSPATH') || exit;

// /itinerarios/<slug>/ lo atiende la página "itinerarios" con la categoría en ?mft_itinerary.
add_action('init', function () {
    add_rewrite_rule('^itinerarios/([^/]+)/?$', 'index.php?pagename=itinerarios&mft_itinerary=$matches[1]', 'top');
});
add_filter('query_vars', function ($vars) {
    $vars[] = 'mft_itinerary';
    return $vars;
});

/** Categorías con sus viajes agrupados por mes, en el orden definido en el admin. */
function mft_itineraries(): array
{
    if (!function_exists('mft_get_itineraries')) {
        return [];
    }
    $data = mft_get_itineraries(new WP_REST_Request('GET', '/mft/v1/itineraries'))->get_data();
    return is_array($data) ? $data : [];
}

function mft_itinerary_category(string $slug): ?array
{
    foreach (mft_itineraries() as $category) {
        if ($category['slug'] === $slug) {
            return $category;
        }
    }
    return null;
}

// Una categoría con link externo no tiene página propia: se va directo al link.
add_action('template_redirect', function () {
    $slug = get_query_var('mft_itinerary');
    if (!$slug) {
        return;
    }
    $category = mft_itinerary_category($slug);
    if (!$category) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        return;
    }
    if ($category['external_url']) {
        wp_redirect($category['external_url']);
        exit;
    }
});

// Título de la pestaña con el nombre de la categoría.
add_filter('document_title_parts', function ($parts) {
    $slug = get_query_var('mft_itinerary');
    $category = $slug ? mft_itinerary_category($slug) : null;
    if ($category) {
        $parts['title'] = $category['name'];
    }
    return $parts;
});

/** Mensaje genérico de WhatsApp para pedir información. */
function mft_itinerary_whatsapp_url(): string
{
    return mft_whatsapp_url('Hola, tengo interes en sus servicios.');
}

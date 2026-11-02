<?php
/**
 * Crea las páginas que usa el tema MFT (si no existen) y ajusta lectura y
 * enlaces permanentes. No toca páginas que ya existan con ese slug.
 *
 * Uso, en la carpeta del WordPress, con el repo copiado al lado:
 *   wp eval-file /ruta/a/mft-wordpress/scripts/setup-pages.php
 */

if (!defined('ABSPATH')) {
    exit;
}

$content_dir = dirname(__DIR__) . '/content/pages';

// slug => [título, archivo de contenido o ''].
$pages = [
    'events' => ['Eventos y Conciertos', ''],
    'tours' => ['Tours', ''],
    'mftsports' => ['MFTSports', ''],
    'tienda' => ['Tienda', ''],
    'itinerarios' => ['Itinerarios', ''],
    'contacto' => ['Contacto', ''],
    'hoteleria' => ['Hotelería', ''],
    'lista-hoteles' => ['Resultados de hoteles', ''],
    'print-center' => ['Print Center', ''],
    'the-silverstone-society' => ['The Silverstone Society', ''],
    'blog' => ['Blog', ''],
    'privacy-policy' => ['Políticas de Privacidad', 'privacy-policy.html'],
    'terms-and-conditions' => ['Términos y Condiciones', 'terms-and-conditions.html'],
    'refund-policy' => ['Política de Reembolso', 'refund-policy.html'],
];

foreach ($pages as $slug => [$title, $file]) {
    $content = $file ? (string) file_get_contents("{$content_dir}/{$file}") : '';
    $existing = get_page_by_path($slug);
    // Una publicada se respeta tal cual. Una en borrador (p. ej. la de privacidad
    // que WordPress crea al instalarse) se publica con el contenido de content/pages.
    if ($existing && $existing->post_status === 'publish') {
        WP_CLI::log("ya existe: /{$slug}/ (#{$existing->ID})");
        continue;
    }
    if ($existing) {
        wp_update_post([
            'ID' => $existing->ID,
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => $content ?: $existing->post_content,
        ]);
        WP_CLI::log("publicada: /{$slug}/ (#{$existing->ID}, estaba en {$existing->post_status})");
        continue;
    }
    $id = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => $slug,
        'post_content' => $content,
    ], true);
    if (is_wp_error($id)) {
        WP_CLI::warning("/{$slug}/: " . $id->get_error_message());
        continue;
    }
    WP_CLI::log("creada: /{$slug}/ (#{$id})");
}

// La página "blog" es la de entradas; la portada sigue siendo la que ya esté
// configurada (el tema usa front-page.php para la portada de todas formas).
$blog = get_page_by_path('blog');
if ($blog) {
    update_option('page_for_posts', $blog->ID);
    if (get_option('show_on_front') !== 'page' || !get_option('page_on_front')) {
        $home = get_page_by_path('inicio') ?: get_post(wp_insert_post([
            'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Inicio', 'post_name' => 'inicio',
        ]));
        update_option('page_on_front', $home->ID);
        update_option('show_on_front', 'page');
    }
}

// Mismas URLs que el front anterior: /blog/<entrada>/ y /event/<producto>/.
update_option('permalink_structure', '/blog/%postname%/');
$wc_permalinks = (array) get_option('woocommerce_permalinks', []);
$wc_permalinks['product_base'] = '/event';
update_option('woocommerce_permalinks', $wc_permalinks);
flush_rewrite_rules();

WP_CLI::success('Páginas y enlaces permanentes listos.');

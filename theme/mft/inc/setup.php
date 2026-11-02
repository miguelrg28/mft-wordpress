<?php
defined('ABSPATH') || exit;

add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('woocommerce');
    add_theme_support('align-wide');
    add_theme_support('responsive-embeds');

    register_nav_menus([
        'primary' => 'Menú principal (header)',
        'footer' => 'Footer: Navegación',
        'legal' => 'Footer: Legal',
    ]);
});

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'mft-fonts',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&family=Montserrat:wght@400;500;700&display=swap',
        [],
        null
    );
    wp_enqueue_style('mft', get_theme_file_uri('assets/css/mft.css'), ['mft-fonts'], mft_asset_version('assets/css/mft.css'));
    wp_enqueue_script('mft', get_theme_file_uri('assets/js/mft.js'), [], mft_asset_version('assets/js/mft.js'), ['strategy' => 'defer']);
});

/** Versión del archivo = fecha de modificación, para que el navegador no use una copia vieja tras desplegar. */
function mft_asset_version(string $path): string
{
    $file = get_theme_file_path($path);
    return file_exists($file) ? (string) filemtime($file) : MFT_THEME_VERSION;
}

// Los estilos genéricos de WooCommerce pelean con el diseño oscuro; el tema trae los suyos.
add_filter('woocommerce_enqueue_styles', '__return_empty_array');

// El contador del carrito del header se actualiza solo cuando WooCommerce agrega algo por AJAX.
add_filter('woocommerce_add_to_cart_fragments', function ($fragments) {
    $fragments['.mft-cart-count'] = mft_cart_count_html();
    return $fragments;
});

function mft_cart_count_html(): string
{
    $count = function_exists('WC') && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
    return '<span class="mft-cart-count"' . ($count ? '' : ' hidden') . '>' . (int) $count . '</span>';
}

/**
 * Enlaces del menú cuando todavía no se ha creado uno en Apariencia → Menús.
 * Son los mismos que tenía el front en Next.js.
 */
function mft_default_menu(string $location): array
{
    $menus = [
        'primary' => [
            '/events/' => 'Eventos',
            '/tours/' => 'Tours',
            '/mftsports/' => 'MFTSports',
            '/tienda/' => 'Tienda',
            '/print-center/' => 'Print Center',
            '/hoteleria/' => 'Hotelería',
            '/blog/' => 'Blog',
            '/contacto/' => 'Contacto',
        ],
        'footer' => [
            '/events/' => 'Eventos',
            '/tours/' => 'Tours',
            '/mftsports/' => 'MFTSports',
            '/print-center/' => 'Print Center',
        ],
        'legal' => [
            '/privacy-policy/' => 'Políticas de Privacidad',
            '/terms-and-conditions/' => 'Términos y Condiciones',
            '/refund-policy/' => 'Política de Reembolso',
        ],
    ];
    return $menus[$location] ?? [];
}

/** Pinta un menú de WordPress, o el de respaldo si esa ubicación aún no tiene uno asignado. */
function mft_menu(string $location, string $class): void
{
    if (has_nav_menu($location)) {
        wp_nav_menu([
            'theme_location' => $location,
            'container' => false,
            'menu_class' => $class,
            'depth' => 1,
            'fallback_cb' => false,
        ]);
        return;
    }
    echo '<ul class="' . esc_attr($class) . '">';
    foreach (mft_default_menu($location) as $path => $label) {
        echo '<li><a href="' . esc_url(home_url($path)) . '">' . esc_html($label) . '</a></li>';
    }
    echo '</ul>';
}

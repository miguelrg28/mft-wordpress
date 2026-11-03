<?php
/**
 * Plugin Name: MFT Dominios
 * Description: El sitio público vive en www.masflowteam.net (WP_HOME) y el panel de WordPress en admin.masflowteam.net (WP_SITEURL). Cada dominio solo sirve lo suyo.
 *
 * Requiere en wp-config.php:
 *   define('WP_HOME', 'https://www.masflowteam.net');
 *   define('WP_SITEURL', 'https://admin.masflowteam.net');
 *   define('COOKIE_DOMAIN', '.masflowteam.net');   // la sesión sirve en los dos dominios
 *
 * Lo que hace:
 *   - En el dominio público, /wp-admin y /wp-login.php se mandan al panel.
 *   - En el dominio del panel, las páginas del sitio se mandan al dominio público.
 *     Siguen respondiendo ahí la API (/wp-json, que usa el retorno de AZUL), el
 *     AJAX, el cron y los archivos.
 *   - En las páginas públicas, admin-ajax.php, admin-post.php y /wp-includes/ salen
 *     del dominio público, para que formularios y AJAX (pronósticos, simuladores,
 *     Print Center) no crucen de dominio.
 */

defined('ABSPATH') || exit;

/** Host con puerto (si lo hay) y en minúsculas, p. ej. "admin.masflowteam.net". */
function mft_domains_host(string $url): string
{
    $parts = wp_parse_url($url);
    return strtolower(($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''));
}

function mft_domains_ready(): bool
{
    // Solo si de verdad están separados (en local o en staging puede ser el mismo dominio).
    return mft_domains_host(home_url()) !== mft_domains_host(site_url());
}

function mft_domains_is_panel_request(): bool
{
    return strtolower($_SERVER['HTTP_HOST'] ?? '') === mft_domains_host(site_url());
}

// En el panel solo el panel: cualquier página del sitio se va al dominio público.
add_action('template_redirect', function () {
    if (!mft_domains_ready() || !mft_domains_is_panel_request()) {
        return;
    }
    if ((defined('REST_REQUEST') && REST_REQUEST) || wp_doing_ajax() || wp_doing_cron() || is_robots() || is_feed()) {
        return;
    }
    wp_redirect(home_url($_SERVER['REQUEST_URI'] ?? '/'), 301);
    exit;
}, 0);

// En el sitio público, el login y el panel se van al dominio del panel.
add_action('init', function () {
    if (!mft_domains_ready() || mft_domains_is_panel_request() || wp_doing_ajax() || wp_doing_cron()) {
        return;
    }
    $path = (string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $script = basename($path);
    if ($script === 'admin-ajax.php' || $script === 'admin-post.php') {
        return;
    }
    if (str_starts_with($path, '/wp-admin') || $script === 'wp-login.php') {
        wp_redirect(site_url($_SERVER['REQUEST_URI'] ?? '/'), 301);
        exit;
    }
}, 0);

// El login del panel es el nativo de WordPress. La Plataforma MFT (plugin
// masflowteam-platform) manda wp-login.php a su página /ingresar/ del sitio
// público; en el dominio del panel se desactiva ese desvío para poder entrar
// aunque el sitio público esté en mantenimiento. En el dominio público no se toca.
add_action('login_init', function () {
    if (mft_domains_ready() && mft_domains_is_panel_request() && class_exists('MFTP_Experience')) {
        remove_action('login_init', ['MFTP_Experience', 'login_gateway']);
    }
}, 0);

// En el panel, la API (/wp-json) se llama por el dominio del panel. WordPress la
// arma con la dirección pública; desde admin.… eso sería una petición a otro
// dominio, que el mantenimiento de www bloquea (Site Kit se quedaba en
// "comprobando compatibilidad", y lo mismo le pasaría al editor y a WooCommerce).
add_filter('rest_url', function ($url) {
    return (mft_domains_ready() && mft_domains_is_panel_request()) ? str_replace(home_url(), site_url(), $url) : $url;
});

// Desde las páginas públicas, AJAX, formularios y archivos de WordPress salen del
// mismo dominio público (sin CORS ni cookies entre dominios).
add_filter('admin_url', function ($url, $path) {
    if (!mft_domains_ready() || is_admin() || !in_array(ltrim((string) $path, '/'), ['admin-ajax.php', 'admin-post.php'], true)) {
        return $url;
    }
    return str_replace(site_url(), home_url(), $url);
}, 10, 2);
// Los CSS, JS y fuentes del tema y de los plugins también: una fuente servida
// desde otro dominio necesita cabeceras CORS y sin ellas el navegador no la carga.
foreach (['includes_url', 'content_url', 'plugins_url', 'script_loader_src', 'style_loader_src'] as $mft_filter) {
    add_filter($mft_filter, function ($url) {
        return (mft_domains_ready() && !is_admin()) ? str_replace(site_url(), home_url(), $url) : $url;
    });
}
unset($mft_filter);

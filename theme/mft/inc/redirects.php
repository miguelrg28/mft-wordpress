<?php
/**
 * Redirecciones de las URLs del front anterior (Next.js) que en WordPress no
 * existen con el mismo nombre. Solo actúan cuando la URL daría 404, así que una
 * página real con ese slug siempre gana.
 */

defined('ABSPATH') || exit;

add_action('template_redirect', function () {
    if (!is_404()) {
        return;
    }
    $path = trim((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    $account = wc_get_page_permalink('myaccount');

    $exact = [
        'login' => $account,
        'registro' => $account,
        'mi-cuenta' => $account,
        'checkout/asistentes' => wc_get_checkout_url(),
        // Páginas de resultado de AZUL y de transferencia del front anterior: sin la
        // orden en la URL no se puede mostrar el recibo; los pedidos están en Mi cuenta.
        'pago-aprobado' => wc_get_account_endpoint_url('orders'),
        'pago-declinado' => wc_get_account_endpoint_url('orders'),
        'pago-cancelado' => wc_get_account_endpoint_url('orders'),
        'payment-success' => wc_get_account_endpoint_url('orders'),
        'plugin-embed' => home_url('/'),
    ];
    $target = $exact[$path] ?? null;

    // /p/<slug> era una página de WordPress servida por el front: ahora vive en /<slug>/.
    if (!$target && preg_match('#^p/([^/]+)$#', $path, $m)) {
        $page = get_page_by_path(sanitize_title($m[1]));
        $target = $page ? get_permalink($page) : null;
    }

    if ($target) {
        wp_safe_redirect($target, 301);
        exit;
    }
});

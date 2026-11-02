<?php
/**
 * /lista-hoteles/: puente hacia los resultados de Viajes AGT.
 *
 * AGT llega aquí con un query en su propio formato, `?s/<token>&p/<producto>`, y
 * los resultados viven en https://reservas.viajesagt.com/.../list-<producto>/<token>.
 * Se redirige la pestaña completa (no un iframe): AGT guarda la búsqueda en una
 * cookie de sesión que el navegador no envía dentro de un iframe de otro dominio.
 * Requiere una página con el slug "lista-hoteles".
 */

$results_base = apply_filters('mft_agt_results_url', 'https://reservas.viajesagt.com/es/microsite/masflowteam/list-');

// Mismo parseo que el snippet de AGT: pares separados por "&", clave/valor por "/".
$params = [];
$query = str_ireplace('%2F', '/', (string) ($_SERVER['QUERY_STRING'] ?? ''));
foreach (explode('&', $query) as $pair) {
    [$key, $value] = array_pad(explode('/', $pair, 2), 2, '');
    if ($key !== '') {
        $params[rawurldecode($key)] = rawurldecode(str_replace('+', ' ', $value));
    }
}
$token = $params['s'] ?? '';
$product = $params['p'] ?? '';

if ($token !== '' && $product !== '' && !preg_match('#[/?\#]#', $token . $product)) {
    wp_redirect($results_base . rawurlencode($product) . '/' . rawurlencode($token), 302, 'MFT');
    exit;
}

get_header();
?>
<div class="mft-container mft-page">
  <div class="mft-agt-head">
    <h1 class="mft-page__title">Resultados de hoteles</h1>
    <a class="mft-back" href="<?php echo esc_url(home_url('/hoteleria/')); ?>"><?php echo mft_icon('chevron-left'); ?> Nueva búsqueda</a>
  </div>
  <div class="mft-agt mft-agt--message">
    <p>No recibimos una búsqueda válida. Vuelve al buscador e intenta de nuevo.</p>
    <a class="mft-link-accent" href="<?php echo esc_url(home_url('/hoteleria/')); ?>"><?php echo mft_icon('chevron-left'); ?> Ir al buscador</a>
  </div>
</div>
<?php
get_footer();

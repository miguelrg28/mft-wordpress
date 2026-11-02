<?php
/**
 * Ficha de producto: complementarios, recomendados, mapa del lugar y el
 * "Agregar al carrito" de varios boletos a la vez (?wc-ajax=mft_add_to_cart).
 */

defined('ABSPATH') || exit;

/** Títulos y enlaces "Ver Todos" de los recomendados, por línea de negocio. */
function mft_line_info(string $line): array
{
    $lines = [
        'event' => ['Otros Eventos que te Pueden Interesar', '/events/'],
        'tour' => ['Otros Tours Recomendados', '/tours/'],
        'shop' => ['Otros Productos que te Pueden Interesar', '/tienda/'],
        'cruise' => ['Otros Cruceros que te Pueden Interesar', '/itinerarios/cruceros/'],
        'colombia' => ['Otras Salidas a Colombia', '/itinerarios/colombia/'],
        'international' => ['Otros Viajes Internacionales', '/itinerarios/viajes-internacionales/'],
        'sport' => ['Otros Eventos Deportivos que te Pueden Interesar', '/mftsports/'],
    ];
    [$heading, $path] = $lines[$line] ?? ['También te Puede Interesar', '/'];
    return ['heading' => $heading, 'url' => home_url($path)];
}

/**
 * Recomendados: hasta 6 disponibles de la misma línea. Si no hay ninguno y el
 * producto es una experiencia (no tienda), otras experiencias disponibles.
 * Devuelve ['items' => [...], 'related' => bool].
 */
function mft_recommendations(array $item): array
{
    $others = array_filter(mft_catalog(), fn($p) => $p['id'] !== $item['id'] && $p['available']);
    $related = array_slice(array_values(array_filter($others, fn($p) => $p['line'] === $item['line'])), 0, 6);
    if ($related) {
        return ['items' => $related, 'related' => true];
    }
    $experiences = ['event', 'tour', 'cruise', 'colombia', 'international', 'sport'];
    if (!in_array($item['line'], $experiences, true)) {
        return ['items' => [], 'related' => false];
    }
    $suggested = array_filter($others, fn($p) => in_array($p['line'], $experiences, true));
    return ['items' => array_slice(array_values($suggested), 0, 6), 'related' => false];
}

/** Complementarios (categoría "complementario") que se pueden comprar ahora. */
function mft_addons(): array
{
    $products = wc_get_products([
        'status' => 'publish',
        'limit' => -1,
        'category' => [MFT_ADDON_CATEGORY],
        'stock_status' => 'instock',
    ]);
    $addons = [];
    foreach ($products as $product) {
        if (!$product->is_purchasable() || $product->is_type('variable')) {
            continue;
        }
        $price = (float) $product->get_price();
        $regular = (float) $product->get_regular_price();
        $addons[] = [
            'id' => $product->get_id(),
            'name' => wp_strip_all_tags($product->get_name()),
            'description' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
            'price' => $price,
            'regular_price' => $product->is_on_sale() && $regular > $price ? $regular : null,
            'image' => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') ?: '',
        ];
    }
    return $addons;
}

/** Selector de cantidad (− n +). $large para el de productos sin tipos de boleto. */
function mft_stepper(string $name, int $value, int $min, int $max, bool $large = false): void
{
    ?>
    <div class="mft-stepper<?php echo $large ? ' mft-stepper--lg' : ''; ?>" data-mft-stepper>
      <button class="mft-stepper__btn" type="button" data-step="-1" aria-label="Quitar uno"><?php echo mft_icon('minus'); ?></button>
      <input class="mft-stepper__input" type="number" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>"
             min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>" inputmode="numeric" aria-label="Cantidad">
      <button class="mft-stepper__btn" type="button" data-step="1" aria-label="Agregar uno"><?php echo mft_icon('plus'); ?></button>
    </div>
    <?php
}

/** Lugar del evento según FooEvents (para el mapa). */
function mft_product_venue(int $product_id): array
{
    $venue = [
        'location' => (string) get_post_meta($product_id, 'WooCommerceEventsLocation', true),
        'gps' => (string) get_post_meta($product_id, 'WooCommerceEventsGPS', true),
        'google_maps' => (string) get_post_meta($product_id, 'WooCommerceEventsGoogleMaps', true),
        'directions' => (string) get_post_meta($product_id, 'WooCommerceEventsDirections', true),
    ];
    $venue['map_src'] = mft_map_embed_src($venue);
    return $venue;
}

/**
 * URL de Google Maps embebido (sin API key): coordenadas GPS, luego las de Google y
 * por último el nombre del lugar. Una URL de maps se usa tal cual con output=embed.
 */
function mft_map_embed_src(array $venue): string
{
    $raw = trim($venue['gps'] ?: $venue['google_maps'] ?: $venue['location']);
    if ($raw === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $raw)) {
        if (preg_match('#google\.[^/]+/maps#i', $raw) && !preg_match('/output=embed/i', $raw)) {
            return $raw . (str_contains($raw, '?') ? '&' : '?') . 'output=embed';
        }
        return $raw;
    }
    return 'https://www.google.com/maps?q=' . rawurlencode($raw) . '&output=embed';
}

/**
 * Agrega al carrito los boletos y complementarios elegidos en la ficha.
 *
 * Recibe (POST): product_id, lines[<id>]=cantidad (id = variación o el producto
 * mismo si es simple), addons[]=id de complementario y el nonce `mft_cart`.
 * Pasa por el mismo filtro de validación que el botón nativo de WooCommerce, así
 * que los plugins (FooEvents, gateways por producto…) siguen aplicando sus reglas.
 * Si algo falla se deshace lo agregado en esta petición.
 */
function mft_ajax_add_to_cart(): void
{
    if (!check_ajax_referer('mft_cart', 'nonce', false)) {
        wp_send_json_error(['message' => 'La página expiró. Recárgala e intenta de nuevo.'], 403);
    }
    $product = wc_get_product(absint($_POST['product_id'] ?? 0));
    if (!$product || !$product->is_purchasable()) {
        wp_send_json_error(['message' => 'Este producto no está disponible.'], 400);
    }

    $data = mft_product_data($product, true);
    $requests = [];
    foreach ((array) ($_POST['lines'] ?? []) as $line_id => $qty) {
        $line_id = absint($line_id);
        $qty = absint($qty);
        if (!$qty) {
            continue;
        }
        if ($data['tickets']) {
            $ticket = current(array_filter($data['tickets'], fn($t) => $t['id'] === $line_id));
            if (!$ticket) {
                continue;
            }
            if (!$ticket['available']) {
                wp_send_json_error(['message' => sprintf('"%s" no está disponible.', $ticket['label'])], 400);
            }
            $requests[] = [$product->get_id(), min($qty, $ticket['max']), $ticket['id'], $ticket['attributes']];
        } elseif ($line_id === $product->get_id()) {
            $variation = $data['default_variation'];
            $requests[] = [$product->get_id(), min($qty, $data['max']), $variation['id'] ?? 0, $variation['attributes'] ?? []];
        }
    }
    if (!$requests) {
        wp_send_json_error(['message' => $data['tickets'] ? 'Selecciona al menos un boleto.' : 'Selecciona la cantidad.'], 400);
    }

    $addon_ids = array_column(mft_addons(), 'id');
    foreach ((array) ($_POST['addons'] ?? []) as $addon_id) {
        if (in_array(absint($addon_id), $addon_ids, true)) {
            $requests[] = [absint($addon_id), 1, 0, []];
        }
    }

    $added = [];
    foreach ($requests as [$product_id, $qty, $variation_id, $attributes]) {
        $valid = apply_filters('woocommerce_add_to_cart_validation', true, $product_id, $qty, $variation_id, $attributes);
        $key = $valid ? WC()->cart->add_to_cart($product_id, $qty, $variation_id, $attributes) : false;
        if (!$key) {
            foreach ($added as $added_key) {
                WC()->cart->remove_cart_item($added_key);
            }
            $notices = wc_get_notices('error');
            wc_clear_notices();
            $message = $notices ? wp_strip_all_tags($notices[0]['notice'] ?? '') : '';
            wp_send_json_error(['message' => $message ?: 'No se pudo agregar al carrito.'], 400);
        }
        $added[] = $key;
    }
    wc_clear_notices();

    wp_send_json_success([
        'redirect' => wc_get_checkout_url(),
        'count' => WC()->cart->get_cart_contents_count(),
    ]);
}
add_action('wc_ajax_mft_add_to_cart', 'mft_ajax_add_to_cart');

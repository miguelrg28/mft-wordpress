<?php
/**
 * Checkout, carrito y "gracias por tu compra".
 *
 * El checkout es el clásico de WooCommerce ([woocommerce_checkout]), no el de
 * bloques: es el que soportan AZUL, FooEvents (asistentes), los fees por método
 * de pago y los métodos de pago por producto. Aquí solo se ajusta la
 * presentación; la lógica de pago la ponen esos plugins.
 */

defined('ABSPATH') || exit;

/**
 * En el resumen del checkout cada artículo lleva su enlace "Quitar" (el front
 * anterior no tenía página de carrito: todo se hacía en el checkout).
 */
add_filter('woocommerce_cart_item_name', function ($name, $cart_item, $cart_item_key) {
    if (!is_checkout() || is_wc_endpoint_url()) {
        return $name;
    }
    $url = wc_get_cart_remove_url($cart_item_key);
    return $name . ' <a class="mft-remove" href="' . esc_url($url) . '" data-mft-remove aria-label="Quitar ' . esc_attr(wp_strip_all_tags($name)) . '">Quitar</a>';
}, 10, 3);

// Al quitar un artículo desde el checkout se vuelve al checkout (no al carrito).
add_filter('woocommerce_get_remove_url', function ($url) {
    return is_checkout() ? add_query_arg('mft_back', 'checkout', $url) : $url;
});
add_action('woocommerce_cart_item_removed', function () {
    if (($_GET['mft_back'] ?? '') === 'checkout' && WC()->cart && !WC()->cart->is_empty()) {
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }
});

// El texto del botón de pago, como en el front anterior.
add_filter('woocommerce_order_button_text', fn() => 'Pagar ahora');

/**
 * Comprobante de transferencia en la página de "gracias" de los pedidos por
 * transferencia bancaria. Sube el archivo al endpoint del plugin
 * (/mft/v1/payment-proof), que lo adjunta a la orden y avisa al equipo.
 */
add_action('woocommerce_thankyou_bacs', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !function_exists('mft_payment_proof')) {
        return;
    }
    $uploaded = (bool) $order->get_meta('_mft_payment_proof_url');
    $hours = function_exists('mft_bacs_expiry_hours') ? mft_bacs_expiry_hours() : 48;
    wp_enqueue_script('mft-proof', get_theme_file_uri('assets/js/proof.js'), [], mft_asset_version('assets/js/proof.js'), ['strategy' => 'defer', 'in_footer' => true]);
    ?>
    <section class="mft-card mft-proof" data-mft-proof data-endpoint="<?php echo esc_url(rest_url('mft/v1/payment-proof')); ?>">
      <h2>Sube tu comprobante de pago</h2>
      <p>Cuando hagas la transferencia, sube aquí la foto o el PDF del comprobante para confirmar tu orden más rápido.
         Si no recibimos el pago en <?php echo esc_html($hours); ?> horas, la orden se cancela automáticamente.</p>
      <p class="mft-notice mft-notice--success" data-mft-proof-done<?php echo $uploaded ? '' : ' hidden'; ?>>¡Recibimos tu comprobante! Te avisaremos cuando confirmemos el pago.</p>
      <?php if (!$uploaded) : ?>
        <form class="mft-form" data-mft-proof-form>
          <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>">
          <input type="hidden" name="order_key" value="<?php echo esc_attr($order->get_order_key()); ?>">
          <label>Comprobante (imagen o PDF, máx. 8 MB)
            <input type="file" name="file" accept="image/jpeg,image/png,image/webp,application/pdf" required>
          </label>
          <p class="mft-form-error" data-mft-proof-error role="alert" hidden></p>
          <button class="mft-btn mft-btn--primary mft-btn--lg" type="submit">Enviar comprobante</button>
        </form>
      <?php endif; ?>
    </section>
    <?php
}, 20);

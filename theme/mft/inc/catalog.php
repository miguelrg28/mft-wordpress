<?php
/**
 * Catálogo: a qué línea de negocio pertenece cada producto (según sus
 * categorías), sus tipos de boleto y el precio que se muestra en las tarjetas.
 * Es la misma lógica que tenía el front en Next.js (src/site/services/woocommerce.ts).
 */

defined('ABSPATH') || exit;

/**
 * Slugs de categoría de WooCommerce por línea de negocio, en orden de prioridad:
 * un producto de "mftsports" puede estar también en "conciertos", pero se lista
 * en MFTSports. Se pueden cambiar con el filtro `mft_catalog_lines`.
 */
function mft_catalog_lines(): array
{
    return apply_filters('mft_catalog_lines', [
        'sport' => ['mftsports'],
        'cruise' => ['cruceros', 'crucero'],
        'colombia' => ['salidas-colombia', 'colombia'],
        'international' => ['viajes-internacionales', 'internacional', 'internacionales'],
        'tour' => ['excursiones', 'ecoturismo', 'pasadias', 'off-road', 'tours', 'tour'],
        'event' => ['conciertos', 'festival', 'conferencia', 'competencia', 'eventos', 'events'],
        'shop' => [
            'tienda', 'termos-tienda', 'men', 'women', 'shirts', 'shoes', 'sport', 'gorras', 'vasos',
            'lanyard', 'jackets', 'jeans', 'pants', 'suit', 'tops', 'dresses', 'mesa-de-domino',
            'articulos-navidenos',
        ],
    ]);
}

/** Categorías que nunca se muestran (eventos viejos). */
const MFT_EXCLUDED_CATEGORIES = ['eventos-pasados'];

/** Categoría de los complementarios que se ofrecen en la ficha (parqueo, cargador, VIP…). */
const MFT_ADDON_CATEGORY = 'complementario';

function mft_product_category_slugs(WC_Product $product): array
{
    $terms = get_the_terms($product->get_id(), 'product_cat');
    return is_array($terms) ? array_map(fn($t) => strtolower($t->slug), $terms) : [];
}

/** Línea de negocio del producto, o null si no se muestra en el catálogo. */
function mft_product_line(WC_Product $product): ?string
{
    $slugs = mft_product_category_slugs($product);
    if (array_intersect($slugs, MFT_EXCLUDED_CATEGORIES)) {
        return null;
    }
    foreach (mft_catalog_lines() as $line => $line_slugs) {
        if (array_intersect($slugs, $line_slugs)) {
            return $line;
        }
    }
    return null;
}

/** Campo del producto: ACF si existe, si no meta plano (como hacía el plugin). */
function mft_product_field(int $product_id, string $key): string
{
    if (function_exists('get_field')) {
        $value = get_field($key, $product_id);
        if ($value !== null && $value !== false && $value !== '') {
            return is_scalar($value) ? (string) $value : '';
        }
    }
    return (string) get_post_meta($product_id, $key, true);
}

function mft_product_featured(WC_Product $product): bool
{
    if ($product->is_featured() || mft_product_field($product->get_id(), 'featured')) {
        return true;
    }
    foreach (mft_product_category_slugs($product) as $slug) {
        if (str_starts_with($slug, 'destacado')) {
            return true;
        }
    }
    return false;
}

/** ¿El nombre del término trae el precio entre corchetes? Ej. "VIP [ RD$5,500 ]". */
function mft_term_has_price(string $name): bool
{
    return (bool) preg_match('/\[[^\]]*\d[^\]]*\]/', $name);
}

/** Separa "VIP [ RD$5,500 ]" en etiqueta, precio y disponibilidad ("[No Disponible]" = agotado). */
function mft_parse_section_term(string $name): array
{
    preg_match('/\[([^\]]*)\]/', $name, $m);
    $bracket = $m[1] ?? '';
    $label = trim(preg_replace('/\s*\[[^\]]*\]\s*\.?\s*$/', '', $name)) ?: $name;
    $price = preg_match('/(\d[\d.,]*)/', $bracket, $pm) ? (float) preg_replace('/[.,]/', '', $pm[1]) : null;
    $available = $price !== null && !preg_match('/no\s*disponible|sold\s*out|agotado/i', $bracket);
    return ['label' => $label, 'price' => $price ?? 0.0, 'available' => $available];
}

/** Quita sufijos técnicos del nombre de una variación ("Solo Tour:" → "Solo Tour"). */
function mft_clean_variation_label(string $value): string
{
    return trim(rtrim(trim($value), ':.')) ?: $value;
}

/**
 * Atributos de variación del producto con sus opciones legibles.
 * Devuelve [clave => ['label' => nombre, 'options' => [valor => nombre visible]]],
 * donde clave es la que usa la variación (`pa_secciones` o `secciones`) y valor
 * es lo que se envía al carrito (slug del término o texto de la opción).
 */
function mft_variation_attributes(WC_Product_Variable $product): array
{
    $out = [];
    foreach ($product->get_attributes() as $attribute) {
        if (!$attribute->get_variation()) {
            continue;
        }
        $key = sanitize_title($attribute->get_name());
        $options = [];
        if ($attribute->is_taxonomy()) {
            foreach ($attribute->get_terms() ?: [] as $term) {
                $options[$term->slug] = $term->name;
            }
        } else {
            foreach ($attribute->get_options() as $option) {
                $options[$option] = $option;
            }
        }
        $out[$key] = ['label' => wc_attribute_label($attribute->get_name(), $product), 'options' => $options];
    }
    return $out;
}

/**
 * Tipos de boleto de un producto variable.
 *
 * El precio va embebido en el nombre del término de sección ("Solo Tour [ RD$1,900 ]"),
 * pero el precio real de la variación manda; el corchete queda como etiqueta y
 * respaldo, y "[No Disponible]" marca agotado aunque haya stock. Con un atributo
 * de "Función" (conciertos con varios shows) se combina función × sección.
 *
 * Devuelve ['tickets' => [...], 'default_variation' => ?array]. default_variation
 * se llena cuando el producto es variable pero no necesita selector (una sola
 * opción sin precio en el nombre): esa variación se agrega directo al carrito.
 * Cada boleto: id (variation_id), label, price, regular_price, available,
 * funcion (o null) y attributes (['attribute_pa_x' => valor]) para el carrito.
 */
function mft_ticket_types(WC_Product $product): array
{
    $empty = ['tickets' => [], 'default_variation' => null, 'label' => ''];
    if (!$product instanceof WC_Product_Variable) {
        return $empty;
    }
    $attrs = mft_variation_attributes($product);
    if (!$attrs) {
        return $empty;
    }

    $func_key = null;
    foreach ($attrs as $key => $attr) {
        if (preg_match('/func/i', $attr['label'])) {
            $func_key = $key;
            break;
        }
    }
    $ticket_key = null;
    foreach ($attrs as $key => $attr) {
        if ($key !== $func_key && array_filter(array_values($attr['options']), 'mft_term_has_price')) {
            $ticket_key = $key;
            break;
        }
    }
    if (!$ticket_key) {
        $others = array_diff(array_keys($attrs), [$func_key]);
        $ticket_key = $others ? reset($others) : array_key_first($attrs);
    }
    $ticket_options = $attrs[$ticket_key]['options'];
    if (!$ticket_options) {
        return $empty;
    }
    $bracket_pricing = (bool) array_filter(array_values($ticket_options), 'mft_term_has_price');

    // Variaciones por combinación de valores ("funcion||seccion" o solo "seccion").
    $variations = [];
    foreach ($product->get_children() as $child_id) {
        $variation = wc_get_product($child_id);
        if (!$variation || $variation->get_status() !== 'publish') {
            continue;
        }
        $values = $variation->get_attributes();
        $ticket_value = $values[$ticket_key] ?? '';
        if ($ticket_value === '') {
            continue;
        }
        $combo = $func_key ? (($values[$func_key] ?? '') . '||' . $ticket_value) : $ticket_value;
        $variations[$combo] = $variation;
    }

    // Una sola opción sin precio en el nombre ni función: no hay nada que elegir.
    if (!$bracket_pricing && !$func_key && count($ticket_options) === 1) {
        $value = array_key_first($ticket_options);
        $variation = $variations[$value] ?? null;
        return [
            'tickets' => [],
            'default_variation' => $variation
                ? ['id' => $variation->get_id(), 'attributes' => ['attribute_' . $ticket_key => $value]]
                : null,
            'label' => '',
        ];
    }

    $functions = $func_key ? $attrs[$func_key]['options'] : [null => null];
    $tickets = [];
    foreach ($functions as $func_value => $func_label) {
        foreach ($ticket_options as $value => $name) {
            $combo = $func_key ? "{$func_value}||{$value}" : $value;
            $variation = $variations[$combo] ?? null;
            $parsed = $bracket_pricing ? mft_parse_section_term($name) : null;

            $price = $variation && $variation->get_price() !== ''
                ? (float) $variation->get_price()
                : ($parsed ? $parsed['price'] : (float) $product->get_price());
            $regular = $variation && $variation->is_on_sale() ? (float) $variation->get_regular_price() : null;
            $label_ok = $parsed ? $parsed['available'] : true;
            $available = $variation && $variation->is_purchasable() && $variation->is_in_stock() && $label_ok;

            $attributes = ['attribute_' . $ticket_key => $value];
            if ($func_key) {
                $attributes = ['attribute_' . $func_key => $func_value] + $attributes;
            }
            $tickets[] = [
                'id' => $variation ? $variation->get_id() : 0,
                'label' => $parsed ? $parsed['label'] : mft_clean_variation_label($name),
                'price' => $price,
                'regular_price' => $regular && $regular > $price ? $regular : null,
                'available' => $available,
                'funcion' => $func_key ? $func_label : null,
                'attributes' => $attributes,
                'max' => $variation ? mft_max_quantity($variation) : 0,
            ];
        }
    }
    return ['tickets' => $tickets, 'default_variation' => null, 'label' => mft_clean_variation_label($attrs[$ticket_key]['label'])];
}

/** Máximo que se puede pedir de un producto o variación (stock gestionado, o 99). */
function mft_max_quantity(WC_Product $product): int
{
    $max = $product->get_max_purchase_quantity();
    return $max > 0 ? (int) $max : 99;
}

/**
 * Datos de un producto para tarjetas y ficha. Null si no pertenece al catálogo,
 * salvo con $any_line (la ficha de un producto suelto, p. ej. un complementario,
 * se muestra igual con la línea "other").
 */
function mft_product_data(WC_Product $product, bool $any_line = false): ?array
{
    $line = mft_product_line($product) ?? ($any_line ? 'other' : null);
    if (!$line) {
        return null;
    }
    $id = $product->get_id();
    $tickets = mft_ticket_types($product);

    // Precio "Desde": el boleto disponible más barato; si no hay boletos, el del producto.
    $price = (float) $product->get_price();
    $price_from = $product instanceof WC_Product_Variable
        && (float) $product->get_variation_price('max') > (float) $product->get_variation_price('min');
    if ($product instanceof WC_Product_Variable) {
        $price = (float) $product->get_variation_price('min');
    }
    $prices = array_column(array_filter($tickets['tickets'], fn($t) => $t['available']), 'price');
    if ($prices) {
        $price = min($prices);
        $price_from = max($prices) > $price;
    }

    $venue_location = (string) get_post_meta($id, 'WooCommerceEventsLocation', true);
    $image = wp_get_attachment_image_url($product->get_image_id(), 'large');

    return [
        'id' => $id,
        'title' => wp_strip_all_tags($product->get_name()),
        'url' => get_permalink($id),
        'line' => $line,
        'date' => mft_product_field($id, 'event_date'),
        'time' => mft_product_field($id, 'event_time'),
        'location' => mft_product_field($id, 'event_location') ?: $venue_location,
        'badge' => mft_product_field($id, 'badge'),
        'featured' => mft_product_featured($product),
        'image' => $image ?: wc_placeholder_img_src('large'),
        'price' => $price,
        'price_from' => $price_from,
        'available' => $product->is_purchasable() && $product->is_in_stock(),
        'tickets' => $tickets['tickets'],
        'tickets_label' => $tickets['label'],
        'default_variation' => $tickets['default_variation'],
        'max' => mft_max_quantity($product),
    ];
}

/**
 * Todo el catálogo visible, del más reciente al más viejo (el mismo orden que la
 * Store API). Se guarda 5 minutos en el caché de objetos (Redis) y se vacía cada
 * vez que cambia un producto.
 */
function mft_catalog(): array
{
    $cached = wp_cache_get('catalog', 'mft');
    if (is_array($cached)) {
        return $cached;
    }
    $catalog = [];
    $products = wc_get_products([
        'status' => 'publish',
        'visibility' => 'catalog',
        'limit' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);
    foreach ($products as $product) {
        $data = mft_product_data($product);
        if ($data) {
            $catalog[] = $data;
        }
    }
    wp_cache_set('catalog', $catalog, 'mft', 5 * MINUTE_IN_SECONDS);
    return $catalog;
}

/** Productos de una línea de negocio (o de varias). */
function mft_catalog_line(string|array $lines, bool $featured_only = false): array
{
    $lines = (array) $lines;
    return array_values(array_filter(
        mft_catalog(),
        fn($p) => in_array($p['line'], $lines, true) && (!$featured_only || $p['featured'])
    ));
}

function mft_flush_catalog(): void
{
    wp_cache_delete('catalog', 'mft');
}
foreach (['save_post_product', 'woocommerce_update_product', 'woocommerce_save_product_variation', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'trashed_post', 'untrashed_post', 'deleted_post', 'edited_product_cat'] as $mft_hook) {
    add_action($mft_hook, 'mft_flush_catalog');
}
unset($mft_hook);

/**
 * Precio para la tarjeta, o '' si no se debe mostrar. Un precio 0 nunca sale
 * como "RD$ 0": si se puede comprar es "Gratis"; si no, se omite. Con varios
 * boletos y el más barato en 0 también se omite ("Desde Gratis" confunde).
 */
function mft_card_price(array $item): string
{
    if ($item['price'] > 0) {
        return ($item['price_from'] ? 'Desde ' : '') . mft_money($item['price']);
    }
    return $item['available'] && !$item['price_from'] ? 'Gratis' : '';
}

/** "RD$ 1,900" (con centavos solo si los hay), como en el front anterior. */
function mft_money(float $amount): string
{
    $decimals = floor($amount) == $amount ? 0 : 2;
    return 'RD$ ' . number_format($amount, $decimals, '.', ',');
}

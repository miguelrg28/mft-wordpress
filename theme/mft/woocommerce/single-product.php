<?php
/**
 * Ficha de producto (/event/<slug>/): foto, datos del evento, selector de
 * boletos o cantidad, complementarios, total, descripción, mapa y recomendados.
 * El carrito lo maneja assets/js/product.js contra inc/product.php.
 */

defined('ABSPATH') || exit;

global $product;
the_post();
$product = wc_get_product(get_the_ID());
$item = mft_product_data($product, true);
$is_shop = $item['line'] === 'shop';
$addons = $is_shop ? [] : mft_addons();
$venue = mft_product_venue($item['id']);
$recommend = mft_recommendations($item);
$line_info = mft_line_info($item['line']);
$noun = $is_shop ? 'Producto' : 'Boleto';

// Boletos agrupados por función (conciertos con varios shows); sin función, un solo grupo.
$groups = [];
foreach ($item['tickets'] as $ticket) {
    $groups[$ticket['funcion'] ?? ''][] = $ticket;
}

wp_enqueue_script('mft-product', get_theme_file_uri('assets/js/product.js'), [], mft_asset_version('assets/js/product.js'), ['strategy' => 'defer']);

get_header();
?>
<div class="mft-container mft-page mft-product">
  <form class="mft-product__grid" data-mft-product
        data-endpoint="<?php echo esc_url(WC_AJAX::get_endpoint('mft_add_to_cart')); ?>"
        data-available="<?php echo $item['available'] ? '1' : '0'; ?>">
    <input type="hidden" name="product_id" value="<?php echo esc_attr($item['id']); ?>">
    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('mft_cart')); ?>">

    <div class="mft-product__media">
      <img src="<?php echo esc_url(wp_get_attachment_image_url($product->get_image_id(), 'full') ?: $item['image']); ?>" alt="<?php echo esc_attr($item['title']); ?>" fetchpriority="high">
      <?php if ($item['badge']) : ?><span class="mft-badge mft-badge--warning mft-product__badge"><?php echo esc_html($item['badge']); ?></span><?php endif; ?>
    </div>

    <div class="mft-product__info">
      <div>
        <h1 class="mft-product__title"><?php echo esc_html($item['title']); ?></h1>
        <?php $short = wp_strip_all_tags($product->get_short_description()); ?>
        <?php if ($short) : ?><p class="mft-product__lead"><?php echo esc_html($short); ?></p><?php endif; ?>
      </div>

      <?php if ($item['date'] || $item['time'] || $item['location']) : ?>
        <div class="mft-card mft-product__facts">
          <?php foreach (['date' => 'calendar', 'time' => 'clock', 'location' => 'map-pin'] as $field => $icon) : ?>
            <?php if ($item[$field]) : ?><div><?php echo mft_icon($icon); ?><span><?php echo esc_html($item[$field]); ?></span></div><?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($item['tickets']) : ?>
        <section>
          <h2 class="mft-product__h2"><?php echo esc_html($item['tickets_label'] ?: 'Selecciona tus Boletos'); ?></h2>
          <?php foreach ($groups as $funcion => $tickets) : ?>
            <div class="mft-ticket-group">
              <?php if ($funcion !== '') : ?><h3 class="mft-ticket-group__title"><?php echo esc_html($funcion); ?></h3><?php endif; ?>
              <?php foreach ($tickets as $ticket) : ?>
                <div class="mft-card mft-ticket<?php echo $ticket['available'] ? '' : ' is-unavailable'; ?>"
                     data-mft-line data-price="<?php echo esc_attr($ticket['price']); ?>"
                     data-label="<?php echo esc_attr(($funcion !== '' ? $funcion . ' — ' : '') . $ticket['label']); ?>">
                  <div class="mft-ticket__text">
                    <h3><?php echo esc_html($ticket['label']); ?></h3>
                    <?php if ($ticket['available']) : ?>
                      <p class="mft-ticket__price">
                        <?php if ($ticket['regular_price']) : ?><del><?php echo esc_html(mft_money($ticket['regular_price'])); ?></del><?php endif; ?>
                        <span><?php echo esc_html(mft_money($ticket['price'])); ?></span>
                      </p>
                    <?php else : ?>
                      <p class="mft-ticket__na">No Disponible</p>
                    <?php endif; ?>
                  </div>
                  <?php if ($ticket['available']) : ?>
                    <?php mft_stepper("lines[{$ticket['id']}]", 0, 0, min($ticket['max'], 99)); ?>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </section>
      <?php elseif ($item['available']) : ?>
        <section>
          <h2 class="mft-product__h2">Cantidad</h2>
          <div class="mft-qty-row" data-mft-line data-price="<?php echo esc_attr($item['price']); ?>" data-label="<?php echo esc_attr($noun); ?>">
            <?php mft_stepper("lines[{$item['id']}]", 1, 1, min($item['max'], 99), true); ?>
            <?php if ($item['max'] < 99) : ?><span class="mft-qty-row__stock"><?php echo esc_html($item['max']); ?> disponibles</span><?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($addons) : ?>
        <section>
          <h2 class="mft-product__h2">Mejora tu Experiencia</h2>
          <div class="mft-addons">
            <?php foreach ($addons as $addon) : ?>
              <label class="mft-card mft-addon" data-mft-addon data-price="<?php echo esc_attr($addon['price']); ?>" data-label="<?php echo esc_attr($addon['name']); ?>">
                <input type="checkbox" name="addons[]" value="<?php echo esc_attr($addon['id']); ?>">
                <?php if ($addon['image']) : ?><img src="<?php echo esc_url($addon['image']); ?>" alt="" loading="lazy"><?php endif; ?>
                <span class="mft-addon__text">
                  <strong><?php echo esc_html($addon['name']); ?></strong>
                  <?php if ($addon['description']) : ?><span><?php echo esc_html($addon['description']); ?></span><?php endif; ?>
                </span>
                <span class="mft-addon__price">
                  <?php if ($addon['regular_price']) : ?><del><?php echo esc_html(mft_money($addon['regular_price'])); ?></del><?php endif; ?>
                  <span>+<?php echo esc_html(mft_money($addon['price'])); ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <div class="mft-card mft-summary">
        <div class="mft-summary__lines" data-mft-summary>
          <?php if ($item['tickets']) : ?><p>Selecciona la cantidad de cada tipo de boleto.</p><?php endif; ?>
        </div>
        <div class="mft-summary__total">
          <span>Total</span>
          <strong data-mft-total><?php echo esc_html(mft_money($item['tickets'] ? 0 : $item['price'])); ?></strong>
        </div>
      </div>

      <p class="mft-form-error" data-mft-error role="alert" hidden></p>

      <button class="mft-btn mft-btn--primary mft-btn--xl mft-btn--block" type="submit" data-mft-submit<?php disabled(!$item['available'] || (bool) $item['tickets']); ?>>
        <?php if ($item['available']) : ?>
          <?php echo mft_icon('cart'); ?> <span data-mft-submit-label>Agregar al Carrito</span>
        <?php else : ?>
          Agotado
        <?php endif; ?>
      </button>
    </div>

    <div class="mft-buybar mft-show-mobile">
      <button class="mft-btn mft-btn--primary mft-btn--block mft-btn--lg" type="submit" data-mft-submit<?php disabled(!$item['available'] || (bool) $item['tickets']); ?>>
        <?php if ($item['available']) : ?>
          Comprar - <span data-mft-total><?php echo esc_html(mft_money($item['tickets'] ? 0 : $item['price'])); ?></span>
        <?php else : ?>
          Agotado
        <?php endif; ?>
      </button>
    </div>
  </form>

  <?php $description = $product->get_description(); ?>
  <?php if ($description) : ?>
    <section class="mft-product__section">
      <h2 class="mft-product__h2 mft-product__h2--lg">Descripción</h2>
      <div class="mft-rich-content"><?php echo apply_filters('the_content', $description); ?></div>
    </section>
  <?php endif; ?>

  <?php if ($venue['map_src']) : ?>
    <section class="mft-product__section">
      <h2 class="mft-product__h2 mft-product__h2--lg mft-with-icon"><?php echo mft_icon('map-pin'); ?> Ubicación del evento</h2>
      <?php if ($venue['location']) : ?><p class="mft-venue__name"><?php echo esc_html($venue['location']); ?></p><?php endif; ?>
      <?php if ($venue['directions']) : ?><p class="mft-venue__directions"><?php echo esc_html($venue['directions']); ?></p><?php endif; ?>
      <div class="mft-venue__map">
        <iframe src="<?php echo esc_url($venue['map_src']); ?>" title="Ubicación<?php echo $venue['location'] ? ' — ' . esc_attr($venue['location']) : ''; ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($recommend['items']) : ?>
    <section class="mft-product__section">
      <div class="mft-product__related-head">
        <h2 class="mft-product__h2 mft-product__h2--lg"><?php echo esc_html($recommend['related'] ? $line_info['heading'] : 'También te Puede Interesar'); ?></h2>
        <a class="mft-btn mft-btn--ghost" href="<?php echo esc_url($line_info['url']); ?>">Ver Todos <?php echo mft_icon('arrow-right'); ?></a>
      </div>
      <div class="mft-carousel" data-mft-carousel>
        <div class="mft-carousel__track mft-related">
          <?php foreach ($recommend['items'] as $related) : ?>
            <div class="mft-carousel__slide mft-related__slide">
              <?php get_template_part('template-parts/product-card', null, ['item' => $related]); ?>
            </div>
          <?php endforeach; ?>
        </div>
        <button class="mft-carousel__nav mft-carousel__nav--prev" type="button" data-mft-prev aria-label="Anterior"><?php echo mft_icon('chevron-left'); ?></button>
        <button class="mft-carousel__nav mft-carousel__nav--next" type="button" data-mft-next aria-label="Siguiente"><?php echo mft_icon('chevron-right'); ?></button>
      </div>
    </section>
  <?php endif; ?>
</div>
<?php
get_footer();

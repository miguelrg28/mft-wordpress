<?php
/**
 * Tarjeta de producto (evento, tour, artículo de tienda…).
 * Args: item (de mft_product_data()), sold_out_label (texto sobre la foto si está agotado; '' para no mostrarlo).
 */
$item = $args['item'];
$sold_out_label = $args['sold_out_label'] ?? '';
$price = mft_card_price($item);
$when = implode(' - ', array_filter([$item['date'], $item['time']]));
?>
<a class="mft-card mft-product-card" href="<?php echo esc_url($item['url']); ?>">
  <div class="mft-product-card__media">
    <img src="<?php echo esc_url($item['image']); ?>" alt="<?php echo esc_attr($item['title']); ?>" loading="lazy">
    <?php if ($item['badge']) : ?>
      <span class="mft-badge mft-badge--warning mft-product-card__badge"><?php echo esc_html($item['badge']); ?></span>
    <?php endif; ?>
    <?php if ($sold_out_label && !$item['available']) : ?>
      <div class="mft-product-card__soldout"><span class="mft-badge mft-badge--error"><?php echo esc_html($sold_out_label); ?></span></div>
    <?php endif; ?>
  </div>
  <div class="mft-product-card__body">
    <h3 class="mft-product-card__title"><?php echo esc_html($item['title']); ?></h3>
    <?php if ($when || $item['location']) : ?>
      <div class="mft-meta-list">
        <?php if ($when) : ?><div class="mft-meta"><?php echo mft_icon('calendar'); ?><span><?php echo esc_html($when); ?></span></div><?php endif; ?>
        <?php if ($item['location']) : ?><div class="mft-meta"><?php echo mft_icon('map-pin'); ?><span class="mft-clamp-1"><?php echo esc_html($item['location']); ?></span></div><?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="mft-product-card__footer">
      <?php if ($price) : ?><span class="mft-product-card__price"><?php echo esc_html($price); ?></span><?php endif; ?>
      <span class="mft-btn mft-btn--primary">Ver Detalles</span>
    </div>
  </div>
</a>

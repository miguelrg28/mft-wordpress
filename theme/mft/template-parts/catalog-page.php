<?php
/**
 * Listado de una línea de negocio (Eventos, Tours, MFTSports, Tienda).
 * Args: line (o lista de líneas), slides (grupo de slides), title, empty, sold_out_label.
 */
$items = mft_catalog_line($args['line']);
$slides = mft_hero_slides($args['slides']);

get_header();
get_template_part('template-parts/hero', null, ['slides' => $slides, 'compact' => true]);
?>
<div class="mft-container mft-page<?php echo $slides ? ' mft-page--after-hero' : ''; ?>">
  <?php get_template_part('template-parts/section-head', null, ['title' => $args['title'], 'tag' => 'h1']); ?>

  <?php if (!$items) : ?>
    <p class="mft-empty"><?php echo esc_html($args['empty']); ?></p>
  <?php else : ?>
    <div class="mft-grid mft-grid--products">
      <?php foreach ($items as $item) {
          get_template_part('template-parts/product-card', null, ['item' => $item, 'sold_out_label' => $args['sold_out_label'] ?? 'Sold Out']);
      } ?>
    </div>
  <?php endif; ?>
</div>
<?php
get_footer();

<?php
/**
 * Archivos de productos de WooCommerce.
 *   - La página de tienda (/tienda/) es el listado de la línea Tienda, igual que page-tienda.php.
 *   - Una categoría o etiqueta (/seccion/<slug>/) muestra sus productos con las mismas tarjetas.
 */

defined('ABSPATH') || exit;

if (is_shop()) {
    get_template_part('template-parts/catalog-page', null, [
        'line' => 'shop',
        'slides' => 'tienda',
        'title' => 'Tienda',
        'empty' => 'No hay productos disponibles en este momento.',
        'sold_out_label' => 'Agotado',
    ]);
    return;
}

$term = get_queried_object();
$ids = [];
while (have_posts()) {
    the_post();
    $ids[] = get_the_ID();
}
// Del catálogo (ya con precios y líneas calculados) solo los de esta categoría,
// en el orden del catálogo. Un producto excluido (eventos pasados) no aparece.
$items = array_values(array_filter(mft_catalog(), fn($p) => in_array($p['id'], $ids, true)));

get_header();
?>
<div class="mft-container mft-page">
  <?php get_template_part('template-parts/section-head', null, ['title' => $term->name ?? 'Productos', 'tag' => 'h1']); ?>
  <?php if (!$items) : ?>
    <p class="mft-empty">No hay productos disponibles en este momento.</p>
  <?php else : ?>
    <div class="mft-grid mft-grid--products">
      <?php foreach ($items as $item) {
          get_template_part('template-parts/product-card', null, ['item' => $item, 'sold_out_label' => 'Agotado']);
      } ?>
    </div>
    <?php the_posts_pagination(['class' => 'mft-pagination']); ?>
  <?php endif; ?>
</div>
<?php
get_footer();

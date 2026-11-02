<?php
/**
 * Encabezado de sección (franja gris con título y, opcional, "Ver Todos →").
 * Args: title, link (URL opcional), link_label (por defecto "Ver Todos"), tag (h1/h2, por defecto h2).
 */
$tag = ($args['tag'] ?? 'h2') === 'h1' ? 'h1' : 'h2';
?>
<div class="mft-section-head">
  <<?php echo $tag; ?>><?php echo esc_html($args['title']); ?></<?php echo $tag; ?>>
  <?php if (!empty($args['link'])) : ?>
    <a class="mft-btn mft-btn--ghost" href="<?php echo esc_url($args['link']); ?>"><?php echo esc_html($args['link_label'] ?? 'Ver Todos'); ?> <?php echo mft_icon('arrow-right'); ?></a>
  <?php endif; ?>
</div>

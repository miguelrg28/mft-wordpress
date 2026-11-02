<?php
/**
 * Carrusel del hero. Args: slides (de mft_hero_slides()), compact (bool, menos aire arriba/abajo).
 * Uso: get_template_part('template-parts/hero', null, ['slides' => mft_hero_slides('events')]);
 */
$slides = $args['slides'] ?? [];
if (!$slides) {
    return;
}
$compact = !empty($args['compact']);
?>
<section class="mft-hero<?php echo $compact ? ' mft-hero--compact' : ''; ?>">
  <div class="mft-container">
    <div class="mft-carousel" data-mft-carousel data-autoplay="5000">
      <div class="mft-carousel__track">
        <?php foreach ($slides as $i => $slide) : ?>
          <?php $tag = $slide['link'] ? 'a' : 'div'; ?>
          <<?php echo $tag; ?> class="mft-carousel__slide mft-hero__slide"<?php echo $slide['link'] ? ' href="' . esc_url($slide['link']) . '"' : ''; ?>>
            <img src="<?php echo esc_url($slide['image']); ?>" alt="<?php echo esc_attr($slide['title']); ?>"<?php echo $i ? ' loading="lazy"' : ' fetchpriority="high"'; ?>>
            <div class="mft-hero__caption">
              <?php if ($slide['badge']) : ?><span class="mft-badge mft-badge--warning"><?php echo esc_html($slide['badge']); ?></span><?php endif; ?>
              <<?php echo $i ? 'h2' : 'h1'; ?> class="mft-hero__title"><?php echo esc_html($slide['title']); ?></<?php echo $i ? 'h2' : 'h1'; ?>>
              <?php if ($slide['subtitle']) : ?><p class="mft-hero__subtitle"><?php echo esc_html($slide['subtitle']); ?></p><?php endif; ?>
            </div>
          </<?php echo $tag; ?>>
        <?php endforeach; ?>
      </div>
      <?php if (count($slides) > 1) : ?>
        <button class="mft-carousel__nav mft-carousel__nav--prev" type="button" data-mft-prev aria-label="Anterior"><?php echo mft_icon('chevron-left'); ?></button>
        <button class="mft-carousel__nav mft-carousel__nav--next" type="button" data-mft-next aria-label="Siguiente"><?php echo mft_icon('chevron-right'); ?></button>
      <?php endif; ?>
    </div>
  </div>
</section>

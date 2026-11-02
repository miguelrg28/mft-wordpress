<?php
/** Tarjeta de entrada del blog. Usa el post actual del loop (setup_postdata / the_post). */
?>
<a class="mft-card mft-blog-card" href="<?php the_permalink(); ?>">
  <div class="mft-blog-card__media">
    <?php if (has_post_thumbnail()) : ?>
      <?php the_post_thumbnail('large', ['loading' => 'lazy', 'alt' => esc_attr(get_the_title())]); ?>
    <?php endif; ?>
  </div>
  <div class="mft-blog-card__body">
    <div class="mft-meta mft-meta--small">
      <?php echo mft_icon('calendar'); ?>
      <span><?php echo esc_html(get_the_date('j \d\e F \d\e Y')); ?> · <?php the_author(); ?></span>
    </div>
    <h3 class="mft-blog-card__title"><?php the_title(); ?></h3>
    <p class="mft-clamp-3"><?php echo esc_html(wp_strip_all_tags(get_the_excerpt())); ?></p>
  </div>
</a>

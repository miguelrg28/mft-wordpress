<?php
/** Entrada del blog. */
get_header();
while (have_posts()) :
    the_post();
    ?>
    <article class="mft-container mft-page mft-page--narrow">
      <a class="mft-back" href="<?php echo esc_url(get_permalink(get_option('page_for_posts')) ?: home_url('/blog/')); ?>"><?php echo mft_icon('chevron-left'); ?> Blog</a>
      <h1 class="mft-page__title"><?php the_title(); ?></h1>
      <div class="mft-meta mft-meta--small mft-post-meta">
        <?php echo mft_icon('calendar'); ?>
        <span><?php echo esc_html(get_the_date('j \d\e F \d\e Y')); ?> · <?php the_author(); ?></span>
      </div>
      <?php if (has_post_thumbnail()) : ?>
        <div class="mft-post-cover"><?php the_post_thumbnail('large'); ?></div>
      <?php endif; ?>
      <div class="mft-rich-content mft-page-content"><?php the_content(); ?></div>
    </article>
    <?php
endwhile;
get_footer();

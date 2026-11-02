<?php
/** Respaldo de WordPress: lista lo que traiga la consulta (archivos, búsquedas…). */
get_header();
?>
<div class="mft-container mft-page">
  <?php get_template_part('template-parts/section-head', null, ['title' => wp_strip_all_tags(get_the_archive_title()) ?: get_bloginfo('name'), 'tag' => 'h1']); ?>
  <?php if (have_posts()) : ?>
    <div class="mft-grid mft-grid--3">
      <?php while (have_posts()) {
          the_post();
          get_template_part('template-parts/blog-card');
      } ?>
    </div>
    <?php the_posts_pagination(['class' => 'mft-pagination']); ?>
  <?php else : ?>
    <p class="mft-empty">No hay nada que mostrar aquí.</p>
  <?php endif; ?>
</div>
<?php
get_footer();

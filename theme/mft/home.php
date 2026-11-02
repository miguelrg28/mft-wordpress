<?php
/** Blog: la página asignada como "Página de entradas" en Ajustes → Lectura (slug "blog"). */
get_header();
get_template_part('template-parts/hero', null, ['slides' => mft_hero_slides('blog'), 'compact' => true]);
?>
<div class="mft-container mft-page mft-page--after-hero">
  <?php get_template_part('template-parts/section-head', null, ['title' => 'Blog', 'tag' => 'h1']); ?>
  <?php if (have_posts()) : ?>
    <div class="mft-grid mft-grid--3">
      <?php while (have_posts()) {
          the_post();
          get_template_part('template-parts/blog-card');
      } ?>
    </div>
    <?php the_posts_pagination(['class' => 'mft-pagination']); ?>
  <?php else : ?>
    <p class="mft-empty">Aún no hay entradas publicadas.</p>
  <?php endif; ?>
</div>
<?php
get_footer();

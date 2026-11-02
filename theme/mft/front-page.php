<?php
/**
 * Home: hero, categorías, destacados por línea, próximos viajes, itinerarios y blog.
 */

$mft_categories = [
    ['icon' => 'ticket', 'name' => 'Boletas', 'description' => 'Eventos y Conciertos', 'path' => '/events/'],
    ['icon' => 'hiking', 'name' => 'Tours', 'description' => 'Experiencias Únicas', 'path' => '/tours/'],
    ['icon' => 'cart-plus', 'name' => 'Tienda', 'description' => 'Mercancía Oficial', 'path' => '/tienda/'],
    ['icon' => 'print', 'name' => 'Print Center', 'description' => 'Impresión B2B', 'path' => '/print-center/'],
];

$mft_travel = [
    ['name' => 'Cruceros', 'description' => 'Próximos Cruceros', 'path' => '/itinerarios/cruceros/', 'image' => 'https://images.unsplash.com/photo-1548574505-5e239809ee19?w=800&q=80'],
    ['name' => 'Salida a Colombia', 'description' => 'Salidas Programadas', 'path' => '/itinerarios/colombia/', 'image' => get_theme_file_uri('assets/img/colombia.webp')],
    ['name' => 'Viajes Internacionales', 'description' => 'Destinos por el Mundo', 'path' => '/itinerarios/viajes-internacionales/', 'image' => 'https://images.unsplash.com/photo-1436491865332-7a61a109cc05?w=800&q=80'],
];

$mft_sections = [
    ['title' => 'Eventos Destacados', 'link' => '/events/', 'items' => array_filter(mft_catalog(), fn($p) => $p['featured'])],
    ['title' => 'Tours', 'link' => '/tours/', 'items' => array_slice(mft_catalog_line('tour', true), 0, 4)],
    ['title' => 'Tienda', 'link' => '/tienda/', 'items' => array_slice(mft_catalog_line('shop', true), 0, 4)],
];

$mft_posts = get_posts(['numberposts' => 3, 'post_status' => 'publish']);

get_header();
get_template_part('template-parts/hero', null, ['slides' => mft_hero_slides('home')]);
?>

<section class="mft-section">
  <div class="mft-container mft-section__inner">
    <?php get_template_part('template-parts/section-head', null, ['title' => 'Categorías']); ?>
    <div class="mft-grid mft-grid--categories">
      <?php foreach ($mft_categories as $category) : ?>
        <a class="mft-card mft-category-card" href="<?php echo esc_url(home_url($category['path'])); ?>">
          <span class="mft-category-card__icon"><?php echo mft_category_icon($category['icon']); ?></span>
          <h3><?php echo esc_html($category['name']); ?></h3>
          <p><?php echo esc_html($category['description']); ?></p>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php foreach ($mft_sections as $section) : ?>
  <?php if (!$section['items']) {
      continue;
  } ?>
  <section class="mft-section">
    <div class="mft-container mft-section__inner">
      <?php get_template_part('template-parts/section-head', null, ['title' => $section['title'], 'link' => home_url($section['link'])]); ?>
      <div class="mft-grid mft-grid--products-home">
        <?php foreach ($section['items'] as $item) {
            get_template_part('template-parts/product-card', null, ['item' => $item]);
        } ?>
      </div>
    </div>
  </section>
<?php endforeach; ?>

<section class="mft-section">
  <div class="mft-container mft-section__inner">
    <?php get_template_part('template-parts/section-head', null, ['title' => 'Próximos Viajes']); ?>
    <div class="mft-grid mft-grid--3">
      <?php foreach ($mft_travel as $card) : ?>
        <a class="mft-card mft-travel-card" href="<?php echo esc_url(home_url($card['path'])); ?>">
          <img src="<?php echo esc_url($card['image']); ?>" alt="<?php echo esc_attr($card['name']); ?>" loading="lazy">
          <div class="mft-travel-card__caption">
            <h3><?php echo esc_html($card['name']); ?></h3>
            <p><?php echo esc_html($card['description']); ?></p>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="mft-section">
  <div class="mft-container mft-section__inner">
    <div class="mft-card mft-banner">
      <div class="mft-banner__text">
        <?php echo mft_icon('map', 'mft-banner__icon'); ?>
        <div>
          <h2>Itinerarios</h2>
          <p>Consulta los itinerarios completos de nuestros cruceros, salidas y viajes.</p>
        </div>
      </div>
      <a class="mft-btn mft-btn--primary mft-btn--lg" href="<?php echo esc_url(home_url('/itinerarios/')); ?>">Ver Itinerarios <?php echo mft_icon('arrow-right'); ?></a>
    </div>
  </div>
</section>

<?php if ($mft_posts) : ?>
  <section class="mft-section">
    <div class="mft-container mft-section__inner">
      <?php get_template_part('template-parts/section-head', null, ['title' => 'Del Blog', 'link' => get_permalink(get_option('page_for_posts')) ?: home_url('/blog/'), 'link_label' => 'Ver Todo']); ?>
      <div class="mft-grid mft-grid--3">
        <?php
        global $post;
        foreach ($mft_posts as $post) {
            setup_postdata($post);
            get_template_part('template-parts/blog-card');
        }
        wp_reset_postdata();
        ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php
get_footer();

<?php
/**
 * /itinerarios/ (todas las categorías) y /itinerarios/<categoría>/ (una sola).
 * Requiere una página de WordPress con el slug "itinerarios".
 */

$slug = get_query_var('mft_itinerary');
$single = $slug ? mft_itinerary_category($slug) : null;
$categories = $single ? [$single] : mft_itineraries();
$slides = $single ? [] : mft_hero_slides('itinerarios');
$empty = '<div class="mft-itin-empty">Actualizando salidas — consulta por WhatsApp</div>';

get_header();

if ($single) : ?>
  <div class="mft-itin-hero">
    <?php if ($single['image']) : ?><img src="<?php echo esc_url($single['image']); ?>" alt="<?php echo esc_attr($single['label']); ?>"><?php endif; ?>
    <div class="mft-container mft-itin-hero__text">
      <p class="mft-kicker">MasFlowTeam</p>
      <h1><?php echo esc_html($single['label']); ?></h1>
    </div>
  </div>
<?php else : ?>
  <?php get_template_part('template-parts/hero', null, ['slides' => $slides, 'compact' => true]); ?>
  <div class="mft-container mft-itin-wrap mft-itin-title">
    <p class="mft-kicker">MasFlowTeam</p>
    <h1>Itinerarios</h1>
  </div>
<?php endif; ?>

<div class="mft-container mft-itin-wrap mft-page">
  <?php get_template_part('template-parts/itinerary-parts', null, ['part' => 'contact']); ?>
  <?php get_template_part('template-parts/itinerary-parts', null, ['part' => 'cuotas']); ?>

  <?php if ($single) : ?>
    <div class="mft-itin-months">
      <?php if (!$single['months']) {
          echo $empty;
      }
      foreach ($single['months'] as $block) {
          get_template_part('template-parts/itinerary-parts', null, ['part' => 'month', 'block' => $block]);
      } ?>
    </div>

  <?php elseif (!$categories) : ?>
    <p class="mft-empty">No hay itinerarios disponibles en este momento.</p>

  <?php else : ?>
    <div class="mft-grid mft-grid--3 mft-itin-cats">
      <?php foreach ($categories as $category) : ?>
        <?php $external = (bool) $category['external_url']; ?>
        <a class="mft-card mft-itin-cat" href="<?php echo esc_url($external ? $category['external_url'] : home_url('/itinerarios/' . $category['slug'] . '/')); ?>"<?php echo $external ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
          <?php if ($category['image']) : ?><img src="<?php echo esc_url($category['image']); ?>" alt="<?php echo esc_attr($category['label']); ?>" loading="lazy"><?php endif; ?>
          <span class="mft-itin-cat__label"><?php echo esc_html($category['label']); ?> <?php echo mft_icon($external ? 'external' : 'arrow-right'); ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php foreach ($categories as $category) : ?>
      <?php if ($category['external_url']) {
          continue;
      } ?>
      <div class="mft-itin-category">
        <div class="mft-itin-category__head">
          <h2><?php echo esc_html($category['label']); ?></h2>
          <a class="mft-btn mft-btn--ghost" href="<?php echo esc_url(home_url('/itinerarios/' . $category['slug'] . '/')); ?>">Ver página <?php echo mft_icon('arrow-right'); ?></a>
        </div>
        <div class="mft-itin-months">
          <?php if (!$category['months']) {
              echo $empty;
          }
          foreach ($category['months'] as $block) {
              get_template_part('template-parts/itinerary-parts', null, ['part' => 'month', 'block' => $block]);
          } ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="mft-itin-footer">
    <?php get_template_part('template-parts/itinerary-parts', null, ['part' => 'whatsapp', 'label' => 'Solicitar información por WhatsApp']); ?>
    <div class="mft-itin-footer__links">
      <?php if ($single) : ?><a class="mft-btn mft-btn--muted" href="<?php echo esc_url(home_url('/itinerarios/')); ?>">Ver todos los itinerarios</a><?php endif; ?>
      <a class="mft-btn mft-btn--muted" href="<?php echo esc_url(home_url('/')); ?>"><?php echo mft_icon('home'); ?> Volver al inicio</a>
    </div>
  </div>
</div>
<?php
get_footer();

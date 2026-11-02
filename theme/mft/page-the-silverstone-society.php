<?php
/**
 * /the-silverstone-society/: landing con formulario de registro (Google Forms).
 * Los datos del evento (título, fecha, hora, lugar, imagen, descripción) salen del
 * producto de WooCommerce con el mismo slug. Requiere una página con el slug
 * "the-silverstone-society".
 */

const MFT_TSS_SLUG = 'the-silverstone-society';
const MFT_TSS_FORM = 'https://docs.google.com/forms/d/e/1FAIpQLSdAUG-mInNEakqHkiSUaoeyBeB2qDi2CCElP_3-TkxMSEDhlw/viewform?embedded=true';

$post = get_page_by_path(MFT_TSS_SLUG, OBJECT, 'product');
$product = $post ? wc_get_product($post->ID) : null;
$event = $product ? mft_product_data($product, true) : null;
$title = $event['title'] ?? 'The Silverstone Society';
$lede = $product ? wp_strip_all_tags($product->get_short_description() ?: $product->get_description()) : '';

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('mft-tss-fonts', 'https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600&family=Bodoni+Moda:ital,wght@0,400;0,500;0,600;1,400;1,500&display=swap', [], null);
    wp_enqueue_style('mft-tss', get_theme_file_uri('assets/css/silverstone.css'), ['mft'], mft_asset_version('assets/css/silverstone.css'));
});
add_filter('document_title_parts', function ($parts) use ($title) {
    $parts['title'] = $title;
    return $parts;
});

get_header();
?>
<div class="tss-root">
  <div class="tss-inner">
    <header class="tss-hero">
      <?php if (!empty($event['image'])) : ?>
        <div class="tss-poster"><img src="<?php echo esc_url($event['image']); ?>" alt="<?php echo esc_attr($title); ?>"></div>
      <?php endif; ?>

      <div class="tss-hero-body">
        <p class="tss-eyebrow">Mas Flow Team</p>
        <h1 class="tss-title"><?php echo esc_html($title); ?></h1>

        <?php if ($event && ($event['date'] || $event['time'] || $event['location'])) : ?>
          <ul class="tss-meta">
            <?php foreach (['date' => 'calendar', 'time' => 'clock', 'location' => 'map-pin'] as $field => $icon) : ?>
              <?php if ($event[$field]) : ?><li><?php echo mft_icon($icon, 'tss-meta-ico'); ?><span><?php echo esc_html($event[$field]); ?></span></li><?php endif; ?>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($lede) : ?><p class="tss-lede"><?php echo esc_html($lede); ?></p><?php endif; ?>
      </div>
    </header>

    <section class="tss-apply" aria-labelledby="tss-apply-title">
      <div class="tss-apply-head">
        <h2 id="tss-apply-title">Formulario de registro</h2>
        <p>Oficial</p>
      </div>
      <div class="tss-frame">
        <iframe src="<?php echo esc_url(MFT_TSS_FORM); ?>" title="Formulario — <?php echo esc_attr($title); ?>" marginheight="0" marginwidth="0" scrolling="no" style="overflow:hidden">Cargando…</iframe>
      </div>
      <p class="tss-note">Al enviar el formulario recibirás la confirmación por correo electrónico.</p>
    </section>
  </div>
</div>
<?php
get_footer();

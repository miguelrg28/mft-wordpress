<?php
/**
 * /hoteleria/: buscador de hoteles de Viajes AGT en un iframe. Al buscar, AGT
 * manda a /lista-hoteles/ (configurado en su panel como "Redirect link on agency
 * domain"). Requiere una página con el slug "hoteleria".
 */

$engine = apply_filters('mft_agt_engine_url', 'https://reservas.viajesagt.com/es/microsite/masflowteam/engine/iframe');
$slides = mft_hero_slides('hoteleria');

get_header();
get_template_part('template-parts/hero', null, ['slides' => $slides, 'compact' => true]);
?>
<div class="mft-container mft-page<?php echo $slides ? ' mft-page--after-hero' : ''; ?>">
  <h1 class="mft-page__title">Hotelería</h1>
  <div class="mft-agt" data-mft-loading>
    <div class="mft-agt__loader"><span class="mft-spinner"></span><p>Cargando buscador de hoteles...</p></div>
    <iframe src="<?php echo esc_url($engine); ?>" width="100%" height="800" scrolling="no" title="Buscador de hoteles"
            onload="this.closest('[data-mft-loading]').classList.add('is-loaded')"></iframe>
  </div>
</div>
<?php
get_footer();

<?php
get_header();
?>
<div class="mft-container mft-page mft-page--narrow mft-404">
  <h1 class="mft-page__title">Página no encontrada</h1>
  <p>La página que buscas no existe o fue movida.</p>
  <a class="mft-btn mft-btn--primary mft-btn--lg" href="<?php echo esc_url(home_url('/')); ?>">Ir al inicio</a>
</div>
<?php
get_footer();

<?php
/**
 * Página genérica (políticas, landing pages hechas con el editor de bloques, etc.).
 * Carrito, checkout y Mi cuenta también pasan por aquí, pero sin los estilos de
 * contenido enriquecido: WooCommerce trae su propio HTML (ver la sección
 * WooCommerce de assets/css/mft.css).
 */
$mft_content_class = (function_exists('is_woocommerce') && (is_cart() || is_checkout() || is_account_page()))
    ? 'mft-wc-content'
    : 'mft-rich-content mft-page-content';

get_header();
while (have_posts()) :
    the_post();
    ?>
    <article class="mft-container mft-page mft-page--narrow">
      <h1 class="mft-page__title"><?php the_title(); ?></h1>
      <div class="<?php echo esc_attr($mft_content_class); ?>"><?php the_content(); ?></div>
    </article>
    <?php
endwhile;
get_footer();

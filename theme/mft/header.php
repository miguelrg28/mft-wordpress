<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header class="mft-header">
  <div class="mft-container mft-header__bar">
    <a class="mft-header__logo" href="<?php echo esc_url(home_url('/')); ?>">
      <img src="<?php echo esc_url(mft_logo_url()); ?>" alt="<?php bloginfo('name'); ?>">
    </a>

    <nav class="mft-header__nav" aria-label="Principal">
      <?php mft_menu('primary', 'mft-nav'); ?>
    </nav>

    <div class="mft-header__actions">
      <a class="mft-icon-btn mft-cart" href="<?php echo esc_url(wc_get_checkout_url()); ?>" aria-label="Carrito">
        <?php echo mft_icon('cart'); ?>
        <?php echo mft_cart_count_html(); ?>
      </a>

      <?php if (is_user_logged_in()) : ?>
        <details class="mft-account">
          <summary class="mft-icon-btn" aria-label="Mi cuenta"><?php echo mft_icon('user'); ?></summary>
          <div class="mft-account__menu">
            <span class="mft-account__name"><?php echo esc_html(wp_get_current_user()->first_name ?: wp_get_current_user()->user_email); ?></span>
            <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>">Mi cuenta</a>
            <a href="<?php echo esc_url(wc_logout_url(home_url('/'))); ?>"><?php echo mft_icon('log-out'); ?> Cerrar sesión</a>
          </div>
        </details>
      <?php else : ?>
        <a class="mft-icon-btn mft-hide-mobile" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" aria-label="Iniciar sesión"><?php echo mft_icon('user'); ?></a>
      <?php endif; ?>

      <button class="mft-icon-btn mft-show-mobile" type="button" data-mft-menu-open aria-label="Abrir menú" aria-controls="mft-drawer" aria-expanded="false">
        <?php echo mft_icon('menu'); ?>
      </button>
    </div>
  </div>
</header>

<div class="mft-drawer" id="mft-drawer" hidden>
  <div class="mft-drawer__backdrop" data-mft-menu-close></div>
  <nav class="mft-drawer__panel" aria-label="Menú">
    <button class="mft-icon-btn mft-drawer__close" type="button" data-mft-menu-close aria-label="Cerrar menú"><?php echo mft_icon('x'); ?></button>
    <?php mft_menu('primary', 'mft-drawer__links'); ?>
    <div class="mft-drawer__account">
      <?php if (is_user_logged_in()) : ?>
        <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php echo mft_icon('user'); ?> Mi cuenta</a>
        <a href="<?php echo esc_url(wc_logout_url(home_url('/'))); ?>"><?php echo mft_icon('log-out'); ?> Cerrar sesión</a>
      <?php else : ?>
        <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php echo mft_icon('user'); ?> Iniciar sesión</a>
      <?php endif; ?>
    </div>
  </nav>
</div>

<main class="mft-main">

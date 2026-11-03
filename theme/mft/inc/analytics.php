<?php
/**
 * AdSense y Meta Pixel: los mismos IDs que tenía el front en Next.js. Para
 * cambiar uno, usa el filtro `mft_analytics_ids` o deja el ID vacío para
 * desactivarlo.
 *
 * Google Analytics no va aquí: lo inserta Site Kit (con su Tag Manager) en todo
 * el sitio, también en las páginas de la Plataforma MFT. Ponerlo además aquí
 * contaría cada visita dos veces.
 */

defined('ABSPATH') || exit;

function mft_analytics_ids(): array
{
    return apply_filters('mft_analytics_ids', [
        'ga' => '',
        'adsense' => 'ca-pub-1700369962327200',
        'pixel' => '1026465010381352',
    ]);
}

add_action('wp_head', function () {
    if (is_admin() || is_preview()) {
        return;
    }
    $ids = mft_analytics_ids();

    if ($ids['adsense']) {
        echo '<meta name="google-adsense-account" content="' . esc_attr($ids['adsense']) . '">' . "\n";
        echo '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . esc_attr($ids['adsense']) . '" crossorigin="anonymous"></script>' . "\n";
    }

    if ($ids['ga']) {
        $ga = esc_js($ids['ga']);
        echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr($ids['ga']) . '"></script>' . "\n";
        echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$ga}');</script>\n";
    }

    if ($ids['pixel']) {
        $pixel = esc_js($ids['pixel']);
        echo "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','{$pixel}');fbq('track','PageView');</script>\n";
        echo '<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id=' . esc_attr($ids['pixel']) . '&amp;ev=PageView&amp;noscript=1"></noscript>' . "\n";
    }
}, 20);

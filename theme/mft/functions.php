<?php
/**
 * Tema MFT. Cada archivo de inc/ agrupa una responsabilidad:
 *   setup.php      soportes del tema, menús, estilos/scripts
 *   content.php    logo, contacto y slides (datos de los CPT/opciones de WordPress)
 *   catalog.php    catálogo: línea de negocio de cada producto, boletos y precios
 *   product.php    ficha: complementarios, recomendados, mapa y agregar al carrito
 *   checkout.php   checkout clásico, quitar artículos y comprobante de transferencia
 *   itineraries.php  /itinerarios/ y /itinerarios/<categoría>/
 *   print-center.php servicios y formulario de cotización del Print Center
 *   redirects.php  URLs del front anterior (Next.js) que cambiaron
 *   icons.php      íconos SVG en línea
 *   analytics.php  GA4, AdSense y Meta Pixel
 */

defined('ABSPATH') || exit;

define('MFT_THEME_VERSION', '0.1.0');

foreach (['setup', 'content', 'catalog', 'product', 'checkout', 'itineraries', 'print-center', 'redirects', 'icons', 'analytics'] as $mft_file) {
    require_once __DIR__ . "/inc/{$mft_file}.php";
}
unset($mft_file);

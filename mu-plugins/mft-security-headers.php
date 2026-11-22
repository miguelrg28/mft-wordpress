<?php
/**
 * Plugin Name: MFT Cabeceras de seguridad
 * Description: HSTS en www y en el panel: el navegador usa siempre HTTPS durante un año (PCI DSS 4.2.1).
 *
 * Sin includeSubDomains ni preload: así no obliga a HTTPS a otros subdominios
 * (print., n.) que no dependen de este sitio. Los navegadores ignoran la
 * cabecera si llega por HTTP, así que se envía siempre.
 */

defined('ABSPATH') || exit;

if (!headers_sent() && PHP_SAPI !== 'cli') {
    header('Strict-Transport-Security: max-age=31536000');
}

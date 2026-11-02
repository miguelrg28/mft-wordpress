<?php
/**
 * Print Center: servicios que se muestran en /print-center/ y el formulario de
 * cotización, que llega por correo al email de Ajustes → Contacto MFT.
 */

defined('ABSPATH') || exit;

function mft_print_services(): array
{
    return apply_filters('mft_print_services', [
        [
            'name' => 'Impresión de Gran Formato',
            'description' => 'Vallas, banners y material POP de alta calidad para eventos corporativos y campañas publicitarias.',
            'price' => 'Desde RD$ 2,500',
            'image' => 'https://images.unsplash.com/photo-1565688534245-05d6b5be184a?w=800&q=80',
        ],
        [
            'name' => 'Material Corporativo Premium',
            'description' => 'Tarjetas de presentación, folders, membretes y papelería institucional con acabados de lujo.',
            'price' => 'Desde RD$ 1,800',
            'image' => 'https://images.unsplash.com/photo-1586281380349-632531db7ed4?w=800&q=80',
        ],
        [
            'name' => 'Branding para Eventos',
            'description' => 'Acreditaciones, señalética, backdrop y material promocional completo para tu evento.',
            'price' => 'Desde RD$ 5,000',
            'image' => 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=800&q=80',
        ],
    ]);
}

/**
 * Envía la cotización por correo y vuelve a la página con ?cotizacion=enviada
 * (o =error). Tiene un campo trampa ("website") que las personas no ven: si
 * viene lleno es un bot y se descarta sin avisar.
 */
function mft_handle_print_quote(): void
{
    $back = wp_get_referer() ?: home_url('/print-center/');
    $back = remove_query_arg('cotizacion', $back);

    if (!wp_verify_nonce($_POST['mft_print_nonce'] ?? '', 'mft_print_quote')) {
        wp_safe_redirect(add_query_arg('cotizacion', 'error', $back) . '#cotizar');
        exit;
    }
    if (!empty($_POST['website'])) {
        wp_safe_redirect(add_query_arg('cotizacion', 'enviada', $back) . '#cotizar');
        exit;
    }

    $fields = [
        'Nombre' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
        'Empresa' => sanitize_text_field(wp_unslash($_POST['company'] ?? '')),
        'Correo' => sanitize_email(wp_unslash($_POST['email'] ?? '')),
        'Teléfono' => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
        'Servicio' => sanitize_text_field(wp_unslash($_POST['service'] ?? '')),
        'Proyecto' => sanitize_textarea_field(wp_unslash($_POST['message'] ?? '')),
    ];
    if (!$fields['Nombre'] || !is_email($fields['Correo']) || !$fields['Servicio']) {
        wp_safe_redirect(add_query_arg('cotizacion', 'incompleta', $back) . '#cotizar');
        exit;
    }

    $body = '';
    foreach ($fields as $label => $value) {
        $body .= $label . ': ' . ($value !== '' ? $value : '—') . "\n";
    }
    $to = apply_filters('mft_print_quote_email', mft_contact()['email'] ?: get_option('admin_email'));
    $sent = wp_mail(
        $to,
        'Cotización Print Center: ' . $fields['Servicio'] . ' — ' . $fields['Nombre'],
        $body,
        ['Reply-To: ' . $fields['Nombre'] . ' <' . $fields['Correo'] . '>']
    );

    wp_safe_redirect(add_query_arg('cotizacion', $sent ? 'enviada' : 'error', $back) . '#cotizar');
    exit;
}
add_action('admin_post_nopriv_mft_print_quote', 'mft_handle_print_quote');
add_action('admin_post_mft_print_quote', 'mft_handle_print_quote');

<?php
/**
 * Plugin Name: MFT Mantenimiento
 * Description: Interruptor de modo mantenimiento en la barra de administración. Con el modo activo, el público ve la página bilingüe de mft-maintenance/maintenance.html (503) y quien tiene sesión de editor o administrador ve el sitio normal.
 *
 * Cómo funciona:
 *   - Activar = crear `maintenance.on` en la raíz de WordPress; desactivar = borrarlo.
 *   - Nginx (Vhost del sitio) ve ese archivo y responde la página de mantenimiento
 *     sin tocar PHP. Las visitas con sesión iniciada pasan a WordPress, y aquí
 *     se decide: editores y administradores ven el sitio; el resto, la misma página.
 *   - Si el Vhost no tuviera la regla, este plugin igual muestra la página.
 *   - La API (/wp-json, retorno de AZUL), el AJAX, el cron, el login y el panel
 *     siguen funcionando con el modo activo.
 */

defined('ABSPATH') || exit;

const MFT_MAINTENANCE_FLAG = ABSPATH . 'maintenance.on';
const MFT_MAINTENANCE_PAGE = ABSPATH . 'maintenance.html';

function mft_maintenance_on(): bool
{
    return file_exists(MFT_MAINTENANCE_FLAG);
}

/** ¿Esta persona ve el sitio aunque esté en mantenimiento? */
function mft_maintenance_can_bypass(): bool
{
    return current_user_can('edit_posts');
}

/** Copia la página del repo a la raíz de WordPress, que es donde la busca Nginx. */
function mft_maintenance_install_page(): void
{
    $source = __DIR__ . '/mft-maintenance/maintenance.html';
    if (is_readable($source) && (!file_exists(MFT_MAINTENANCE_PAGE) || md5_file($source) !== md5_file(MFT_MAINTENANCE_PAGE))) {
        copy($source, MFT_MAINTENANCE_PAGE);
    }
}

function mft_maintenance_set(bool $on): bool
{
    mft_maintenance_install_page();
    if ($on) {
        return mft_maintenance_on() || false !== file_put_contents(MFT_MAINTENANCE_FLAG, '');
    }
    return !mft_maintenance_on() || unlink(MFT_MAINTENANCE_FLAG);
}

// --- La página de mantenimiento para el público -----------------------------
add_action('template_redirect', function () {
    if (!mft_maintenance_on() || mft_maintenance_can_bypass()) {
        return;
    }
    if ((defined('REST_REQUEST') && REST_REQUEST) || wp_doing_ajax() || wp_doing_cron()) {
        return;
    }
    status_header(503);
    header('Retry-After: 3600');
    header('Cache-Control: no-store');
    nocache_headers();
    $page = __DIR__ . '/mft-maintenance/maintenance.html';
    if (is_readable($page)) {
        readfile($page);
    } else {
        echo 'En mantenimiento · Under maintenance';
    }
    exit;
}, -1);

// --- Interruptor ---------------------------------------------------------------
add_action('admin_post_mft_maintenance_toggle', function () {
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permiso para cambiar el modo mantenimiento.', 403);
    }
    check_admin_referer('mft_maintenance_toggle');
    $on = ($_GET['estado'] ?? '') === 'activar';
    if (!mft_maintenance_set($on)) {
        wp_die('No se pudo cambiar el modo mantenimiento: WordPress no puede escribir en ' . esc_html(ABSPATH) . '.');
    }
    wp_safe_redirect(add_query_arg('mft_mantenimiento', $on ? 'activado' : 'desactivado', wp_get_referer() ?: admin_url()));
    exit;
});

function mft_maintenance_toggle_url(bool $on): string
{
    return wp_nonce_url(
        add_query_arg(['action' => 'mft_maintenance_toggle', 'estado' => $on ? 'activar' : 'desactivar'], admin_url('admin-post.php')),
        'mft_maintenance_toggle'
    );
}

// Botón en la barra de administración (panel y sitio).
add_action('admin_bar_menu', function (WP_Admin_Bar $bar) {
    if (!current_user_can('manage_options')) {
        return;
    }
    $on = mft_maintenance_on();
    $bar->add_node([
        'id' => 'mft-maintenance',
        'title' => '<span class="mft-mnt-dot"></span>' . ($on ? 'Mantenimiento: ACTIVO' : 'Sitio en línea'),
        'href' => admin_url('options-general.php?page=mft-maintenance'),
        'meta' => ['class' => $on ? 'mft-mnt-on' : 'mft-mnt-off'],
    ]);
    $bar->add_node([
        'parent' => 'mft-maintenance',
        'id' => 'mft-maintenance-toggle',
        'title' => $on ? 'Desactivar mantenimiento (abrir el sitio)' : 'Activar mantenimiento',
        'href' => mft_maintenance_toggle_url(!$on),
        'meta' => $on ? [] : ['onclick' => "return confirm('¿Poner el sitio en mantenimiento? El público dejará de verlo.');"],
    ]);
}, 100);

function mft_maintenance_bar_style(): void
{
    if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
        return;
    }
    echo '<style>#wpadminbar .mft-mnt-on > .ab-item{background:#FF0055;color:#fff;font-weight:600}'
        . '#wpadminbar .mft-mnt-off > .ab-item{color:#34C759}'
        . '#wpadminbar .mft-mnt-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:currentColor;margin-right:6px;vertical-align:middle}</style>';
}
add_action('wp_head', 'mft_maintenance_bar_style');
add_action('admin_head', 'mft_maintenance_bar_style');

// Página Ajustes → Mantenimiento, con el interruptor grande y la vista previa.
add_action('admin_menu', function () {
    add_options_page('Mantenimiento', 'Mantenimiento', 'manage_options', 'mft-maintenance', function () {
        $on = mft_maintenance_on();
        ?>
        <div class="wrap">
          <h1>Modo mantenimiento</h1>
          <p style="font-size:15px">Estado actual:
            <strong style="color:<?php echo $on ? '#d63638' : '#00a32a'; ?>"><?php echo $on ? 'ACTIVO: el público ve la página de mantenimiento.' : 'Sitio en línea: el público ve el sitio.'; ?></strong>
          </p>
          <p>Con el modo activo, tú (y cualquier editor o administrador con sesión iniciada) sigues viendo el sitio normal. La API, el retorno de AZUL y el panel siguen funcionando.</p>
          <p>
            <a class="button button-primary button-hero" href="<?php echo esc_url(mft_maintenance_toggle_url(!$on)); ?>"
               <?php echo $on ? '' : 'onclick="return confirm(\'¿Poner el sitio en mantenimiento? El público dejará de verlo.\');"'; ?>>
              <?php echo $on ? 'Desactivar mantenimiento (abrir el sitio)' : 'Activar mantenimiento'; ?>
            </a>
          </p>
          <h2>Vista previa de la página</h2>
          <iframe src="<?php echo esc_url(plugins_url('mft-maintenance/maintenance.html', __FILE__)); ?>" style="width:100%;max-width:900px;height:620px;border:1px solid #c3c4c7;border-radius:4px;background:#0A0A0A"></iframe>
        </div>
        <?php
    });
});

// Aviso arriba del panel mientras el modo está activo, y confirmación al cambiarlo.
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    $changed = sanitize_key($_GET['mft_mantenimiento'] ?? '');
    if ($changed) {
        $text = $changed === 'activado' ? 'Modo mantenimiento activado.' : 'Modo mantenimiento desactivado: el sitio está abierto al público.';
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }
    if (mft_maintenance_on() && ($_GET['page'] ?? '') !== 'mft-maintenance') {
        echo '<div class="notice notice-warning"><p><strong>El sitio está en modo mantenimiento.</strong> El público ve la página de mantenimiento. '
            . '<a href="' . esc_url(admin_url('options-general.php?page=mft-maintenance')) . '">Cambiar</a></p></div>';
    }
});

// Que la página de Nginx esté siempre al día con la del repo (tras cada despliegue).
add_action('admin_init', 'mft_maintenance_install_page');

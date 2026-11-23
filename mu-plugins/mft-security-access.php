<?php
/**
 * Plugin Name: MFT Seguridad de acceso
 * Description: Bloqueo por intentos fallidos, cierre de sesión por inactividad, reglas de contraseña y registro de actividad (PCI DSS 8.2.8, 8.3.4, 8.3.6, 8.3.7 y 10.2).
 *
 * Es un mu-plugin para que nadie pueda desactivarlo desde el admin.
 *
 * - Equipo: cuentas con cualquier rol que no sea de cliente (cliente, suscriptor,
 *   participante MFT o contacto del CRM; ver MFT_SEC_CONSUMER_ROLES). PCI no exige
 *   estas reglas a las cuentas de clientes, así que el checkout no cambia.
 * - Bloqueo (todas las cuentas): 10 intentos fallidos seguidos bloquean la cuenta 30
 *   minutos; restablecer la contraseña la desbloquea. 30 fallos desde una misma IP
 *   bloquean esa IP 30 minutos.
 * - Inactividad (equipo): la sesión se cierra tras 15 minutos sin actividad. El latido
 *   automático del admin (heartbeat) no cuenta como actividad.
 * - Contraseñas (equipo): 12 caracteres o más, con letras y números, y distinta de las
 *   4 últimas.
 * - Registro: inicios y cierres de sesión, fallos, bloqueos, cuentas, roles y
 *   contraseñas, plugins y temas, y toda acción del equipo que modifique algo. Va al
 *   syslog del servidor (/var/log/auth.log, se guarda 400 días), fuera del alcance de
 *   WordPress. Nunca se registra el contenido de formularios ni contraseñas.
 */

defined('ABSPATH') || exit;

const MFT_SEC_MAX_FAILS = 10;
const MFT_SEC_IP_MAX_FAILS = 30;
const MFT_SEC_LOCK_SECONDS = 1800;
const MFT_SEC_IDLE_SECONDS = 900;
const MFT_SEC_PASSWORD_MIN = 12;
const MFT_SEC_PASSWORD_HISTORY = 4; // la actual y las 3 anteriores
// Roles de clientes y participantes: no son equipo.
const MFT_SEC_CONSUMER_ROLES = ['customer', 'subscriber', 'mft_participant', 'zerobs_customer'];

// ---------------------------------------------------------------- utilidades

/** IP del visitante. Detrás de Varnish REMOTE_ADDR es local: se toma la última IP pública que añadió nuestro Nginx. */
function mft_sec_ip(): string
{
    $public = static function ($ip): bool {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    };
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($public($remote)) {
        return $remote;
    }
    $chain = array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
    foreach ($chain as $hop) {
        if ($public($hop)) {
            return $hop;
        }
    }
    return $remote !== '' ? $remote : 'desconocida';
}

/** ¿Cuenta del equipo? (algún rol que no sea de cliente). */
function mft_sec_is_staff($user): bool
{
    if (!$user instanceof WP_User || !$user->exists()) {
        return false;
    }
    return (bool) array_diff((array) $user->roles, MFT_SEC_CONSUMER_ROLES);
}

function mft_sec_label($user): string
{
    return $user instanceof WP_User && $user->exists() ? $user->user_login . '#' . $user->ID : '-';
}

/**
 * Una línea en el syslog: evento, resultado, usuario, IP, sitio y los datos extra.
 * Si el syslog no está disponible, va al log de errores de PHP.
 */
function mft_sec_log(string $event, array $data = [], bool $ok = true): void
{
    static $opened = null;
    $user = array_key_exists('usuario', $data) ? $data['usuario'] : mft_sec_label(wp_get_current_user());
    if ($user === '-' && defined('WP_CLI') && WP_CLI) {
        $user = 'wp-cli'; // quién lo corrió queda en el registro de SSH del servidor
    }
    unset($data['usuario']);
    $fields = [
        'evento' => $event,
        'resultado' => $ok ? 'ok' : 'fallo',
        'usuario' => $user,
        'ip' => mft_sec_ip(),
        'sitio' => (string) ($_SERVER['HTTP_HOST'] ?? '-'),
    ] + $data;
    $parts = [];
    foreach ($fields as $key => $value) {
        $value = is_scalar($value) || $value === null ? (string) $value : (string) wp_json_encode($value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
        $parts[] = $key . '=' . (preg_match('/[\s"]/', $value) ? '"' . str_replace('"', "'", $value) . '"' : $value);
    }
    $line = implode(' ', $parts);
    if ($opened === null) {
        $opened = function_exists('openlog') && openlog('mft-wordpress', LOG_PID, LOG_AUTH);
    }
    if (!$opened || !syslog(LOG_NOTICE, $line)) {
        error_log('mft-wordpress: ' . $line);
    }
}

// ---------------------------------------------------------------- bloqueo por intentos fallidos (8.3.4)

function mft_sec_find_user(string $login)
{
    $login = trim($login);
    if ($login === '') {
        return false;
    }
    $user = get_user_by('login', $login);
    if (!$user && is_email($login)) {
        $user = get_user_by('email', $login);
    }
    return $user;
}

/** Fallos de una cuenta: n = seguidos, last = último fallo, until = bloqueada hasta. */
function mft_sec_fail_state(int $user_id): array
{
    $state = get_user_meta($user_id, '_mft_sec_fails', true);
    $state = (is_array($state) ? $state : []) + ['n' => 0, 'last' => 0, 'until' => 0];
    // Pasados 30 minutos sin fallos y sin bloqueo vigente, empieza de cero.
    if ($state['until'] <= time() && time() - (int) $state['last'] > MFT_SEC_LOCK_SECONDS) {
        $state = ['n' => 0, 'last' => 0, 'until' => 0];
    }
    return $state;
}

function mft_sec_ip_key(): string
{
    return 'mft_sec_ip_' . md5(mft_sec_ip());
}

function mft_sec_lock_error(): WP_Error
{
    return new WP_Error(
        'mft_locked',
        sprintf(
            '<strong>Acceso bloqueado temporalmente</strong> por demasiados intentos fallidos. Inténtalo de nuevo en %d minutos o <a href="%s">restablece tu contraseña</a>.',
            (int) ceil(MFT_SEC_LOCK_SECONDS / 60),
            esc_url(wp_lostpassword_url())
        )
    );
}

// Prioridad 99: después de que WordPress comprobó la contraseña, para que nada lo deshaga.
add_filter('authenticate', function ($user, $username, $password) {
    if ($username === '' && $password === '' && !$user instanceof WP_User) {
        return $user;
    }
    $ip_state = get_transient(mft_sec_ip_key());
    if (is_array($ip_state) && ($ip_state['until'] ?? 0) > time()) {
        return mft_sec_lock_error();
    }
    $target = $user instanceof WP_User ? $user : mft_sec_find_user((string) $username);
    if ($target && mft_sec_fail_state($target->ID)['until'] > time()) {
        return mft_sec_lock_error();
    }
    return $user;
}, 99, 3);

add_action('wp_login_failed', function ($username, $error = null) {
    $account = mft_sec_find_user((string) $username);
    // Si la cuenta no existe no se escribe lo tecleado: a veces es la contraseña.
    $who = $account ? mft_sec_label($account) : 'desconocida';
    $reason = $error instanceof WP_Error ? $error->get_error_code() : '-';

    if ($reason === 'mft_locked') {
        mft_sec_log('login_rechazado_bloqueo', ['usuario' => $who], false);
        return;
    }

    $ip_state = get_transient(mft_sec_ip_key());
    $ip_state = (is_array($ip_state) ? $ip_state : []) + ['n' => 0, 'until' => 0];
    $ip_state['n']++;
    if ($ip_state['n'] >= MFT_SEC_IP_MAX_FAILS && $ip_state['until'] <= time()) {
        $ip_state['until'] = time() + MFT_SEC_LOCK_SECONDS;
        mft_sec_log('ip_bloqueada', ['usuario' => $who, 'intentos' => $ip_state['n']], false);
    }
    set_transient(mft_sec_ip_key(), $ip_state, MFT_SEC_LOCK_SECONDS);

    $data = ['usuario' => $who, 'motivo' => $reason];
    if ($account) {
        $state = mft_sec_fail_state($account->ID);
        $state['n']++;
        $state['last'] = time();
        if ($state['n'] >= MFT_SEC_MAX_FAILS && $state['until'] <= time()) {
            $state['until'] = time() + MFT_SEC_LOCK_SECONDS;
            mft_sec_log('cuenta_bloqueada', ['usuario' => $who, 'intentos' => $state['n']], false);
        }
        update_user_meta($account->ID, '_mft_sec_fails', $state);
        $data['intentos'] = $state['n'];
    }
    mft_sec_log('login', $data, false);
}, 10, 2);

add_action('wp_login', function ($login, $user) {
    delete_user_meta($user->ID, '_mft_sec_fails');
    mft_sec_log('login', ['usuario' => mft_sec_label($user), 'roles' => implode(',', (array) $user->roles)]);
}, 10, 2);

// ---------------------------------------------------------------- cierre por inactividad (8.2.8)

// Cada sesión nueva arranca con su hora de actividad.
add_filter('attach_session_information', function ($session) {
    $session['mft_last_activity'] = time();
    return $session;
});

add_action('init', function () {
    if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI) || !is_user_logged_in()) {
        return;
    }
    $user = wp_get_current_user();
    $token = wp_get_session_token();
    if (!mft_sec_is_staff($user) || $token === '') {
        return; // clientes, o acceso sin sesión de navegador (contraseñas de aplicación)
    }
    $manager = WP_Session_Tokens::get_instance($user->ID);
    $session = $manager->get($token);
    if (!is_array($session)) {
        return;
    }
    $now = time();
    $last = (int) ($session['mft_last_activity'] ?? 0);

    if ($last > 0 && $now - $last > MFT_SEC_IDLE_SECONDS) {
        mft_sec_log('sesion_expirada', ['usuario' => mft_sec_label($user), 'inactivo_min' => intdiv($now - $last, 60)]);
        $GLOBALS['mft_sec_idle_logout'] = true;
        wp_logout();
        if (is_admin() && !wp_doing_ajax()) {
            $here = (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
            wp_safe_redirect(add_query_arg('mft_idle', '1', wp_login_url($here)));
            exit;
        }
        return; // en el sitio público sigue como visitante; el AJAX ve la sesión cerrada
    }

    $is_heartbeat = wp_doing_ajax() && (($_REQUEST['action'] ?? '') === 'heartbeat');
    if (!$is_heartbeat && $now - $last >= 60) {
        $session['mft_last_activity'] = $now;
        $manager->update($token, $session);
    }
}, 1);

add_filter('login_message', function ($message) {
    if (isset($_GET['mft_idle'])) {
        $message .= '<p class="message">Tu sesión se cerró tras 15 minutos de inactividad. Vuelve a entrar.</p>';
    }
    return $message;
});

add_action('wp_logout', function ($user_id = 0) {
    if (empty($GLOBALS['mft_sec_idle_logout'])) {
        mft_sec_log('logout', ['usuario' => mft_sec_label(get_userdata((int) $user_id))]);
    }
});

// ---------------------------------------------------------------- contraseñas del equipo (8.3.6, 8.3.7)

/** Errores de una contraseña nueva; $user es la cuenta existente (para el historial) o null si es nueva. */
function mft_sec_password_problems(string $password, $user): array
{
    $problems = [];
    if (mb_strlen($password) < MFT_SEC_PASSWORD_MIN) {
        $problems[] = sprintf('La contraseña debe tener al menos %d caracteres.', MFT_SEC_PASSWORD_MIN);
    }
    if (!preg_match('/\pL/u', $password) || !preg_match('/\d/', $password)) {
        $problems[] = 'La contraseña debe tener letras y números.';
    }
    if ($user instanceof WP_User && $user->exists()) {
        $history = get_user_meta($user->ID, '_mft_sec_pass_history', true);
        $hashes = array_merge([$user->user_pass], is_array($history) ? $history : []);
        foreach (array_slice(array_filter($hashes), 0, MFT_SEC_PASSWORD_HISTORY) as $hash) {
            if (wp_check_password($password, $hash)) {
                $problems[] = sprintf('La contraseña no puede ser igual a ninguna de las últimas %d.', MFT_SEC_PASSWORD_HISTORY);
                break;
            }
        }
    }
    return $problems;
}

function mft_sec_add_problems(WP_Error $errors, array $problems): void
{
    foreach ($problems as $i => $problem) {
        $errors->add('mft_password_' . $i, $problem);
    }
}

// Perfil y "Añadir usuario" del admin.
add_action('user_profile_update_errors', function ($errors, $update, $data) {
    if (empty($data->user_pass)) {
        return;
    }
    $existing = $update && !empty($data->ID) ? get_userdata((int) $data->ID) : null;
    $roles = $existing ? (array) $existing->roles : [];
    if (!empty($data->role)) {
        $roles[] = $data->role;
    }
    if (array_diff($roles, MFT_SEC_CONSUMER_ROLES)) {
        mft_sec_add_problems($errors, mft_sec_password_problems((string) $data->user_pass, $existing ?: null));
    }
}, 10, 3);

// Restablecer contraseña (WordPress y Mi cuenta de WooCommerce).
add_action('validate_password_reset', function ($errors, $user) {
    $password = (string) ($_POST['pass1'] ?? $_POST['password_1'] ?? '');
    if ($password !== '' && $user instanceof WP_User && mft_sec_is_staff($user)) {
        mft_sec_add_problems($errors, mft_sec_password_problems($password, $user));
    }
}, 10, 2);

// Cambio de contraseña en Mi cuenta de WooCommerce.
add_action('woocommerce_save_account_details_errors', function ($errors, $data) {
    $user = !empty($data->ID) ? get_userdata((int) $data->ID) : null;
    if (!empty($data->user_pass) && mft_sec_is_staff($user)) {
        mft_sec_add_problems($errors, mft_sec_password_problems((string) $data->user_pass, $user));
    }
}, 10, 2);

/** Guarda el hash anterior en el historial (solo los necesarios para comparar). */
function mft_sec_remember_hash(int $user_id, string $old_hash): void
{
    if ($old_hash === '') {
        return;
    }
    $history = get_user_meta($user_id, '_mft_sec_pass_history', true);
    $history = array_slice(array_merge([$old_hash], is_array($history) ? $history : []), 0, MFT_SEC_PASSWORD_HISTORY - 1);
    update_user_meta($user_id, '_mft_sec_pass_history', $history);
}

add_action('profile_update', function ($user_id, $old_user_data = null) {
    $new = get_userdata((int) $user_id);
    if ($new && $old_user_data instanceof WP_User && $old_user_data->user_pass !== $new->user_pass) {
        mft_sec_remember_hash((int) $user_id, (string) $old_user_data->user_pass);
        mft_sec_log('contrasena_cambiada', ['afectado' => mft_sec_label($new)]);
    }
}, 10, 2);

add_action('password_reset', function ($user) {
    mft_sec_remember_hash($user->ID, (string) $user->user_pass);
});

add_action('after_password_reset', function ($user) {
    delete_user_meta($user->ID, '_mft_sec_fails'); // restablecer la contraseña desbloquea la cuenta
    mft_sec_log('contrasena_restablecida', ['afectado' => mft_sec_label($user)]);
});

// ---------------------------------------------------------------- registro de actividad (10.2.1.x)

add_action('retrieve_password', function ($login) {
    mft_sec_log('restablecimiento_solicitado', ['afectado' => mft_sec_label(mft_sec_find_user((string) $login))]);
});

add_action('user_register', function ($user_id) {
    $user = get_userdata((int) $user_id);
    mft_sec_log('cuenta_creada', ['afectado' => mft_sec_label($user), 'roles' => $user ? implode(',', (array) $user->roles) : '-']);
});

add_action('delete_user', function ($user_id) {
    mft_sec_log('cuenta_eliminada', ['afectado' => mft_sec_label(get_userdata((int) $user_id))]);
});

add_action('set_user_role', function ($user_id, $role, $old_roles = []) {
    mft_sec_log('rol_cambiado', ['afectado' => mft_sec_label(get_userdata((int) $user_id)), 'antes' => implode(',', (array) $old_roles), 'ahora' => $role]);
}, 10, 3);

add_action('add_user_role', function ($user_id, $role) {
    mft_sec_log('rol_agregado', ['afectado' => mft_sec_label(get_userdata((int) $user_id)), 'rol' => $role]);
}, 10, 2);

add_action('remove_user_role', function ($user_id, $role) {
    mft_sec_log('rol_quitado', ['afectado' => mft_sec_label(get_userdata((int) $user_id)), 'rol' => $role]);
}, 10, 2);

add_action('wp_create_application_password', function ($user_id, $item = []) {
    mft_sec_log('contrasena_aplicacion_creada', ['afectado' => mft_sec_label(get_userdata((int) $user_id)), 'nombre' => $item['name'] ?? '-']);
}, 10, 2);

add_action('wp_delete_application_password', function ($user_id, $item = []) {
    mft_sec_log('contrasena_aplicacion_borrada', ['afectado' => mft_sec_label(get_userdata((int) $user_id)), 'nombre' => $item['name'] ?? '-']);
}, 10, 2);

add_action('activated_plugin', fn($plugin) => mft_sec_log('plugin_activado', ['plugin' => $plugin]));
add_action('deactivated_plugin', fn($plugin) => mft_sec_log('plugin_desactivado', ['plugin' => $plugin]));
add_action('deleted_plugin', fn($plugin, $deleted = true) => mft_sec_log('plugin_borrado', ['plugin' => $plugin], (bool) $deleted), 10, 2);
add_action('switch_theme', fn($name) => mft_sec_log('tema_cambiado', ['tema' => $name]));
add_action('upgrader_process_complete', function ($upgrader, $extra = []) {
    $items = $extra['plugins'] ?? $extra['themes'] ?? (isset($extra['plugin']) ? [$extra['plugin']] : (isset($extra['theme']) ? [$extra['theme']] : []));
    mft_sec_log('instalacion', ['tipo' => $extra['type'] ?? '-', 'accion' => $extra['action'] ?? '-', 'elementos' => implode(',', (array) $items)]);
}, 10, 2);

// Toda acción del equipo que modifica algo: POST/PUT/PATCH/DELETE al admin, AJAX o API,
// y enlaces del admin con ?action= (papelera, activar…). Solo la ruta y unos pocos
// parámetros, nunca el contenido del formulario.
add_action('init', function () {
    if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI) || !is_user_logged_in()) {
        return;
    }
    $user = wp_get_current_user();
    if (!mft_sec_is_staff($user)) {
        return;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = sanitize_key((string) ($_REQUEST['action'] ?? ''));
    if ($action === 'heartbeat') {
        return;
    }
    $writes = !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
    $admin_link = $method === 'GET' && is_admin() && !wp_doing_ajax() && $action !== '' && $action !== 'edit';
    if (!$writes && !$admin_link) {
        return;
    }
    $data = [
        'usuario' => mft_sec_label($user),
        'metodo' => $method,
        'ruta' => (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
    ];
    foreach (['action', 'page', 'tab', 'section', 'post', 'post_type', 'taxonomy', 'plugin', 'user_id'] as $key) {
        if (isset($_REQUEST[$key]) && is_scalar($_REQUEST[$key])) {
            $data[$key] = substr(sanitize_text_field((string) $_REQUEST[$key]), 0, 100);
        }
    }
    mft_sec_log('accion', $data);
}, 2);

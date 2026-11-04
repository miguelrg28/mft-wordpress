<?php
/**
 * Plugin Name: MFT AZUL API (pago con tarjeta + 3D Secure 2.0)
 * Description: Método de pago de WooCommerce que cobra con tarjeta dentro del sitio usando la "Integración vía API" de AZUL (JSON + autenticación mutua) con 3D Secure 2.0. Convive con la "Página de Pago AZUL": son métodos distintos.
 * Version: 0.2.0
 * Requires Plugins: woocommerce
 *
 * ---------------------------------------------------------------------------
 * PCI DSS: con este método el número de tarjeta PASA por este servidor, por lo
 * que AZUL exige el Cuestionario de autoevaluación D (SAQ D) + Atestación.
 * Reglas que este código respeta y que NO se deben romper:
 *   - Nunca se guarda PAN/CVC/vencimiento (ni en BD, ni en logs, ni en notas).
 *   - Solo se guardan los últimos 4 dígitos y los datos de respuesta de AZUL.
 * ---------------------------------------------------------------------------
 *
 * CONFIGURACIÓN
 *  1. AZUL firma un certificado cliente a partir de nuestro CSR (RSA 4096).
 *     El certificado y la llave van FUERA del webroot (ej. /home/usuario/azul/).
 *  2. En wp-config.php (tienen prioridad sobre los ajustes del admin):
 *       define('MFT_AZUL_AUTH1', '...');
 *       define('MFT_AZUL_AUTH2', '...');
 *       define('MFT_AZUL_CERT_PATH', '/home/usuario/azul/cert.pem');
 *       define('MFT_AZUL_KEY_PATH',  '/home/usuario/azul/key.pem');
 *       define('MFT_AZUL_KEY_PASS',  '');   // si la llave tiene contraseña
 *     En pruebas AZUL da dos pares Auth1/Auth2: "splitit" (sin 3DS) y
 *     "3dsecure". Este método siempre usa 3D Secure: va el de 3dsecure.
 *  3. WooCommerce → Ajustes → Pagos → "Tarjeta de crédito/débito (AZUL API)":
 *     ambiente, Channel, Store (MID)…
 *  4. Si cobra recargo, configúralo en el plugin de fees también para
 *     `mft_azul_api`, y permítelo en "Payment Gateways per Product".
 *
 * FLUJO
 *  Checkout → la orden queda `pending` y el cliente va a la página de pago de la
 *  orden (order-pay), donde está el formulario de tarjeta (assets/pay.js):
 *    POST mft/v1/azul-api/pay con tarjeta + BrowserInfo.
 *    - IsoCode 00          → orden pagada, va a "orden recibida".
 *    - IsoCode 3D2METHOD   → se monta el MethodForm (iframe oculto), se consulta
 *                            /method-status hasta 10 s y se llama /continue.
 *    - IsoCode 3D          → POST (creq) al RedirectPostUrl del ACS del banco;
 *                            el ACS vuelve a /term (TermUrl) y aquí se completa.
 *  Timeouts: VerifyPayment por cron (nunca se reintenta un cobro a ciegas).
 */

defined('ABSPATH') || exit;

define('MFT_AZUL_API_ID', 'mft_azul_api');
define('MFT_AZUL_API_VERSION', '0.2.0');

// Endpoints JSON. El método va en el query string (Default.aspx sin query =
// ProcessPayment).
const MFT_AZUL_API_URLS = [
    'test' => ['https://pruebas.azul.com.do/WebServices/JSON/Default.aspx'],
    'prod' => [
        'https://pagos.azul.com.do/WebServices/JSON/Default.aspx',
        'https://contpagos.azul.com.do/WebServices/JSON/Default.aspx', // sitio alterno
    ],
];

// ===========================================================================
// GATEWAY
// ===========================================================================

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class MFT_Azul_API_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = MFT_AZUL_API_ID;
            $this->method_title = 'Tarjeta de crédito/débito (AZUL API)';
            $this->method_description = 'Pago con tarjeta dentro del sitio (Integración vía API de AZUL + 3D Secure 2.0). El cliente escribe la tarjeta en la página de pago de la orden.';
            $this->has_fields = false; // la tarjeta se pide en la página de pago de la orden
            $this->supports = ['products', 'refunds'];

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            if ($this->is_test() && !is_admin()) {
                $this->title .= ' (PRUEBA)';
                $this->description .= ' Ambiente de pruebas: usa solo tarjetas de prueba de AZUL.';
            }

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
        }

        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => 'Activar',
                    'type' => 'checkbox',
                    'label' => 'Activar pago con tarjeta vía API de AZUL',
                    'default' => 'no',
                ],
                'only_admins' => [
                    'title' => 'Solo administradores y probadores',
                    'type' => 'checkbox',
                    'label' => 'Mostrar este método solo a quienes administran la tienda y a los probadores (para probarlo antes de abrirlo al público). En el ambiente de Pruebas siempre es así.',
                    'default' => 'yes',
                ],
                'testers' => [
                    'title' => 'Probadores',
                    'type' => 'textarea',
                    'default' => '',
                    'description' => 'Correos de cuentas del sitio que también ven este método, separados por coma o salto de línea. Deben ingresar con esa cuenta.',
                ],
                'title' => [
                    'title' => 'Título',
                    'type' => 'text',
                    'default' => 'Tarjeta de crédito o débito',
                ],
                'description' => [
                    'title' => 'Descripción',
                    'type' => 'textarea',
                    'default' => 'Paga con Visa, Mastercard o American Express de forma segura (3D Secure). Escribirás los datos de tu tarjeta en el siguiente paso.',
                ],
                'environment' => [
                    'title' => 'Ambiente',
                    'type' => 'select',
                    'options' => ['test' => 'Pruebas (pruebas.azul.com.do)', 'prod' => 'Producción'],
                    'default' => 'test',
                ],
                'channel' => [
                    'title' => 'Channel',
                    'type' => 'text',
                    'default' => 'EC',
                    'description' => 'Proporcionado por AZUL.',
                ],
                'store' => [
                    'title' => 'Store (Merchant ID)',
                    'type' => 'text',
                    'default' => '',
                ],
                'pos_input_mode' => [
                    'title' => 'PosInputMode',
                    'type' => 'text',
                    'default' => 'E-Commerce',
                ],
                'auth1' => [
                    'title' => 'Auth1',
                    'type' => 'password',
                    'default' => '',
                    'description' => 'Ignorado si existe la constante MFT_AZUL_AUTH1 en wp-config.php.',
                ],
                'auth2' => [
                    'title' => 'Auth2',
                    'type' => 'password',
                    'default' => '',
                    'description' => 'Ignorado si existe la constante MFT_AZUL_AUTH2.',
                ],
                'cert_path' => [
                    'title' => 'Ruta del certificado cliente (.pem)',
                    'type' => 'text',
                    'default' => '',
                    'description' => 'Ruta absoluta FUERA del webroot. Ignorado si existe MFT_AZUL_CERT_PATH.',
                ],
                'key_path' => [
                    'title' => 'Ruta de la llave privada (.pem)',
                    'type' => 'text',
                    'default' => '',
                    'description' => 'Ignorado si existe MFT_AZUL_KEY_PATH.',
                ],
                'customer_service_phone' => [
                    'title' => 'Teléfono de servicio al cliente',
                    'type' => 'text',
                    'default' => '',
                ],
                'ecommerce_url' => [
                    'title' => 'URL del comercio',
                    'type' => 'text',
                    'default' => 'masflowteam.net',
                ],
                'alt_merchant_name' => [
                    'title' => 'Nombre en estado de cuenta (máx. 25)',
                    'type' => 'text',
                    'default' => 'MAS FLOW TEAM',
                ],
                'itbis_mode' => [
                    'title' => 'ITBIS',
                    'type' => 'select',
                    'options' => [
                        'order' => 'Usar el impuesto calculado por WooCommerce',
                        'included_18' => 'Precios con ITBIS 18% incluido',
                        'exempt' => 'Exento (000)',
                    ],
                    'default' => 'order',
                ],
                'max_attempts' => [
                    'title' => 'Intentos máximos por orden',
                    'type' => 'number',
                    'default' => '5',
                ],
                'debug' => [
                    'title' => 'Log de depuración',
                    'type' => 'checkbox',
                    'label' => 'Registrar respuestas en WooCommerce → Estado → Registros (fuente mft-azul-api). Nunca registra datos de tarjeta.',
                    'default' => 'no',
                ],
            ];
        }

        /** Credencial: la constante de wp-config tiene prioridad sobre el ajuste. */
        public function secret($key)
        {
            $const = 'MFT_AZUL_' . strtoupper($key);
            if (defined($const)) {
                return (string) constant($const);
            }
            return (string) $this->get_option($key, '');
        }

        public function is_configured()
        {
            $cert = $this->secret('cert_path');
            $key = $this->secret('key_path');
            return $this->get_option('store') !== ''
                && $this->secret('auth1') !== ''
                && $this->secret('auth2') !== ''
                && $cert !== '' && is_readable($cert)
                && $key !== '' && is_readable($key);
        }

        public function is_test()
        {
            return $this->get_option('environment') !== 'prod';
        }

        /**
         * Administradores de la tienda y probadores (por correo). Las tarjetas de
         * prueba de AZUL son públicas: en Pruebas nadie más puede ver el método,
         * o cualquiera "pagaría" boletos sin pagar.
         */
        public function is_tester()
        {
            if (current_user_can('manage_woocommerce')) {
                return true;
            }
            $user = wp_get_current_user();
            if (!$user->exists()) {
                return false;
            }
            $emails = preg_split('/[\s,;]+/', strtolower((string) $this->get_option('testers', '')), -1, PREG_SPLIT_NO_EMPTY);
            return in_array(strtolower($user->user_email), $emails, true);
        }

        public function is_available()
        {
            if (($this->is_test() || $this->get_option('only_admins', 'yes') === 'yes') && !$this->is_tester()) {
                return false;
            }
            return parent::is_available() && $this->is_configured();
        }

        /**
         * Al colocar la orden no se cobra todavía: queda `pending` y el cliente
         * pasa a la página de pago de la orden, donde escribe la tarjeta.
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                return ['result' => 'failure'];
            }
            if (!$order->has_status('pending')) {
                $order->update_status('pending');
            }
            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            ];
        }

        /** Página de pago de la orden: formulario de tarjeta. */
        public function receipt_page($order_id)
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }
            $notice = (string) $order->get_meta('_mft_azul_notice');
            if ($notice !== '') {
                $order->delete_meta_data('_mft_azul_notice');
                $order->save();
            }

            wp_enqueue_style('mft-azul-api', plugins_url('assets/pay.css', __FILE__), [], MFT_AZUL_API_VERSION);
            wp_enqueue_script('mft-azul-api', plugins_url('assets/pay.js', __FILE__), [], MFT_AZUL_API_VERSION, true);
            wp_localize_script('mft-azul-api', 'mftAzul', [
                'api' => untrailingslashit(rest_url('mft/v1/azul-api')),
                'orderId' => $order->get_id(),
                'orderKey' => $order->get_order_key(),
            ]);
            ?>
            <div class="mft-azul" id="mft-azul" data-busy="0">
                <p class="mft-azul__notice" role="alert"<?php echo $notice === '' ? ' hidden' : ''; ?>><?php echo esc_html($notice); ?></p>
                <?php // Sin <form> ni atributos name: la tarjeta nunca viaja por un submit nativo. ?>
                <div class="mft-azul__field">
                    <label for="mft-cc-name">Nombre en la tarjeta</label>
                    <input id="mft-cc-name" type="text" autocomplete="cc-name" data-field="name">
                </div>
                <div class="mft-azul__field">
                    <label for="mft-cc-number">Número de tarjeta</label>
                    <input id="mft-cc-number" type="text" inputmode="numeric" autocomplete="cc-number" placeholder="0000 0000 0000 0000" data-field="number">
                </div>
                <div class="mft-azul__row">
                    <div class="mft-azul__field">
                        <label for="mft-cc-exp">Vencimiento</label>
                        <input id="mft-cc-exp" type="text" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/AA" data-field="exp">
                    </div>
                    <div class="mft-azul__field">
                        <label for="mft-cc-csc">CVC</label>
                        <input id="mft-cc-csc" type="text" inputmode="numeric" autocomplete="cc-csc" placeholder="123" data-field="cvc">
                    </div>
                </div>
                <p class="mft-azul__error" role="alert" hidden></p>
                <button type="button" class="button alt mft-azul__pay">
                    Pagar <?php echo wp_kses_post($order->get_formatted_order_total()); ?>
                </button>
                <?php // AZUL exige mostrar las marcas de 3D Secure en el checkout. ?>
                <p class="mft-azul__brands">Pago procesado por AZUL · Visa Secure · Mastercard ID Check · Amex SafeKey</p>
                <div class="mft-azul__overlay" hidden>
                    <span class="mft-azul__spinner" aria-hidden="true"></span>
                    <strong class="mft-azul__stage">Procesando tu pago...</strong>
                    <span>No cierres ni recargues esta ventana.</span>
                </div>
            </div>
            <?php
        }

        /**
         * Reembolso desde el admin: anulación (ProcessVoid) si es total y dentro de
         * los 20 minutos de aprobada; si no, devolución (Refund).
         */
        public function process_refund($order_id, $amount = null, $reason = '')
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                return new WP_Error('mft_azul', 'Orden no encontrada.');
            }
            $azul_order_id = (string) $order->get_meta('_mft_azul_order_id');
            if ($azul_order_id === '') {
                return new WP_Error('mft_azul', 'La orden no tiene AzulOrderId; reembolsa desde el portal de AZUL.');
            }

            $amount = (float) ($amount === null ? $order->get_total() : $amount);
            $is_full = abs($amount - (float) $order->get_total()) < 0.01;
            $approved_at = (int) $order->get_meta('_mft_azul_approved_at');
            // Margen de 1 minuto sobre el límite de 20 de AZUL.
            $can_void = $is_full && $approved_at > 0 && (time() - $approved_at) < 19 * MINUTE_IN_SECONDS
                && !$order->get_meta('_mft_azul_voided');

            if ($can_void) {
                $res = mft_azul_api_call('ProcessVoid', [
                    'Channel' => $this->get_option('channel'),
                    'Store' => $this->get_option('store'),
                    'AzulOrderId' => $azul_order_id,
                ]);
                $label = 'Anulación (Void)';
            } else {
                $cents = mft_azul_api_cents($amount);
                $total_cents = mft_azul_api_cents($order->get_total());
                $itbis = $total_cents > 0
                    ? (int) round(mft_azul_api_itbis_cents($order, $total_cents) * $cents / $total_cents)
                    : 0;
                $refunds = (int) $order->get_meta('_mft_azul_refund_count') + 1;
                $order->update_meta_data('_mft_azul_refund_count', $refunds);
                $order->save();

                $res = mft_azul_api_call('ProcessPayment', [
                    'Channel' => $this->get_option('channel'),
                    'Store' => $this->get_option('store'),
                    'PosInputMode' => $this->get_option('pos_input_mode'),
                    'TrxType' => 'Refund',
                    'Amount' => (string) $cents,
                    'Itbis' => mft_azul_api_format_itbis($itbis),
                    'CurrencyPosCode' => '$',
                    'Payments' => '1',
                    'Plan' => '0',
                    'OriginalDate' => substr((string) $order->get_meta('_mft_azul_datetime'), 0, 8),
                    'AcquirerRefData' => '',
                    'RRN' => null,
                    'AzulOrderId' => $azul_order_id,
                    'CustomerServicePhone' => $this->get_option('customer_service_phone'),
                    'OrderNumber' => mft_azul_api_attempt_ref($order, 'R' . $refunds),
                    'ECommerceUrl' => $this->get_option('ecommerce_url'),
                    'CustomOrderId' => mft_azul_api_attempt_ref($order, 'R' . $refunds),
                    'DataVaultToken' => '',
                    'ForceNo3DS' => '1',
                    'SaveToDataVault' => '0',
                ]);
                $label = 'Devolución (Refund)';
            }

            if (is_wp_error($res)) {
                $order->add_order_note($label . ' AZUL sin respuesta: ' . $res->get_error_message() . '. Verifica en el portal de AZUL antes de reintentar.');
                return $res;
            }
            if (($res['IsoCode'] ?? '') !== '00') {
                $msg = trim(($res['ResponseMessage'] ?? '') . ' ' . ($res['ErrorDescription'] ?? ''));
                $order->add_order_note($label . ' AZUL rechazada: ' . $msg);
                return new WP_Error('mft_azul', $label . ' rechazada por AZUL: ' . $msg);
            }

            if ($can_void) {
                $order->update_meta_data('_mft_azul_voided', 1);
                $order->save();
            }
            $order->add_order_note(sprintf(
                '%s AZUL aprobada por %s. Autorización: %s%s',
                $label,
                wc_price($amount),
                $res['AuthorizationCode'] ?? '',
                $reason ? ' · Motivo: ' . $reason : ''
            ));
            return true;
        }
    }

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'MFT_Azul_API_Gateway';
        return $gateways;
    });
});

// ===========================================================================
// HELPERS
// ===========================================================================

/** @return MFT_Azul_API_Gateway|null */
function mft_azul_api_gateway()
{
    if (!function_exists('WC') || !WC()->payment_gateways()) {
        return null;
    }
    $all = WC()->payment_gateways()->payment_gateways();
    return isset($all[MFT_AZUL_API_ID]) ? $all[MFT_AZUL_API_ID] : null;
}

/** Monto en centavos sin separadores (formato N(12) de AZUL). */
function mft_azul_api_cents($amount)
{
    return (int) round(((float) $amount) * 100);
}

function mft_azul_api_itbis_cents($order, $amount_cents)
{
    $gw = mft_azul_api_gateway();
    $mode = $gw ? $gw->get_option('itbis_mode', 'order') : 'order';
    if ($mode === 'included_18') {
        return (int) round($amount_cents - $amount_cents / 1.18);
    }
    if ($mode === 'order') {
        return mft_azul_api_cents($order->get_total_tax());
    }
    return 0;
}

/** AZUL pide "000" cuando no hay ITBIS. */
function mft_azul_api_format_itbis($cents)
{
    return $cents > 0 ? (string) $cents : '000';
}

function mft_azul_api_log($message, $context = [])
{
    $gw = mft_azul_api_gateway();
    if (!$gw || $gw->get_option('debug') !== 'yes' || !function_exists('wc_get_logger')) {
        return;
    }
    // Defensa extra: jamás registrar datos de tarjeta aunque llegaran en $context.
    foreach (['CardNumber', 'CVC', 'Expiration', 'card'] as $k) {
        unset($context[$k]);
    }
    wc_get_logger()->info($message . ($context ? ' ' . wp_json_encode($context) : ''), ['source' => 'mft-azul-api']);
}

/**
 * Llama a un método del webservice JSON de AZUL con autenticación mutua.
 *
 * Devuelve el array de respuesta, o WP_Error con código:
 *  - `azul_connect`: no se pudo conectar (la petición NO llegó; es seguro reintentar).
 *  - `azul_timeout`: se envió pero no hubo respuesta (resultado DESCONOCIDO: verificar).
 *  - `azul_http` / `azul_parse`: hubo respuesta pero inválida (resultado DESCONOCIDO).
 *  - `azul_config`.
 */
function mft_azul_api_call($method, array $payload)
{
    $gw = mft_azul_api_gateway();
    if (!$gw || !$gw->is_configured()) {
        return new WP_Error('azul_config', 'Método AZUL API no configurado.');
    }
    if (!function_exists('curl_init')) {
        return new WP_Error('azul_config', 'cURL no disponible en el servidor.');
    }

    $env = $gw->get_option('environment') === 'prod' ? 'prod' : 'test';
    $query = $method === 'ProcessPayment' ? '' : '?' . $method;
    $body = wp_json_encode($payload);
    $last_error = null;

    foreach (MFT_AZUL_API_URLS[$env] as $base) {
        $ch = curl_init($base . $query);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Auth1: ' . $gw->secret('auth1'),
                'Auth2: ' . $gw->secret('auth2'),
            ],
            CURLOPT_SSLCERT => $gw->secret('cert_path'),
            CURLOPT_SSLKEY => $gw->secret('key_path'),
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 15,
            // AZUL recomienda hasta 120 s (el emisor puede demorar la aprobación).
            CURLOPT_TIMEOUT => 120,
        ];
        $pass = $gw->secret('key_pass');
        if ($pass !== '') {
            $opts[CURLOPT_SSLKEYPASSWD] = $pass;
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            // Solo pasamos al sitio alterno si la petición no llegó a enviarse.
            // Un timeout tras enviar podría haber cobrado: no se repite.
            $connect_errors = [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR];
            if (in_array($errno, $connect_errors, true)) {
                $last_error = new WP_Error('azul_connect', 'No se pudo conectar con AZUL: ' . $error);
                mft_azul_api_log($method . ' connect error', ['url' => $base, 'error' => $error]);
                continue;
            }
            mft_azul_api_log($method . ' timeout/error', ['url' => $base, 'errno' => $errno, 'error' => $error]);
            return new WP_Error('azul_timeout', 'Sin respuesta de AZUL: ' . $error);
        }
        if ($http < 200 || $http >= 300) {
            mft_azul_api_log($method . ' HTTP ' . $http, ['url' => $base]);
            return new WP_Error('azul_http', 'AZUL respondió HTTP ' . $http);
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            mft_azul_api_log($method . ' respuesta no JSON', ['raw' => substr((string) $raw, 0, 500)]);
            return new WP_Error('azul_parse', 'Respuesta de AZUL no válida.');
        }
        // Algunas respuestas vienen envueltas (ej. {"ProcessPaymentResult": {...}}).
        if (count($data) === 1) {
            $inner = reset($data);
            if (is_array($inner) && (isset($inner['IsoCode']) || isset($inner['ResponseCode']))) {
                $data = $inner;
            }
        }
        mft_azul_api_log($method . ' OK', [
            'IsoCode' => $data['IsoCode'] ?? null,
            'ResponseCode' => $data['ResponseCode'] ?? null,
            'ResponseMessage' => $data['ResponseMessage'] ?? null,
            'ErrorDescription' => $data['ErrorDescription'] ?? null,
            'AzulOrderId' => $data['AzulOrderId'] ?? null,
        ]);
        return $data;
    }

    return $last_error ?: new WP_Error('azul_connect', 'No se pudo conectar con AZUL.');
}

/**
 * Lock por orden para evitar cobros dobles (doble clic, dos pestañas).
 * add_option es atómico: falla si la opción ya existe (option_name es único).
 */
function mft_azul_api_lock($order_id)
{
    $name = 'mft_azul_lock_' . (int) $order_id;
    if (add_option($name, time(), '', 'no')) {
        return true;
    }
    $since = (int) get_option($name);
    if ($since && time() - $since > 150) { // lock huérfano (> timeout de AZUL)
        delete_option($name);
        return add_option($name, time(), '', 'no');
    }
    return false;
}

function mft_azul_api_unlock($order_id)
{
    delete_option('mft_azul_lock_' . (int) $order_id);
}

/** Marca la orden como pagada (mismo criterio que la Página de Pago AZUL). */
function mft_azul_api_mark_paid($order, array $res)
{
    if ($order->is_paid()) {
        return;
    }
    $order->update_meta_data('_mft_azul_order_id', (string) ($res['AzulOrderId'] ?? $order->get_meta('_mft_azul_order_id')));
    $order->update_meta_data('_mft_azul_authorization_code', (string) ($res['AuthorizationCode'] ?? ''));
    $order->update_meta_data('_mft_azul_rrn', (string) ($res['RRN'] ?? ''));
    $order->update_meta_data('_mft_azul_datetime', (string) ($res['DateTime'] ?? gmdate('YmdHis')));
    $order->update_meta_data('_mft_azul_approved_at', time());
    $order->update_meta_data('AuthorizationCode', (string) ($res['AuthorizationCode'] ?? ''));
    $order->update_meta_data('_mft_azul_3ds_state', 'done');
    $order->delete_meta_data('_mft_azul_pending_verify');
    $gw = mft_azul_api_gateway();
    $test = !$gw || $gw->is_test();
    if ($test) {
        $order->update_meta_data('_mft_azul_test', 1);
    }
    $order->save();

    if ($test) {
        $order->add_order_note('PAGO DE PRUEBA (AZUL pruebas): no se cobró dinero. No entregar ni despachar esta orden.');
    }

    $order->add_order_note(sprintf(
        'AZUL API aprobado. Autorización: %s · RRN: %s · AzulOrderId: %s · Tarjeta ****%s',
        $res['AuthorizationCode'] ?? '',
        $res['RRN'] ?? '',
        $res['AzulOrderId'] ?? $order->get_meta('_mft_azul_order_id'),
        $order->get_meta('_mft_azul_card_last4')
    ));
    // payment_complete dispara los hooks de pago (FooEvents, stock, correos).
    $order->payment_complete((string) ($res['AuthorizationCode'] ?? ''));
    $order->update_status('completed', 'El pago ha sido completado');
}

/** "Orden recibida" de WooCommerce (vacía el carrito al llegar). */
function mft_azul_api_approved_redirect($order)
{
    return $order->get_checkout_order_received_url();
}

/** Vuelve a la página de pago de la orden mostrando un aviso. */
function mft_azul_api_retry_redirect($order, $message)
{
    $order->update_meta_data('_mft_azul_notice', $message);
    $order->save();
    return $order->get_checkout_payment_url(true);
}

/** Mensaje para el cliente (los detalles técnicos van solo a la nota privada). */
function mft_azul_api_customer_message(array $res)
{
    $iso = (string) ($res['IsoCode'] ?? '');
    if (($res['ResponseCode'] ?? '') === 'Error') {
        return 'No pudimos procesar el pago. Verifica los datos de tu tarjeta e intenta de nuevo.';
    }
    if ($iso === '99') {
        return stripos((string) ($res['ErrorDescription'] ?? ''), '3D') !== false
            ? 'Tu banco no pudo autenticar la tarjeta (3D Secure). Intenta de nuevo o usa otra tarjeta.'
            : 'No pudimos procesar el pago. Verifica los datos de tu tarjeta e intenta de nuevo.';
    }
    if ($iso === '08') {
        return 'Tu banco no pudo autenticar la tarjeta en este momento. Intenta más tarde o usa otra tarjeta.';
    }
    $msg = trim((string) ($res['ResponseMessage'] ?? ''));
    return $msg !== '' ? 'Pago declinado: ' . $msg . '.' : 'Pago declinado por el banco emisor.';
}

/** Programa la verificación (VerifyPayment) de un intento con resultado desconocido. */
function mft_azul_api_schedule_verify($order, $custom_order_id, $tries = 0)
{
    $order->update_meta_data('_mft_azul_pending_verify', $custom_order_id);
    $order->save();
    wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, 'mft_azul_api_verify', [$order->get_id(), $tries]);
}

/**
 * Traduce una respuesta de AZUL (Sale, ProcessThreeDSMethod o
 * ProcessThreeDSChallenge) al resultado que consume assets/pay.js.
 */
function mft_azul_api_handle_result($order, $res)
{
    if (is_wp_error($res)) {
        // La petición se envió pero no sabemos si AZUL cobró: se verifica, no se reintenta.
        if (in_array($res->get_error_code(), ['azul_timeout', 'azul_http', 'azul_parse'], true)) {
            $custom = (string) $order->get_meta('_mft_azul_custom_order_id');
            $order->add_order_note('AZUL API sin respuesta (' . $res->get_error_message() . '). Se verificará con VerifyPayment (' . $custom . ').');
            mft_azul_api_schedule_verify($order, $custom);
            return [
                'status' => 'pending',
                'message' => 'No recibimos confirmación de AZUL. No intentes pagar de nuevo: verificaremos tu pago en unos minutos y te avisaremos por correo.',
            ];
        }
        $order->add_order_note('AZUL API error: ' . $res->get_error_message());
        return ['status' => 'error', 'message' => 'No pudimos conectar con el procesador de pagos. Intenta de nuevo en unos minutos.'];
    }

    $iso = (string) ($res['IsoCode'] ?? '');
    if (!empty($res['AzulOrderId'])) {
        $order->update_meta_data('_mft_azul_order_id', (string) $res['AzulOrderId']);
    }

    if ($iso === '00') {
        mft_azul_api_mark_paid($order, $res);
        return ['status' => 'approved', 'redirect' => mft_azul_api_approved_redirect($order)];
    }

    if ($iso === '3D2METHOD') {
        $order->update_meta_data('_mft_azul_3ds_state', 'method');
        $order->update_meta_data('_mft_azul_method_received', 0);
        $order->save();
        return [
            'status' => 'method',
            'methodForm' => (string) ($res['ThreeDSMethod']['MethodForm'] ?? ''),
        ];
    }

    if ($iso === '3D') {
        $order->update_meta_data('_mft_azul_3ds_state', 'challenge');
        $order->save();
        return [
            'status' => 'challenge',
            'redirectPostUrl' => (string) ($res['ThreeDSChallenge']['RedirectPostUrl'] ?? ''),
            'creq' => (string) ($res['ThreeDSChallenge']['CReq'] ?? ''),
            'termUrl' => mft_azul_api_term_url($order),
        ];
    }

    // Declinada o error de validación. La orden sigue `pending` para reintentar.
    $order->update_meta_data('_mft_azul_3ds_state', 'declined');
    $order->save();
    $order->add_order_note(sprintf(
        'AZUL API declinado. ResponseCode: %s · IsoCode: %s · %s %s',
        $res['ResponseCode'] ?? '',
        $iso,
        $res['ResponseMessage'] ?? '',
        $res['ErrorDescription'] ?? ''
    ));
    return [
        'status' => 'declined',
        'isoCode' => $iso,
        'message' => mft_azul_api_customer_message($res),
    ];
}

/**
 * OrderNumber/CustomOrderId de un intento: "<orden>-<intento>". El comercio de
 * pruebas de AZUL lo comparten otros sitios, así que ahí se antepone un prefijo
 * propio de este sitio para no chocar con números que ya usaron otros.
 */
function mft_azul_api_attempt_ref($order, $attempt)
{
    $ref = $order->get_id() . '-' . $attempt;
    $gw = mft_azul_api_gateway();
    if ($gw && $gw->get_option('environment') !== 'prod') {
        $prefix = (string) get_option('mft_azul_test_prefix');
        if ($prefix === '') {
            $prefix = 'T' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            update_option('mft_azul_test_prefix', $prefix, false);
        }
        $ref = $prefix . '-' . $ref;
    }
    return $ref;
}

function mft_azul_api_sid($order)
{
    $sid = (string) $order->get_meta('_mft_azul_sid');
    if ($sid === '') {
        $sid = bin2hex(random_bytes(16));
        $order->update_meta_data('_mft_azul_sid', $sid);
        $order->save();
    }
    return $sid;
}

function mft_azul_api_term_url($order)
{
    return add_query_arg(['order' => $order->get_id(), 'sid' => mft_azul_api_sid($order)], rest_url('mft/v1/azul-api/term'));
}

function mft_azul_api_method_notification_url($order)
{
    return add_query_arg(['order' => $order->get_id(), 'sid' => mft_azul_api_sid($order)], rest_url('mft/v1/azul-api/method-notify'));
}

/** Orden a partir de order_id + order_key (lo que tiene la página de pago). */
function mft_azul_api_order_from_request(WP_REST_Request $request)
{
    $order_id = absint($request->get_param('order_id'));
    $key = (string) $request->get_param('order_key');
    $order = $order_id ? wc_get_order($order_id) : false;
    if (!$order || $key === '' || !hash_equals($order->get_order_key(), $key)) {
        return new WP_Error('mft_azul_order', 'Orden no válida.', ['status' => 404]);
    }
    if ($order->get_payment_method() !== MFT_AZUL_API_ID) {
        return new WP_Error('mft_azul_order', 'La orden no usa pago con tarjeta.', ['status' => 400]);
    }
    return $order;
}

/** Orden a partir de order + sid (callbacks del ACS, que no conocen la order_key). */
function mft_azul_api_order_from_sid(WP_REST_Request $request)
{
    $order_id = absint($request->get_param('order'));
    $sid = (string) $request->get_param('sid');
    $order = $order_id ? wc_get_order($order_id) : false;
    if (!$order || $sid === '' || !hash_equals((string) $order->get_meta('_mft_azul_sid'), $sid)) {
        return null;
    }
    return $order;
}

/** Límite simple por IP contra pruebas masivas de tarjetas (card testing). */
function mft_azul_api_rate_limited()
{
    $ip = class_exists('WC_Geolocation') ? WC_Geolocation::get_ip_address() : ($_SERVER['REMOTE_ADDR'] ?? '');
    $key = 'mft_azul_rl_' . md5((string) $ip);
    $count = (int) get_transient($key);
    if ($count >= (int) apply_filters('mft_azul_api_ip_limit', 15)) {
        return true;
    }
    set_transient($key, $count + 1, HOUR_IN_SECONDS);
    return false;
}

function mft_azul_api_luhn($number)
{
    $sum = 0;
    $alt = false;
    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $n = (int) $number[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
        $alt = !$alt;
    }
    return $sum % 10 === 0;
}

/** Recorta/omite campos vacíos de CardHolderInfo (AZUL recomienda omitirlos). */
function mft_azul_api_cardholder_info($order, $name)
{
    $country = $order->get_billing_country();
    $state = $order->get_billing_state();
    $fields = [
        'Name' => $name,
        'Email' => $order->get_billing_email(),
        'PhoneMobile' => $order->get_billing_phone(),
        'BillingAddressLine1' => $order->get_billing_address_1(),
        'BillingAddressLine2' => $order->get_billing_address_2(),
        'BillingAddressCity' => $order->get_billing_city(),
        'BillingAddressState' => ($country && $state && isset(WC()->countries->get_states($country)[$state]))
            ? WC()->countries->get_states($country)[$state]
            : $state,
        'BillingAddressCountry' => $country,
        'BillingAddressZip' => $order->get_billing_postcode(),
    ];
    $limits = ['Email' => 254, 'PhoneMobile' => 32, 'BillingAddressZip' => 24, 'BillingAddressCountry' => 2];
    $out = [];
    foreach ($fields as $k => $v) {
        $v = trim((string) $v);
        if ($v === '') {
            continue;
        }
        $out[$k] = mb_substr($v, 0, $limits[$k] ?? 96);
    }
    return $out;
}

function mft_azul_api_browser_info(array $b)
{
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? (string) wp_unslash($_SERVER['HTTP_ACCEPT']) : '';
    // La llamada llega por fetch (Accept: application/json); AZUL espera el Accept
    // de navegación del navegador, así que usamos el típico si no hay text/html.
    if (stripos($accept, 'text/html') === false) {
        $accept = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
    }
    $ip = class_exists('WC_Geolocation') ? WC_Geolocation::get_ip_address() : ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
    $int = function ($k, $default) use ($b) {
        return isset($b[$k]) && is_numeric($b[$k]) ? (string) (int) $b[$k] : $default;
    };
    return [
        'AcceptHeader' => $accept,
        'IPAddress' => (string) $ip,
        'Language' => isset($b['language']) ? substr(preg_replace('/[^A-Za-z\-]/', '', (string) $b['language']), 0, 8) : 'es-DO',
        'ColorDepth' => $int('colorDepth', '24'),
        'ScreenWidth' => $int('screenWidth', '1280'),
        'ScreenHeight' => $int('screenHeight', '800'),
        'TimeZone' => $int('timeZone', '240'),
        'UserAgent' => substr($ua, 0, 2048),
        'JavaScriptEnabled' => 'true',
    ];
}

// ===========================================================================
// REST: mft/v1/azul-api/*
// ===========================================================================

add_action('rest_api_init', function () {
    register_rest_route('mft/v1', '/azul-api/pay', [
        'methods' => 'POST',
        'callback' => 'mft_azul_api_rest_pay',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('mft/v1', '/azul-api/method-status', [
        'methods' => 'GET',
        'callback' => 'mft_azul_api_rest_method_status',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('mft/v1', '/azul-api/continue', [
        'methods' => 'POST',
        'callback' => 'mft_azul_api_rest_continue',
        'permission_callback' => '__return_true',
    ]);
    // Callbacks del ACS del banco emisor (POST de formulario desde el navegador).
    register_rest_route('mft/v1', '/azul-api/method-notify', [
        'methods' => ['GET', 'POST'],
        'callback' => 'mft_azul_api_rest_method_notify',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('mft/v1', '/azul-api/term', [
        'methods' => ['GET', 'POST'],
        'callback' => 'mft_azul_api_rest_term',
        'permission_callback' => '__return_true',
    ]);
});

// Las respuestas de pago nunca se cachean (Varnish, navegador).
add_filter('rest_post_dispatch', function ($response, $server, $request) {
    if (str_starts_with((string) $request->get_route(), '/mft/v1/azul-api/')) {
        foreach (wp_get_nocache_headers() as $name => $value) {
            $response->header($name, $value);
        }
    }
    return $response;
}, 10, 3);

function mft_azul_api_rest_pay(WP_REST_Request $request)
{
    $gw = mft_azul_api_gateway();
    if (!$gw || !$gw->is_configured()) {
        return new WP_Error('mft_azul_config', 'El pago con tarjeta no está disponible.', ['status' => 503]);
    }
    if ($gw->get_option('environment') === 'prod' && !is_ssl()) {
        return new WP_Error('mft_azul_ssl', 'Conexión no segura.', ['status' => 400]);
    }
    $order = mft_azul_api_order_from_request($request);
    if (is_wp_error($order)) {
        return $order;
    }
    if ($order->is_paid()) {
        return ['status' => 'approved', 'redirect' => mft_azul_api_approved_redirect($order)];
    }
    if (!$order->needs_payment()) {
        return new WP_Error('mft_azul_order', 'La orden ya no admite pagos.', ['status' => 409]);
    }

    // Un intento anterior quedó sin respuesta: primero se verifica, nunca se cobra a ciegas.
    if ($order->get_meta('_mft_azul_pending_verify')) {
        mft_azul_api_verify_order($order->get_id(), 99);
        $order = wc_get_order($order->get_id());
        if ($order->is_paid()) {
            return ['status' => 'approved', 'redirect' => mft_azul_api_approved_redirect($order)];
        }
        if ($order->get_meta('_mft_azul_pending_verify')) {
            return ['status' => 'pending', 'message' => 'Estamos confirmando un intento de pago anterior. Intenta de nuevo en unos minutos.'];
        }
    }

    // Tarjeta (solo en memoria; nunca se persiste).
    $card = (array) $request->get_param('card');
    $number = preg_replace('/\D/', '', (string) ($card['number'] ?? ''));
    $cvc = preg_replace('/\D/', '', (string) ($card['cvc'] ?? ''));
    $month = (int) ($card['expMonth'] ?? 0);
    $year = (int) ($card['expYear'] ?? 0);
    if ($year > 0 && $year < 100) {
        $year += 2000;
    }
    $name = trim(sanitize_text_field((string) ($card['name'] ?? '')));
    $exp_ts = $year ? mktime(0, 0, 0, $month + 1, 1, $year) : 0;

    if (strlen($number) < 13 || strlen($number) > 19 || !mft_azul_api_luhn($number)) {
        return ['status' => 'invalid', 'field' => 'number', 'message' => 'El número de tarjeta no es válido.'];
    }
    if ($month < 1 || $month > 12 || $exp_ts <= time()) {
        return ['status' => 'invalid', 'field' => 'exp', 'message' => 'La fecha de vencimiento no es válida.'];
    }
    if (strlen($cvc) < 3 || strlen($cvc) > 4) {
        return ['status' => 'invalid', 'field' => 'cvc', 'message' => 'El código de seguridad no es válido.'];
    }
    if ($name === '') {
        return ['status' => 'invalid', 'field' => 'name', 'message' => 'Escribe el nombre como aparece en la tarjeta.'];
    }

    if (mft_azul_api_rate_limited()) {
        return new WP_Error('mft_azul_rate', 'Demasiados intentos. Intenta más tarde.', ['status' => 429]);
    }
    $attempts = (int) $order->get_meta('_mft_azul_attempts');
    $max = max(1, (int) $gw->get_option('max_attempts', 5));
    if ($attempts >= $max) {
        return new WP_Error('mft_azul_attempts', 'Se alcanzó el máximo de intentos para esta orden. Contáctanos para completar tu compra.', ['status' => 429]);
    }
    if (!mft_azul_api_lock($order->get_id())) {
        return new WP_Error('mft_azul_busy', 'Ya hay un pago en proceso para esta orden.', ['status' => 409]);
    }

    try {
        // AZUL a veces responde "SGS-005003: The order already exists" con un
        // número nuevo (visto en pruebas). Es un rechazo sin cobro: se reintenta
        // una vez con otro número de intento.
        for ($try = 0; $try < 2; $try++) {
            $res = mft_azul_api_sale($gw, $order, ++$attempts, $request, $number, $cvc, $month, $year, $name);
            $repeated = !is_wp_error($res) && ($res['IsoCode'] ?? '') === '99'
                && str_contains((string) ($res['ErrorDescription'] ?? ''), 'SGS-005003');
            if (!$repeated || $attempts >= $max) {
                break;
            }
            mft_azul_api_log('Sale SGS-005003, reintento con otro número', ['attempt' => $attempts]);
        }
        unset($number, $cvc, $card);

        return mft_azul_api_handle_result($order, $res);
    } finally {
        mft_azul_api_unlock($order->get_id());
    }
}

/** Envía la venta (Sale con 3D Secure) de un intento. */
function mft_azul_api_sale($gw, $order, $attempts, WP_REST_Request $request, $number, $cvc, $month, $year, $name)
{
    // Id único por intento. AZUL rechaza un OrderNumber repetido
    // ("SGS-005003: The order already exists"), y VerifyPayment devuelve la
    // última transacción con ese CustomOrderId: cada intento se verifica aparte.
    $custom_order_id = mft_azul_api_attempt_ref($order, $attempts);
    $order->update_meta_data('_mft_azul_attempts', $attempts);
    $order->update_meta_data('_mft_azul_custom_order_id', $custom_order_id);
    $order->update_meta_data('_mft_azul_card_last4', substr($number, -4));
    $order->update_meta_data('_mft_azul_3ds_state', 'sale');
    $order->save();

    $cents = mft_azul_api_cents($order->get_total());
    $payload = [
        'Channel' => $gw->get_option('channel'),
        'Store' => $gw->get_option('store'),
        'CardNumber' => $number,
        'Expiration' => sprintf('%04d%02d', $year, $month),
        'CVC' => $cvc,
        'PosInputMode' => $gw->get_option('pos_input_mode'),
        'TrxType' => 'Sale',
        'Amount' => (string) $cents,
        'Itbis' => mft_azul_api_format_itbis(mft_azul_api_itbis_cents($order, $cents)),
        'CurrencyPosCode' => '$',
        'Payments' => '1',
        'Plan' => '0',
        'AcquirerRefData' => '1',
        'CustomerServicePhone' => $gw->get_option('customer_service_phone'),
        'OrderNumber' => $custom_order_id,
        'ECommerceUrl' => $gw->get_option('ecommerce_url'),
        'CustomOrderId' => $custom_order_id,
        'AltMerchantName' => mb_substr((string) $gw->get_option('alt_merchant_name'), 0, 25),
        'DataVaultToken' => '',
        'SaveToDataVault' => '0',
        'ForceNo3DS' => '0',
        'ThreeDSAuth' => [
            'TermUrl' => mft_azul_api_term_url($order),
            'MethodNotificationUrl' => mft_azul_api_method_notification_url($order),
            'RequestorChallengeIndicator' => '01',
        ],
        'CardHolderInfo' => mft_azul_api_cardholder_info($order, $name),
        'BrowserInfo' => mft_azul_api_browser_info((array) $request->get_param('browser')),
    ];

    $res = mft_azul_api_call('ProcessPayment', $payload);
    unset($payload);
    return $res;
}

function mft_azul_api_rest_method_status(WP_REST_Request $request)
{
    $order = mft_azul_api_order_from_request($request);
    if (is_wp_error($order)) {
        return $order;
    }
    return ['received' => (bool) $order->get_meta('_mft_azul_method_received')];
}

/** El ACS del emisor confirma (dentro del iframe oculto) que recibió los datos del navegador. */
function mft_azul_api_rest_method_notify(WP_REST_Request $request)
{
    $order = mft_azul_api_order_from_sid($request);
    if ($order) {
        $data = json_decode((string) base64_decode((string) $request->get_param('threeDSMethodData'), true), true);
        $order->update_meta_data('_mft_azul_method_received', 1);
        if (!empty($data['threeDSServerTransID'])) {
            $order->update_meta_data('_mft_azul_3ds_server_trans_id', sanitize_text_field((string) $data['threeDSServerTransID']));
        }
        $order->save();
    }
    // Se renderiza dentro del iframe oculto: basta una página vacía.
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><title>ok</title>';
    exit;
}

/** La página de pago avisa que terminó la espera del MethodForm (recibido o no en 10 s). */
function mft_azul_api_rest_continue(WP_REST_Request $request)
{
    $gw = mft_azul_api_gateway();
    $order = mft_azul_api_order_from_request($request);
    if (is_wp_error($order)) {
        return $order;
    }
    if ($order->is_paid()) {
        return ['status' => 'approved', 'redirect' => mft_azul_api_approved_redirect($order)];
    }
    if ($order->get_meta('_mft_azul_3ds_state') !== 'method') {
        return new WP_Error('mft_azul_state', 'El pago no está esperando la verificación del banco.', ['status' => 409]);
    }
    if (!mft_azul_api_lock($order->get_id())) {
        return new WP_Error('mft_azul_busy', 'Ya hay un pago en proceso para esta orden.', ['status' => 409]);
    }
    try {
        $res = mft_azul_api_call('processthreedsmethod', [
            'Channel' => $gw->get_option('channel'),
            'Store' => $gw->get_option('store'),
            'AzulOrderId' => (string) $order->get_meta('_mft_azul_order_id'),
            'MethodNotificationStatus' => $order->get_meta('_mft_azul_method_received') ? 'RECEIVED' : 'EXPECTED_BUT_NOT_RECEIVED',
        ]);
        return mft_azul_api_handle_result($order, $res);
    } finally {
        mft_azul_api_unlock($order->get_id());
    }
}

/** TermUrl: el ACS devuelve al cliente aquí (POST con cRes) tras el desafío. */
function mft_azul_api_rest_term(WP_REST_Request $request)
{
    $order = mft_azul_api_order_from_sid($request);
    if (!$order) {
        wp_safe_redirect(home_url('/'));
        exit;
    }
    if ($order->is_paid()) {
        wp_safe_redirect(mft_azul_api_approved_redirect($order));
        exit;
    }
    $cres = '';
    foreach (['cRes', 'CRes', 'cres'] as $k) {
        if ($request->get_param($k)) {
            $cres = (string) $request->get_param($k);
            break;
        }
    }
    if ($cres === '' || $order->get_meta('_mft_azul_3ds_state') !== 'challenge' || !mft_azul_api_lock($order->get_id())) {
        wp_safe_redirect(mft_azul_api_retry_redirect($order, 'No se completó la autenticación con tu banco. Intenta de nuevo.'));
        exit;
    }

    $gw = mft_azul_api_gateway();
    try {
        $res = mft_azul_api_call('processthreedschallenge', [
            'Channel' => $gw->get_option('channel'),
            'Store' => $gw->get_option('store'),
            'AzulOrderId' => (string) $order->get_meta('_mft_azul_order_id'),
            'CRes' => $cres,
        ]);
        $result = mft_azul_api_handle_result($order, $res);
    } finally {
        mft_azul_api_unlock($order->get_id());
    }

    // Resultado desconocido ('pending') NO es un rechazo: /pay bloquea nuevos
    // cobros hasta que VerifyPayment lo resuelva.
    wp_safe_redirect($result['status'] === 'approved'
        ? $result['redirect']
        : mft_azul_api_retry_redirect($order, $result['message'] ?? 'No se pudo completar el pago.'));
    exit;
}

// ===========================================================================
// CRON: VerifyPayment de intentos sin respuesta
// ===========================================================================

add_action('mft_azul_api_verify', 'mft_azul_api_verify_order', 10, 2);

function mft_azul_api_verify_order($order_id, $tries = 0)
{
    $order = wc_get_order($order_id);
    $gw = mft_azul_api_gateway();
    if (!$order || !$gw) {
        return;
    }
    $custom = (string) $order->get_meta('_mft_azul_pending_verify');
    if ($custom === '' || $order->is_paid()) {
        return;
    }

    $res = mft_azul_api_call('VerifyPayment', [
        'Channel' => $gw->get_option('channel'),
        'Store' => $gw->get_option('store'),
        'CustomOrderId' => $custom,
    ]);

    if (is_wp_error($res) || ($res['ResponseCode'] ?? '') === 'Error') {
        // Llamada manual desde /pay (tries=99): no reprogramar, ya hay un evento.
        if ($tries < 3) {
            wp_schedule_single_event(time() + 10 * MINUTE_IN_SECONDS, 'mft_azul_api_verify', [$order_id, $tries + 1]);
        } elseif ($tries !== 99) {
            $order->add_order_note('VerifyPayment AZUL sin respuesta tras varios intentos (' . $custom . '). Revisar manualmente en el portal de AZUL.');
        }
        return;
    }

    $found = filter_var($res['Found'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($found && ($res['IsoCode'] ?? '') === '00') {
        $order->add_order_note('VerifyPayment AZUL: el intento ' . $custom . ' fue APROBADO.');
        mft_azul_api_mark_paid($order, $res);
        return;
    }

    $order->delete_meta_data('_mft_azul_pending_verify');
    $order->save();
    $order->add_order_note(sprintf(
        'VerifyPayment AZUL: el intento %s %s.',
        $custom,
        $found ? 'no se completó (IsoCode ' . ($res['IsoCode'] ?? '') . ')' : 'no se encontró (no hubo cobro)'
    ));
}

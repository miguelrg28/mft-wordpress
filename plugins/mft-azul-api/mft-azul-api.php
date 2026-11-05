<?php
/**
 * Plugin Name: MFT AZUL API (pago con tarjeta + 3D Secure 2.0)
 * Description: Método de pago de WooCommerce que cobra con tarjeta dentro del sitio usando la "Integración vía API" de AZUL (JSON + autenticación mutua) con 3D Secure 2.0. Convive con la "Página de Pago AZUL": son métodos distintos.
 * Version: 0.3.0
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
 *
 * GARANTÍAS
 *  - Un solo cobro por orden: candado atómico por orden y, ya con el candado, se
 *    vuelve a leer la orden. Una aprobación de AZUL se guarda (`_mft_azul_charged`)
 *    antes de completar la orden, así un fallo al completarla nunca lleva a cobrar
 *    otra vez.
 *  - Resultado desconocido (timeout, respuesta inválida, error inesperado con una
 *    llamada en curso): nunca se reintenta a ciegas; se verifica con VerifyPayment.
 *  - Todo error llega al cliente como un mensaje en español y sin detalles
 *    técnicos; el detalle va a la nota privada de la orden y al log.
 */

defined('ABSPATH') || exit;

define('MFT_AZUL_API_ID', 'mft_azul_api');
define('MFT_AZUL_API_VERSION', '0.3.0');

// Endpoints JSON. El método va en el query string (Default.aspx sin query =
// ProcessPayment).
const MFT_AZUL_API_URLS = [
    'test' => ['https://pruebas.azul.com.do/WebServices/JSON/Default.aspx'],
    'prod' => [
        'https://pagos.azul.com.do/WebServices/JSON/Default.aspx',
        'https://contpagos.azul.com.do/WebServices/JSON/Default.aspx', // sitio alterno
    ],
];

// Cloudflare corta a los 100 s: AZUL debe responder (o vencer) antes, para que
// el servidor y no el navegador decida qué hacer con un resultado desconocido.
const MFT_AZUL_API_TIMEOUT = 85;

// AZUL solo cobra en pesos dominicanos con este comercio (CurrencyPosCode "$").
const MFT_AZUL_API_CURRENCY = 'DOP';

/** Mensajes para el cliente. Nunca llevan detalles técnicos. */
const MFT_AZUL_API_MSG = [
    'generic' => 'No pudimos procesar el pago. Intenta de nuevo en unos minutos.',
    'connect' => 'No pudimos conectar con el procesador de pagos. Intenta de nuevo en unos minutos.',
    'unknown' => 'No recibimos la confirmación del banco. No intentes pagar de nuevo: verificaremos tu pago en unos minutos y te avisaremos por correo.',
    'verifying' => 'Estamos confirmando un intento de pago anterior. Intenta de nuevo en unos minutos.',
    'busy' => 'Ya hay un pago en proceso para esta orden. Espera unos segundos.',
    'unavailable' => 'El pago con tarjeta no está disponible en este momento. Elige otro método de pago o intenta más tarde.',
    'order' => 'No encontramos esta orden. Vuelve a abrir el enlace de pago desde tu correo o desde Mi cuenta.',
    'expired' => 'Esta orden ya no admite pagos (puede haber expirado). Vuelve a hacer tu pedido.',
    'attempts' => 'Se alcanzó el máximo de intentos para esta orden. Contáctanos para completar tu compra.',
    'rate' => 'Demasiados intentos de pago. Espera un rato antes de volver a intentar.',
    'auth' => 'No se completó la autenticación con tu banco. Intenta de nuevo.',
    'declined' => 'Pago declinado por el banco emisor. Intenta con otra tarjeta o contacta a tu banco.',
    'card' => 'No pudimos procesar el pago. Verifica los datos de tu tarjeta e intenta de nuevo.',
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
                    'label' => 'Registrar respuestas en WooCommerce → Estado → Registros (fuente mft-azul-api). Nunca registra datos de tarjeta. Los errores se registran siempre.',
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
         * prueba de AZUL son públicas: en Pruebas nadie más puede usar el método,
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
            if (get_woocommerce_currency() !== MFT_AZUL_API_CURRENCY) {
                return false;
            }
            return parent::is_available() && $this->is_configured();
        }

        /**
         * Al colocar la orden no se cobra todavía: queda `pending` y el cliente
         * pasa a la página de pago de la orden, donde escribe la tarjeta.
         * WooCommerce solo llega aquí si el método está disponible para este
         * usuario, así que la marca `_mft_azul_allowed` autoriza a pagar la orden
         * con /pay (ver mft_azul_api_order_from_request).
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                wc_add_notice(MFT_AZUL_API_MSG['generic'], 'error');
                return ['result' => 'failure'];
            }
            $order->update_meta_data('_mft_azul_allowed', $this->is_test() ? 'test' : 'prod');
            $order->save();
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
            if (!$this->is_configured() || $order->get_currency() !== MFT_AZUL_API_CURRENCY) {
                echo '<p class="woocommerce-error">' . esc_html(MFT_AZUL_API_MSG['unavailable']) . '</p>';
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
                'msg' => [
                    'generic' => MFT_AZUL_API_MSG['generic'],
                    'connect' => 'No pudimos comunicarnos con el sitio. Revisa tu conexión a internet e intenta de nuevo.',
                    'checking' => 'Estamos confirmando el resultado de tu pago...',
                    'unknown' => MFT_AZUL_API_MSG['unknown'],
                    'method' => 'No se pudo completar la verificación con tu banco. Intenta de nuevo.',
                    'leave' => 'Tu pago se está procesando. Si sales ahora podrías no ver el resultado.',
                ],
            ]);
            ?>
            <div class="mft-azul" id="mft-azul" data-busy="0">
                <p class="mft-azul__notice" role="alert"<?php echo $notice === '' ? ' hidden' : ''; ?>><?php echo esc_html($notice); ?></p>
                <noscript><p class="mft-azul__notice">Para pagar con tarjeta necesitas activar JavaScript en tu navegador.</p></noscript>
                <?php // Sin <form> ni atributos name: la tarjeta nunca viaja por un submit nativo. ?>
                <div class="mft-azul__field">
                    <label for="mft-cc-name">Nombre en la tarjeta</label>
                    <input id="mft-cc-name" type="text" autocomplete="cc-name" maxlength="60" data-field="name">
                </div>
                <div class="mft-azul__field">
                    <label for="mft-cc-number">Número de tarjeta</label>
                    <input id="mft-cc-number" type="text" inputmode="numeric" autocomplete="cc-number" maxlength="23" placeholder="0000 0000 0000 0000" data-field="number">
                </div>
                <div class="mft-azul__row">
                    <div class="mft-azul__field">
                        <label for="mft-cc-exp">Vencimiento</label>
                        <input id="mft-cc-exp" type="text" inputmode="numeric" autocomplete="cc-exp" maxlength="7" placeholder="MM/AA" data-field="exp">
                    </div>
                    <div class="mft-azul__field">
                        <label for="mft-cc-csc">CVC</label>
                        <input id="mft-cc-csc" type="text" inputmode="numeric" autocomplete="cc-csc" maxlength="4" placeholder="123" data-field="cvc">
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
         * los 20 minutos de aprobada; si no (o si AZUL ya no permite anular),
         * devolución (Refund).
         */
        public function process_refund($order_id, $amount = null, $reason = '')
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                return new WP_Error('mft_azul', 'Orden no encontrada.');
            }
            $azul_order_id = (string) $order->get_meta('_mft_azul_order_id');
            if ($azul_order_id === '' || !$order->get_meta('_mft_azul_charged')) {
                return new WP_Error('mft_azul', 'La orden no tiene un cobro AZUL API aprobado; revisa en el portal de AZUL.');
            }
            if (!mft_azul_api_lock($order->get_id())) {
                return new WP_Error('mft_azul', 'Hay otra operación de pago en curso para esta orden. Intenta en un minuto.');
            }

            try {
                $amount = (float) ($amount === null ? $order->get_total() : $amount);
                if ($amount <= 0) {
                    return new WP_Error('mft_azul', 'El monto a reembolsar debe ser mayor que cero.');
                }
                $is_full = abs($amount - (float) $order->get_total()) < 0.01;
                $approved_at = (int) $order->get_meta('_mft_azul_approved_at');
                // Margen de 1 minuto sobre el límite de 20 de AZUL.
                $can_void = $is_full && $approved_at > 0 && (time() - $approved_at) < 19 * MINUTE_IN_SECONDS
                    && !$order->get_meta('_mft_azul_voided');

                $res = null;
                $label = '';
                if ($can_void) {
                    $label = 'Anulación (Void)';
                    $res = mft_azul_api_call('ProcessVoid', [
                        'Channel' => $this->get_option('channel'),
                        'Store' => $this->get_option('store'),
                        'AzulOrderId' => $azul_order_id,
                    ]);
                    // AZUL ya cerró el lote: se pasa a devolución.
                    if (!is_wp_error($res) && ($res['IsoCode'] ?? '') !== '00') {
                        $order->add_order_note('Anulación AZUL no permitida (' . mft_azul_api_detail($res) . '); se intenta devolución.');
                        $can_void = false;
                        $res = null;
                    }
                }
                if (!$can_void) {
                    $label = 'Devolución (Refund)';
                    $cents = mft_azul_api_cents($amount);
                    $total_cents = mft_azul_api_cents($order->get_total());
                    $itbis = $total_cents > 0
                        ? (int) round(mft_azul_api_itbis_cents($order, $total_cents) * $cents / $total_cents)
                        : 0;
                    $refunds = (int) $order->get_meta('_mft_azul_refund_count') + 1;
                    $order->update_meta_data('_mft_azul_refund_count', $refunds);
                    $order->save();
                    $ref = mft_azul_api_attempt_ref($order, 'R' . $refunds);

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
                        'OrderNumber' => $ref,
                        'ECommerceUrl' => $this->get_option('ecommerce_url'),
                        'CustomOrderId' => $ref,
                        'DataVaultToken' => '',
                        'ForceNo3DS' => '1',
                        'SaveToDataVault' => '0',
                    ]);
                }

                if (is_wp_error($res)) {
                    $order->add_order_note($label . ' AZUL sin respuesta: ' . $res->get_error_message() . '. Verifica en el portal de AZUL antes de reintentar.');
                    return new WP_Error('mft_azul', $label . ' sin respuesta de AZUL. Verifica en el portal de AZUL antes de reintentar.');
                }
                if (($res['IsoCode'] ?? '') !== '00') {
                    $order->add_order_note($label . ' AZUL rechazada: ' . mft_azul_api_detail($res));
                    return new WP_Error('mft_azul', $label . ' rechazada por AZUL: ' . mft_azul_api_detail($res));
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
            } catch (Throwable $e) {
                mft_azul_api_log_error('refund', $e);
                return new WP_Error('mft_azul', 'Error inesperado al reembolsar. Verifica en el portal de AZUL antes de reintentar.');
            } finally {
                mft_azul_api_unlock($order->get_id());
            }
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
        return min($amount_cents, mft_azul_api_cents($order->get_total_tax()));
    }
    return 0;
}

/** AZUL pide "000" cuando no hay ITBIS. */
function mft_azul_api_format_itbis($cents)
{
    return $cents > 0 ? (string) $cents : '000';
}

/** Detalle técnico de una respuesta de AZUL (solo para notas privadas y log). */
function mft_azul_api_detail($res)
{
    if (is_wp_error($res)) {
        return $res->get_error_message();
    }
    return trim(sprintf(
        'ResponseCode: %s · IsoCode: %s · %s %s',
        $res['ResponseCode'] ?? '',
        $res['IsoCode'] ?? '',
        $res['ResponseMessage'] ?? '',
        $res['ErrorDescription'] ?? ''
    ));
}

/** Quita cualquier dato de tarjeta antes de registrar algo. */
function mft_azul_api_redact(array $context)
{
    foreach (['CardNumber', 'CVC', 'Expiration', 'card', 'number', 'cvc'] as $k) {
        unset($context[$k]);
    }
    return $context;
}

function mft_azul_api_log($message, $context = [])
{
    $gw = mft_azul_api_gateway();
    if (!$gw || $gw->get_option('debug') !== 'yes' || !function_exists('wc_get_logger')) {
        return;
    }
    $context = mft_azul_api_redact((array) $context);
    wc_get_logger()->info($message . ($context ? ' ' . wp_json_encode($context) : ''), ['source' => 'mft-azul-api']);
}

/** Los errores inesperados se registran siempre (sin datos de tarjeta). */
function mft_azul_api_log_error($where, Throwable $e)
{
    if (!function_exists('wc_get_logger')) {
        return;
    }
    wc_get_logger()->error(sprintf(
        '%s: %s: %s en %s:%d',
        $where,
        get_class($e),
        $e->getMessage(),
        basename($e->getFile()),
        $e->getLine()
    ), ['source' => 'mft-azul-api']);
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
    if ($body === false) {
        return new WP_Error('azul_config', 'No se pudo armar la petición a AZUL.');
    }
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
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => MFT_AZUL_API_TIMEOUT,
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
            $connect_errors = [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CERTPROBLEM];
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
            mft_azul_api_log($method . ' respuesta no JSON', ['raw' => substr((string) $raw, 0, 300)]);
            return new WP_Error('azul_parse', 'Respuesta de AZUL no válida.');
        }
        // Algunas respuestas vienen envueltas (ej. {"ProcessPaymentResult": {...}}).
        if (count($data) === 1) {
            $inner = reset($data);
            if (is_array($inner) && (isset($inner['IsoCode']) || isset($inner['ResponseCode']))) {
                $data = $inner;
            }
        }
        if (!isset($data['IsoCode']) && !isset($data['ResponseCode'])) {
            mft_azul_api_log($method . ' respuesta sin IsoCode', ['keys' => array_keys($data)]);
            return new WP_Error('azul_parse', 'Respuesta de AZUL incompleta.');
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
 * Candado por orden contra cobros dobles (doble clic, dos pestañas, el cron de
 * VerifyPayment). INSERT IGNORE sobre la clave única de wp_options es atómico y
 * no pasa por la caché de objetos (Redis); add_option no sirve: hace
 * INSERT … ON DUPLICATE KEY UPDATE y consulta la caché antes.
 */
function mft_azul_api_lock($order_id)
{
    global $wpdb;
    $name = 'mft_azul_lock_' . (int) $order_id;
    // Candado huérfano (proceso que murió): más viejo que el timeout de AZUL.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
        $name,
        time() - (MFT_AZUL_API_TIMEOUT * 2 + 30)
    ));
    $ok = (int) $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
        $name,
        (string) time()
    ));
    return $ok === 1;
}

function mft_azul_api_unlock($order_id)
{
    global $wpdb;
    $wpdb->delete($wpdb->options, ['option_name' => 'mft_azul_lock_' . (int) $order_id]);
}

/** Lee la orden de nuevo desde la base (sin la copia en memoria de esta petición). */
function mft_azul_api_fresh_order($order_id)
{
    if (function_exists('wp_cache_delete')) {
        wp_cache_delete($order_id, 'posts');
        wp_cache_delete($order_id, 'post_meta');
        wp_cache_delete($order_id, 'orders');
        wp_cache_delete('order-' . $order_id, 'orders');
    }
    return wc_get_order($order_id);
}

/** ¿AZUL ya aprobó un cobro para esta orden? (aunque completarla haya fallado) */
function mft_azul_api_is_charged($order)
{
    return $order->is_paid() || (bool) $order->get_meta('_mft_azul_charged');
}

/**
 * Marca la orden como pagada (mismo criterio que la Página de Pago AZUL).
 * Primero se guarda la aprobación: si después falla completar la orden (un hook
 * de otro plugin), nunca se vuelve a cobrar y queda una nota para revisar.
 */
function mft_azul_api_mark_paid($order, array $res)
{
    if ($order->is_paid()) {
        return;
    }
    $gw = mft_azul_api_gateway();
    $test = !$gw || $gw->is_test();

    $order->update_meta_data('_mft_azul_charged', (string) ($res['AzulOrderId'] ?? '1'));
    $order->update_meta_data('_mft_azul_order_id', (string) ($res['AzulOrderId'] ?? $order->get_meta('_mft_azul_order_id')));
    $order->update_meta_data('_mft_azul_authorization_code', (string) ($res['AuthorizationCode'] ?? ''));
    $order->update_meta_data('_mft_azul_rrn', (string) ($res['RRN'] ?? ''));
    $order->update_meta_data('_mft_azul_datetime', (string) ($res['DateTime'] ?? gmdate('YmdHis')));
    $order->update_meta_data('_mft_azul_approved_at', time());
    $order->update_meta_data('AuthorizationCode', (string) ($res['AuthorizationCode'] ?? ''));
    $order->update_meta_data('_mft_azul_3ds_state', 'done');
    $order->delete_meta_data('_mft_azul_pending_verify');
    $order->delete_meta_data('_mft_azul_inflight');
    if ($test) {
        $order->update_meta_data('_mft_azul_test', 1);
    }
    $order->save();

    $order->add_order_note(sprintf(
        'AZUL API aprobado. Autorización: %s · RRN: %s · AzulOrderId: %s · Tarjeta ****%s',
        $res['AuthorizationCode'] ?? '',
        $res['RRN'] ?? '',
        $res['AzulOrderId'] ?? $order->get_meta('_mft_azul_order_id'),
        $order->get_meta('_mft_azul_card_last4')
    ));
    if ($test) {
        $order->add_order_note('PAGO DE PRUEBA (AZUL pruebas): no se cobró dinero. No entregar ni despachar esta orden.');
    }

    // payment_complete dispara los hooks de pago (FooEvents, stock, correos).
    // WooCommerce atrapa las excepciones de esos hooks y devuelve false.
    $error = '';
    try {
        $ok = $order->payment_complete((string) ($res['AuthorizationCode'] ?? ''));
        $order = mft_azul_api_fresh_order($order->get_id());
        if ($ok && $order->is_paid()) {
            $order->update_status('completed', 'El pago ha sido completado');
            return;
        }
        $error = 'WooCommerce no pudo registrar el pago';
    } catch (Throwable $e) {
        mft_azul_api_log_error('payment_complete', $e);
        $error = $e->getMessage();
    }
    if (function_exists('wc_get_logger')) {
        wc_get_logger()->error('Orden ' . $order->get_id() . ' cobrada en AZUL sin completar: ' . $error, ['source' => 'mft-azul-api']);
    }
    $order->update_status('on-hold', 'ATENCIÓN: AZUL aprobó el cobro pero falló completar la orden (' . $error . '). NO cobrar de nuevo: revisa la orden y complétala manualmente.');
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
        return MFT_AZUL_API_MSG['card'];
    }
    if (str_contains((string) ($res['ErrorDescription'] ?? ''), 'SGS-005003')) {
        return 'El procesador de pagos no pudo registrar el intento. Espera unos segundos e intenta de nuevo; no se te cobró nada.';
    }
    if ($iso === '99') {
        return stripos((string) ($res['ErrorDescription'] ?? ''), '3D') !== false
            ? 'Tu banco no pudo autenticar la tarjeta (3D Secure). Intenta de nuevo o usa otra tarjeta.'
            : MFT_AZUL_API_MSG['card'];
    }
    if ($iso === '08') {
        return 'Tu banco no pudo autenticar la tarjeta en este momento. Intenta más tarde o usa otra tarjeta.';
    }
    $known = [
        '51' => 'Fondos insuficientes. Intenta con otra tarjeta.',
        '54' => 'La tarjeta está vencida. Intenta con otra tarjeta.',
        '05' => MFT_AZUL_API_MSG['declined'],
        '14' => 'El número de tarjeta no es válido. Verifícalo e intenta de nuevo.',
        '41' => MFT_AZUL_API_MSG['declined'],
        '43' => MFT_AZUL_API_MSG['declined'],
        '57' => 'Tu tarjeta no permite este tipo de compra. Contacta a tu banco o usa otra tarjeta.',
        '61' => 'La compra supera el límite de tu tarjeta. Intenta con otra tarjeta.',
        '82' => 'El código de seguridad (CVC) no es válido.',
        'N7' => 'El código de seguridad (CVC) no es válido.',
        '91' => 'Tu banco no responde en este momento. Intenta más tarde.',
    ];
    return $known[$iso] ?? MFT_AZUL_API_MSG['declined'];
}

/** Programa la verificación (VerifyPayment) de un intento con resultado desconocido. */
function mft_azul_api_schedule_verify($order, $custom_order_id, $tries = 0)
{
    $order->update_meta_data('_mft_azul_pending_verify', $custom_order_id);
    $order->delete_meta_data('_mft_azul_inflight');
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
            return ['status' => 'pending', 'message' => MFT_AZUL_API_MSG['unknown']];
        }
        $order->delete_meta_data('_mft_azul_inflight');
        $order->save();
        $order->add_order_note('AZUL API error: ' . $res->get_error_message());
        return ['status' => 'error', 'message' => MFT_AZUL_API_MSG['connect']];
    }

    $iso = (string) ($res['IsoCode'] ?? '');
    if (!empty($res['AzulOrderId'])) {
        $order->update_meta_data('_mft_azul_order_id', (string) $res['AzulOrderId']);
    }
    $order->delete_meta_data('_mft_azul_inflight');

    if ($iso === '00') {
        mft_azul_api_mark_paid($order, $res);
        return ['status' => 'approved', 'redirect' => mft_azul_api_approved_redirect($order)];
    }

    if ($iso === '3D2METHOD') {
        $form = (string) ($res['ThreeDSMethod']['MethodForm'] ?? '');
        $order->update_meta_data('_mft_azul_3ds_state', 'method');
        $order->update_meta_data('_mft_azul_method_received', 0);
        $order->save();
        return ['status' => 'method', 'methodForm' => $form];
    }

    if ($iso === '3D') {
        $url = (string) ($res['ThreeDSChallenge']['RedirectPostUrl'] ?? '');
        $creq = (string) ($res['ThreeDSChallenge']['CReq'] ?? '');
        if (!wp_http_validate_url($url) || wp_parse_url($url, PHP_URL_SCHEME) !== 'https' || $creq === '') {
            $order->update_meta_data('_mft_azul_3ds_state', 'declined');
            $order->save();
            $order->add_order_note('AZUL API: desafío 3DS sin URL o CReq válidos. ' . mft_azul_api_detail($res));
            return ['status' => 'declined', 'message' => MFT_AZUL_API_MSG['auth']];
        }
        $order->update_meta_data('_mft_azul_3ds_state', 'challenge');
        $order->save();
        return [
            'status' => 'challenge',
            'redirectPostUrl' => $url,
            'creq' => $creq,
            'termUrl' => mft_azul_api_term_url($order),
        ];
    }

    // Declinada o error de validación. La orden sigue `pending` para reintentar.
    $order->update_meta_data('_mft_azul_3ds_state', 'declined');
    $order->save();
    $order->add_order_note('AZUL API declinado. ' . mft_azul_api_detail($res));
    mft_azul_api_count_decline();
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
    if ($gw && $gw->is_test()) {
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

/**
 * Orden a partir de order_id + order_key (lo que tiene la página de pago).
 * En Pruebas, además, la orden tiene que haber pasado por el checkout con el
 * método disponible (un administrador o probador): así nadie paga con tarjetas
 * de prueba una orden ajena aunque tenga su enlace.
 */
function mft_azul_api_order_from_request(WP_REST_Request $request)
{
    $order_id = (int) $request->get_param('order_id');
    $key = (string) $request->get_param('order_key');
    $order = $order_id > 0 ? wc_get_order($order_id) : false;
    if (!$order || $order instanceof WC_Order_Refund || $key === '' || !hash_equals($order->get_order_key(), $key)) {
        return new WP_Error('mft_azul_order', MFT_AZUL_API_MSG['order'], ['status' => 404]);
    }
    if ($order->get_payment_method() !== MFT_AZUL_API_ID) {
        return new WP_Error('mft_azul_order', 'Esta orden no usa pago con tarjeta. Vuelve a la página de pago y elige el método.', ['status' => 400]);
    }
    $gw = mft_azul_api_gateway();
    if ($gw && $gw->is_test() && $order->get_meta('_mft_azul_allowed') !== 'test') {
        return new WP_Error('mft_azul_order', MFT_AZUL_API_MSG['unavailable'], ['status' => 403]);
    }
    return $order;
}

/** Orden a partir de order + sid (callbacks del ACS, que no conocen la order_key). */
function mft_azul_api_order_from_sid(WP_REST_Request $request)
{
    $order_id = (int) $request->get_param('order');
    $sid = (string) $request->get_param('sid');
    $order = $order_id > 0 ? wc_get_order($order_id) : false;
    if (!$order || $order instanceof WC_Order_Refund || $sid === '' || !hash_equals((string) $order->get_meta('_mft_azul_sid'), $sid)) {
        return null;
    }
    return $order;
}

function mft_azul_api_public_ip($ip)
{
    return (bool) filter_var(trim((string) $ip), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/**
 * IPs del cliente para el límite de intentos (se cuentan todas).
 *  - REMOTE_ADDR pública: es la conexión real y no se puede falsear.
 *  - REMOTE_ADDR privada: es nuestro proxy (Varnish/nginx) y la comparten todos
 *    los clientes; se usa el último salto público de X-Forwarded-For, que lo
 *    agrega nuestro proxy (los anteriores los puede inventar el cliente).
 *  - La de WC_Geolocation (cabeceras falseables) se suma: falsearla solo agrega
 *    contadores, nunca evita los anteriores.
 */
function mft_azul_api_client_ips()
{
    $ips = [];
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (mft_azul_api_public_ip($remote)) {
        $ips[] = $remote;
    } else {
        $chain = array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($chain as $hop) {
            if (mft_azul_api_public_ip($hop)) {
                $ips[] = $hop;
                break;
            }
        }
        if (!$ips && $remote !== '') {
            $ips[] = $remote;
        }
    }
    if (class_exists('WC_Geolocation')) {
        $ips[] = (string) WC_Geolocation::get_ip_address();
    }
    return array_values(array_unique(array_filter($ips)));
}

/** Límite por IP contra pruebas masivas de tarjetas (card testing). */
function mft_azul_api_rate_limited()
{
    $limit = (int) apply_filters('mft_azul_api_ip_limit', 15);
    $keys = [];
    foreach (mft_azul_api_client_ips() as $ip) {
        $key = 'mft_azul_rl_' . md5($ip);
        if ((int) get_transient($key) >= $limit) {
            return true;
        }
        $keys[] = $key;
    }
    if (mft_azul_api_breaker_open()) {
        return true;
    }
    foreach ($keys as $key) {
        set_transient($key, (int) get_transient($key) + 1, HOUR_IN_SECONDS);
    }
    return false;
}

/**
 * Freno global: muchas tarjetas rechazadas en una hora en todo el sitio es la
 * señal típica de card testing desde muchas IPs. Se pausa el método un rato.
 */
function mft_azul_api_count_decline()
{
    $key = 'mft_azul_declines_' . gmdate('YmdH');
    set_transient($key, (int) get_transient($key) + 1, 2 * HOUR_IN_SECONDS);
}

function mft_azul_api_breaker_open()
{
    $limit = (int) apply_filters('mft_azul_api_decline_limit', 40);
    return (int) get_transient('mft_azul_declines_' . gmdate('YmdH')) >= $limit;
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
    $states = $country ? (array) WC()->countries->get_states($country) : [];
    $fields = [
        'Name' => $name,
        'Email' => $order->get_billing_email(),
        'PhoneMobile' => $order->get_billing_phone(),
        'BillingAddressLine1' => $order->get_billing_address_1(),
        'BillingAddressLine2' => $order->get_billing_address_2(),
        'BillingAddressCity' => $order->get_billing_city(),
        'BillingAddressState' => $states[$state] ?? $state,
        'BillingAddressCountry' => $country,
        'BillingAddressZip' => $order->get_billing_postcode(),
    ];
    $limits = ['Email' => 254, 'PhoneMobile' => 32, 'BillingAddressZip' => 24, 'BillingAddressCountry' => 2];
    $out = [];
    foreach ($fields as $k => $v) {
        $v = trim(wp_strip_all_tags((string) $v));
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
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
    $int = function ($k, $default, $min, $max) use ($b) {
        if (!isset($b[$k]) || !is_numeric($b[$k])) {
            return $default;
        }
        return (string) max($min, min($max, (int) $b[$k]));
    };
    $lang = isset($b['language']) && is_string($b['language'])
        ? substr(preg_replace('/[^A-Za-z\-]/', '', $b['language']), 0, 8)
        : '';
    return [
        'AcceptHeader' => substr($accept, 0, 2048),
        'IPAddress' => (string) (mft_azul_api_client_ips()[0] ?? ''),
        'Language' => $lang !== '' ? $lang : 'es-DO',
        'ColorDepth' => $int('colorDepth', '24', 1, 48),
        'ScreenWidth' => $int('screenWidth', '1280', 1, 99999),
        'ScreenHeight' => $int('screenHeight', '800', 1, 99999),
        'TimeZone' => $int('timeZone', '240', -900, 900),
        'UserAgent' => substr($ua, 0, 2048),
        'JavaScriptEnabled' => 'true',
    ];
}

/** La conexión del cliente es HTTPS (también detrás de Cloudflare/Varnish). */
function mft_azul_api_is_https()
{
    if (is_ssl()) {
        return true;
    }
    $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $cf = (string) ($_SERVER['HTTP_CF_VISITOR'] ?? '');
    return $proto === 'https' || str_contains($cf, '"https"');
}

// ===========================================================================
// REST: mft/v1/azul-api/*
// ===========================================================================

add_action('rest_api_init', function () {
    $order_args = [
        'order_id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
        'order_key' => ['type' => 'string', 'required' => true, 'maxLength' => 64],
    ];
    register_rest_route('mft/v1', '/azul-api/pay', [
        'methods' => 'POST',
        'callback' => mft_azul_api_guarded('mft_azul_api_rest_pay'),
        'permission_callback' => '__return_true',
        'args' => $order_args + [
            'card' => ['type' => 'object', 'required' => true],
            'browser' => ['type' => 'object', 'required' => false],
        ],
    ]);
    register_rest_route('mft/v1', '/azul-api/method-status', [
        'methods' => 'GET',
        'callback' => mft_azul_api_guarded('mft_azul_api_rest_method_status'),
        'permission_callback' => '__return_true',
        'args' => $order_args,
    ]);
    register_rest_route('mft/v1', '/azul-api/status', [
        'methods' => 'GET',
        'callback' => mft_azul_api_guarded('mft_azul_api_rest_status'),
        'permission_callback' => '__return_true',
        'args' => $order_args,
    ]);
    register_rest_route('mft/v1', '/azul-api/continue', [
        'methods' => 'POST',
        'callback' => mft_azul_api_guarded('mft_azul_api_rest_continue'),
        'permission_callback' => '__return_true',
        'args' => $order_args,
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

/**
 * Envuelve un endpoint JSON: ningún error inesperado llega crudo al cliente.
 * Si había una llamada a AZUL en curso (`_mft_azul_inflight`), el resultado es
 * desconocido y se programa VerifyPayment en vez de permitir otro cobro.
 */
function mft_azul_api_guarded($callback)
{
    return function (WP_REST_Request $request) use ($callback) {
        @set_time_limit(MFT_AZUL_API_TIMEOUT * 2 + 30);
        try {
            return $callback($request);
        } catch (Throwable $e) {
            mft_azul_api_log_error($callback, $e);
            $order_id = (int) $request->get_param('order_id');
            $order = $order_id > 0 ? mft_azul_api_fresh_order($order_id) : null;
            if ($order && $order->get_meta('_mft_azul_inflight')) {
                $order->add_order_note('Error inesperado durante un cobro AZUL (' . $e->getMessage() . '). Se verificará con VerifyPayment.');
                mft_azul_api_schedule_verify($order, (string) $order->get_meta('_mft_azul_inflight'));
                return ['status' => 'pending', 'message' => MFT_AZUL_API_MSG['unknown']];
            }
            return new WP_Error('mft_azul_error', MFT_AZUL_API_MSG['generic'], ['status' => 500]);
        }
    };
}

// Las respuestas de pago nunca se cachean (Varnish, navegador).
add_filter('rest_post_dispatch', function ($response, $server, $request) {
    if (str_starts_with((string) $request->get_route(), '/mft/v1/azul-api/')) {
        foreach (wp_get_nocache_headers() as $name => $value) {
            $response->header($name, $value);
        }
    }
    return $response;
}, 10, 3);

// Los errores de validación de WordPress (en inglés) se traducen para la página de pago.
add_filter('rest_request_after_callbacks', function ($response, $handler, $request) {
    if (is_wp_error($response) && str_starts_with((string) $request->get_route(), '/mft/v1/azul-api/')
        && !str_starts_with((string) $response->get_error_code(), 'mft_azul')) {
        $data = (array) $response->get_error_data();
        return new WP_Error('mft_azul_request', MFT_AZUL_API_MSG['generic'], ['status' => (int) ($data['status'] ?? 400)]);
    }
    return $response;
}, 10, 3);

function mft_azul_api_rest_pay(WP_REST_Request $request)
{
    $gw = mft_azul_api_gateway();
    if (!$gw || !$gw->is_configured() || $gw->enabled !== 'yes') {
        return new WP_Error('mft_azul_config', MFT_AZUL_API_MSG['unavailable'], ['status' => 503]);
    }
    if (!$gw->is_test() && !mft_azul_api_is_https()) {
        return new WP_Error('mft_azul_ssl', 'La conexión no es segura. Abre la página de pago con https://.', ['status' => 400]);
    }
    $order = mft_azul_api_order_from_request($request);
    if (is_wp_error($order)) {
        return $order;
    }
    if ($order->get_currency() !== MFT_AZUL_API_CURRENCY) {
        return new WP_Error('mft_azul_currency', MFT_AZUL_API_MSG['unavailable'], ['status' => 400]);
    }
    if (mft_azul_api_is_charged($order)) {
        return ['status' => 'approved', 'redirect' => mft_azul_api_approved_redirect($order)];
    }
    if (!$order->needs_payment()) {
        return new WP_Error('mft_azul_order', MFT_AZUL_API_MSG['expired'], ['status' => 409]);
    }

    // Un intento anterior quedó sin respuesta: primero se verifica, nunca se cobra a ciegas.
    if ($order->get_meta('_mft_azul_pending_verify')) {
        mft_azul_api_verify_order($order->get_id(), 99);
        $order = mft_azul_api_fresh_order($order->get_id());
        if (mft_azul_api_is_charged($order)) {
            return ['status' => 'approved', 'redirect' => mft_azul_api_approved_redirect($order)];
        }
        if ($order->get_meta('_mft_azul_pending_verify')) {
            return ['status' => 'pending', 'message' => MFT_AZUL_API_MSG['verifying']];
        }
    }

    // Tarjeta (solo en memoria; nunca se persiste).
    $card = (array) $request->get_param('card');
    $scalar = function ($k) use ($card) {
        return isset($card[$k]) && is_scalar($card[$k]) ? (string) $card[$k] : '';
    };
    $number = preg_replace('/\D/', '', $scalar('number'));
    $cvc = preg_replace('/\D/', '', $scalar('cvc'));
    $month = (int) $scalar('expMonth');
    $year = (int) $scalar('expYear');
    if ($year > 0 && $year < 100) {
        $year += 2000;
    }
    $name = trim(sanitize_text_field($scalar('name')));
    $exp_ts = ($year >= 2000 && $year <= 2100) ? mktime(0, 0, 0, $month + 1, 1, $year) : 0;

    if ($name === '' || mb_strlen($name) > 60) {
        return ['status' => 'invalid', 'field' => 'name', 'message' => 'Escribe el nombre como aparece en la tarjeta.'];
    }
    if (strlen($number) < 13 || strlen($number) > 19 || !mft_azul_api_luhn($number)) {
        return ['status' => 'invalid', 'field' => 'number', 'message' => 'El número de tarjeta no es válido.'];
    }
    if ($month < 1 || $month > 12 || $exp_ts <= time()) {
        return ['status' => 'invalid', 'field' => 'exp', 'message' => 'La fecha de vencimiento no es válida.'];
    }
    if (strlen($cvc) < 3 || strlen($cvc) > 4) {
        return ['status' => 'invalid', 'field' => 'cvc', 'message' => 'El código de seguridad no es válido.'];
    }

    if (mft_azul_api_rate_limited()) {
        return new WP_Error('mft_azul_rate', MFT_AZUL_API_MSG['rate'], ['status' => 429]);
    }
    $max = max(1, (int) $gw->get_option('max_attempts', 5));
    if ((int) $order->get_meta('_mft_azul_attempts') >= $max) {
        return new WP_Error('mft_azul_attempts', MFT_AZUL_API_MSG['attempts'], ['status' => 429]);
    }
    if (!mft_azul_api_lock($order->get_id())) {
        return new WP_Error('mft_azul_busy', MFT_AZUL_API_MSG['busy'], ['status' => 409]);
    }

    try {
        // Con el candado, se vuelve a leer la orden: otra petición pudo cobrarla
        // entre la primera lectura y este punto.
        $order = mft_azul_api_fresh_order($order->get_id());
        if (mft_azul_api_is_charged($order)) {
            return ['status' => 'approved', 'redirect' => mft_azul_api_approved_redirect($order)];
        }
        if (!$order->needs_payment() || $order->get_meta('_mft_azul_pending_verify')) {
            return ['status' => 'pending', 'message' => MFT_AZUL_API_MSG['verifying']];
        }
        $attempts = (int) $order->get_meta('_mft_azul_attempts');
        if ($attempts >= $max) {
            return new WP_Error('mft_azul_attempts', MFT_AZUL_API_MSG['attempts'], ['status' => 429]);
        }

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
    $order->update_meta_data('_mft_azul_inflight', $custom_order_id);
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

/**
 * Estado de la orden para la página de pago cuando no supo el resultado (corte
 * de red, timeout de Cloudflare…).
 */
function mft_azul_api_rest_status(WP_REST_Request $request)
{
    $order = mft_azul_api_order_from_request($request);
    if (is_wp_error($order)) {
        return $order;
    }
    $order = mft_azul_api_fresh_order($order->get_id());
    if (mft_azul_api_is_charged($order)) {
        return ['status' => 'approved', 'redirect' => mft_azul_api_approved_redirect($order)];
    }
    if ($order->get_meta('_mft_azul_inflight') || $order->get_meta('_mft_azul_pending_verify')) {
        return ['status' => 'processing'];
    }
    return ['status' => 'open'];
}

/** El ACS del emisor confirma (dentro del iframe oculto) que recibió los datos del navegador. */
function mft_azul_api_rest_method_notify(WP_REST_Request $request)
{
    try {
        $order = mft_azul_api_order_from_sid($request);
        if ($order && $order->get_meta('_mft_azul_3ds_state') === 'method') {
            $raw = $request->get_param('threeDSMethodData');
            $data = is_string($raw) ? json_decode((string) base64_decode($raw, true), true) : null;
            $order->update_meta_data('_mft_azul_method_received', 1);
            if (is_array($data) && !empty($data['threeDSServerTransID']) && is_string($data['threeDSServerTransID'])) {
                $order->update_meta_data('_mft_azul_3ds_server_trans_id', substr(sanitize_text_field($data['threeDSServerTransID']), 0, 64));
            }
            $order->save();
        }
    } catch (Throwable $e) {
        mft_azul_api_log_error('method-notify', $e);
    }
    // Se renderiza dentro del iframe oculto: basta una página vacía.
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><title>ok</title>';
    exit;
}

/** La página de pago avisa que terminó la espera del MethodForm (recibido o no en 10 s). */
function mft_azul_api_rest_continue(WP_REST_Request $request)
{
    $gw = mft_azul_api_gateway();
    if (!$gw || !$gw->is_configured()) {
        return new WP_Error('mft_azul_config', MFT_AZUL_API_MSG['unavailable'], ['status' => 503]);
    }
    $order = mft_azul_api_order_from_request($request);
    if (is_wp_error($order)) {
        return $order;
    }
    if (mft_azul_api_is_charged($order)) {
        return ['status' => 'approved', 'redirect' => mft_azul_api_approved_redirect($order)];
    }
    if (!mft_azul_api_lock($order->get_id())) {
        return new WP_Error('mft_azul_busy', MFT_AZUL_API_MSG['busy'], ['status' => 409]);
    }
    try {
        $order = mft_azul_api_fresh_order($order->get_id());
        if (mft_azul_api_is_charged($order)) {
            return ['status' => 'approved', 'redirect' => mft_azul_api_approved_redirect($order)];
        }
        if ($order->get_meta('_mft_azul_3ds_state') !== 'method') {
            return ['status' => 'error', 'message' => 'El pago no está esperando la verificación del banco. Intenta de nuevo.'];
        }
        // La orden expiró (reserva de stock) mientras se verificaba: no se completa el cobro.
        if (!$order->needs_payment()) {
            $order->update_meta_data('_mft_azul_3ds_state', 'expired');
            $order->save();
            return ['status' => 'error', 'message' => MFT_AZUL_API_MSG['expired']];
        }
        $order->update_meta_data('_mft_azul_inflight', (string) $order->get_meta('_mft_azul_custom_order_id'));
        $order->save();
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
    @set_time_limit(MFT_AZUL_API_TIMEOUT * 2 + 30);
    nocache_headers();
    $order = null;
    try {
        $order = mft_azul_api_order_from_sid($request);
        if (!$order) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
        $redirect = mft_azul_api_term_process($order, $request);
    } catch (Throwable $e) {
        mft_azul_api_log_error('term', $e);
        $redirect = home_url('/');
        if ($order) {
            $order = mft_azul_api_fresh_order($order->get_id());
            if ($order->get_meta('_mft_azul_inflight')) {
                $order->add_order_note('Error inesperado al completar el 3D Secure (' . $e->getMessage() . '). Se verificará con VerifyPayment.');
                mft_azul_api_schedule_verify($order, (string) $order->get_meta('_mft_azul_inflight'));
                $redirect = mft_azul_api_retry_redirect($order, MFT_AZUL_API_MSG['unknown']);
            } else {
                $redirect = mft_azul_api_retry_redirect($order, MFT_AZUL_API_MSG['generic']);
            }
        }
    }
    wp_safe_redirect($redirect);
    exit;
}

/** Completa el desafío 3DS y devuelve a dónde mandar al cliente. */
function mft_azul_api_term_process($order, WP_REST_Request $request)
{
    if (mft_azul_api_is_charged($order)) {
        return mft_azul_api_approved_redirect($order);
    }
    $cres = '';
    foreach (['cRes', 'CRes', 'cres'] as $k) {
        $v = $request->get_param($k);
        if (is_string($v) && $v !== '') {
            $cres = $v;
            break;
        }
    }
    if ($cres === '' || strlen($cres) > 20000 || $order->get_meta('_mft_azul_3ds_state') !== 'challenge') {
        return mft_azul_api_retry_redirect($order, MFT_AZUL_API_MSG['auth']);
    }

    // El ACS a veces envía el formulario dos veces: la segunda espera a la primera.
    $locked = false;
    for ($i = 0; $i < 20 && !($locked = mft_azul_api_lock($order->get_id())); $i++) {
        usleep(500000);
        $order = mft_azul_api_fresh_order($order->get_id());
        if (mft_azul_api_is_charged($order)) {
            return mft_azul_api_approved_redirect($order);
        }
    }
    if (!$locked) {
        return mft_azul_api_retry_redirect($order, MFT_AZUL_API_MSG['verifying']);
    }

    try {
        $order = mft_azul_api_fresh_order($order->get_id());
        if (mft_azul_api_is_charged($order)) {
            return mft_azul_api_approved_redirect($order);
        }
        if ($order->get_meta('_mft_azul_3ds_state') !== 'challenge') {
            return mft_azul_api_retry_redirect($order, MFT_AZUL_API_MSG['auth']);
        }
        // La orden expiró (reserva de stock) mientras el cliente estaba en el banco:
        // no se completa el cobro.
        if (!$order->needs_payment()) {
            $order->update_meta_data('_mft_azul_3ds_state', 'expired');
            $order->save();
            $order->add_order_note('AZUL API: el cliente terminó el 3D Secure con la orden ya ' . $order->get_status() . '; no se completó el cobro.');
            return mft_azul_api_retry_redirect($order, MFT_AZUL_API_MSG['expired']);
        }

        $gw = mft_azul_api_gateway();
        $order->update_meta_data('_mft_azul_inflight', (string) $order->get_meta('_mft_azul_custom_order_id'));
        $order->save();
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
    if ($result['status'] === 'approved') {
        return $result['redirect'];
    }
    return mft_azul_api_retry_redirect($order, $result['message'] ?? MFT_AZUL_API_MSG['generic']);
}

// ===========================================================================
// CRON: VerifyPayment de intentos sin respuesta
// ===========================================================================

add_action('mft_azul_api_verify', 'mft_azul_api_verify_order', 10, 2);

function mft_azul_api_verify_order($order_id, $tries = 0)
{
    try {
        mft_azul_api_verify_run((int) $order_id, (int) $tries);
    } catch (Throwable $e) {
        mft_azul_api_log_error('verify', $e);
    }
}

function mft_azul_api_verify_run($order_id, $tries)
{
    $gw = mft_azul_api_gateway();
    if (!$gw || !mft_azul_api_lock($order_id)) {
        // Hay otra operación en curso: se reintenta más tarde.
        if ($gw && $tries !== 99) {
            wp_schedule_single_event(time() + 2 * MINUTE_IN_SECONDS, 'mft_azul_api_verify', [$order_id, $tries + 1]);
        }
        return;
    }
    try {
        $order = mft_azul_api_fresh_order($order_id);
        if (!$order) {
            return;
        }
        $custom = (string) $order->get_meta('_mft_azul_pending_verify');
        if ($custom === '' || mft_azul_api_is_charged($order)) {
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
        $order->update_meta_data('_mft_azul_3ds_state', 'declined');
        $order->save();
        $order->add_order_note(sprintf(
            'VerifyPayment AZUL: el intento %s %s.',
            $custom,
            $found ? 'no se completó (IsoCode ' . ($res['IsoCode'] ?? '') . ')' : 'no se encontró (no hubo cobro)'
        ));
    } finally {
        mft_azul_api_unlock($order_id);
    }
}

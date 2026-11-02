<?php
/** /print-center/: servicios y formulario de cotización. Requiere una página con el slug "print-center". */

$services = mft_print_services();
$status = sanitize_key($_GET['cotizacion'] ?? '');
$messages = [
    'enviada' => ['success', 'Cotización enviada. Te contactaremos pronto.'],
    'incompleta' => ['error', 'Por favor completa los campos obligatorios.'],
    'error' => ['error', 'No pudimos enviar tu cotización. Intenta de nuevo o escríbenos por WhatsApp.'],
];

get_header();
?>
<div class="mft-print-hero">
  <div class="mft-container">
    <h1>Print Center B2B</h1>
    <p>Soluciones de impresión profesional de alto volumen para empresas y eventos corporativos</p>
  </div>
</div>

<div class="mft-container mft-page">
  <h2 class="mft-print__h2">Nuestros Servicios</h2>
  <div class="mft-grid mft-grid--3 mft-print__services">
    <?php foreach ($services as $service) : ?>
      <div class="mft-card mft-print-service">
        <img src="<?php echo esc_url($service['image']); ?>" alt="<?php echo esc_attr($service['name']); ?>" loading="lazy">
        <div>
          <h3><?php echo esc_html($service['name']); ?></h3>
          <p><?php echo esc_html($service['description']); ?></p>
          <strong><?php echo esc_html($service['price']); ?></strong>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="mft-print__grid" id="cotizar">
    <div class="mft-card mft-print__form">
      <h2>Solicita tu Cotización</h2>
      <?php if (isset($messages[$status])) : ?>
        <p class="mft-notice mft-notice--<?php echo esc_attr($messages[$status][0]); ?>" role="status"><?php echo esc_html($messages[$status][1]); ?></p>
      <?php endif; ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mft-form">
        <input type="hidden" name="action" value="mft_print_quote">
        <?php wp_nonce_field('mft_print_quote', 'mft_print_nonce'); ?>
        <div class="mft-hp" aria-hidden="true"><label>Sitio web <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <label>Nombre Completo *<input type="text" name="name" required autocomplete="name"></label>
        <label>Empresa<input type="text" name="company" autocomplete="organization"></label>
        <label>Correo Electrónico *<input type="email" name="email" required autocomplete="email"></label>
        <label>Teléfono<input type="tel" name="phone" autocomplete="tel"></label>
        <label>Servicio de Interés *
          <select name="service" required>
            <option value="">Selecciona un servicio</option>
            <?php foreach ($services as $service) : ?><option><?php echo esc_html($service['name']); ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Descripción del Proyecto<textarea name="message" rows="4" placeholder="Cuéntanos sobre tu proyecto, cantidad aproximada, fechas de entrega, etc."></textarea></label>
        <button class="mft-btn mft-btn--primary mft-btn--lg mft-btn--block" type="submit">Enviar Cotización</button>
      </form>
    </div>

    <div class="mft-print__side">
      <div class="mft-card mft-print__box">
        <h3>¿Prefieres WhatsApp?</h3>
        <p>Contáctanos directamente por WhatsApp para una respuesta inmediata.</p>
        <a class="mft-btn mft-btn--whatsapp mft-btn--lg mft-btn--block" href="<?php echo esc_url(mft_whatsapp_url('Hola, me interesa solicitar una cotización para servicios de impresión.')); ?>" target="_blank" rel="noopener noreferrer">
          <?php echo mft_icon('message'); ?> Abrir WhatsApp
        </a>
      </div>
      <div class="mft-card mft-print__box mft-print__box--why">
        <h3>¿Por qué elegirnos?</h3>
        <ul class="mft-checklist">
          <li>Equipos de impresión de última generación</li>
          <li>Tiempos de entrega garantizados</li>
          <li>Soporte personalizado para proyectos corporativos</li>
          <li>Precios competitivos para alto volumen</li>
        </ul>
      </div>
    </div>
  </div>
</div>
<?php
get_footer();

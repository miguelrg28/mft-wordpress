<?php
/** /contacto/: datos de Ajustes → Contacto MFT. Requiere una página con el slug "contacto". */

$contact = mft_contact();
$socials = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'twitter' => 'X'];
$socials = array_filter($socials, fn($key) => !empty($contact[$key]), ARRAY_FILTER_USE_KEY);

get_header();
get_template_part('template-parts/hero', null, ['slides' => mft_hero_slides('contacto'), 'compact' => true]);
?>
<div class="mft-container mft-page mft-contact">
  <h1 class="mft-with-icon mft-contact__title"><?php echo mft_icon('building', 'mft-icon--lg'); ?> Oficina Comercial</h1>
  <p class="mft-contact__lead">¿Tienes preguntas sobre eventos, tours o la tienda? Visítanos o escríbenos.</p>

  <div class="mft-contact__grid">
    <?php if ($contact['phone_office']) : ?>
      <div class="mft-card mft-contact__item"><?php echo mft_icon('phone'); ?><div><h3>Oficina</h3>
        <a href="tel:<?php echo esc_attr(preg_replace('/[^\d+]/', '', $contact['phone_office'])); ?>"><?php echo esc_html($contact['phone_office']); ?></a></div></div>
    <?php endif; ?>
    <?php if ($contact['phone_display']) : ?>
      <div class="mft-card mft-contact__item"><?php echo mft_icon('message'); ?><div><h3>WhatsApp</h3>
        <a href="<?php echo esc_url(mft_whatsapp_url()); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($contact['phone_display']); ?></a></div></div>
    <?php endif; ?>
    <?php if ($contact['email']) : ?>
      <div class="mft-card mft-contact__item"><?php echo mft_icon('mail'); ?><div><h3>Correo</h3>
        <a class="mft-break" href="mailto:<?php echo esc_attr($contact['email']); ?>"><?php echo esc_html($contact['email']); ?></a></div></div>
    <?php endif; ?>
    <?php if ($contact['schedule']) : ?>
      <div class="mft-card mft-contact__item"><?php echo mft_icon('clock'); ?><div><h3>Horario de atención</h3>
        <p><?php echo esc_html($contact['schedule']); ?></p></div></div>
    <?php endif; ?>
    <?php if ($contact['address']) : ?>
      <div class="mft-card mft-contact__item mft-contact__item--wide"><?php echo mft_icon('map-pin'); ?><div><h3>Ubicación</h3>
        <p><?php echo esc_html($contact['address']); ?></p>
        <?php if ($contact['maps_url']) : ?>
          <a class="mft-link-accent" href="<?php echo esc_url($contact['maps_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo mft_icon('external'); ?> Cómo llegar en Google Maps</a>
        <?php endif; ?>
      </div></div>
    <?php endif; ?>
  </div>

  <?php if ($contact['whatsapp']) : ?>
    <a class="mft-btn mft-btn--success mft-btn--xl mft-btn--block" href="<?php echo esc_url(mft_whatsapp_url()); ?>" target="_blank" rel="noopener noreferrer">
      <?php echo mft_icon('message'); ?> Escríbenos por WhatsApp
    </a>
  <?php endif; ?>

  <?php if ($socials) : ?>
    <div class="mft-contact__social">
      <h2>Síguenos</h2>
      <div>
        <?php foreach ($socials as $key => $label) : ?>
          <a href="<?php echo esc_url($contact[$key]); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr($label); ?>"><?php echo mft_icon($key); ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php
get_footer();

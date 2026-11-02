<?php
/**
 * Piezas de las páginas de itinerarios. Args: part = whatsapp | contact | cuotas | month,
 * y según la pieza: label (whatsapp), block (month: ['month' => …, 'trips' => […]]).
 */
$part = $args['part'];
$whatsapp = mft_itinerary_whatsapp_url();

if ($part === 'whatsapp') : ?>
  <a class="mft-btn mft-btn--whatsapp" href="<?php echo esc_url($whatsapp); ?>" target="_blank" rel="noopener noreferrer">
    <?php echo mft_icon('message'); ?> <?php echo esc_html($args['label'] ?? 'Contactar por WhatsApp'); ?>
  </a>

<?php elseif ($part === 'contact') : ?>
  <div class="mft-card mft-itin-contact">
    <div>
      <p class="mft-itin-contact__q">¿Te interesa algún viaje o salida?</p>
      <p>Verifica disponibilidad y reserva con nuestros representantes vía WhatsApp.</p>
      <p class="mft-itin-contact__phone"><?php echo esc_html(mft_contact()['phone_display']); ?></p>
    </div>
    <?php get_template_part('template-parts/itinerary-parts', null, ['part' => 'whatsapp']); ?>
  </div>

<?php elseif ($part === 'cuotas') : ?>
  <div class="mft-itin-cuotas">
    <img src="<?php echo esc_url(get_theme_file_uri('assets/img/cuotas-tarjetas.png')); ?>" alt="Cuotas disponibles con tarjetas" loading="lazy">
  </div>

<?php elseif ($part === 'month') : ?>
  <?php $block = $args['block']; ?>
  <section class="mft-itin-month">
    <h2 class="mft-itin-month__title"><span><?php echo esc_html($block['month']); ?></span></h2>
    <div class="mft-itin-trips">
      <?php foreach ($block['trips'] as $trip) : ?>
        <a class="mft-card mft-itin-trip" href="<?php echo esc_url($trip['url'] ?: $whatsapp); ?>" target="_blank" rel="noopener noreferrer">
          <div class="mft-itin-trip__text">
            <p class="mft-itin-trip__name">
              <?php echo esc_html($trip['destination']); ?>
              <?php if ($trip['note']) : ?><span class="mft-itin-trip__note">✦ <?php echo esc_html($trip['note']); ?></span><?php endif; ?>
            </p>
            <p class="mft-meta"><?php echo mft_icon('map-pin'); ?> <?php echo esc_html($trip['dates']); ?></p>
          </div>
          <div class="mft-itin-trip__price">
            <span><?php echo esc_html($trip['price']); ?></span>
            <?php echo mft_icon($trip['url'] ? 'external' : 'arrow-right'); ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif;

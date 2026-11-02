<?php $mft_contact = mft_contact(); ?>
</main>

<footer class="mft-footer">
  <div class="mft-container">
    <div class="mft-footer__grid">
      <div>
        <a class="mft-footer__logo" href="<?php echo esc_url(home_url('/')); ?>">
          <img src="<?php echo esc_url(get_theme_file_uri('assets/img/logo-full.webp')); ?>" alt="<?php bloginfo('name'); ?>">
        </a>
        <p>Tu plataforma de confianza para eventos exclusivos, tours inolvidables y servicios de impresión profesional.</p>
      </div>

      <div>
        <h4>Navegación</h4>
        <?php mft_menu('footer', 'mft-footer__links'); ?>
      </div>

      <div>
        <h4>Legal</h4>
        <?php mft_menu('legal', 'mft-footer__links'); ?>
      </div>

      <div>
        <h4>Síguenos</h4>
        <div class="mft-footer__social">
          <?php foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook'] as $network => $label) : ?>
            <?php if (!empty($mft_contact[$network])) : ?>
              <a href="<?php echo esc_url($mft_contact[$network]); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr($label); ?>"><?php echo mft_icon($network); ?></a>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if (!empty($mft_contact['email'])) : ?>
            <a href="mailto:<?php echo esc_attr($mft_contact['email']); ?>" aria-label="Correo"><?php echo mft_icon('mail'); ?></a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="mft-footer__bottom">
      <p>© <?php echo esc_html(wp_date('Y')); ?> Mas Flow Team. Todos los derechos reservados.</p>
      <img src="<?php echo esc_url(get_theme_file_uri('assets/img/azul.png')); ?>" alt="AZUL" class="mft-footer__azul">
    </div>
  </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>

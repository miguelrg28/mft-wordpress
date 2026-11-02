/**
 * MFT: comportamiento del sitio sin dependencias.
 *   - Carrusel del hero ([data-mft-carousel]): deslizar, flechas y autoplay.
 *   - Menú móvil ([data-mft-menu-open] / [data-mft-menu-close]).
 */
(function () {
  'use strict';

  // --- Carrusel ------------------------------------------------------------
  document.querySelectorAll('[data-mft-carousel]').forEach(function (root) {
    var track = root.querySelector('.mft-carousel__track');
    var slides = track ? track.children : [];
    if (!track || slides.length < 2) return;

    var delay = parseInt(root.getAttribute('data-autoplay'), 10) || 0;
    var timer = null;

    // Se avanza de a una "página" (el ancho visible): en el hero es un slide; en
    // los recomendados de la ficha, los 2-3 que se ven a la vez.
    function current() {
      return Math.round(track.scrollLeft / track.clientWidth);
    }
    // En bucle: después de la última página vuelve a la primera y viceversa.
    function go(index) {
      var count = Math.max(1, Math.round(track.scrollWidth / track.clientWidth));
      var target = ((index % count) + count) % count;
      track.scrollTo({ left: target * track.clientWidth, behavior: 'smooth' });
    }
    function stop() {
      if (timer) clearInterval(timer);
      timer = null;
    }
    function start() {
      stop();
      if (delay && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        timer = setInterval(function () { go(current() + 1); }, delay);
      }
    }

    var prev = root.querySelector('[data-mft-prev]');
    var next = root.querySelector('[data-mft-next]');
    if (prev) prev.addEventListener('click', function () { go(current() - 1); start(); });
    if (next) next.addEventListener('click', function () { go(current() + 1); start(); });

    // Si la persona toca o pasa el mouse, se pausa; al soltar sigue.
    root.addEventListener('pointerenter', stop);
    root.addEventListener('pointerleave', start);
    track.addEventListener('touchstart', stop, { passive: true });
    track.addEventListener('touchend', start, { passive: true });
    document.addEventListener('visibilitychange', function () {
      document.hidden ? stop() : start();
    });
    start();
  });

  // --- Menú móvil ----------------------------------------------------------
  var drawer = document.getElementById('mft-drawer');
  var opener = document.querySelector('[data-mft-menu-open]');
  if (drawer && opener) {
    var setOpen = function (open) {
      drawer.hidden = !open;
      opener.setAttribute('aria-expanded', String(open));
      document.body.classList.toggle('mft-no-scroll', open);
      if (open) drawer.querySelector('[data-mft-menu-close]').focus();
    };
    opener.addEventListener('click', function () { setOpen(true); });
    drawer.querySelectorAll('[data-mft-menu-close]').forEach(function (el) {
      el.addEventListener('click', function () { setOpen(false); });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !drawer.hidden) setOpen(false);
    });
  }

  // --- Menú de cuenta (escritorio): se cierra al hacer clic fuera ------------
  document.addEventListener('click', function (e) {
    document.querySelectorAll('details.mft-account[open]').forEach(function (el) {
      if (!el.contains(e.target)) el.removeAttribute('open');
    });
  });
})();

/** Subida del comprobante de transferencia en la página de "gracias" (inc/checkout.php). */
(function () {
  'use strict';

  var box = document.querySelector('[data-mft-proof]');
  var form = box && box.querySelector('[data-mft-proof-form]');
  if (!form) return;

  var error = box.querySelector('[data-mft-proof-error]');
  var done = box.querySelector('[data-mft-proof-done]');
  var button = form.querySelector('button');

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    error.hidden = true;
    button.disabled = true;
    button.textContent = 'Enviando...';

    fetch(box.getAttribute('data-endpoint'), { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
      .then(function (res) {
        return res.json().then(function (json) {
          if (!res.ok || !json.success) throw new Error(json.error || 'No se pudo subir el comprobante.');
        });
      })
      .then(function () {
        form.remove();
        done.hidden = false;
      })
      .catch(function (err) {
        error.textContent = err.message;
        error.hidden = false;
        button.disabled = false;
        button.textContent = 'Enviar comprobante';
      });
  });
})();

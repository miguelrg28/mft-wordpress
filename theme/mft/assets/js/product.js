/**
 * Ficha de producto: cantidades (− n +), complementarios, resumen con total y
 * "Agregar al carrito" de todo junto (?wc-ajax=mft_add_to_cart, ver inc/product.php).
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-mft-product]');
  if (!form) return;

  var hasTickets = !!form.querySelector('.mft-ticket');
  var summary = form.querySelector('[data-mft-summary]');
  var errorBox = form.querySelector('[data-mft-error]');
  var submits = form.querySelectorAll('[data-mft-submit]');
  var soldOut = form.getAttribute('data-available') !== '1';

  function money(n) {
    var decimals = Math.floor(n) === n ? 0 : 2;
    return 'RD$ ' + n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
  }

  function clamp(input) {
    var min = parseInt(input.min, 10) || 0;
    var max = parseInt(input.max, 10) || 99;
    var value = parseInt(input.value, 10);
    input.value = String(Math.min(max, Math.max(min, isNaN(value) ? min : value)));
  }

  // Recalcula resumen, total y si se puede comprar.
  function update() {
    var rows = [];
    var total = 0;
    var units = 0;

    form.querySelectorAll('[data-mft-line]').forEach(function (line) {
      var input = line.querySelector('input');
      if (!input) return;
      var qty = parseInt(input.value, 10) || 0;
      var price = parseFloat(line.getAttribute('data-price')) || 0;
      line.classList.toggle('is-selected', hasTickets && qty > 0);
      if (!qty) return;
      units += qty;
      total += qty * price;
      rows.push([line.getAttribute('data-label') + ' x ' + qty, money(qty * price)]);
    });

    form.querySelectorAll('[data-mft-addon]').forEach(function (addon) {
      var checked = addon.querySelector('input').checked;
      addon.classList.toggle('is-selected', checked);
      if (!checked) return;
      var price = parseFloat(addon.getAttribute('data-price')) || 0;
      total += price;
      rows.push([addon.getAttribute('data-label'), money(price)]);
    });

    summary.innerHTML = '';
    if (hasTickets && !units) {
      var hint = document.createElement('p');
      hint.textContent = 'Selecciona la cantidad de cada tipo de boleto.';
      summary.appendChild(hint);
    }
    rows.forEach(function (row) {
      var div = document.createElement('div');
      var label = document.createElement('span');
      var amount = document.createElement('span');
      label.textContent = row[0];
      amount.textContent = row[1];
      div.appendChild(label);
      div.appendChild(amount);
      summary.appendChild(div);
    });

    form.querySelectorAll('[data-mft-total]').forEach(function (el) { el.textContent = money(total); });
    submits.forEach(function (btn) { btn.disabled = soldOut || !units; });
    form.dataset.units = String(units);
  }

  form.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-step]');
    if (!btn) return;
    var input = btn.closest('[data-mft-stepper]').querySelector('input');
    input.value = String((parseInt(input.value, 10) || 0) + parseInt(btn.getAttribute('data-step'), 10));
    clamp(input);
    update();
  });
  form.addEventListener('change', function (e) {
    if (e.target.matches('.mft-stepper__input')) clamp(e.target);
    update();
  });
  form.addEventListener('input', update);

  function showError(message) {
    errorBox.textContent = message;
    errorBox.hidden = false;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    errorBox.hidden = true;
    var labels = form.querySelectorAll('[data-mft-submit-label]');
    submits.forEach(function (btn) { btn.disabled = true; btn.classList.add('is-loading'); });
    labels.forEach(function (el) { el.textContent = 'Agregando...'; });

    fetch(form.getAttribute('data-endpoint'), {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
    })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (json && json.success) {
          window.location.href = json.data.redirect;
          return;
        }
        throw new Error((json && json.data && json.data.message) || 'No se pudo agregar al carrito.');
      })
      .catch(function (err) {
        showError(err.message || 'No se pudo agregar al carrito.');
        submits.forEach(function (btn) { btn.classList.remove('is-loading'); });
        labels.forEach(function (el) { el.textContent = 'Agregar al Carrito'; });
        update();
      });
  });

  update();
})();

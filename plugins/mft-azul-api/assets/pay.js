/**
 * Página de pago de la orden con AZUL API + 3D Secure 2.0.
 *
 * 1. Envía la tarjeta y los datos del navegador a mft/v1/azul-api/pay.
 * 2. Si AZUL pide 3D2METHOD, monta el MethodForm del emisor en un iframe oculto y
 *    avisa a /continue cuando el emisor lo confirmó o pasaron 10 s.
 * 3. Si hay desafío (3D), navega al ACS del banco con un POST (creq); el banco
 *    vuelve al TermUrl, que completa el cobro y redirige.
 *
 * Los datos de tarjeta solo viven en memoria durante la llamada a /pay.
 */
(function () {
  'use strict';

  var cfg = window.mftAzul;
  var root = document.getElementById('mft-azul');
  if (!cfg || !root) return;

  var inputs = {};
  root.querySelectorAll('[data-field]').forEach(function (el) {
    inputs[el.getAttribute('data-field')] = el;
  });
  var errorEl = root.querySelector('.mft-azul__error');
  var noticeEl = root.querySelector('.mft-azul__notice');
  var overlay = root.querySelector('.mft-azul__overlay');
  var stageEl = root.querySelector('.mft-azul__stage');
  var payBtn = root.querySelector('.mft-azul__pay');

  function digits(v) {
    return String(v || '').replace(/\D/g, '');
  }

  // Agrupa de 4 en 4 (Amex 4-6-5) solo para mostrar; se envían únicamente dígitos.
  inputs.number.addEventListener('input', function () {
    var d = digits(this.value).slice(0, 19);
    this.value = /^3[47]/.test(d)
      ? [d.slice(0, 4), d.slice(4, 10), d.slice(10, 15)].filter(Boolean).join(' ')
      : d.replace(/(\d{4})(?=\d)/g, '$1 ');
  });
  inputs.exp.addEventListener('input', function (e) {
    var d = digits(this.value).slice(0, 4);
    // Al borrar la barra no la volvemos a poner.
    if (e.inputType === 'deleteContentBackward' && d.length <= 2) {
      this.value = d;
      return;
    }
    this.value = d.length >= 2 ? d.slice(0, 2) + '/' + d.slice(2) : d;
  });
  inputs.cvc.addEventListener('input', function () {
    this.value = digits(this.value).slice(0, 4);
  });

  function luhn(n) {
    var sum = 0;
    var alt = false;
    for (var i = n.length - 1; i >= 0; i--) {
      var x = Number(n[i]);
      if (alt) {
        x *= 2;
        if (x > 9) x -= 9;
      }
      sum += x;
      alt = !alt;
    }
    return sum % 10 === 0;
  }

  function parseExp(v) {
    var m = String(v).match(/^\s*(\d{1,2})\s*\/\s*(\d{2}|\d{4})\s*$/);
    if (!m) return null;
    var month = Number(m[1]);
    var year = m[2].length === 2 ? 2000 + Number(m[2]) : Number(m[2]);
    return month >= 1 && month <= 12 ? { month: month, year: year } : null;
  }

  // Validación local (el plugin vuelve a validar todo).
  function validate() {
    if (!inputs.name.value.trim()) return { field: 'name', message: 'Escribe el nombre como aparece en la tarjeta.' };
    var n = digits(inputs.number.value);
    if (n.length < 13 || n.length > 19 || !luhn(n)) return { field: 'number', message: 'El número de tarjeta no es válido.' };
    var exp = parseExp(inputs.exp.value);
    var now = new Date();
    if (!exp || exp.year < now.getFullYear() || (exp.year === now.getFullYear() && exp.month < now.getMonth() + 1)) {
      return { field: 'exp', message: 'La fecha de vencimiento no es válida.' };
    }
    var c = digits(inputs.cvc.value);
    if (c.length < 3 || c.length > 4) return { field: 'cvc', message: 'El código de seguridad no es válido.' };
    return null;
  }

  function showError(err) {
    Object.keys(inputs).forEach(function (k) {
      inputs[k].classList.toggle('is-invalid', !!err && err.field === k);
    });
    errorEl.hidden = !err;
    errorEl.textContent = err ? err.message : '';
    if (err && inputs[err.field]) inputs[err.field].focus();
  }

  function showNotice(message) {
    noticeEl.hidden = !message;
    noticeEl.textContent = message || '';
  }

  function setBusy(stage) {
    var busy = !!stage;
    root.setAttribute('data-busy', busy ? '1' : '0');
    overlay.hidden = !busy;
    payBtn.disabled = busy;
    Object.keys(inputs).forEach(function (k) {
      inputs[k].disabled = busy;
    });
    if (busy) stageEl.textContent = stage === 'authenticating' ? 'Verificando con tu banco...' : 'Procesando tu pago...';
  }

  function post(path, body) {
    return fetch(cfg.api + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) throw new Error((data && data.message) || 'Error ' + res.status);
        return data;
      });
    });
  }

  // Datos del navegador que pide 3D Secure 2.0 (doc AZUL 3DS, "BrowserInfo").
  function browserInfo() {
    var ratio = window.devicePixelRatio || 1;
    return {
      language: navigator.language,
      colorDepth: window.screen ? window.screen.colorDepth : 24,
      screenWidth: Math.round((window.screen ? window.screen.width : 0) * ratio),
      screenHeight: Math.round((window.screen ? window.screen.height : 0) * ratio),
      timeZone: new Date().getTimezoneOffset(),
    };
  }

  /**
   * Monta el MethodForm de AZUL (iframe oculto + form). Los <script> insertados
   * con innerHTML no se ejecutan, así que enviamos el form a mano.
   */
  function mountMethodForm(html) {
    var box = document.createElement('div');
    box.setAttribute('aria-hidden', 'true');
    box.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;left:-9999px;top:0';
    box.innerHTML = html;
    document.body.appendChild(box);
    var form = box.querySelector('form');
    if (form) form.submit();
    return function () { box.remove(); };
  }

  function sleep(ms) {
    return new Promise(function (r) { setTimeout(r, ms); });
  }

  // Espera la notificación del ACS (hasta 10 s, según el doc) consultando al plugin.
  function waitMethodNotification() {
    var deadline = Date.now() + 10000;
    var qs = '?order_id=' + encodeURIComponent(cfg.orderId) + '&order_key=' + encodeURIComponent(cfg.orderKey);
    function tick() {
      if (Date.now() >= deadline) return Promise.resolve();
      return sleep(1000)
        .then(function () {
          return fetch(cfg.api + '/method-status' + qs + '&_=' + Date.now(), { headers: { Accept: 'application/json' }, cache: 'no-store' });
        })
        .then(function (res) { return res.ok ? res.json() : {}; })
        .catch(function () { return {}; })
        .then(function (data) { return data && data.received ? null : tick(); });
    }
    return tick();
  }

  // Desafío: POST de navegación completa al ACS del banco (creq + TermUrl).
  function submitChallenge(url, creq, termUrl) {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = url;
    form.style.display = 'none';
    // `creq` va en minúscula: el ACS es case sensitive (doc AZUL 3DS, paso 4).
    [['creq', creq], ['TermUrl', termUrl]].forEach(function (pair) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = pair[0];
      input.value = pair[1];
      form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
  }

  function finish(res) {
    if (res.status === 'approved') {
      window.location.assign(res.redirect);
      return;
    }
    if (res.status === 'challenge') {
      submitChallenge(res.redirectPostUrl, res.creq, res.termUrl);
      return;
    }
    setBusy(null);
    if (res.status === 'invalid') {
      showError({ field: res.field, message: res.message });
      return;
    }
    // Se limpian los datos sensibles tras cada intento fallido.
    inputs.number.value = '';
    inputs.cvc.value = '';
    showNotice(res.status === 'method' ? 'No se pudo completar la verificación con tu banco.' : res.message);
  }

  function fail(e) {
    setBusy(null);
    showNotice(e && e.message ? e.message : 'No se pudo procesar el pago.');
  }

  payBtn.addEventListener('click', function () {
    var err = validate();
    showError(err);
    if (err) return;
    showNotice('');
    setBusy('paying');

    var exp = parseExp(inputs.exp.value);
    post('/pay', {
      order_id: cfg.orderId,
      order_key: cfg.orderKey,
      card: {
        name: inputs.name.value.trim(),
        number: digits(inputs.number.value),
        expMonth: exp.month,
        expYear: exp.year,
        cvc: digits(inputs.cvc.value),
      },
      browser: browserInfo(),
    })
      .then(function (res) {
        // El emisor quiere los datos del navegador antes de decidir (3D2METHOD).
        if (res.status !== 'method') return res;
        setBusy('authenticating');
        var unmount = mountMethodForm(res.methodForm);
        return waitMethodNotification()
          .then(function () { return post('/continue', { order_id: cfg.orderId, order_key: cfg.orderKey }); })
          .finally(unmount);
      })
      .then(finish, fail);
  });
})();

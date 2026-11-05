/**
 * Pago con tarjeta AZUL API + 3D Secure 2.0, en dos lugares:
 *  - Checkout de WooCommerce (`data-context="checkout"`): los campos están en el
 *    bloque de pagos y se usa el botón "Realizar el pedido". WooCommerce crea la
 *    orden y redirige a `#mft-azul-pay:<orden>:<key>`; aquí se intercepta ese
 *    hash y se cobra sin salir de la página.
 *  - Página de pago de la orden (`data-context="receipt"`): botón propio.
 *
 * 1. Envía la tarjeta y los datos del navegador a mft/v1/azul-api/pay.
 * 2. Si AZUL pide 3D2METHOD, monta el MethodForm del emisor en un iframe oculto y
 *    avisa a /continue cuando el emisor lo confirmó o pasaron 10 s.
 * 3. Si hay desafío (3D), navega al ACS del banco con un POST (creq); el banco
 *    vuelve al TermUrl, que completa el cobro y redirige.
 *
 * Si no se sabe el resultado (corte de red, timeout, respuesta rara) nunca se
 * invita a pagar de nuevo: se consulta /status hasta saber si la orden se pagó.
 * Los datos de tarjeta solo viven en memoria durante la llamada a /pay.
 */
(function () {
  'use strict';

  var cfg = window.mftAzul;
  if (!cfg) return;
  var msg = cfg.msg || {};
  var $ = window.jQuery;
  var HASH = /^#mft-azul-pay:(\d+):([A-Za-z0-9_\-]+)$/;

  var busy = false;
  var leaving = false;
  var order = null; // { id, key } de la orden que se está cobrando
  var overlay = null;

  // En el checkout WooCommerce vuelve a pintar el bloque de pagos (update_checkout):
  // los elementos se buscan cada vez, nunca se guardan.
  function root() {
    return document.querySelector('.mft-azul');
  }
  function part(sel) {
    var r = root();
    return r ? r.querySelector(sel) : null;
  }
  function input(name) {
    return part('[data-field="' + name + '"]');
  }
  function value(name) {
    var el = input(name);
    return el ? el.value : '';
  }
  function isCheckout() {
    var r = root();
    return !!r && r.getAttribute('data-context') === 'checkout';
  }
  var FIELDS = ['name', 'number', 'exp', 'cvc'];

  function digits(v) {
    return String(v || '').replace(/\D/g, '');
  }

  // Formato mientras se escribe. Agrupa de 4 en 4 (Amex 4-6-5) solo para mostrar;
  // se envían únicamente dígitos.
  document.addEventListener('input', function (e) {
    var el = e.target;
    if (!el || !el.closest || !el.closest('.mft-azul')) return;
    var field = el.getAttribute('data-field');
    if (field === 'number') {
      var d = digits(el.value).slice(0, 19);
      el.value = /^3[47]/.test(d)
        ? [d.slice(0, 4), d.slice(4, 10), d.slice(10, 15)].filter(Boolean).join(' ')
        : d.replace(/(\d{4})(?=\d)/g, '$1 ');
    } else if (field === 'exp') {
      var x = digits(el.value).slice(0, 4);
      // Al borrar la barra no la volvemos a poner.
      el.value = e.inputType === 'deleteContentBackward' && x.length <= 2
        ? x
        : x.length >= 2 ? x.slice(0, 2) + '/' + x.slice(2) : x;
    } else if (field === 'cvc') {
      el.value = digits(el.value).slice(0, 4);
    }
  });

  // Enter en la página de pago de la orden = botón Pagar (en el checkout, Enter
  // ya envía el formulario de WooCommerce).
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || isCheckout()) return;
    if (e.target && e.target.closest && e.target.closest('.mft-azul') && e.target.hasAttribute('data-field')) {
      e.preventDefault();
      var btn = part('.mft-azul__pay');
      if (btn) btn.click();
    }
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
    if (!root()) return { field: '', message: msg.generic };
    var name = value('name').trim();
    if (!name || name.length > 60) return { field: 'name', message: 'Escribe el nombre como aparece en la tarjeta.' };
    var n = digits(value('number'));
    if (n.length < 13 || n.length > 19 || !luhn(n)) return { field: 'number', message: 'El número de tarjeta no es válido.' };
    var exp = parseExp(value('exp'));
    var now = new Date();
    if (!exp || exp.year < now.getFullYear() || (exp.year === now.getFullYear() && exp.month < now.getMonth() + 1)) {
      return { field: 'exp', message: 'La fecha de vencimiento no es válida.' };
    }
    var c = digits(value('cvc'));
    if (c.length < 3 || c.length > 4) return { field: 'cvc', message: 'El código de seguridad no es válido.' };
    return null;
  }

  function showError(err) {
    FIELDS.forEach(function (k) {
      var el = input(k);
      if (!el) return;
      el.classList.toggle('is-invalid', !!err && err.field === k);
      el.setAttribute('aria-invalid', err && err.field === k ? 'true' : 'false');
    });
    var box = part('.mft-azul__error');
    if (box) {
      box.hidden = !err;
      box.textContent = err ? err.message : '';
    }
    if (err && input(err.field)) {
      input(err.field).focus();
      if (box) box.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
  }

  function showNotice(message) {
    var box = part('.mft-azul__notice');
    if (!box) return;
    box.hidden = !message;
    box.textContent = message || '';
    if (message) box.scrollIntoView({ block: 'center', behavior: 'smooth' });
  }

  // El checkout queda bloqueado por WooCommerce mientras "procesa": se libera al
  // terminar sin redirigir, para que el cliente pueda corregir y volver a pagar.
  function releaseCheckout() {
    if (!$ || !isCheckout()) return;
    var $form = $('form.checkout');
    $form.removeClass('processing');
    if ($form.unblock) $form.unblock();
  }

  function showOverlay(stage) {
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = 'mft-azul__overlay';
      overlay.setAttribute('role', 'status');
      overlay.innerHTML = '<span class="mft-azul__spinner" aria-hidden="true"></span>' +
        '<strong class="mft-azul__stage"></strong><span>No cierres ni recargues esta ventana.</span>';
      overlay.hidden = true;
      document.body.appendChild(overlay);
    }
    overlay.hidden = !stage;
    if (stage) {
      overlay.querySelector('.mft-azul__stage').textContent = stage === 'authenticating'
        ? 'Verificando con tu banco...'
        : stage === 'checking' ? (msg.checking || 'Confirmando tu pago...') : 'Procesando tu pago...';
    }
  }

  function setBusy(stage) {
    busy = !!stage;
    showOverlay(stage);
    var btn = part('.mft-azul__pay');
    if (btn) btn.disabled = busy;
    FIELDS.forEach(function (k) {
      var el = input(k);
      if (el) el.disabled = busy;
    });
    if (!busy) releaseCheckout();
  }

  function go(url) {
    leaving = true;
    window.location.assign(url);
  }

  // Avisa antes de salir mientras el pago está en curso.
  window.addEventListener('beforeunload', function (e) {
    if (busy && !leaving) {
      e.preventDefault();
      e.returnValue = msg.leave || '';
    }
  });

  /**
   * Error de la API. `known` = el servidor respondió con uno de nuestros
   * mensajes (códigos mft_azul_*); si no, el resultado puede ser desconocido.
   */
  function ApiError(message, known, status) {
    this.message = message;
    this.known = known;
    this.status = status || 0;
  }

  function request(method, path, body) {
    var url = cfg.api + path;
    var opts = {
      method: method,
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    };
    if (method === 'GET') {
      url += (url.indexOf('?') === -1 ? '?' : '&') + 'order_id=' + encodeURIComponent(order.id) +
        '&order_key=' + encodeURIComponent(order.key) + '&_=' + Date.now();
    } else {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(url, opts).then(
      function (res) {
        return res.text().then(function (text) {
          var data = null;
          try { data = JSON.parse(text); } catch (e) { data = null; }
          var ours = data && typeof data.code === 'string' && data.code.indexOf('mft_azul') === 0;
          if (!res.ok) throw new ApiError(ours ? data.message : (msg.generic || ''), ours, res.status);
          if (!data || typeof data.status !== 'string') throw new ApiError(msg.generic || '', false, res.status);
          return data;
        });
      },
      function () {
        throw new ApiError(msg.connect || '', false, 0);
      }
    );
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
    box.innerHTML = String(html || '');
    document.body.appendChild(box);
    var form = box.querySelector('form');
    if (form) {
      try { form.submit(); } catch (e) { /* se sigue sin la notificación del emisor */ }
    }
    return function () { box.remove(); };
  }

  function sleep(ms) {
    return new Promise(function (r) { setTimeout(r, ms); });
  }

  // Espera la notificación del ACS (hasta 10 s, según el doc) consultando al plugin.
  function waitMethodNotification() {
    var deadline = Date.now() + 10000;
    function tick() {
      if (Date.now() >= deadline) return Promise.resolve();
      return sleep(1000)
        .then(function () { return request('GET', '/method-status'); })
        .catch(function () { return {}; })
        .then(function (data) { return data && data.received ? null : tick(); });
    }
    return tick();
  }

  /**
   * Resultado desconocido: consulta el estado de la orden hasta ~90 s. Si se
   * pagó, se va a "orden recibida"; si no, se explica sin invitar a pagar otra
   * vez si el servidor sigue procesando.
   */
  function checkStatus() {
    setBusy('checking');
    var deadline = Date.now() + 90000;
    function tick() {
      return request('GET', '/status')
        .catch(function () { return { status: 'unreachable' }; })
        .then(function (data) {
          if (data.status === 'approved') return go(data.redirect);
          if (data.status === 'open') {
            setBusy(null);
            showNotice(msg.generic);
            return null;
          }
          if (Date.now() >= deadline) {
            setBusy(null);
            var btn = part('.mft-azul__pay');
            if (btn) btn.disabled = true;
            showNotice(msg.unknown);
            return null;
          }
          return sleep(4000).then(tick);
        });
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
      var el = document.createElement('input');
      el.type = 'hidden';
      el.name = pair[0];
      el.value = pair[1];
      form.appendChild(el);
    });
    document.body.appendChild(form);
    leaving = true;
    form.submit();
  }

  function clearSensitive() {
    ['number', 'cvc'].forEach(function (k) {
      var el = input(k);
      if (el) el.value = '';
    });
  }

  function finish(res) {
    if (res.status === 'approved' && res.redirect) return go(res.redirect);
    if (res.status === 'challenge' && res.redirectPostUrl) {
      return submitChallenge(res.redirectPostUrl, res.creq, res.termUrl);
    }
    setBusy(null);
    if (res.status === 'invalid') {
      showError({ field: res.field, message: res.message });
      return null;
    }
    // Se limpian los datos sensibles tras cada intento fallido.
    clearSensitive();
    if (res.status === 'pending') {
      var btn = part('.mft-azul__pay');
      if (btn) btn.disabled = true;
      showNotice(res.message || msg.unknown);
      return null;
    }
    showNotice(res.status === 'method' ? msg.method : (res.message || msg.generic));
    return null;
  }

  function fail(err) {
    clearSensitive();
    // Nuestro servidor respondió con un mensaje claro (orden no válida, límite,
    // otro pago en curso…): se muestra. Si no, el resultado puede ser desconocido.
    if (err && err.known) {
      setBusy(null);
      showNotice(err.message || msg.generic);
      return null;
    }
    return checkStatus();
  }

  function pay() {
    if (busy) return;
    var err = validate();
    showError(err);
    if (err) {
      releaseCheckout();
      return;
    }
    showNotice('');
    setBusy('paying');

    var exp = parseExp(value('exp'));
    request('POST', '/pay', {
      order_id: order.id,
      order_key: order.key,
      card: {
        name: value('name').trim(),
        number: digits(value('number')),
        expMonth: exp.month,
        expYear: exp.year,
        cvc: digits(value('cvc')),
      },
      browser: browserInfo(),
    })
      .then(function (res) {
        // El emisor quiere los datos del navegador antes de decidir (3D2METHOD).
        if (res.status !== 'method') return res;
        setBusy('authenticating');
        var unmount = mountMethodForm(res.methodForm);
        return waitMethodNotification()
          .then(function () { return request('POST', '/continue', { order_id: order.id, order_key: order.key }); })
          .finally(unmount);
      })
      .then(finish)
      .catch(fail)
      .catch(function () {
        // Último recurso: nunca dejar la página bloqueada sin explicación.
        setBusy(null);
        showNotice(msg.generic);
      });
  }

  // --- Página de pago de la orden ---------------------------------------------
  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('.mft-azul__pay') : null;
    var r = root();
    if (!btn || !r || r.getAttribute('data-context') !== 'receipt') return;
    order = { id: Number(r.getAttribute('data-order-id')), key: r.getAttribute('data-order-key') };
    pay();
  });

  // --- Checkout ---------------------------------------------------------------
  // Le dice a process_payment que este script cobrará en el checkout (si no,
  // WooCommerce manda a la página de pago de la orden).
  function markInline() {
    var el = document.getElementById('mft-azul-inline');
    if (el) el.value = '1';
  }

  // WooCommerce redirige a `#mft-azul-pay:<orden>:<key>` tras crear la orden.
  function fromHash() {
    var m = HASH.exec(window.location.hash);
    if (!m) return;
    // Se limpia el hash: un segundo intento debe volver a disparar hashchange.
    history.replaceState(null, '', window.location.pathname + window.location.search);
    order = { id: Number(m[1]), key: m[2] };
    if (!root() && payUrl) {
      // No hay formulario (no debería pasar): la página de pago de la orden lo tiene.
      go(payUrl);
      return;
    }
    pay();
  }

  var payUrl = '';
  if ($) {
    // Antes de la redirección: guarda la página de pago de la orden como respaldo.
    $('form.checkout').on('checkout_place_order_success', function (e, result) {
      if (result && typeof result.mftAzulPayUrl === 'string') payUrl = result.mftAzulPayUrl;
    });
    $(document.body).on('updated_checkout payment_method_selected', markInline);
    // Valida la tarjeta antes de que WooCommerce cree la orden.
    $('form.checkout').on('checkout_place_order_' + (cfg.gatewayId || 'mft_azul_api'), function () {
      if (busy) return false;
      markInline();
      var err = validate();
      showError(err);
      // El aviso sale ya, mientras WooCommerce crea la orden (en vez de su gris).
      if (!err) showOverlay('paying');
      return !err;
    });
    // WooCommerce rechazó el pedido (falta un dato de facturación, etc.).
    $(document.body).on('checkout_error', function () {
      if (!busy) showOverlay(null);
    });
  }
  markInline();
  window.addEventListener('hashchange', fromHash);
  fromHash();
})();

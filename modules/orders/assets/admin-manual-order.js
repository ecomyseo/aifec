/**
 * AIFEC — «Pedido nuevo» del panel (el pedido por teléfono, por WhatsApp o en el mostrador).
 *
 * Todo va por delegación sobre document: lo que el servidor vuelve a pintar funciona sin conectar nada otra vez.
 *   1. Cada paso (form[data-mo-form]) se envía con Aifec.post. El servidor contesta con la columna de los pasos ya pintada y
 *      aquí solo se cambia por la nueva. Después se pide el resumen a «quote» y se repintan sus filas COPIANDO los textos que
 *      manda el servidor: este guion no suma, no redondea ni convierte ningún importe.
 *   2. Los dos buscadores son «combobox» (patrón de la WAI): flechas para moverse, Intro para elegir, Escape para cerrar. El
 *      número de resultados y el total nuevo se anuncian en data-mo-live.
 *   3. Un doble clic no manda dos veces: el formulario queda ocupado (aria-busy) hasta que vuelve la respuesta.
 * Los textos vienen del HTML (data-mo-t-*). Sin JavaScript la pantalla funciona igual: cada paso es un formulario normal.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
(function () {
  'use strict';

  var KINDS = {line: true, sub: true, discount: true, adjustment: true, tax: true, total: true};
  var timers = {};
  var lastSubmitter = null;

  function screen() {
    return document.querySelector('[data-mo]');
  }

  function text(name) {
    var root = screen();
    return root ? (root.getAttribute('data-mo-t-' + name) || '') : '';
  }

  function ready() {
    return !!(window.Aifec && typeof window.Aifec.post === 'function' && typeof window.Aifec.get === 'function'
      && typeof window.FormData === 'function' && typeof window.DOMParser === 'function');
  }

  function safeUrl(url) {
    return typeof url === 'string' && /^(https?:\/\/|\/(?!\/))/i.test(url);
  }

  function trim(value) {
    return String(value || '').replace(/^\s+|\s+$/g, '');
  }

  // Lo que se lee en voz alta (el número de resultados, el total nuevo).
  function announce(message) {
    var live = document.querySelector('[data-mo-live]');
    if (!live) {
      return;
    }
    live.textContent = '';
    window.setTimeout(function () {
      live.textContent = message || '';
    }, 30);
  }

  // El HTML del servidor, convertido en nodos sin innerHTML, sin guiones y sin manejadores.
  function nodesFrom(html) {
    var doc = new window.DOMParser().parseFromString(String(html), 'text/html');
    var bad = doc.body.querySelectorAll('script,iframe,object,embed,base,meta,link,style');
    var i;
    for (i = 0; i < bad.length; i++) {
      bad[i].parentNode.removeChild(bad[i]);
    }
    var all = doc.body.querySelectorAll('*');
    for (i = 0; i < all.length; i++) {
      var attrs = Array.prototype.slice.call(all[i].attributes);
      for (var k = 0; k < attrs.length; k++) {
        var name = String(attrs[k].name).toLowerCase();
        var link = name === 'href' || name === 'action' || name === 'formaction' || name === 'src';
        if (name.indexOf('on') === 0 || (link && !safeUrl(attrs[k].value) && String(attrs[k].value).charAt(0) !== '#')) {
          all[i].removeAttribute(attrs[k].name);
        }
      }
    }
    var fragment = document.createDocumentFragment();
    var kids = Array.prototype.slice.call(doc.body.childNodes);
    for (i = 0; i < kids.length; i++) {
      fragment.appendChild(document.importNode(kids[i], true));
    }
    return fragment;
  }

  function replaceSteps(html, focusId) {
    var box = document.querySelector('[data-mo-steps]');
    if (!box) {
      return;
    }
    var fragment = nodesFrom(html);
    while (box.firstChild) {
      box.removeChild(box.firstChild);
    }
    box.appendChild(fragment);
    if (typeof window.Aifec.enhance === 'function') {
      window.Aifec.enhance(box);
    }
    var section = focusId ? document.getElementById(focusId) : null;
    var heading = section ? section.querySelector('h2[tabindex]') : null;
    if (heading && typeof heading.focus === 'function') {
      heading.focus();
    }
  }

  // ------------------------------------------------------------------ el resumen

  function paint(q) {
    var rows = document.querySelector('[data-mo-rows]');
    if (!rows || !q || !q.rows) {
      return;
    }
    while (rows.firstChild) {
      rows.removeChild(rows.firstChild);
    }
    q.rows.forEach(function (row) {
      var wrap = document.createElement('div');
      wrap.className = 'mo-row mo-row-' + (KINDS[row.kind] === true ? row.kind : 'sub');
      var dt = document.createElement('dt');
      dt.textContent = String(row.label);
      var dd = document.createElement('dd');
      dd.textContent = String(row.value);
      wrap.appendChild(dt);
      wrap.appendChild(dd);
      rows.appendChild(wrap);
    });
    var list = document.querySelector('[data-mo-problems]');
    if (list) {
      while (list.firstChild) {
        list.removeChild(list.firstChild);
      }
      (q.problems || []).forEach(function (problem) {
        var li = document.createElement('li');
        li.textContent = String(problem);
        list.appendChild(li);
      });
      list.hidden = !(q.problems && q.problems.length);
    }
    announce(q.status ? String(q.status) : '');
  }

  function refresh() {
    var root = screen();
    var url = root ? root.getAttribute('data-mo-quote') : '';
    if (!url || !safeUrl(url)) {
      return;
    }
    window.Aifec.post(url, {}).then(paint, function () {
      return null;
    });
  }

  // ------------------------------------------------------------------ los pasos

  function busy(form, on) {
    form.setAttribute('aria-busy', on ? 'true' : 'false');
    var buttons = form.querySelectorAll('button[type="submit"]');
    for (var i = 0; i < buttons.length; i++) {
      if (on && !buttons[i].disabled) {
        buttons[i].disabled = true;
        buttons[i].setAttribute('data-mo-busy', '1');
      } else if (!on && buttons[i].getAttribute('data-mo-busy') === '1') {
        buttons[i].disabled = false;
        buttons[i].removeAttribute('data-mo-busy');
      }
    }
  }

  function failed(err) {
    var data = err && err.data && typeof err.data === 'object' ? err.data : null;
    var message = data && data.error && data.error.message ? data.error.message : (data && data.message ? data.message : text('error'));
    window.Aifec.toast(String(message), 'bad');
  }

  function done(d, focusId) {
    var data = d && typeof d === 'object' ? d : {};
    if (data.redirect && safeUrl(String(data.redirect))) {
      window.location.assign(String(data.redirect));
      return;
    }
    if (typeof data.html === 'string' && data.html !== '') {
      replaceSteps(data.html, data.focus || focusId);
    }
    if (data.message) {
      window.Aifec.toast(String(data.message), data.ok === false ? 'bad' : 'ok');
    }
    refresh();
  }

  function send(form, submitter) {
    if (form.getAttribute('aria-busy') === 'true') {
      return;
    }
    var data = new window.FormData(form);
    if (submitter && submitter.name) {
      data.append(submitter.name, submitter.value);
    }
    var action = submitter && submitter.getAttribute('formaction') ? submitter.getAttribute('formaction') : form.getAttribute('action');
    if (!safeUrl(action)) {
      return;
    }
    var section = form.closest ? form.closest('section') : null;
    busy(form, true);
    window.Aifec.post(action, data).then(function (d) {
      done(d, section ? section.id : '');
    }, failed).then(function () {
      busy(form, false);
    });
  }

  // El botón que envía el formulario (para «Quitar» o «Volver al precio de catálogo», que van a otra dirección).
  document.addEventListener('click', function (e) {
    var button = e.target && e.target.closest ? e.target.closest('button[type="submit"]') : null;
    if (button && button.form && button.form.hasAttribute('data-mo-form')) {
      lastSubmitter = button;
    }
  }, true);

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.hasAttribute || !ready()) {
      return;
    }
    if (form.hasAttribute('data-mo-find')) {
      var input = form.querySelector('[data-mo-combo]');
      if (input) {
        e.preventDefault();
        search(input);
      }
      return;
    }
    if (!form.hasAttribute('data-mo-form')) {
      return;
    }
    e.preventDefault();
    var submitter = e.submitter || (lastSubmitter && lastSubmitter.form === form ? lastSubmitter : null);
    lastSubmitter = null;
    var ask = form.getAttribute('data-mo-confirm');
    if (ask && typeof window.Aifec.confirm === 'function') {
      window.Aifec.confirm(ask).then(function (ok) {
        if (ok) {
          send(form, submitter);
        }
      });
      return;
    }
    send(form, submitter);
  });

  // ------------------------------------------------------------------ los buscadores (combobox)

  function listOf(input) {
    return document.getElementById(input.getAttribute('aria-controls') || '');
  }

  function close(input) {
    var list = listOf(input);
    if (list) {
      list.hidden = true;
    }
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
  }

  function fill(input, list, results) {
    while (list.firstChild) {
      list.removeChild(list.firstChild);
    }
    results.forEach(function (result, i) {
      var li = document.createElement('li');
      li.id = input.id + '-opt-' + i;
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', 'false');
      li.textContent = String(result.label);
      if (result.disabled) {
        li.setAttribute('aria-disabled', 'true');
      }
      li.__moPick = result;
      list.appendChild(li);
    });
    list.hidden = results.length === 0;
    input.setAttribute('aria-expanded', results.length ? 'true' : 'false');
    input.removeAttribute('aria-activedescendant');
  }

  function search(input) {
    var q = trim(input.value);
    var list = listOf(input);
    var url = input.getAttribute('data-mo-combo');
    if (!list || !url || !safeUrl(url)) {
      return;
    }
    if (q.length < 2) {
      close(input);
      if (q.length) {
        announce(text('short'));
      }
      return;
    }
    announce(text('searching'));
    window.Aifec.get(url + (url.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q)).then(function (d) {
      if (trim(input.value) !== q) {
        return;
      }
      fill(input, list, d && d.results ? d.results : []);
      announce(d && d.status ? String(d.status) : '');
    }, failed);
  }

  function activate(input, options, index) {
    options.forEach(function (option, i) {
      option.setAttribute('aria-selected', i === index ? 'true' : 'false');
    });
    input.setAttribute('aria-activedescendant', options[index].id);
    if (typeof options[index].scrollIntoView === 'function') {
      options[index].scrollIntoView({block: 'nearest'});
    }
  }

  function pick(input, option) {
    if (option.getAttribute('aria-disabled') === 'true') {
      announce(text('unavailable'));
      return;
    }
    var result = option.__moPick || {};
    var data = result.product_id ? {product_id: result.product_id, variant_id: result.variant_id, qty: 1} : {customer_id: result.id};
    var url = input.getAttribute('data-mo-pick');
    var id = input.id;
    if (!safeUrl(url)) {
      return;
    }
    close(input);
    input.value = '';
    window.Aifec.post(url, data).then(function (d) {
      done(d, '');
      var again = document.getElementById(id);
      if (again && typeof again.focus === 'function') {
        again.focus();
      }
    }, failed);
  }

  document.addEventListener('input', function (e) {
    var input = e.target;
    if (!input || !input.hasAttribute || !input.hasAttribute('data-mo-combo') || !ready()) {
      return;
    }
    if (timers[input.id]) {
      window.clearTimeout(timers[input.id]);
    }
    timers[input.id] = window.setTimeout(function () {
      search(input);
    }, 250);
  });

  document.addEventListener('keydown', function (e) {
    var input = e.target;
    if (!input || !input.hasAttribute || !input.hasAttribute('data-mo-combo')) {
      return;
    }
    var list = listOf(input);
    if (!list) {
      return;
    }
    var options = Array.prototype.slice.call(list.querySelectorAll('[role="option"]'));
    var current = input.getAttribute('aria-activedescendant');
    var index = -1;
    options.forEach(function (option, i) {
      if (option.id === current) {
        index = i;
      }
    });
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (!options.length) {
        return;
      }
      e.preventDefault();
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      var next = e.key === 'ArrowDown' ? (index + 1) % options.length : (index <= 0 ? options.length - 1 : index - 1);
      activate(input, options, next);
    } else if (e.key === 'Enter' && index >= 0 && !list.hidden) {
      e.preventDefault();
      pick(input, options[index]);
    } else if (e.key === 'Escape' && !list.hidden) {
      e.preventDefault();
      close(input);
    }
  });

  // Pulsar una opción no le quita el foco al buscador (así el clic llega a la opción).
  document.addEventListener('mousedown', function (e) {
    if (e.target && e.target.closest && e.target.closest('.mo-options [role="option"]')) {
      e.preventDefault();
    }
  });

  document.addEventListener('click', function (e) {
    var option = e.target && e.target.closest ? e.target.closest('.mo-options [role="option"]') : null;
    if (!option || !ready()) {
      return;
    }
    var list = option.parentNode;
    var input = list && list.id ? document.querySelector('[aria-controls="' + list.id + '"]') : null;
    if (input && input.hasAttribute('data-mo-combo')) {
      pick(input, option);
    }
  });

  document.addEventListener('focusout', function (e) {
    var input = e.target;
    if (!input || !input.hasAttribute || !input.hasAttribute('data-mo-combo')) {
      return;
    }
    window.setTimeout(function () {
      if (document.activeElement !== input) {
        close(input);
      }
    }, 150);
  });
})();

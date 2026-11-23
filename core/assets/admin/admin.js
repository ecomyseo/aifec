/*
 * AIFEC — JavaScript del panel de administración.
 * Sin dependencias y sin transpilar. Todo por delegación sobre `document`: lo que se pinte
 * después (filas de un repetidor, ventanas) funciona sin volver a conectar nada.
 * Expone solo `window.Aifec`. Autor: Ecom Experts · 2026 · Ver LICENSE.md
 */
(function (window, document) {
  'use strict';

  if (!window || !document) {
    return;
  }

  var Aifec = {};
  var uid = 0;

  // ------------------------------------------------------------------ utilidades

  function arr(list) {
    var out = [];
    if (!list) {
      return out;
    }
    for (var i = 0; i < list.length; i++) {
      out.push(list[i]);
    }
    return out;
  }

  function all(sel, root) {
    try {
      return arr((root || document).querySelectorAll(sel));
    } catch (e) {
      return [];
    }
  }

  function one(sel, root) {
    try {
      return (root || document).querySelector(sel) || null;
    } catch (e) {
      return null;
    }
  }

  function matches(el, sel) {
    var f = el.matches || el.webkitMatchesSelector || el.msMatchesSelector;
    return f ? f.call(el, sel) : false;
  }

  function closest(el, sel) {
    while (el && el.nodeType === 1) {
      if (matches(el, sel)) {
        return el;
      }
      el = el.parentNode;
    }
    return null;
  }

  function attrEsc(value) {
    return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  }

  function h(tag, attrs, text) {
    var el = document.createElement(tag);
    if (attrs) {
      for (var k in attrs) {
        if (Object.prototype.hasOwnProperty.call(attrs, k) && attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) {
          el.setAttribute(k, attrs[k] === true ? '' : String(attrs[k]));
        }
      }
    }
    if (text !== undefined && text !== null) {
      el.textContent = String(text);
    }
    return el;
  }

  function svg(paths) {
    var ns = 'http://www.w3.org/2000/svg';
    var el = document.createElementNS(ns, 'svg');
    el.setAttribute('viewBox', '0 0 24 24');
    el.setAttribute('width', '16');
    el.setAttribute('height', '16');
    el.setAttribute('fill', 'none');
    el.setAttribute('stroke', 'currentColor');
    el.setAttribute('stroke-width', '2');
    el.setAttribute('stroke-linecap', 'round');
    el.setAttribute('stroke-linejoin', 'round');
    el.setAttribute('aria-hidden', 'true');
    for (var i = 0; i < paths.length; i++) {
      var p = document.createElementNS(ns, 'path');
      p.setAttribute('d', paths[i]);
      el.appendChild(p);
    }
    return el;
  }

  function remove(el) {
    if (el && el.parentNode) {
      el.parentNode.removeChild(el);
    }
  }

  function stripCtl(value) {
    var s = String(value);
    var out = '';
    for (var i = 0; i < s.length; i++) {
      if (s.charCodeAt(i) > 32) {
        out += s.charAt(i);
      }
    }
    return out;
  }

  /** Una dirección que no ejecuta código ni lleva datos incrustados. */
  function safeUrl(value) {
    var c = stripCtl(value).toLowerCase();
    return !(c.indexOf('javascript:') === 0 || c.indexOf('vbscript:') === 0 || c.indexOf('data:') === 0);
  }

  function go(url) {
    if (typeof url === 'string' && url !== '' && safeUrl(url)) {
      window.location.href = url;
    }
  }

  function joinQ(url, q) {
    return String(url) + (String(url).indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q);
  }

  function ch(code) {
    return String.fromCharCode(code);
  }

  // ------------------------------------------------------------------ delegación

  var registry = {};
  var CAPTURE = {close: true, cancel: true, focus: true, blur: true};

  function dispatch(e) {
    var list = registry[e.type];
    if (!list) {
      return;
    }
    var base = e.target;
    if (base && base.nodeType === 3) {
      base = base.parentNode;
    }
    for (var i = 0; i < list.length; i++) {
      var hnd = list[i];
      var m = null;
      if (hnd.sel === null) {
        m = document;
      } else if (base && base.nodeType === 1) {
        m = closest(base, hnd.sel);
      }
      if (!m) {
        continue;
      }
      if (hnd.fn.call(m, e, m) === false) {
        break;
      }
    }
  }

  function on(type, sel, fn) {
    if (!registry[type]) {
      registry[type] = [];
      if (typeof document.addEventListener === 'function') {
        document.addEventListener(type, dispatch, CAPTURE[type] === true);
      }
    }
    registry[type].push({sel: sel ? String(sel) : null, fn: fn});
  }

  // ------------------------------------------------------------------ red

  function token() {
    var m = one('meta[name="af-token"]');
    return m ? (m.getAttribute('content') || '') : '';
  }

  function encode(data) {
    if (typeof data === 'string') {
      return data;
    }
    var parts = [];
    function add(k, v) {
      if (v === null || v === undefined) {
        return;
      }
      if (typeof v === 'object') {
        for (var key in v) {
          if (Object.prototype.hasOwnProperty.call(v, key)) {
            add(k + '[' + key + ']', v[key]);
          }
        }
        return;
      }
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(typeof v === 'boolean' ? (v ? '1' : '0') : String(v)));
    }
    if (data && typeof data === 'object') {
      for (var k in data) {
        if (Object.prototype.hasOwnProperty.call(data, k)) {
          add(k, data[k]);
        }
      }
    }
    return parts.join('&');
  }

  function parse(res) {
    return res.text().then(function (t) {
      var d = null;
      if (t) {
        try {
          d = JSON.parse(t);
        } catch (e) {
          d = null;
        }
      }
      if (!res.ok) {
        return Promise.reject({status: res.status, data: d});
      }
      return d === null ? {} : d;
    });
  }

  function request(method, url, data) {
    var headers = {'Accept': 'application/json', 'X-Requested-With': 'fetch'};
    var opts = {method: method, headers: headers, cache: 'no-store', credentials: 'same-origin'};
    if (method === 'POST') {
      var tk = token();
      if (tk !== '') {
        headers['X-CSRF-Token'] = tk;
      }
      if (typeof FormData !== 'undefined' && data instanceof FormData) {
        opts.body = data;
      } else {
        headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
        opts.body = encode(data);
      }
    }
    if (typeof window.fetch !== 'function') {
      return Promise.reject({status: 0, data: null});
    }
    return window.fetch(url, opts).then(parse, function (err) {
      return Promise.reject({status: 0, data: null, error: err});
    });
  }

  function post(url, data) {
    return request('POST', url, data);
  }

  function get(url) {
    return request('GET', url);
  }

  // ------------------------------------------------------------------ avisos y confirmación

  function toast(texto, tipo) {
    var msg = texto === null || texto === undefined ? '' : String(texto);
    if (msg === '') {
      return null;
    }
    var box = document.getElementById('af-toasts');
    if (!box && document.body) {
      box = h('div', {id: 'af-toasts', 'class': 'af-toasts', 'aria-live': 'polite'});
      document.body.appendChild(box);
    }
    if (!box) {
      return null;
    }
    var bad = tipo === 'bad';
    var el = h('div', {'class': 'af-toast' + (bad ? ' af-toast-bad' : ''), role: bad ? 'alert' : 'status'}, msg);
    box.appendChild(el);
    window.setTimeout(function () {
      remove(el);
    }, 5000);
    return el;
  }

  var pendingConfirm = null;

  function settleConfirm(value) {
    var resolve = pendingConfirm;
    pendingConfirm = null;
    var dlg = document.getElementById('af-confirm');
    if (dlg && dlg.open && typeof dlg.close === 'function') {
      try {
        dlg.close();
      } catch (e) {
        // ya estaba cerrada
      }
    }
    if (resolve) {
      resolve(value);
    }
  }

  function confirmDialog(texto) {
    var dlg = document.getElementById('af-confirm');
    if (!dlg || typeof dlg.showModal !== 'function') {
      return Promise.resolve(window.confirm(String(texto)));
    }
    if (pendingConfirm) {
      settleConfirm(false);
    }
    var p = one('[data-af-confirm-text]', dlg);
    if (p) {
      p.textContent = String(texto);
    }
    return new Promise(function (resolve) {
      pendingConfirm = resolve;
      try {
        if (!dlg.open) {
          dlg.showModal();
        }
      } catch (e) {
        pendingConfirm = null;
        resolve(window.confirm(String(texto)));
      }
    });
  }

  on('click', '[data-af-confirm-yes]', function () {
    settleConfirm(true);
    return false;
  });
  on('click', '[data-af-confirm-no]', function () {
    settleConfirm(false);
    return false;
  });
  on('click', '#af-confirm', function (e, dlg) {
    if (e.target === dlg) {
      settleConfirm(false);
    }
  });
  on('cancel', '#af-confirm', function () {
    settleConfirm(false);
  });
  on('close', '#af-confirm', function (e, dlg) {
    if (!dlg.open) {
      settleConfirm(false);
    }
  });

  // ------------------------------------------------------------------ direcciones amigables

  var COMBINING = new RegExp('[' + ch(768) + '-' + ch(879) + ']', 'g');

  // Letras que no se descomponen: eszett, ae, oe, o con barra, d con barra, eth, l con barra, thorn, i sin punto.
  var SLUG_EXTRA = [[223, 'ss'], [230, 'ae'], [339, 'oe'], [248, 'o'], [273, 'd'], [240, 'd'], [322, 'l'], [254, 'th'], [305, 'i']];

  function slug(texto) {
    var s = texto === null || texto === undefined ? '' : String(texto);
    if (typeof s.normalize === 'function') {
      s = s.normalize('NFD');
    }
    s = s.replace(COMBINING, '').toLowerCase();
    for (var i = 0; i < SLUG_EXTRA.length; i++) {
      s = s.split(ch(SLUG_EXTRA[i][0])).join(SLUG_EXTRA[i][1]);
    }
    return s.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

  // ------------------------------------------------------------------ HTML ajeno

  var BAD_TAGS = 'script,iframe,object,embed,base,meta,link,noscript';
  var URL_ATTRS = {href: true, src: true, action: true, formaction: true, 'xlink:href': true, poster: true};

  function sanitize(root) {
    all(BAD_TAGS, root).forEach(remove);
    all('*', root).forEach(function (el) {
      var attrs = arr(el.attributes);
      for (var i = 0; i < attrs.length; i++) {
        var name = String(attrs[i].name).toLowerCase();
        if (name.indexOf('on') === 0 || name === 'srcdoc' || (URL_ATTRS[name] === true && !safeUrl(attrs[i].value))) {
          el.removeAttribute(attrs[i].name);
        }
      }
    });
  }

  /** Convierte un texto HTML en nodos sin ejecutar nada (sin innerHTML y sin guiones ni manejadores). */
  function fragmentFrom(html) {
    var out = document.createDocumentFragment();
    var doc = null;
    try {
      doc = new window.DOMParser().parseFromString(String(html), 'text/html');
    } catch (e) {
      doc = null;
    }
    if (!doc || !doc.body) {
      out.appendChild(document.createTextNode(String(html)));
      return out;
    }
    sanitize(doc.body);
    var nodes = arr(doc.body.childNodes);
    for (var i = 0; i < nodes.length; i++) {
      out.appendChild(document.importNode(nodes[i], true));
    }
    return out;
  }

  function fill(target, html) {
    while (target.firstChild) {
      target.removeChild(target.firstChild);
    }
    target.appendChild(fragmentFrom(html));
  }

  // ------------------------------------------------------------------ marco: menú, tema, desplegables

  // El menu en cajon (tableta y movil): al abrirlo el foco entra en el; al cerrarlo vuelve al boton que lo abrio.
  function setMenu(open, refocus) {
    var shell = one('[data-af-shell]');
    var btn = one('[data-af-toggle="menu"]');
    if (!shell || shell.classList.contains('is-menu') === open) {
      return;
    }
    shell.classList.toggle('is-menu', open);
    if (btn) {
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (open) {
      var first = one('#af-side [aria-current="page"]') || one('#af-side a, #af-side summary');
      if (first && first.focus) {
        first.focus();
      }
    } else if (refocus && btn && btn.focus) {
      btn.focus();
    }
  }

  on('click', '[data-af-toggle="menu"]', function () {
    var shell = one('[data-af-shell]');
    if (shell) {
      setMenu(!shell.classList.contains('is-menu'), false);
    }
  });

  on('click', '[data-af-theme]', function () {
    var root = document.documentElement;
    var cur = root.getAttribute('data-theme');
    if (!cur) {
      cur = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    var next = cur === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    try {
      window.localStorage.setItem('af-theme', next);
    } catch (e) {
      // sin almacenamiento: el cambio vale solo para esta página
    }
  });

  function closeDropdowns(except) {
    all('[data-af-dropdown]').forEach(function (btn) {
      var box = btn.parentNode;
      if (box === except) {
        return;
      }
      var menu = box ? one('.af-dropdown-menu', box) : null;
      if (menu && !menu.hidden) {
        menu.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  on('click', '[data-af-dropdown]', function (e, btn) {
    var box = btn.parentNode;
    var menu = box ? one('.af-dropdown-menu', box) : null;
    if (!menu) {
      return;
    }
    var opening = menu.hidden;
    closeDropdowns(opening ? box : null);
    menu.hidden = !opening;
    btn.setAttribute('aria-expanded', opening ? 'true' : 'false');
  });

  // Con teclado: flecha abajo abre el desplegable y entra en el; arriba y abajo recorren sus opciones.
  on('keydown', '.af-dropdown', function (e, box) {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') {
      return;
    }
    var btn = one('[data-af-dropdown]', box);
    var menu = one('.af-dropdown-menu', box);
    if (!btn || !menu) {
      return;
    }
    e.preventDefault();
    if (menu.hidden) {
      closeDropdowns(box);
      menu.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
    }
    var items = all('a, button', menu);
    var at = items.indexOf(document.activeElement);
    var next = e.key === 'ArrowDown' ? at + 1 : at - 1;
    if (next < 0) {
      next = items.length - 1;
    }
    if (items.length) {
      items[next % items.length].focus();
    }
  });

  on('click', null, function (e) {
    var t = e.target;
    if (!t || t.nodeType !== 1) {
      return;
    }
    all('[data-af-dropdown]').forEach(function (btn) {
      var box = btn.parentNode;
      if (box && !box.contains(t)) {
        var menu = one('.af-dropdown-menu', box);
        if (menu && !menu.hidden) {
          menu.hidden = true;
          btn.setAttribute('aria-expanded', 'false');
        }
      }
    });
    if (one('[data-af-shell].is-menu') && !closest(t, 'aside, [data-af-toggle="menu"]')) {
      setMenu(false, false);
    }
  });

  on('keydown', null, function (e) {
    if (e.key !== 'Escape') {
      return;
    }
    var open = one('[data-af-dropdown][aria-expanded="true"]');
    closeDropdowns(null);
    if (open && open.parentNode.contains(document.activeElement) && open.focus) {
      open.focus();
    }
    setMenu(false, true);
    if (drag) {
      cancelDrag();
    }
  });

  // ------------------------------------------------------------------ pestañas

  function tabScope(container) {
    return closest(container, 'form') || container.parentNode || document;
  }

  function tabKey(btn) {
    return btn.getAttribute('data-af-tab');
  }

  function activateTab(container, key, fromUser) {
    var tabs = all('[data-af-tab]', container);
    var found = null;
    var keys = {};
    var i;
    for (i = 0; i < tabs.length; i++) {
      keys[tabKey(tabs[i])] = true;
      if (tabKey(tabs[i]) === key) {
        found = tabs[i];
      }
    }
    if (!found) {
      return false;
    }
    for (i = 0; i < tabs.length; i++) {
      var sel = tabs[i] === found;
      tabs[i].setAttribute('aria-selected', sel ? 'true' : 'false');
      tabs[i].setAttribute('tabindex', sel ? '0' : '-1');
    }
    var scope = tabScope(container);
    all('[data-af-panel]', scope).forEach(function (panel) {
      var k = panel.getAttribute('data-af-panel');
      if (keys[k] === true) {
        panel.hidden = k !== key;
      }
    });
    var input = one('input[data-af-tab-input]', scope);
    if (input) {
      input.value = key;
    }
    if (fromUser) {
      try {
        window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search + '#tab=' + encodeURIComponent(key));
      } catch (e) {
        // sin historial: no pasa nada
      }
    }
    return true;
  }

  function initTabs(container) {
    var tabs = all('[data-af-tab]', container);
    if (!tabs.length) {
      return;
    }
    var keys = {};
    tabs.forEach(function (t) {
      keys[tabKey(t)] = true;
    });
    var key = null;
    var m = /^#(?:.*&)?tab=([^&]*)/.exec(window.location.hash || '');
    if (m) {
      try {
        key = decodeURIComponent(m[1]);
      } catch (e) {
        key = m[1];
      }
    }
    if (key === null || keys[key] !== true) {
      key = container.getAttribute('data-af-active');
    }
    if (key === null || keys[key] !== true) {
      var chosen = one('[data-af-tab][aria-selected="true"]', container);
      key = tabKey(chosen || tabs[0]);
    }
    activateTab(container, key, false);
  }

  on('click', '[data-af-tab]', function (e, btn) {
    var c = closest(btn, '[data-af-tabs]');
    if (btn.tagName === 'A') {
      e.preventDefault();
    }
    if (c) {
      activateTab(c, tabKey(btn), true);
    }
  });

  on('keydown', '[data-af-tab]', function (e, btn) {
    var k = e.key;
    if (k !== 'ArrowLeft' && k !== 'ArrowRight' && k !== 'Home' && k !== 'End') {
      return;
    }
    var c = closest(btn, '[data-af-tabs]');
    if (!c) {
      return;
    }
    var tabs = all('[data-af-tab]', c);
    var idx = tabs.indexOf(btn);
    var next = idx;
    if (k === 'ArrowRight') {
      next = (idx + 1) % tabs.length;
    } else if (k === 'ArrowLeft') {
      next = (idx - 1 + tabs.length) % tabs.length;
    } else if (k === 'Home') {
      next = 0;
    } else {
      next = tabs.length - 1;
    }
    e.preventDefault();
    activateTab(c, tabKey(tabs[next]), true);
    if (typeof tabs[next].focus === 'function') {
      tabs[next].focus();
    }
  });

  // Pestañas entre pantallas hermanas: son enlaces (sin JavaScript también van); aquí solo las flechas, Inicio y Fin.
  on('keydown', '[data-af-pagetabs] [role="tab"]', function (e, link) {
    var k = e.key;
    var links = all('[role="tab"]', closest(link, '[data-af-pagetabs]'));
    var idx = links.indexOf(link);
    var next = k === 'ArrowRight' ? (idx + 1) % links.length : k === 'ArrowLeft' ? (idx - 1 + links.length) % links.length : k === 'Home' ? 0 : k === 'End' ? links.length - 1 : -1;
    if (next >= 0) {
      e.preventDefault();
      links[next].focus();
    }
  });

  // ------------------------------------------------------------------ confirmación (enlaces, botones, formularios)

  function proceed(el) {
    var form = el.form;
    if (form && el.type === 'submit' && typeof form.requestSubmit === 'function') {
      try {
        form.requestSubmit(el);
        return;
      } catch (x) {
        // sigue con el respaldo
      }
    }
    el.__afOk = true;
    el.click();
    el.__afOk = false;
  }

  // «Imprimir»: por delegación; un onclick escrito en la página no se ejecuta con la política de contenido.
  on('click', '[data-af-print]', function () {
    window.print();
  });

  on('click', '[data-af-confirm]:not(form)', function (e, el) {
    if (el.__afOk) {
      el.__afOk = false;
      return;
    }
    var text = el.getAttribute('data-af-confirm');
    if (!text) {
      return;
    }
    e.preventDefault();
    confirmDialog(text).then(function (ok) {
      if (ok) {
        proceed(el);
      }
    });
    return false;
  });

  // Un interruptor que pide confirmación solo al encenderlo (data-af-confirm-on lleva la pregunta): si se dice que no, sigue apagado.
  on('click', 'input[data-af-confirm-on]', function (e, el) {
    if (el.__afOk) {
      el.__afOk = false;
      return;
    }
    var text = el.getAttribute('data-af-confirm-on');
    if (!text || !el.checked) {
      return;
    }
    e.preventDefault();
    confirmDialog(text).then(function (ok) {
      if (ok) {
        el.__afOk = true;
        el.click();
      }
    });
    return false;
  });

  function confirmTextOf(form) {
    var text = form.getAttribute('data-af-confirm');
    if (text) {
      return text;
    }
    if (form.hasAttribute('data-af-bulk')) {
      var select = one('select[name="action"]', form);
      var opt = select && select.options ? select.options[select.selectedIndex] : null;
      var c = opt ? opt.getAttribute('data-confirm') : '';
      return c ? c : '';
    }
    return '';
  }

  /** Reenvía un formulario ya confirmado: la marca __afOk evita preguntar otra vez (la consume el manejador de submit). */
  function resubmit(form, submitter) {
    form.__afOk = true;
    if (typeof form.requestSubmit === 'function') {
      try {
        form.requestSubmit(submitter || undefined);
        form.__afOk = false;
        return;
      } catch (x) {
        // sigue con el respaldo
      }
    }
    var ev = null;
    try {
      ev = new window.Event('submit', {bubbles: true, cancelable: true});
    } catch (x2) {
      ev = null;
    }
    if (ev === null || form.dispatchEvent(ev)) {
      form.__afOk = false;
      if (typeof form.submit === 'function') {
        form.submit();
      }
    }
    form.__afOk = false;
  }

  // ------------------------------------------------------------------ envío de formularios

  on('submit', 'form', function (e, form) {
    if (e.defaultPrevented) {
      return;
    }
    var submitter = e.submitter || null;
    if (form.__afOk) {
      form.__afOk = false;
    } else {
      var text = confirmTextOf(form);
      if (text) {
        e.preventDefault();
        confirmDialog(text).then(function (ok) {
          if (ok) {
            resubmit(form, submitter);
          }
        });
        return false;
      }
    }
    prepare(form);
    form.__afDirty = false;
    if (form.hasAttribute('data-af-ajax')) {
      e.preventDefault();
      ajaxSubmit(form, submitter);
      return false;
    }
  });

  function prepare(form) {
    all('input[data-af-tags]', form).forEach(cleanTags);
    syncRichtext(form);
    renumber(form);
  }

  function setBusy(form, busy) {
    form.setAttribute('aria-busy', busy ? 'true' : 'false');
    all('button[type="submit"], input[type="submit"], button:not([type])', form).forEach(function (b) {
      if (busy) {
        if (!b.disabled) {
          b.disabled = true;
          b.__afBusy = true;
        }
      } else if (b.__afBusy) {
        b.disabled = false;
        b.__afBusy = false;
      }
    });
  }

  function clearErrors(form) {
    all('.af-error', form).forEach(remove);
    all('.af-field-invalid', form).forEach(function (el) {
      el.classList.remove('af-field-invalid');
    });
  }

  function fieldBox(form, key) {
    var box = one('[data-af-field="' + attrEsc(key) + '"]', form);
    if (!box) {
      var base = String(key).split(/[.\[]/)[0];
      if (base !== key) {
        box = one('[data-af-field="' + attrEsc(base) + '"]', form);
      }
    }
    return box;
  }

  function revealField(field) {
    var panel = closest(field, '[data-af-panel]');
    if (!panel) {
      return;
    }
    var scope = closest(panel, 'form') || document;
    var container = one('[data-af-tabs]', scope);
    if (container) {
      activateTab(container, panel.getAttribute('data-af-panel'), true);
    }
  }

  function showErrors(form, errors) {
    var first = null;
    var loose = [];
    for (var key in errors) {
      if (!Object.prototype.hasOwnProperty.call(errors, key)) {
        continue;
      }
      var msg = errors[key];
      if (Object.prototype.toString.call(msg) === '[object Array]') {
        msg = msg[0];
      }
      msg = msg === null || msg === undefined ? '' : String(msg);
      var field = fieldBox(form, key);
      if (!field) {
        loose.push(msg);
        continue;
      }
      field.classList.add('af-field-invalid');
      (one('.af-control', field) || field).appendChild(h('div', {'class': 'af-error', role: 'alert'}, msg));
      if (!first) {
        first = field;
      }
    }
    if (first) {
      revealField(first);
      var input = one('input:not([type="hidden"]), select, textarea', first);
      if (input && typeof input.focus === 'function') {
        input.focus();
      }
    }
    loose.forEach(function (m) {
      toast(m, 'bad');
    });
    return first !== null || loose.length > 0;
  }

  function ajaxSubmit(form, submitter) {
    var action = submitter && submitter.getAttribute('formaction') ? submitter.getAttribute('formaction') : (form.getAttribute('action') || window.location.href);
    var fd = new window.FormData(form);
    if (submitter && submitter.name) {
      fd.append(submitter.name, submitter.value);
    }
    var targetSel = (submitter && submitter.getAttribute('data-af-target')) || form.getAttribute('data-af-target');
    setBusy(form, true);
    post(action, fd).then(function (data) {
      var d = data && typeof data === 'object' ? data : {};
      clearErrors(form);
      var hasErrors = false;
      if (d.errors && typeof d.errors === 'object') {
        hasErrors = showErrors(form, d.errors);
      }
      var okay = d.ok !== false && !hasErrors;
      form.__afDirty = !okay;
      if (typeof d.html === 'string' && targetSel) {
        var target = one(targetSel);
        if (target) {
          fill(target, d.html);
          enhance(target);
        }
      }
      if (d.message) {
        toast(d.message, okay ? 'ok' : 'bad');
      }
      if (d.redirect) {
        go(String(d.redirect));
      }
    }).catch(function (err) {
      form.__afDirty = true;
      var data = err && err.data && typeof err.data === 'object' ? err.data : null;
      if (data && data.errors && typeof data.errors === 'object') {
        clearErrors(form);
        showErrors(form, data.errors);
      }
      var msg = data && data.error && data.error.message ? data.error.message : (data && data.message ? data.message : '');
      if (msg === '') {
        msg = form.getAttribute('data-af-error') || (err && err.status ? String(err.status) : '');
      }
      toast(msg, 'bad');
    }).then(function () {
      setBusy(form, false);
    });
  }

  // ------------------------------------------------------------------ selección de filas

  function refreshSelection(table) {
    var boxes = all('[data-af-check]', table);
    var n = 0;
    boxes.forEach(function (b) {
      if (b.checked) {
        n++;
      }
    });
    var bar = one('[data-af-bulk]', table);
    if (bar) {
      bar.classList.toggle('is-on', n > 0);
    }
    var count = one('[data-af-count]', table);
    if (count) {
      count.textContent = String(n);
    }
    var master = one('[data-af-check-all]', table);
    if (master) {
      master.checked = n > 0 && n === boxes.length;
      master.indeterminate = n > 0 && n < boxes.length;
    }
  }

  on('change', '[data-af-check-all]', function (e, master) {
    var table = closest(master, '[data-af-table]');
    if (!table) {
      return;
    }
    all('[data-af-check]', table).forEach(function (b) {
      if (!b.disabled) {
        b.checked = master.checked;
      }
    });
    refreshSelection(table);
  });

  on('change', '[data-af-check]', function (e, box) {
    var table = closest(box, '[data-af-table]');
    if (table) {
      refreshSelection(table);
    }
  });

  on('change', '[data-af-autosubmit]', function (e, el) {
    var form = el.form || closest(el, 'form');
    if (!form) {
      return;
    }
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else if (typeof form.submit === 'function') {
      form.submit();
    }
  });

  // ------------------------------------------------------------------ campos condicionados

  function fieldValues(form, key) {
    var els = [];
    var names = ['f[' + key + ']', 'f[' + key + '][]'];
    for (var i = 0; i < names.length && !els.length; i++) {
      els = all('[name="' + attrEsc(names[i]) + '"]', form);
    }
    if (!els.length) {
      els = all('[name$="[' + attrEsc(key) + ']"]', form);
    }
    if (!els.length) {
      return null;
    }
    var out = [];
    var checks = [];
    var hiddens = [];
    var radios = false;
    els.forEach(function (el) {
      var type = String(el.type || '').toLowerCase();
      if (type === 'radio') {
        radios = true;
        if (el.checked) {
          out.push(el.value);
        }
      } else if (type === 'checkbox') {
        checks.push(el);
      } else if (type === 'hidden') {
        hiddens.push(el.value);
      } else if (type === 'select-multiple') {
        arr(el.options).forEach(function (o) {
          if (o.selected) {
            out.push(o.value);
          }
        });
      } else {
        out.push(el.value);
      }
    });
    if (checks.length === 1) {
      out.push(checks[0].checked ? '1' : '0');
    } else if (checks.length > 1) {
      checks.forEach(function (c) {
        if (c.checked) {
          out.push(c.value);
        }
      });
    } else if (!radios && !out.length) {
      out = hiddens;
    }
    return out.length ? out : [''];
  }

  function norm(v) {
    if (v === true) {
      return '1';
    }
    if (v === false) {
      return '0';
    }
    return v === null || v === undefined ? '' : String(v);
  }

  function evalShowIf(el) {
    var cond = null;
    try {
      cond = JSON.parse(el.getAttribute('data-af-show-if'));
    } catch (e) {
      return;
    }
    if (!cond || typeof cond !== 'object') {
      return;
    }
    var form = closest(el, 'form') || document;
    var visible = true;
    for (var key in cond) {
      if (!Object.prototype.hasOwnProperty.call(cond, key)) {
        continue;
      }
      var actual = fieldValues(form, key);
      if (actual === null) {
        continue;
      }
      var expected = Object.prototype.toString.call(cond[key]) === '[object Array]' ? cond[key] : [cond[key]];
      var hit = false;
      for (var i = 0; i < expected.length; i++) {
        if (actual.indexOf(norm(expected[i])) !== -1) {
          hit = true;
          break;
        }
      }
      if (!hit) {
        visible = false;
        break;
      }
    }
    el.hidden = !visible;
  }

  function reevaluate(e) {
    var form = e.target && e.target.nodeType === 1 ? closest(e.target, 'form') : null;
    if (form) {
      all('[data-af-show-if]', form).forEach(evalShowIf);
    }
  }

  on('change', null, reevaluate);
  on('input', null, reevaluate);

  // ------------------------------------------------------------------ idiomas

  on('click', '[data-af-lang]', function (e, btn) {
    var lang = btn.getAttribute('data-af-lang');
    all('[data-af-langs]').forEach(function (box) {
      all('[data-af-lang]', box).forEach(function (b) {
        b.setAttribute('aria-pressed', b.getAttribute('data-af-lang') === lang ? 'true' : 'false');
      });
      all('[data-af-lang-panel]', box).forEach(function (p) {
        p.hidden = p.getAttribute('data-af-lang-panel') !== lang;
      });
    });
  });

  // ------------------------------------------------------------------ repetidor

  var drag = null;

  function markDirty(el) {
    var form = el ? closest(el, 'form[data-af-dirty]') : null;
    if (form) {
      form.__afDirty = true;
    }
  }

  function ownBox(rep) {
    return one('[data-af-rows]', rep);
  }

  function ownRows(rep) {
    var box = ownBox(rep);
    return box ? arr(box.children).filter(function (c) {
      return c.hasAttribute('data-af-row');
    }) : [];
  }

  function ownTemplate(rep) {
    var list = all('template[data-af-template]', rep);
    for (var i = 0; i < list.length; i++) {
      if (closest(list[i].parentNode, '[data-af-repeater]') === rep) {
        return list[i];
      }
    }
    return null;
  }

  function tplPrefix(tpl) {
    if (!tpl || !tpl.content) {
      return null;
    }
    var el = one('[name*="__i__"]', tpl.content);
    if (!el) {
      return null;
    }
    var name = el.getAttribute('name');
    return name.slice(0, name.indexOf('__i__'));
  }

  var INDEXED = ['name', 'id', 'for', 'aria-controls', 'aria-labelledby', 'aria-describedby'];

  function setIndex(root, index) {
    all('[name],[id],[for],[aria-controls],[aria-labelledby],[aria-describedby]', root).forEach(function (el) {
      INDEXED.forEach(function (a) {
        var v = el.getAttribute(a);
        if (v !== null && v.indexOf('__i__') !== -1) {
          el.setAttribute(a, v.replace('__i__', String(index)));
        }
      });
    });
    all('template', root).forEach(function (t) {
      if (t.content) {
        setIndex(t.content, index);
      }
    });
  }

  on('click', '[data-af-add]', function (e, btn) {
    var rep = closest(btn, '[data-af-repeater]');
    var tpl = rep ? ownTemplate(rep) : null;
    var box = rep ? ownBox(rep) : null;
    if (!rep || !tpl || !box || !tpl.content) {
      return;
    }
    e.preventDefault();
    var prefix = tplPrefix(tpl);
    var rows = ownRows(rep);
    var next = Math.max(parseInt(rep.getAttribute('data-af-next') || '0', 10) || 0, rows.length);
    if (prefix !== null) {
      rows.forEach(function (row) {
        all('[name]', row).forEach(function (el) {
          var n = el.getAttribute('name');
          if (n.indexOf(prefix) === 0) {
            var m = /^(\d+)/.exec(n.slice(prefix.length));
            if (m && parseInt(m[1], 10) >= next) {
              next = parseInt(m[1], 10) + 1;
            }
          }
        });
      });
    }
    rep.setAttribute('data-af-next', String(next + 1));
    var frag = document.importNode(tpl.content, true);
    setIndex(frag, next);
    box.appendChild(frag);
    var row = box.lastElementChild;
    if (row) {
      enhance(row);
      var first = one('input:not([type="hidden"]), select, textarea', row);
      if (first && typeof first.focus === 'function') {
        first.focus();
      }
    }
    markDirty(box);
  });

  on('click', '[data-af-remove]', function (e, btn) {
    var row = closest(btn, '[data-af-row]');
    if (!row) {
      return;
    }
    e.preventDefault();
    var form = row.parentNode;
    remove(row);
    markDirty(form);
  });

  function renumber(form) {
    all('[data-af-repeater]', form).forEach(function (rep) {
      var prefix = tplPrefix(ownTemplate(rep));
      if (prefix === null) {
        return;
      }
      ownRows(rep).forEach(function (row, i) {
        all('[name]', row).forEach(function (el) {
          var n = el.getAttribute('name');
          if (n.indexOf(prefix) === 0) {
            var rest = n.slice(prefix.length);
            var m = /^\d+/.exec(rest);
            if (m) {
              el.setAttribute('name', prefix + i + rest.slice(m[0].length));
            }
          }
        });
      });
    });
  }

  function clearDropMarks() {
    all('.af-drop-before, .af-drop-after').forEach(function (el) {
      el.classList.remove('af-drop-before');
      el.classList.remove('af-drop-after');
    });
  }

  function cancelDrag() {
    if (!drag) {
      return;
    }
    drag.row.classList.remove('is-dragging');
    clearDropMarks();
    if (document.body) {
      document.body.classList.remove('af-dragging');
    }
    drag = null;
  }

  on('pointerdown', '[data-af-drag]', function (e, handle) {
    if (e.pointerType === 'mouse' && e.button !== 0) {
      return;
    }
    var row = closest(handle, '[data-af-row]');
    if (!row) {
      return;
    }
    e.preventDefault();
    drag = {row: row, before: null};
    row.classList.add('is-dragging');
    if (document.body) {
      document.body.classList.add('af-dragging');
    }
  });

  on('pointermove', null, function (e) {
    if (!drag) {
      return;
    }
    var row = drag.row;
    var parent = row.parentNode;
    if (!parent) {
      return;
    }
    var sibs = arr(parent.children).filter(function (c) {
      return c !== row && c.hasAttribute('data-af-row');
    });
    var before = null;
    for (var i = 0; i < sibs.length; i++) {
      var r = sibs[i].getBoundingClientRect();
      if (e.clientY < r.top + r.height / 2) {
        before = sibs[i];
        break;
      }
    }
    drag.before = before;
    clearDropMarks();
    if (before) {
      before.classList.add('af-drop-before');
    } else if (sibs.length) {
      sibs[sibs.length - 1].classList.add('af-drop-after');
    }
  });

  function endDrag() {
    if (!drag) {
      return;
    }
    var row = drag.row;
    var parent = row.parentNode;
    var before = drag.before;
    cancelDrag();
    if (parent) {
      parent.insertBefore(row, before);
      markDirty(parent);
    }
  }

  on('pointerup', null, endDrag);
  on('pointercancel', null, cancelDrag);

  on('keydown', '[data-af-row]', function (e, row) {
    if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) {
      return;
    }
    var parent = row.parentNode;
    if (!parent) {
      return;
    }
    e.preventDefault();
    var t = e.target;
    if (e.key === 'ArrowUp') {
      var prev = row.previousElementSibling;
      if (prev && prev.hasAttribute('data-af-row')) {
        parent.insertBefore(row, prev);
      }
    } else {
      var nxt = row.nextElementSibling;
      if (nxt && nxt.hasAttribute('data-af-row')) {
        parent.insertBefore(row, nxt.nextElementSibling);
      }
    }
    if (t && typeof t.focus === 'function') {
      t.focus();
    }
    markDirty(parent);
    return false;
  });

  // ------------------------------------------------------------------ dirección amigable y etiquetas

  function slugAuto(target) {
    if (target.__afSlugAuto === undefined) {
      target.__afSlugAuto = target.value === '';
    }
    return target.__afSlugAuto;
  }

  on('input', 'input, textarea', function (e, src) {
    if (src.hasAttribute('data-af-slug-from')) {
      src.__afSlugAuto = false;
      return;
    }
    if (!src.id) {
      return;
    }
    all('input[data-af-slug-from="' + attrEsc(src.id) + '"]').forEach(function (target) {
      if (slugAuto(target)) {
        target.value = slug(src.value);
      }
    });
  });

  function cleanTags(input) {
    var seen = {};
    var out = [];
    String(input.value).split(',').forEach(function (part) {
      var v = part.trim();
      var k = v.toLowerCase();
      if (v !== '' && seen[k] !== true) {
        seen[k] = true;
        out.push(v);
      }
    });
    input.value = out.join(', ');
  }

  on('focusout', 'input[data-af-tags]', function (e, input) {
    cleanTags(input);
  });

  // ------------------------------------------------------------------ editor de texto

  var RT_TOOLS = [
    {k: 'bold', t: 'B', cmd: 'bold', state: 'bold'},
    {k: 'italic', t: 'I', cmd: 'italic', state: 'italic'},
    {k: 'h2', t: 'H2', cmd: 'formatBlock', arg: 'h2'},
    {k: 'h3', t: 'H3', cmd: 'formatBlock', arg: 'h3'},
    {k: 'p', t: ch(182), cmd: 'formatBlock', arg: 'p'},
    {k: 'ul', t: ch(8226), cmd: 'insertUnorderedList', state: 'insertUnorderedList'},
    {k: 'ol', t: '1.', cmd: 'insertOrderedList', state: 'insertOrderedList'},
    {k: 'quote', t: ch(8220) + ch(8221), cmd: 'formatBlock', arg: 'blockquote'},
    {k: 'link', svg: ['M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1', 'M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1']},
    {k: 'unlink', svg: ['M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1', 'M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1', 'M4 4l16 16']},
    {k: 'clear', svg: ['M5 5h14', 'M9 5l-3 14', 'M15 5l-1.5 5', 'M4 19h8', 'M15 15l5 5', 'M20 15l-5 5']},
    {k: 'html', t: 'HTML'}
  ];

  function exec(cmd, arg) {
    try {
      return document.execCommand(cmd, false, arg === undefined ? null : arg);
    } catch (e) {
      return false;
    }
  }

  function isSafeLink(url) {
    return /^(https?:\/\/|\/|#|mailto:|tel:)/i.test(url);
  }

  function rtSync(wrap) {
    if (!wrap || wrap.__afCode || !wrap.__afTa) {
      return;
    }
    var html = wrap.__afArea.innerHTML;
    if (html === '<br>' || html === '<p><br></p>' || html === '<div><br></div>') {
      html = '';
    }
    wrap.__afTa.value = html;
  }

  function syncRichtext(form) {
    all('.af-rt', form).forEach(rtSync);
  }

  function initRichtext(ta) {
    if (ta.__afRt || !ta.parentNode) {
      return;
    }
    ta.__afRt = true;
    var labels = {};
    var raw = ta.getAttribute('data-af-richtext');
    if (raw && raw.charAt(0) === '{') {
      try {
        labels = JSON.parse(raw) || {};
      } catch (e) {
        labels = {};
      }
    }
    var wrap = h('div', {'class': 'af-rt'});
    var bar = h('div', {'class': 'af-rt-bar', role: 'toolbar'});
    var area = h('div', {'class': 'af-rt-area', contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true'});
    RT_TOOLS.forEach(function (tool) {
      var label = typeof labels[tool.k] === 'string' && labels[tool.k] !== '' ? labels[tool.k] : (tool.t || tool.k);
      var btn = h('button', {type: 'button', 'class': 'af-rt-btn', 'data-af-rt': tool.k, 'aria-label': label, title: label});
      if (tool.svg) {
        btn.appendChild(svg(tool.svg));
      } else {
        btn.textContent = tool.t;
      }
      bar.appendChild(btn);
    });
    var lab = ta.id ? one('label[for="' + attrEsc(ta.id) + '"]') : null;
    if (lab) {
      if (!lab.id) {
        lab.id = 'af-lbl-' + (++uid);
      }
      area.setAttribute('aria-labelledby', lab.id);
    }
    fill(area, ta.value);
    wrap.appendChild(bar);
    wrap.appendChild(area);
    wrap.__afTa = ta;
    wrap.__afArea = area;
    wrap.__afCode = false;
    ta.hidden = true;
    ta.parentNode.insertBefore(wrap, ta);
    exec('defaultParagraphSeparator', 'p');
  }

  function rtToggleCode(wrap) {
    var area = wrap.__afArea;
    var ta = wrap.__afTa;
    if (!wrap.__afCode) {
      rtSync(wrap);
      wrap.__afCode = true;
      area.hidden = true;
      ta.hidden = false;
      if (typeof ta.focus === 'function') {
        ta.focus();
      }
    } else {
      fill(area, ta.value);
      wrap.__afCode = false;
      ta.hidden = true;
      area.hidden = false;
      rtSync(wrap);
      if (typeof area.focus === 'function') {
        area.focus();
      }
    }
    all('.af-rt-btn', wrap).forEach(function (b) {
      var isHtml = b.getAttribute('data-af-rt') === 'html';
      if (isHtml) {
        b.setAttribute('aria-pressed', wrap.__afCode ? 'true' : 'false');
      } else {
        b.disabled = wrap.__afCode;
      }
    });
  }

  function rtLink(wrap, area, label) {
    var sel = window.getSelection ? window.getSelection() : null;
    var saved = sel && sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null;
    var url = window.prompt(label, 'https://');
    if (url === null) {
      return;
    }
    url = String(url).trim();
    if (url === '' || !isSafeLink(url)) {
      return;
    }
    area.focus();
    if (saved && sel) {
      sel.removeAllRanges();
      sel.addRange(saved);
    }
    if (saved && saved.collapsed) {
      var a = h('a', {href: url}, url);
      saved.insertNode(a);
      return;
    }
    exec('createLink', url);
  }

  function rtState(wrap) {
    if (!wrap || wrap.__afCode) {
      return;
    }
    var block = '';
    try {
      block = String(document.queryCommandValue('formatBlock')).toLowerCase().replace(/[<>]/g, '');
    } catch (e) {
      block = '';
    }
    RT_TOOLS.forEach(function (tool) {
      var on_ = false;
      try {
        if (tool.state) {
          on_ = document.queryCommandState(tool.state) === true;
        } else if (tool.cmd === 'formatBlock') {
          on_ = block === tool.arg;
        } else {
          return;
        }
      } catch (e) {
        on_ = false;
      }
      var btn = one('[data-af-rt="' + tool.k + '"]', wrap);
      if (btn) {
        btn.setAttribute('aria-pressed', on_ ? 'true' : 'false');
      }
    });
  }

  on('mousedown', '.af-rt-btn', function (e) {
    e.preventDefault();
  });

  on('click', '.af-rt-btn', function (e, btn) {
    var wrap = closest(btn, '.af-rt');
    if (!wrap) {
      return;
    }
    e.preventDefault();
    var key = btn.getAttribute('data-af-rt');
    if (key === 'html') {
      rtToggleCode(wrap);
      return;
    }
    if (wrap.__afCode) {
      return;
    }
    var area = wrap.__afArea;
    area.focus();
    if (key === 'link') {
      rtLink(wrap, area, btn.getAttribute('aria-label') || '');
    } else if (key === 'unlink') {
      exec('unlink');
    } else if (key === 'clear') {
      exec('removeFormat');
      exec('unlink');
    } else {
      for (var i = 0; i < RT_TOOLS.length; i++) {
        var tool = RT_TOOLS[i];
        if (tool.k !== key) {
          continue;
        }
        if (tool.cmd === 'formatBlock') {
          var cur = '';
          try {
            cur = String(document.queryCommandValue('formatBlock')).toLowerCase().replace(/[<>]/g, '');
          } catch (x) {
            cur = '';
          }
          exec('formatBlock', '<' + (cur === tool.arg ? 'p' : tool.arg) + '>');
        } else {
          exec(tool.cmd);
        }
        break;
      }
    }
    rtSync(wrap);
    rtState(wrap);
  });

  on('input', '.af-rt-area', function (e, area) {
    rtSync(closest(area, '.af-rt'));
  });

  on('paste', '.af-rt-area', function (e, area) {
    var cd = e.clipboardData || window.clipboardData;
    if (!cd) {
      return;
    }
    var text = cd.getData('text/plain');
    if (typeof text !== 'string') {
      return;
    }
    e.preventDefault();
    if (!exec('insertText', text)) {
      var sel = window.getSelection ? window.getSelection() : null;
      if (sel && sel.rangeCount) {
        var r = sel.getRangeAt(0);
        r.deleteContents();
        var node = document.createTextNode(text);
        r.insertNode(node);
        r.setStartAfter(node);
        r.collapse(true);
        sel.removeAllRanges();
        sel.addRange(r);
      }
    }
    rtSync(closest(area, '.af-rt'));
  });

  on('selectionchange', null, function () {
    var a = document.activeElement;
    var area = a ? closest(a, '.af-rt-area') : null;
    if (area) {
      rtState(closest(area, '.af-rt'));
    }
  });

  // ------------------------------------------------------------------ selector con búsqueda

  function relLabel(sel) {
    if (sel.value === '' || !sel.options || sel.selectedIndex < 0) {
      return '';
    }
    var opt = sel.options[sel.selectedIndex];
    return opt ? opt.textContent : '';
  }

  function relClose(wrap) {
    var list = one('.af-rel-list', wrap);
    var input = one('.af-rel-input', wrap);
    if (list) {
      list.hidden = true;
    }
    if (input) {
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    }
  }

  function relActive(list, idx) {
    var items = all('li', list);
    items.forEach(function (li, i) {
      var on_ = i === idx;
      li.classList.toggle('is-active', on_);
      li.setAttribute('aria-selected', on_ ? 'true' : 'false');
    });
    var wrap = closest(list, '.af-rel');
    var input = wrap ? one('.af-rel-input', wrap) : null;
    if (items[idx]) {
      if (input) {
        input.setAttribute('aria-activedescendant', items[idx].id);
      }
      if (typeof items[idx].scrollIntoView === 'function') {
        items[idx].scrollIntoView({block: 'nearest'});
      }
    }
    list.__afIdx = idx;
  }

  function relRender(wrap, items) {
    var list = one('.af-rel-list', wrap);
    var input = one('.af-rel-input', wrap);
    while (list.firstChild) {
      list.removeChild(list.firstChild);
    }
    var rows = Object.prototype.toString.call(items) === '[object Array]' ? items : [];
    rows.forEach(function (item) {
      if (!item || item.id === undefined || item.id === null) {
        return;
      }
      var li = h('li', {role: 'option', id: 'af-opt-' + (++uid), 'aria-selected': 'false'}, item.label === undefined || item.label === null ? item.id : item.label);
      li.__afItem = {id: String(item.id), label: li.textContent};
      list.appendChild(li);
    });
    if (!list.firstChild) {
      relClose(wrap);
      return;
    }
    list.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    relActive(list, 0);
  }

  function relSearch(wrap, q) {
    var sel = wrap.__afSel;
    var url = sel.getAttribute('data-af-relation');
    wrap.__afSeq = (wrap.__afSeq || 0) + 1;
    var seq = wrap.__afSeq;
    get(joinQ(url, q)).then(function (res) {
      if (seq === wrap.__afSeq) {
        relRender(wrap, res && res.items);
      }
    }, function () {
      if (seq === wrap.__afSeq) {
        relRender(wrap, []);
      }
    });
  }

  function relPick(wrap, item) {
    var sel = wrap.__afSel;
    var found = null;
    arr(sel.options).forEach(function (o) {
      if (o.value === item.id) {
        found = o;
      }
    });
    if (!found) {
      found = h('option', {value: item.id}, item.label);
      sel.appendChild(found);
    }
    sel.value = item.id;
    one('.af-rel-input', wrap).value = item.label;
    relClose(wrap);
    sel.dispatchEvent(new window.Event('change', {bubbles: true}));
  }

  function initRelation(sel) {
    if (sel.__afRel || !sel.parentNode || !sel.getAttribute('data-af-relation')) {
      return;
    }
    sel.__afRel = true;
    var wrap = h('div', {'class': 'af-rel'});
    var listId = 'af-rel-' + (++uid);
    var input = h('input', {type: 'text', 'class': 'af-input af-input-md af-rel-input', role: 'combobox', autocomplete: 'off', 'aria-autocomplete': 'list', 'aria-expanded': 'false', 'aria-controls': listId});
    var clear = h('button', {type: 'button', 'class': 'af-icon-btn af-rel-clear'}, ch(215));
    var list = h('ul', {'class': 'af-rel-list', role: 'listbox', id: listId});
    list.hidden = true;
    var empty = sel.options && sel.options.length ? arr(sel.options).filter(function (o) {
      return o.value === '';
    })[0] : null;
    if (empty && empty.textContent) {
      input.setAttribute('placeholder', empty.textContent);
    }
    input.value = relLabel(sel);
    var lab = sel.id ? one('label[for="' + attrEsc(sel.id) + '"]') : null;
    if (lab) {
      if (!lab.id) {
        lab.id = 'af-lbl-' + (++uid);
      }
      input.setAttribute('aria-labelledby', lab.id);
    }
    wrap.appendChild(input);
    wrap.appendChild(clear);
    wrap.appendChild(list);
    wrap.__afSel = sel;
    sel.hidden = true;
    sel.parentNode.insertBefore(wrap, sel);
  }

  on('input', '.af-rel-input', function (e, input) {
    var wrap = closest(input, '.af-rel');
    if (!wrap) {
      return;
    }
    window.clearTimeout(wrap.__afTimer);
    wrap.__afTimer = window.setTimeout(function () {
      relSearch(wrap, input.value);
    }, 200);
  });

  on('keydown', '.af-rel-input', function (e, input) {
    var wrap = closest(input, '.af-rel');
    var list = wrap ? one('.af-rel-list', wrap) : null;
    if (!list) {
      return;
    }
    var k = e.key;
    var n = all('li', list).length;
    var idx = typeof list.__afIdx === 'number' ? list.__afIdx : 0;
    if (k === 'ArrowDown') {
      e.preventDefault();
      if (list.hidden) {
        window.clearTimeout(wrap.__afTimer);
        relSearch(wrap, input.value);
      } else if (n) {
        relActive(list, (idx + 1) % n);
      }
    } else if (k === 'ArrowUp') {
      e.preventDefault();
      if (!list.hidden && n) {
        relActive(list, (idx - 1 + n) % n);
      }
    } else if (k === 'Enter') {
      e.preventDefault();
      var li = !list.hidden ? all('li', list)[idx] : null;
      if (li && li.__afItem) {
        relPick(wrap, li.__afItem);
      }
    } else if (k === 'Escape') {
      if (!list.hidden) {
        e.preventDefault();
        relClose(wrap);
        input.value = relLabel(wrap.__afSel);
        return false;
      }
    } else if (k === 'Tab') {
      relClose(wrap);
    }
  });

  on('mousedown', '.af-rel-list', function (e) {
    e.preventDefault();
  });

  on('click', '.af-rel-list li', function (e, li) {
    var wrap = closest(li, '.af-rel');
    if (wrap && li.__afItem) {
      relPick(wrap, li.__afItem);
    }
  });

  on('click', '.af-rel-clear', function (e, btn) {
    var wrap = closest(btn, '.af-rel');
    if (!wrap) {
      return;
    }
    e.preventDefault();
    wrap.__afSel.value = '';
    one('.af-rel-input', wrap).value = '';
    relClose(wrap);
    wrap.__afSel.dispatchEvent(new window.Event('change', {bubbles: true}));
  });

  on('focusout', '.af-rel-input', function (e, input) {
    var wrap = closest(input, '.af-rel');
    if (wrap) {
      relClose(wrap);
      input.value = relLabel(wrap.__afSel);
    }
  });

  // ------------------------------------------------------------------ cambios sin guardar

  on('input', 'form[data-af-dirty]', function (e, form) {
    form.__afDirty = true;
  });
  on('change', 'form[data-af-dirty]', function (e, form) {
    form.__afDirty = true;
  });

  if (typeof window.addEventListener === 'function') {
    window.addEventListener('beforeunload', function (e) {
      var forms = all('form[data-af-dirty]');
      for (var i = 0; i < forms.length; i++) {
        if (forms[i].__afDirty) {
          e.preventDefault();
          e.returnValue = '';
          return '';
        }
      }
    });
  }

  // ------------------------------------------------------------------ copiar

  function legacyCopy(text) {
    if (!document.body) {
      return false;
    }
    var ta = h('textarea', {readonly: true, 'aria-hidden': 'true', style: 'position:fixed;top:-1000px;opacity:0'});
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try {
      ok = document.execCommand('copy');
    } catch (e) {
      ok = false;
    }
    remove(ta);
    return ok === true;
  }

  on('click', '[data-af-copy]', function (e, el) {
    var text = el.getAttribute('data-af-copy');
    if (text === null) {
      return;
    }
    e.preventDefault();
    var done = function () {
      var msg = el.getAttribute('data-af-copied');
      toast(msg ? msg : (text.length > 60 ? text.slice(0, 60) : text), 'ok');
    };
    var nav = window.navigator;
    if (nav && nav.clipboard && typeof nav.clipboard.writeText === 'function') {
      nav.clipboard.writeText(text).then(done, function () {
        if (legacyCopy(text)) {
          done();
        }
      });
    } else if (legacyCopy(text)) {
      done();
    }
  });

  // ------------------------------------------------------------------ biblioteca de medios (solo quitar)

  on('click', '[data-af-media-remove]', function (e, btn) {
    var item = closest(btn, '.af-thumb-item');
    var box = closest(btn, '[data-af-media]');
    if (!item || !box) {
      return;
    }
    e.preventDefault();
    var form = item.parentNode;
    remove(item);
    var input = one('[data-af-media-input]', box);
    if (input) {
      input.value = all('.af-thumb-item', box).map(function (el) {
        return el.getAttribute('data-id');
      }).filter(function (v) {
        return v !== null && v !== '';
      }).join(',');
    }
    markDirty(form);
  });

  // ------------------------------------------------------------------ buscador de órdenes

  var paletteSeq = 0;

  function paletteSelect(list, idx) {
    var links = all('a', list);
    links.forEach(function (a, i) {
      a.setAttribute('aria-selected', i === idx ? 'true' : 'false');
    });
    if (links[idx] && typeof links[idx].scrollIntoView === 'function') {
      links[idx].scrollIntoView({block: 'nearest'});
    }
  }

  function paletteRender(list, items) {
    while (list.firstChild) {
      list.removeChild(list.firstChild);
    }
    var rows = Object.prototype.toString.call(items) === '[object Array]' ? items : [];
    rows.forEach(function (item) {
      if (!item || typeof item.url !== 'string' || !safeUrl(item.url)) {
        return;
      }
      var a = h('a', {href: item.url});
      a.appendChild(document.createTextNode(item.label === undefined || item.label === null ? '' : String(item.label)));
      if (item.group) {
        a.appendChild(h('small', null, item.group));
      }
      var li = document.createElement('li');
      li.appendChild(a);
      list.appendChild(li);
    });
    paletteSelect(list, 0);
  }

  function paletteLoad(q) {
    var dlg = document.getElementById('af-palette');
    var list = dlg ? one('[data-af-palette-list]', dlg) : null;
    var url = dlg ? dlg.getAttribute('data-url') : '';
    if (!list || !url) {
      return;
    }
    paletteSeq++;
    var seq = paletteSeq;
    get(joinQ(url, q)).then(function (res) {
      if (seq === paletteSeq) {
        paletteRender(list, res && res.items);
      }
    }, function () {
      if (seq === paletteSeq) {
        paletteRender(list, []);
      }
    });
  }

  function paletteOpen() {
    var dlg = document.getElementById('af-palette');
    if (!dlg || typeof dlg.showModal !== 'function') {
      return;
    }
    var input = one('[data-af-palette-input]', dlg);
    if (!dlg.open) {
      try {
        dlg.showModal();
      } catch (e) {
        return;
      }
      if (input) {
        input.value = '';
      }
      paletteLoad('');
    }
    if (input && typeof input.focus === 'function') {
      input.focus();
    }
  }

  on('click', '[data-af-palette-open]', function (e) {
    e.preventDefault();
    paletteOpen();
  });

  on('click', '#af-palette', function (e, dlg) {
    if (e.target === dlg && typeof dlg.close === 'function') {
      dlg.close();
    }
  });

  on('keydown', null, function (e) {
    if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey) {
      var dlg = document.getElementById('af-palette');
      if (dlg && typeof dlg.showModal === 'function') {
        e.preventDefault();
        paletteOpen();
      }
    }
  });

  on('input', '[data-af-palette-input]', function (e, input) {
    window.clearTimeout(input.__afTimer);
    input.__afTimer = window.setTimeout(function () {
      paletteLoad(input.value);
    }, 150);
  });

  on('keydown', '[data-af-palette-input]', function (e, input) {
    var dlg = closest(input, 'dialog');
    var list = dlg ? one('[data-af-palette-list]', dlg) : null;
    if (!list) {
      return;
    }
    var links = all('a', list);
    var idx = 0;
    links.forEach(function (a, i) {
      if (a.getAttribute('aria-selected') === 'true') {
        idx = i;
      }
    });
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (links.length) {
        paletteSelect(list, (idx + (e.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length);
      }
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (links[idx]) {
        go(links[idx].getAttribute('href'));
      }
    }
  });

  // ------------------------------------------------------------------ puesta en marcha

  // Por debajo de 768 px cada fila de una tabla es una tarjeta: cada celda necesita el nombre de su columna (data-label)
  // y, como la hoja les cambia el display, los papeles de tabla escritos para que un lector de pantalla la siga leyendo
  // como tabla. Las tablas que pinta Table.php ya traen data-label; aqui se completa en las escritas a mano.
  function labelTable(table) {
    if (table.getAttribute('data-af-labelled')) {
      return;
    }
    table.setAttribute('data-af-labelled', '1');
    table.setAttribute('role', 'table');
    var heads = [];
    all('thead tr', table).forEach(function (tr, n) {
      var col = 0;
      arr(tr.children).forEach(function (th) {
        var span = parseInt(th.getAttribute('colspan') || '1', 10) || 1;
        var text = (th.textContent || '').replace(/\s+/g, ' ').trim();
        for (var i = 0; i < span; i++) {
          if (n === 0 || text) {
            heads[col + i] = text;
          }
        }
        col += span;
        th.setAttribute('role', 'columnheader');
      });
    });
    all('thead, tbody, tfoot', table).forEach(function (g) {
      g.setAttribute('role', 'rowgroup');
    });
    all('tr', table).forEach(function (tr) {
      tr.setAttribute('role', 'row');
    });
    all('tbody tr, tfoot tr', table).forEach(function (tr) {
      var col = 0;
      arr(tr.children).forEach(function (cell) {
        var span = parseInt(cell.getAttribute('colspan') || '1', 10) || 1;
        cell.setAttribute('role', cell.tagName === 'TH' ? 'rowheader' : 'cell');
        if (!cell.hasAttribute('data-label') && span === 1 && heads[col] && !matches(cell, '.af-cell-actions, .af-cell-check')) {
          cell.setAttribute('data-label', heads[col]);
        }
        col += span;
      });
    });
  }

  // En pantalla estrecha los filtros de un listado se pliegan si no hay ninguno puesto: lo primero que se ve es la lista.
  function foldFilters(box) {
    if (box.getAttribute('data-af-filters') === '0' && window.matchMedia && window.matchMedia('(max-width: 900px)').matches) {
      box.removeAttribute('open');
    }
  }

  function enhance(root) {
    var scope = root || document;
    all('table.af-table', scope).forEach(labelTable);
    all('details[data-af-filters]', scope).forEach(foldFilters);
    all('textarea[data-af-richtext]', scope).forEach(initRichtext);
    all('select[data-af-relation]', scope).forEach(initRelation);
    all('input[data-af-slug-from]', scope).forEach(slugAuto);
    all('[data-af-show-if]', scope).forEach(evalShowIf);
  }

  function init() {
    all('[data-af-tabs]').forEach(initTabs);
    all('[data-af-pagetabs]').forEach(function (bar) {
      var cur = one('[aria-selected="true"]', bar);
      if (cur && bar.scrollWidth > bar.clientWidth) {
        bar.scrollLeft = cur.offsetLeft - bar.offsetLeft - 16;
      }
    });
    all('[data-af-table]').forEach(refreshSelection);
    enhance(document);
  }

  if (document.readyState === 'loading' && typeof document.addEventListener === 'function') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  Aifec.on = on;
  Aifec.toast = toast;
  Aifec.post = post;
  Aifec.get = get;
  Aifec.confirm = confirmDialog;
  Aifec.slug = slug;
  Aifec.enhance = enhance;

  window.Aifec = Aifec;
})(typeof window !== 'undefined' ? window : null, typeof document !== 'undefined' ? document : null);

/*
 * AIFEC — JavaScript de la tienda (tema base).
 * Sin dependencias y sin transpilar. Todo por delegación sobre `document`: lo que se pinte después funciona sin
 * volver a conectar nada. Sin cadenas visibles: los textos los pone el servidor. Expone solo `window.Aifec`.
 * Autor: Ecom Experts · 2026 · Ver LICENSE.md
 */
(function (window, document) {
  'use strict';

  if (!window || !document || !document.documentElement) {
    return;
  }

  var root = document.documentElement;
  var Aifec = window.Aifec || {};
  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  var state = {opener: null, open: null};

  // ------------------------------------------------------------------ utilidades

  function qs(selector, scope) {
    return (scope || document).querySelector(selector);
  }

  function qsa(selector, scope) {
    return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
  }

  function closest(node, selector) {
    while (node && node.nodeType !== 1) {
      node = node.parentNode;
    }
    return node && node.closest ? node.closest(selector) : null;
  }

  function attr(node, name) {
    return node && node.getAttribute ? node.getAttribute(name) : null;
  }

  function visible(node) {
    return !!(node.offsetWidth || node.offsetHeight || node.getClientRects().length);
  }

  /** Escucha un evento sobre `document` y llama a `fn` con el elemento que encaja con el selector. */
  Aifec.on = function (type, selector, fn) {
    document.addEventListener(type, function (event) {
      var hit = closest(event.target, selector);
      if (hit) {
        fn.call(hit, event, hit);
      }
    });
  };

  function encode(data) {
    if (typeof FormData !== 'undefined' && data instanceof FormData) {
      var params = new URLSearchParams();
      data.forEach(function (value, key) {
        if (typeof value === 'string') {
          params.append(key, value);
        }
      });
      return params;
    }
    var out = new URLSearchParams();
    Object.keys(data || {}).forEach(function (key) {
      out.append(key, data[key]);
    });
    return out;
  }

  function request(url, options) {
    if (!window.fetch) {
      return Promise.reject(new Error('fetch'));
    }
    options.credentials = 'same-origin';
    options.cache = 'no-store';
    options.headers = Object.assign({'Accept': 'application/json', 'X-Requested-With': 'fetch'}, options.headers || {});
    return window.fetch(url, options).then(function (response) {
      return response.json().catch(function () {
        return {};
      }).then(function (data) {
        if (!response.ok || (data && (data.error || data.ok === false))) {
          var error = new Error('http');
          error.status = response.status;
          error.data = data || {};
          throw error;
        }
        return data || {};
      });
    });
  }

  /** GET que espera JSON. Rechaza con `error.status` y `error.data` si la respuesta no es buena. */
  Aifec.get = function (url) {
    return request(url, {method: 'GET'});
  };

  /** POST (formulario) que espera JSON. `data` es un objeto o un FormData. */
  Aifec.post = function (url, data) {
    return request(url, {method: 'POST', body: encode(data), headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'}});
  };

  /**
   * Aviso breve. `type`: ok, bad o nada. El texto se pone con textContent. `action` (opcional): {url, label}, un enlace
   * al final del aviso («Ver el carrito»); solo vale una dirección de este mismo sitio.
   */
  Aifec.toast = function (text, type, action) {
    if (!text) {
      return;
    }
    var box = document.getElementById('af-toasts');
    if (!box) {
      box = document.createElement('div');
      box.id = 'af-toasts';
      box.className = 'af-toasts';
      box.setAttribute('aria-live', 'polite');
      document.body.appendChild(box);
    }
    var toast = document.createElement('div');
    toast.className = 'af-toast' + (type ? ' af-toast-' + type : '');
    toast.textContent = String(text);
    if (action && action.label && typeof action.url === 'string' && (/^\/(?!\/)/.test(action.url) || action.url.indexOf(window.location.origin + '/') === 0)) {
      var link = document.createElement('a');
      link.href = action.url;
      link.textContent = String(action.label);
      toast.appendChild(document.createTextNode(' '));
      toast.appendChild(link);
    }
    box.appendChild(toast);
    while (box.children.length > 3) {
      box.removeChild(box.firstChild);
    }
    window.setTimeout(function () {
      if (toast.parentNode) {
        toast.parentNode.removeChild(toast);
      }
    }, 4500);
  };

  /** Importe en céntimos con el formato del idioma y la moneda de <html data-currency data-locale>. */
  Aifec.money = function (cents) {
    var currency = attr(root, 'data-currency') || 'EUR';
    var locale = attr(root, 'data-locale') || undefined;
    var amount = (Number(cents) || 0) / 100;
    try {
      return new Intl.NumberFormat(locale, {style: 'currency', currency: currency}).format(amount);
    } catch (e) {
      return amount.toFixed(2) + ' ' + currency;
    }
  };

  function message(data) {
    if (!data) {
      return '';
    }
    if (data.message) {
      return String(data.message);
    }
    return data.error && data.error.message ? String(data.error.message) : '';
  }

  // ------------------------------------------------------------------ carrito: contador y panel

  /**
   * ¿Está puesta la marca? Las marcas (carrito, cuenta) son cookies sin ningún secreto que el servidor pone junto a las
   * de verdad, que el navegador no deja leer. Dicen si merece la pena pedir algo; así una página de la caché no pregunta nada.
   */
  function hasMark(name) {
    if (!name) {
      return false;
    }
    return document.cookie.split(';').some(function (part) {
      return part.replace(/^\s+/, '').indexOf(name + '=') === 0;
    });
  }

  function cartCookie() {
    return hasMark(attr(root, 'data-cart-cookie'));
  }

  // ------------------------------------------------------------------ cuenta: quién ha entrado

  function loadAccount() {
    var url = attr(root, 'data-account-url');
    if (!url || !hasMark(attr(root, 'data-account-cookie'))) {
      return;
    }
    Aifec.get(url).then(function (data) {
      if (!data || !data.logged) {
        return;
      }
      qsa('[data-af-account]').forEach(function (node) {
        node.classList.add('is-logged');
        if (data.name) {
          node.setAttribute('title', String(data.name));
        }
      });
    }).catch(function () {
      // Sin estado de la cuenta la tienda funciona igual.
    });
  }

  function countOf(data) {
    if (!data) {
      return null;
    }
    var candidates = [data.count, data.cart && data.cart.count, data.summary && data.summary.count];
    for (var i = 0; i < candidates.length; i++) {
      if (typeof candidates[i] === 'number') {
        return candidates[i];
      }
    }
    return null;
  }

  function applyCount(n) {
    if (typeof n !== 'number') {
      return;
    }
    qsa('[data-af-cart-count]').forEach(function (node) {
      node.textContent = n > 99 ? '99+' : String(n);
      node.hidden = n <= 0;
    });
  }

  function linesOf(data) {
    var lines = data && data.lines ? data.lines : [];
    return Array.isArray(lines) ? lines : Object.keys(lines).map(function (key) {
      return lines[key];
    });
  }

  function renderCart(data) {
    var list = qs('[data-af-cart-lines]');
    if (!list) {
      return;
    }
    var lines = linesOf(data);
    list.textContent = '';
    lines.forEach(function (line) {
      var item = document.createElement('li');
      var thumb = line.image && (line.image.thumb || line.image.url);
      if (thumb) {
        var img = document.createElement('img');
        img.src = String(thumb);
        img.alt = '';
        img.width = 56;
        img.height = 56;
        item.appendChild(img);
      } else {
        item.appendChild(document.createElement('span'));
      }
      var info = document.createElement('div');
      var name = document.createElement(line.url ? 'a' : 'span');
      name.className = 'af-mini-cart-name';
      name.textContent = String(line.name || '');
      if (line.url) {
        name.href = String(line.url);
      }
      info.appendChild(name);
      var meta = document.createElement('p');
      meta.className = 'af-small af-muted';
      meta.style.margin = '0';
      meta.textContent = (line.variant_label ? line.variant_label + ' · ' : '') + '× ' + (line.qty || 1);
      info.appendChild(meta);
      item.appendChild(info);
      var total = document.createElement('strong');
      total.textContent = typeof line.total === 'number' ? Aifec.money(line.total) : '';
      item.appendChild(total);
      list.appendChild(item);
    });
    qsa('[data-af-cart-empty]').forEach(function (node) {
      node.hidden = lines.length > 0;
    });
    qsa('[data-af-cart-actions]').forEach(function (node) {
      node.hidden = lines.length === 0;
    });
    var totals = data && data.totals;
    var amount = totals && typeof totals.total === 'number' ? totals.total : null;
    qsa('[data-af-cart-total]').forEach(function (node) {
      node.textContent = amount !== null ? Aifec.money(amount) : '';
    });
    qsa('[data-af-cart-total-row]').forEach(function (node) {
      node.hidden = amount === null || lines.length === 0;
    });
  }

  function loadCart(render) {
    var url = attr(root, 'data-cart-url');
    if (!url || !cartCookie()) {
      if (render) {
        renderCart(null);
      }
      return;
    }
    Aifec.get(url).then(function (data) {
      applyCount(countOf(data));
      if (render) {
        renderCart(data);
      }
    }, function () {
      return null;
    });
  }

  // ------------------------------------------------------------------ paneles laterales

  function focusables(panel) {
    return qsa(FOCUSABLE, panel).filter(visible);
  }

  function closeDrawer() {
    var panel = state.open;
    if (!panel) {
      return;
    }
    state.open = null;
    panel.classList.remove('is-open');
    panel.removeAttribute('role');
    panel.removeAttribute('aria-modal');
    qsa('.af-drawer-backdrop').forEach(function (node) {
      node.classList.remove('is-open');
    });
    root.classList.remove('af-lock');
    qsa('[data-af-drawer-open]').forEach(function (node) {
      node.setAttribute('aria-expanded', 'false');
    });
    var back = state.opener;
    state.opener = null;
    if (back && document.body.contains(back) && visible(back)) {
      back.focus();
    }
  }

  function openDrawer(id, opener) {
    var panel = document.getElementById(id);
    if (!panel) {
      return false;
    }
    if (state.open) {
      var previous = state.opener;
      closeDrawer();
      state.opener = previous;
    }
    state.open = panel;
    state.opener = opener || document.activeElement;
    panel.classList.add('is-open');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    qsa('.af-drawer-backdrop').forEach(function (node) {
      node.classList.add('is-open');
    });
    root.classList.add('af-lock');
    qsa('[data-af-drawer-open="' + id + '"]').forEach(function (node) {
      node.setAttribute('aria-expanded', 'true');
    });
    if (id === 'af-cart') {
      loadCart(true);
    }
    var target = qs('input[type="search"],input:not([type="hidden"]),select,textarea', panel) || focusables(panel)[0] || panel;
    window.setTimeout(function () {
      if (state.open === panel && target && target.focus) {
        target.focus();
      }
    }, 30);
    return true;
  }

  Aifec.on('click', '[data-af-drawer-open]', function (event, node) {
    if (openDrawer(attr(node, 'data-af-drawer-open'), node)) {
      event.preventDefault();
    }
  });

  Aifec.on('click', '[data-af-drawer-close]', function () {
    closeDrawer();
  });

  document.addEventListener('keydown', function (event) {
    var panel = state.open;
    if (!panel) {
      return;
    }
    if (event.key === 'Escape' || event.key === 'Esc') {
      closeDrawer();
      return;
    }
    if (event.key !== 'Tab') {
      return;
    }
    var items = focusables(panel);
    if (items.length === 0) {
      event.preventDefault();
      return;
    }
    var first = items[0];
    var last = items[items.length - 1];
    if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  // ------------------------------------------------------------------ cantidad, envío automático, mostrar/ocultar

  Aifec.on('click', '[data-af-qty-step]', function (event, button) {
    var box = closest(button, '[data-af-qty]');
    var input = box ? qs('input', box) : null;
    if (!input) {
      return;
    }
    event.preventDefault();
    var step = parseInt(attr(button, 'data-af-qty-step'), 10) || 0;
    var min = input.min !== '' ? parseInt(input.min, 10) : 1;
    var max = input.max !== '' ? parseInt(input.max, 10) : Infinity;
    var next = Math.min(max, Math.max(min, (parseInt(input.value, 10) || min) + step));
    if (String(next) === input.value) {
      return;
    }
    input.value = String(next);
    input.dispatchEvent(new Event('input', {bubbles: true}));
    input.dispatchEvent(new Event('change', {bubbles: true}));
  });

  Aifec.on('change', '[data-af-autosubmit]', function (event, node) {
    var form = node.tagName === 'FORM' ? node : node.form || closest(node, 'form');
    if (!form || event.defaultPrevented) {
      return;
    }
    if (form.requestSubmit) {
      form.requestSubmit();
    } else {
      HTMLFormElement.prototype.submit.call(form);
    }
  });

  Aifec.on('click', '[data-af-toggle]', function (event, button) {
    var target = document.getElementById(attr(button, 'data-af-toggle'));
    if (!target) {
      return;
    }
    event.preventDefault();
    target.hidden = !target.hidden;
    button.setAttribute('aria-expanded', target.hidden ? 'false' : 'true');
  });

  // ------------------------------------------------------------------ añadir al carrito

  /**
   * El botón dice «✓ Añadido» un par de segundos y vuelve a lo suyo. Lo que tenía dentro (texto, icono) se aparta y se
   * repone tal cual; un botón de solo icono enseña solo la marca. El ancho se conserva para que la página no salte.
   */
  function flashAdded(button, label) {
    if (!label || button.getAttribute('data-af-added')) {
      return;
    }
    var nodes = Array.prototype.slice.call(button.childNodes);
    var hasText = (button.textContent || '').replace(/\s+/g, '') !== '';
    var width = button.offsetWidth;
    button.setAttribute('data-af-added', '1');
    button.classList.add('is-added');
    if (width) {
      button.style.minWidth = width + 'px';
    }
    nodes.forEach(function (node) {
      button.removeChild(node);
    });
    button.appendChild(document.createTextNode(hasText ? '\u2713 ' + label : '\u2713'));
    window.setTimeout(function () {
      while (button.firstChild) {
        button.removeChild(button.firstChild);
      }
      nodes.forEach(function (node) {
        button.appendChild(node);
      });
      button.classList.remove('is-added');
      button.removeAttribute('data-af-added');
      button.style.minWidth = '';
    }, 2200);
  }

  document.addEventListener('submit', function (event) {
    var form = closest(event.target, 'form[data-af-cart-add]');
    if (!form || event.defaultPrevented || !window.fetch || typeof FormData === 'undefined') {
      return;
    }
    event.preventDefault();
    var buttons = qsa('[type="submit"]', form);
    buttons.forEach(function (button) {
      button.setAttribute('aria-busy', 'true');
    });
    Aifec.post(form.getAttribute('action') || window.location.href, new FormData(form)).then(function (data) {
      applyCount(countOf(data));
      // Dos señales de que se ha añadido: el botón lo dice un momento (es donde está mirando quien lo ha pulsado) y un
      // aviso con el nombre del producto y el enlace al carrito.
      buttons.forEach(function (button) {
        flashAdded(button, data && data.added ? String(data.added) : '');
      });
      Aifec.toast(message(data), 'ok', data && data.cart ? data.cart : null);
      document.dispatchEvent(new CustomEvent('aifec:cart', {detail: data}));
      if (state.open && state.open.id === 'af-cart') {
        loadCart(true);
      }
    }, function (error) {
      var text = error && error.data ? message(error.data) : '';
      if (text) {
        Aifec.toast(text, 'bad');
      } else {
        // Sin respuesta que enseñar (red caída, error del servidor): se envía como un formulario de toda la vida.
        HTMLFormElement.prototype.submit.call(form);
      }
    }).then(function () {
      buttons.forEach(function (button) {
        button.removeAttribute('aria-busy');
      });
    });
  });

  // ------------------------------------------------------------------ galería

  Aifec.on('click', '[data-af-gallery] [data-src]', function (event, thumb) {
    var gallery = closest(thumb, '[data-af-gallery]');
    var main = gallery ? qs('[data-af-gallery-main]', gallery) : null;
    if (!main) {
      return;
    }
    event.preventDefault();
    main.src = attr(thumb, 'data-src');
    main.removeAttribute('srcset');
    main.alt = attr(thumb, 'data-alt') || '';
    qsa('[data-src]', gallery).forEach(function (other) {
      if (other === thumb) {
        other.setAttribute('aria-current', 'true');
      } else {
        other.removeAttribute('aria-current');
      }
    });
  });

  // ------------------------------------------------------------------ pestañas

  function selectTab(tab) {
    var list = closest(tab, '[role="tablist"]');
    if (!list) {
      return;
    }
    qsa('[role="tab"]', list).forEach(function (other) {
      var on = other === tab;
      other.setAttribute('aria-selected', on ? 'true' : 'false');
      other.tabIndex = on ? 0 : -1;
      var panel = document.getElementById(attr(other, 'aria-controls'));
      if (panel) {
        panel.hidden = !on;
      }
    });
  }

  Aifec.on('click', '[data-af-tabs] [role="tab"]', function (event, tab) {
    selectTab(tab);
  });

  Aifec.on('keydown', '[data-af-tabs] [role="tab"]', function (event, tab) {
    var tabs = qsa('[role="tab"]', closest(tab, '[role="tablist"]'));
    var index = tabs.indexOf(tab);
    var next = -1;
    if (event.key === 'ArrowRight') {
      next = (index + 1) % tabs.length;
    } else if (event.key === 'ArrowLeft') {
      next = (index - 1 + tabs.length) % tabs.length;
    } else if (event.key === 'Home') {
      next = 0;
    } else if (event.key === 'End') {
      next = tabs.length - 1;
    }
    if (next >= 0) {
      event.preventDefault();
      tabs[next].focus();
      selectTab(tabs[next]);
    }
  });

  function initTabs() {
    qsa('[data-af-tabs] [role="tablist"]').forEach(function (list) {
      var tabs = qsa('[role="tab"]', list);
      var current = tabs.filter(function (tab) {
        return attr(tab, 'aria-selected') === 'true';
      })[0] || tabs[0];
      if (current) {
        selectTab(current);
      }
    });
  }

  // ------------------------------------------------------------------ variantes

  function variantsOf(box) {
    var script = qs('script[type="application/json"]', box);
    if (!script) {
      return [];
    }
    try {
      var data = JSON.parse(script.textContent);
      return Array.isArray(data) ? data : (data && Array.isArray(data.variants) ? data.variants : []);
    } catch (e) {
      return [];
    }
  }

  function chosen(box) {
    var picked = {};
    var complete = true;
    qsa('[data-af-option]', box).forEach(function (group) {
      var code = attr(group, 'data-af-option');
      var value = null;
      if (group.tagName === 'SELECT') {
        value = group.value;
      } else {
        // Radios nativos (lo normal) o, en un tema que use botones, el que lleva aria-pressed.
        var radio = qs('input[type="radio"]:checked', group);
        var pressed = radio ? null : qs('button[aria-pressed="true"]', group);
        value = radio ? radio.value : (pressed ? attr(pressed, 'data-value') : null);
      }
      if (value === null || value === '') {
        complete = false;
      } else {
        picked[code] = value;
      }
    });
    return complete ? picked : null;
  }

  function setText(box, selector, text) {
    qsa(selector, box).forEach(function (node) {
      node.textContent = text;
    });
  }

  function showVariant(box, variant) {
    qsa('[data-af-variant-id]', box).forEach(function (input) {
      input.value = String(variant.id);
    });
    setText(box, '[data-af-sku]', variant.sku || '');
    var price = variant.price || null;
    if (price) {
      setText(box, '[data-af-price-now]', Aifec.money(price.amount));
      var sale = typeof price.compare === 'number' && price.compare > price.amount;
      qsa('[data-af-price-old]', box).forEach(function (node) {
        node.hidden = !sale;
        if (sale) {
          var hidden = qs('.af-sr', node);
          node.textContent = Aifec.money(price.compare);
          if (hidden) {
            node.insertBefore(hidden, node.firstChild);
          }
        }
      });
      qsa('[data-af-price-off]', box).forEach(function (node) {
        node.hidden = !sale;
        node.textContent = sale && price.discount_percent ? '−' + price.discount_percent + ' %' : '';
      });
      qsa('.af-price', box).forEach(function (node) {
        node.classList.toggle('is-sale', sale);
      });
    }
    var stock = variant.availability || null;
    qsa('[data-af-availability]', box).forEach(function (node) {
      if (stock) {
        node.textContent = String(stock.label || '');
        node.classList.remove('is-ok', 'is-low', 'is-out');
        node.classList.add(stock.status === 'out_of_stock' ? 'is-out' : (stock.status === 'low_stock' ? 'is-low' : 'is-ok'));
      }
    });
    qsa('[data-af-add]', box).forEach(function (button) {
      button.disabled = !!stock && stock.status === 'out_of_stock';
    });
    if (variant.image_id) {
      var thumb = qs('[data-af-gallery] [data-image-id="' + variant.image_id + '"]', box);
      if (thumb && attr(thumb, 'aria-current') !== 'true') {
        thumb.click();
      }
    }
  }

  function updateVariant(box) {
    var picked = chosen(box);
    if (!picked) {
      return;
    }
    var found = variantsOf(box).filter(function (variant) {
      var values = variant.values || {};
      return Object.keys(picked).every(function (code) {
        return String(values[code]) === picked[code];
      });
    })[0];
    if (found) {
      showVariant(box, found);
    }
  }

  // Opciones con radios: el estado es el del propio radio; la clase is-on solo acompaña al estilo.
  Aifec.on('change', '[data-af-variants] [data-af-option] input[type="radio"]', function (event, radio) {
    var group = closest(radio, '[data-af-option]');
    qsa('.af-variant', group).forEach(function (label) {
      var own = qs('input[type="radio"]', label);
      label.classList.toggle('is-on', !!own && own.checked);
    });
    updateVariant(closest(radio, '[data-af-variants]'));
  });

  // Opciones con botones (un tema puede pintarlas así): aria-pressed sí es de un botón.
  Aifec.on('click', '[data-af-variants] [data-af-option] button[data-value]', function (event, button) {
    var group = closest(button, '[data-af-option]');
    qsa('button[data-value]', group).forEach(function (other) {
      other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
    });
    updateVariant(closest(button, '[data-af-variants]'));
  });

  Aifec.on('change', '[data-af-variants] select[data-af-option]', function (event, select) {
    updateVariant(closest(select, '[data-af-variants]'));
  });

  // ------------------------------------------------------------------ arranque

  function start() {
    initTabs();
    loadCart(false);
    loadAccount();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  // Al volver con «atrás» la página puede salir de la caché del navegador con el contador viejo.
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
      loadCart(false);
    }
  });

  window.Aifec = Aifec;
})(window, document);

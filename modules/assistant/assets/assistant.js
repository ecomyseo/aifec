/*
 * AIFEC — asistente de la tienda. Sin dependencias y sin transpilar. Todo por delegación sobre `document`.
 * Sin cadenas visibles: los textos llegan en los atributos data-t-* del contenedor, ya traducidos.
 * No pide nada al servidor hasta que se escribe un mensaje o se pulsa «añadir». Todo lo que viene del servidor se pinta
 * con textContent (nunca como HTML) y las direcciones se comprueban antes de usarse.
 * Autor: Ecom Experts · 2026 · Ver LICENSE.md
 */
/* El formato se representa mediante nodos de texto. */
(function (root) {
  'use strict';

  function inline(text) {
    var parts = [];
    var re = /\*\*([^*\n]+)\*\*|\[([^\]\n]+)\]\((https?:\/\/[^\s()<>"']+|\/(?!\/)[^\s()<>"'\\]*)\)/gi;
    var last = 0;
    var match;
    while ((match = re.exec(text)) !== null) {
      if (match.index > last) {
        parts.push({bold: false, text: text.slice(last, match.index)});
      }
      parts.push(match[1] ? {bold: true, text: match[1]} : {bold: false, text: match[2], href: match[3]});
      last = match.index + match[0].length;
    }
    if (last < text.length) {
      parts.push({bold: false, text: text.slice(last)});
    }
    return parts;
  }

  function parse(source) {
    var lines = String(source === undefined || source === null ? '' : source).replace(/\r\n?/g, '\n').split('\n');
    var blocks = [];
    var para = null;
    var list = null;
    lines.forEach(function (line) {
      var item = /^\s*[-*]\s+(\S.*)$/.exec(line);
      if (item) {
        para = null;
        if (!list) {
          list = {type: 'list', items: []};
          blocks.push(list);
        }
        list.items.push(inline(item[1]));
        return;
      }
      list = null;
      if (/^\s*$/.test(line)) {
        para = null;
        return;
      }
      if (!para) {
        para = {type: 'p', lines: []};
        blocks.push(para);
      }
      para.lines.push(inline(line));
    });
    return blocks;
  }

  var api = {parse: parse};
  root.AifecAssistantFormat = api;
  if (typeof module === 'object' && module && module.exports) {
    module.exports = api;
  }
}(typeof window !== 'undefined' ? window : this));

(function (window, document) {
  'use strict';

  if (!window || !document || !document.documentElement || !window.fetch) {
    return;
  }

  var STORE_KEY = 'af-assistant-public-log';
  var MAX_LOG = 40;
  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  var busy = false;
  var restored = false;
  var askedForRating = false;
  var log = [];
  // Los textos ya traducidos que manda el servidor cuando la conversación va en otro idioma que la página.
  var labels = {};

  function root() {
    return document.querySelector('[data-af-assistant]');
  }

  function part(selector) {
    var box = root();
    return box ? box.querySelector(selector) : null;
  }

  function text(name) {
    if (labels[name]) {
      return labels[name];
    }
    var box = root();
    return (box && box.getAttribute('data-t-' + name)) || '';
  }

  function closest(node, selector) {
    while (node && node.nodeType !== 1) {
      node = node.parentNode;
    }
    return node && node.closest ? node.closest(selector) : null;
  }

  function el(tag, className, content) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (content !== undefined && content !== null && content !== '') {
      node.textContent = String(content);
    }
    return node;
  }

  /** Solo se enlaza a direcciones de la propia web o http(s): nunca «javascript:» ni nada parecido. */
  function safeUrl(value) {
    var url = String(value || '');
    if (/[\\\x00-\x20\x7f]/.test(url)) { return ''; }
    return /^(https?:\/\/|\/(?!\/))/i.test(url) ? url : '';
  }

  // ------------------------------------------------------------------ memoria de la pestaña

  function remember() {
    try {
      window.sessionStorage.setItem(STORE_KEY, JSON.stringify(log.slice(-MAX_LOG)));
    } catch (e) {
      // Sin almacenamiento la conversación sigue, pero no sobrevive a un cambio de página.
    }
  }

  function recall() {
    try {
      var saved = JSON.parse(window.sessionStorage.getItem(STORE_KEY) || '[]');
      return Object.prototype.toString.call(saved) === '[object Array]' ? saved : [];
    } catch (e) {
      return [];
    }
  }

  // ------------------------------------------------------------------ pintar

  function scrollDown() {
    var area = part('[data-af-assistant-log]');
    if (area) {
      area.scrollTop = area.scrollHeight;
    }
  }

  /** Pinta un texto con formato como nodos del DOM: todo con textContent, nunca como HTML. */
  function rich(box, content) {
    var blocks = window.AifecAssistantFormat.parse(content);
    if (!blocks.length) {
      box.appendChild(el('p', '', content));
      return;
    }
    var fill = function (node, parts) {
      parts.forEach(function (part) {
        if (part.href && safeUrl(part.href)) {
          var link = el('a', '', part.text);
          link.href = part.href;
          link.rel = 'noopener noreferrer';
          node.appendChild(link);
        } else {
          node.appendChild(part.bold ? el('strong', '', part.text) : document.createTextNode(part.text));
        }
      });
    };
    blocks.forEach(function (block) {
      if (block.type === 'list') {
        var list = el('ul');
        block.items.forEach(function (parts) {
          var item = el('li');
          fill(item, parts);
          list.appendChild(item);
        });
        box.appendChild(list);
        return;
      }
      var para = el('p');
      block.lines.forEach(function (parts, index) {
        if (index > 0) {
          para.appendChild(el('br'));
        }
        fill(para, parts);
      });
      box.appendChild(para);
    });
  }

  function bubble(kind, content) {
    var area = part('[data-af-assistant-log]');
    if (!area) {
      return null;
    }
    var box = el('div', 'af-assistant-msg ' + kind);
    if (kind === 'is-bot') {
      rich(box, content);
    } else {
      box.appendChild(el('p', '', content));
    }
    area.appendChild(box);
    scrollDown();
    return box;
  }

  function card(data) {
    var box = el('article', 'af-assistant-card');
    box.setAttribute('data-chat-product', data.id);
    var link = safeUrl(data.url);
    var photos = el(link ? 'a' : 'div', 'af-assistant-card-photos');
    if (link) {
      photos.setAttribute('href', link);
      photos.setAttribute('tabindex', '-1');
      photos.setAttribute('aria-hidden', 'true');
    }
    var shots = [safeUrl(data.image), safeUrl(data.image2)];
    for (var s = 0; s < shots.length; s++) {
      if (!shots[s]) { continue; }
      var img = el('img');
      img.setAttribute('src', shots[s]);
      img.setAttribute('alt', '');
      img.setAttribute('width', '160');
      img.setAttribute('height', '160');
      img.setAttribute('loading', 'lazy');
      photos.appendChild(img);
    }
    if (!photos.firstChild) {
      photos.appendChild(el('div', 'af-assistant-card-noimg'));
    }
    box.appendChild(photos);
    var body = el('div', 'af-assistant-card-body');
    if (link) {
      var title = el('a', 'af-assistant-card-name', data.name);
      title.setAttribute('href', link);
      body.appendChild(title);
    } else {
      body.appendChild(el('div', 'af-assistant-card-name', data.name));
    }
    if (data.featured) {
      body.appendChild(el('span', 'af-assistant-card-featured', text('featured')));
    }
    var price = el('div', 'af-assistant-card-price');
    price.appendChild(el('span', 'af-assistant-card-now', (data.from ? text('from') + ' ' : '') + data.price));
    if (data.compare_price) {
      price.appendChild(el('span', 'af-assistant-card-old', data.compare_price));
    }
    if (data.discount_percent > 0) {
      price.appendChild(el('span', 'af-assistant-card-discount', text('discount').replace('{percent}', String(data.discount_percent))));
    }
    body.appendChild(price);
    if (data.rating && data.rating.count > 0) {
      body.appendChild(el('span', 'af-assistant-card-rating', text('rating').replace('{score}', data.rating.average).replace('{count}', data.rating.count)));
    }
    var options = data.options && data.options.length ? data.options : [];
    for (var o = 0; o < options.length; o++) {
      if (!options[o] || !options[o].values || !options[o].values.length) { continue; }
      var line = el('div', 'af-assistant-card-opts');
      line.appendChild(el('span', 'af-assistant-card-opt', String(options[o].name || '') + ':'));
      var swatches = options[o].swatches || {};
      options[o].values.forEach(function (value, index) {
        line.appendChild(document.createTextNode(index ? ', ' : ' '));
        if (/^#[0-9a-f]{6}$/i.test(swatches[value] || '')) {
          var dot = el('span', 'af-assistant-swatch');
          dot.setAttribute('aria-hidden', 'true');
          dot.style.backgroundColor = swatches[value];
          line.appendChild(dot);
        }
        line.appendChild(document.createTextNode(value));
      });
      body.appendChild(line);
    }
    if (Array.isArray(data.matched_attributes) && data.matched_attributes.length) {
      body.appendChild(el('div', 'af-assistant-card-fit', data.matched_attributes.join(' \u00b7 ')));
    }
    if (data.delivery && data.status !== 'out_of_stock') {
      body.appendChild(el('div', 'af-assistant-card-delivery', data.delivery));
    }
    if (data.availability) {
      body.appendChild(el('div', 'af-assistant-card-av', data.availability));
    }
    var actions = el('div', 'af-assistant-card-actions');
    if (data.can_add) {
      var add = el('button', 'af-btn af-btn-primary', text('add'));
      add.setAttribute('type', 'button');
      add.setAttribute('data-af-assistant-add', String(data.id));
      actions.appendChild(add);
    } else if (link) {
      var pick = el('a', 'af-btn af-btn-primary', data.has_variants ? text('options') : text('view'));
      pick.setAttribute('href', link);
      actions.appendChild(pick);
    }
    if (data.stock_signup && safeUrl(data.stock_signup)) {
      var notify = el('a', 'af-btn', data.stock_signup_label);
      notify.href = safeUrl(data.stock_signup);
      actions.appendChild(notify);
    }
    body.appendChild(actions);
    box.appendChild(body);
    return box;
  }

  /** Una fila «etiqueta ··· importe» de la tarjeta del pedido. */
  function orderRow(label, value, className) {
    var row = el('div', 'af-assistant-order-row' + (className ? ' ' + className : ''));
    row.appendChild(el('span', '', label));
    row.appendChild(el('span', 'af-assistant-order-amount', value));
    return row;
  }

  /** El resumen del pedido con su botón «Confirmar pedido»: todo lo que hay en él lo ha calculado el servidor. */
  function orderCard(data) {
    var box = el('article', 'af-assistant-order');
    box.appendChild(el('div', 'af-assistant-order-title', text('summary')));
    (data.lines || []).forEach(function (line) {
      box.appendChild(orderRow(String(line.qty) + ' \u00d7 ' + line.name, line.total));
    });
    (data.discounts || []).forEach(function (item) {
      box.appendChild(orderRow(item.label, item.amount));
    });
    if (data.shipping) {
      box.appendChild(orderRow(text('shipping') + ': ' + data.shipping.name, data.shipping.price));
    }
    (data.fees || []).forEach(function (item) {
      box.appendChild(orderRow(item.label, item.amount));
    });
    box.appendChild(orderRow(text('total'), data.total, 'is-total'));
    var where = [];
    if (data.name) {
      where.push(data.name);
    }
    (data.address || []).forEach(function (line) {
      where.push(line);
    });
    if (where.length) {
      var address = el('div', 'af-assistant-order-where');
      address.appendChild(el('strong', '', text('deliver') + ': '));
      address.appendChild(document.createTextNode(where.join(', ')));
      box.appendChild(address);
    }
    if (data.company || data.vat_id) {
      var who = [data.company || data.name || ''];
      if (data.vat_id) {
        who.push(text('taxid') + ' ' + data.vat_id);
      }
      var bill = el('div', 'af-assistant-order-where');
      bill.appendChild(el('strong', '', text('invoice') + ': '));
      bill.appendChild(document.createTextNode(who.filter(function (part) { return part; }).join(' \u00b7 ')));
      box.appendChild(bill);
    }
    if (data.payment) {
      var pay = el('div', 'af-assistant-order-where');
      pay.appendChild(el('strong', '', text('payment') + ': '));
      pay.appendChild(document.createTextNode(data.payment));
      box.appendChild(pay);
    }
    var terms = el('p', 'af-assistant-order-terms', text('accept'));
    var termsUrl = safeUrl(data.terms_url);
    if (termsUrl) {
      terms.appendChild(document.createTextNode(' '));
      var link = el('a', '', text('terms'));
      link.setAttribute('href', termsUrl);
      link.setAttribute('target', '_blank');
      link.setAttribute('rel', 'noopener');
      terms.appendChild(link);
    }
    box.appendChild(terms);
    var confirm = el('button', 'af-btn af-btn-primary af-assistant-confirm', text('confirm'));
    confirm.setAttribute('type', 'button');
    confirm.setAttribute('data-af-assistant-confirm', '1');
    box.appendChild(confirm);
    return box;
  }

  /** Los botones «Confirmar pedido» ya pintados dejan de valer en cuanto llega otra respuesta. */
  function disableConfirms() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-af-assistant-confirm]'), function (node) {
      node.setAttribute('disabled', 'disabled');
    });
  }

  function chips(list) {
    var box = part('[data-af-assistant-chips]');
    if (!box) {
      return;
    }
    box.textContent = '';
    (list || []).slice(0, 8).forEach(function (label) {
      var chip = el('button', 'af-assistant-chip', label);
      chip.setAttribute('type', 'button');
      chip.setAttribute('data-af-assistant-chip', String(label));
      box.appendChild(chip);
    });
  }

  function applyCount(n) {
    if (typeof n !== 'number') {
      return;
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-af-cart-count]'), function (node) {
      node.textContent = n > 99 ? '99+' : String(n);
      node.hidden = n <= 0;
    });
  }

  /** Pinta la respuesta del servidor: texto, fichas, acciones y respuestas rápidas. */
  function render(data) {
    disableConfirms();
    // Las etiquetas traducidas van antes que nada: las tarjetas y los botones que se pintan a continuación las usan.
    (data.actions || []).forEach(function (action) {
      if (action.type === 'labels') {
        labels = action.labels && typeof action.labels === 'object' && !Array.isArray(action.labels) ? action.labels : {};
      }
    });
    if (data.text) {
      bubble('is-bot', data.text);
    }
    var area = part('[data-af-assistant-log]');
    if (area && data.notice) { area.appendChild(el('small', 'af-assistant-mode', data.notice)); }
    if (area && data.cards && data.cards.length) {
      var list = el('div', 'af-assistant-cards');
      data.cards.forEach(function (item) {
        list.appendChild(card(item));
      });
      area.appendChild(list);
    }
    (data.actions || []).forEach(function (action) {
      if (action.type === 'subscriptions' && safeUrl(action.url) && area) {
        var plans = el('a', 'af-btn af-btn-primary', text('subscriptions'));
        plans.setAttribute('href', safeUrl(action.url));
        area.appendChild(plans);
      } else if (action.type === 'checkout' && safeUrl(action.url) && area) {
        var go = el('a', 'af-btn af-btn-primary', text('checkout'));
        go.setAttribute('href', safeUrl(action.url));
        area.appendChild(go);
      } else if (action.type === 'confirm_order' && area) {
        area.appendChild(orderCard(action));
      } else if (action.type === 'pay' && safeUrl(action.url) && area) {
        if (action.method === 'post' && action.fields) {
          // Una pasarela que pide un formulario: se envía desde un formulario de verdad, con sus campos escondidos.
          var form = el('form', 'af-assistant-payform');
          form.setAttribute('method', 'post');
          form.setAttribute('action', safeUrl(action.url));
          Object.keys(action.fields).forEach(function (name) {
            var hidden = el('input');
            hidden.setAttribute('type', 'hidden');
            hidden.setAttribute('name', name);
            hidden.setAttribute('value', String(action.fields[name]));
            form.appendChild(hidden);
          });
          var submit = el('button', 'af-btn af-btn-primary', text('pay'));
          submit.setAttribute('type', 'submit');
          form.appendChild(submit);
          area.appendChild(form);
        } else {
          var payLink = el('a', 'af-btn af-btn-primary', text('pay'));
          payLink.setAttribute('href', safeUrl(action.url));
          area.appendChild(payLink);
        }
      } else if (action.type === 'order' && safeUrl(action.url) && area) {
        var orderLink = el('a', 'af-btn', text('vieworder'));
        orderLink.setAttribute('href', safeUrl(action.url));
        area.appendChild(orderLink);
      } else if (action.type === 'cart_changed') {
        applyCount(typeof action.count === 'number' ? action.count : null);
        document.dispatchEvent(new CustomEvent('aifec:cart', {detail: {count: action.count}}));
      }
    });
    chips(data.suggestions);
    scrollDown();
  }

  // ------------------------------------------------------------------ hablar con el servidor

  /** ¿Puede este navegador leer una respuesta por partes? */
  function canStream() {
    return typeof window.TextDecoder === 'function' && typeof window.ReadableStream === 'function';
  }

  /** Lo que se está haciendo (la burbuja de «escribiendo…» cambia de texto con cada línea de estado). */
  function setBusy(node, status) {
    var line = node && node.firstChild;
    if (line && status) {
      line.textContent = String(status);
    }
  }

  /**
   * Lee la respuesta por partes: cada línea es un JSON, {status} mientras trabaja y {done} al final con la respuesta de siempre.
   */
  function readStream(response, wait) {
    var reader = response.body.getReader();
    var decoder = new window.TextDecoder('utf-8');
    var buffer = '';
    var finalData = null;
    var hasFact = false;
    var take = function (line) {
      var item = null;
      try {
        item = JSON.parse(line);
      } catch (e) {
        return;
      }
      if (item && item.fact) {
        hasFact = true;
        setBusy(wait, item.fact);
      } else if (item && item.status && !hasFact) {
        setBusy(wait, item.status);
      } else if (item && item.done) {
        finalData = item.done;
      }
    };
    var pump = function () {
      return reader.read().then(function (chunk) {
        if (chunk.done) {
          buffer += decoder.decode();
          if (buffer.replace(/\s+$/, '')) {
            take(buffer);
          }
          return {ok: !!finalData, data: finalData || {}};
        }
        buffer += decoder.decode(chunk.value, {stream: true});
        var lines = buffer.split('\n');
        buffer = lines.pop();
        lines.forEach(take);
        return pump();
      });
    };
    return pump();
  }

  function cooldown(seconds, draft) {
    var input = part('#af-assistant-input');
    var submit = part('[data-af-assistant-form] button');
    if (!input || !submit) { return; }
    if (draft && !input.value) { input.value = draft; }
    var notice = bubble('is-bot', '');
    var until = Date.now() + seconds * 1000;
    var tick = function () {
      var left = Math.max(0, Math.ceil((until - Date.now()) / 1000));
      input.disabled = submit.disabled = left > 0;
      if (left === 0) {
        notice.remove();
        return;
      }
      notice.textContent = text('retry').replace('{seconds}', String(left));
      window.setTimeout(tick, 1000);
    };
    tick();
  }

  function send(payload, shown, urlAttribute) {
    var box = root();
    var url = box ? box.getAttribute(urlAttribute || 'data-af-assistant-url') : '';
    if (!url || busy) {
      return;
    }
    busy = true;
    if (shown) {
      bubble('is-user', shown);
    }
    chips([]);
    var wait = bubble('is-busy', text('busy') + '\u2026');
    var done = function () {
      busy = false;
      if (wait && wait.parentNode) {
        wait.parentNode.removeChild(wait);
      }
    };
    var streaming = !!payload.message && canStream();
    if (streaming) {
      payload.stream = 1;
      payload.progress = 1;
    }
    window.fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {'Content-Type': 'application/json', 'Accept': streaming ? 'application/x-ndjson, application/json' : 'application/json', 'X-Requested-With': 'fetch'},
      body: JSON.stringify(payload)
    }).then(function (response) {
      var type = response.headers && response.headers.get ? String(response.headers.get('content-type') || '') : '';
      if (streaming && response.ok && type.indexOf('ndjson') !== -1 && response.body && response.body.getReader) {
        return readStream(response, wait);
      }
      return response.json().catch(function () {
        return {};
      }).then(function (data) {
        return {ok: response.ok, data: data || {}};
      });
    }).then(function (result) {
      done();
      if (!result.ok || result.data.error) {
        if (result.data.retry_after > 0) {
          cooldown(result.data.retry_after, payload.message);
          return;
        }
        if (payload.vote) { part('[data-af-survey-status]').textContent = text('error'); return; }
        bubble('is-bot', result.data.error || text('error'));
        return;
      }
      if (result.data.preferences) {
        preferences(result.data.preferences);
        delete result.data.preferences;
        if (payload.save_budget || payload.remove) { return; }
      }
      if (result.data.rated) {
        var survey = part('[data-af-assistant-survey]');
        survey.hidden = true;
        part('[data-af-assistant-open]').focus();
        return;
      }
      if (result.data.restarted) {
        askedForRating = false;
        log = [];
        labels = {};
        chips([]);
        starterChips();
        remember();
        var area = part('[data-af-assistant-log]');
        while (area && area.children.length > 1) { area.removeChild(area.lastChild); }
        var input = part('#af-assistant-input');
        if (input) { input.value = ''; input.focus(); }
        return;
      }
      if (payload.message) { analytics({event: 'chat_message'}); }
      (result.data.actions || []).forEach(function (action) {
        if (action.type === 'analytics') { analytics(action.event); }
      });
      render(result.data);
      if (part('.af-assistant-panel').hidden && result.data.text) {
        unread(1 + (parseInt(part('[data-af-unread]').textContent, 10) || 0));
      }
      var privateTurn = result.data.private_history || (result.data.actions || []).some(function (action) {
        return ['private_turn', 'confirm_order', 'order', 'pay'].indexOf(action.type) >= 0;
      });
      if (!privateTurn) {
        if (shown) {
          log.push({u: shown});
        }
        log.push({b: result.data});
      }
      remember();
    }, function () {
      done();
      if (payload.vote) { part('[data-af-survey-status]').textContent = text('error'); return; }
      bubble('is-bot', text('error'));
    });
  }

  function submitMessage(value) {
    var message = String(value || '').replace(/^\s+|\s+$/g, '');
    if (message) {
      send({message: message, page_path: location.pathname, criteria: 1}, message);
    }
  }

  // ------------------------------------------------------------------ abrir y cerrar

  function preferences(data) {
    if (!data) { return; }
    var box = part('[data-af-criteria]');
    box.textContent = '';
    Object.keys(data.chips || {}).forEach(function (key) {
      var button = el('button', 'af-assistant-chip', data.chips[key] + ' \u00d7');
      button.type = 'button';
      button.setAttribute('data-af-remove-pref', key);
      button.setAttribute('aria-label', text('remove-pref') + ' ' + data.chips[key]);
      box.appendChild(button);
    });
    box.hidden = !box.childElementCount;
    var form = part('[data-af-budget]');
    form.hidden = !data.signed_in;
    form.elements.budget.value = data.budget ? String(data.budget / 100) : '';
  }

  function starterChips() {
    var url = root().getAttribute('data-af-starters');
    if (!url) { return; }
    url += (url.indexOf('?') < 0 ? '?' : '&') + 'page_path=' + encodeURIComponent(location.pathname);
    window.fetch(url, {credentials: 'same-origin', cache: 'no-store'}).then(function (res) {
      return res.ok ? res.json() : {};
    }).then(function (data) {
      if (!busy) {
        preferences(data.preferences);
        if (!log.length && data.suggestions) { chips(data.suggestions); }
      }
    }).catch(function () { /* Las preguntas libres siguen disponibles. */ });
  }

  function unread(n) {
    var badge = part('[data-af-unread]');
    if (!badge) { return; }
    var label = badge.getAttribute('data-label').replace('{count}', String(n));
    badge.textContent = n ? String(n) : '';
    badge.hidden = !n;
    var button = part('[data-af-assistant-open]');
    if (!button.dataset.originalLabel) { button.dataset.originalLabel = button.getAttribute('aria-label'); }
    button.setAttribute('aria-label', button.dataset.originalLabel + (n ? '. ' + label : ''));
    try { sessionStorage.setItem('af-chat-unread', String(n)); } catch (e) { /* opcional */ }
  }

  function analytics(event) {
    var consent = window.AifecConsent;
    if (!window.AifecDataEvent || !consent || typeof consent.letters !== 'function' || consent.letters().indexOf('s') < 0) { return; }
    event.source = 'assistant';
    window.AifecDataEvent(event);
  }

  function openPanel() {
    var box = root();
    var panel = part('.af-assistant-panel');
    var button = part('[data-af-assistant-open]');
    if (!box || !panel) {
      return;
    }
    part('[data-af-assistant-survey]').hidden = true;
    if (panel.hidden) { analytics({event: 'chat_open'}); }
    panel.hidden = false;
    unread(0);
    panel.setAttribute('aria-modal', box.getAttribute('data-layout') === 'side' && window.innerWidth >= 900 ? 'false' : 'true');
    var notice = part('[data-af-assistant-resume]');
    if (notice) { notice.hidden = true; }
    if (typeof panel.showPopover === 'function') {
      panel.setAttribute('popover', 'manual');
      panel.showPopover();
    }
    box.classList.add('is-open');
    document.documentElement.classList.add('af-assistant-lock');
    if (button) {
      button.setAttribute('aria-expanded', 'true');
    }
    if (!restored) {
      restored = true;
      var saved = recall();
      saved.forEach(function (entry) {
        if (entry && entry.u) {
          bubble('is-user', entry.u);
        } else if (entry && entry.b) {
          render(entry.b);
        }
      });
      log = saved;
      starterChips();
    }
    var input = part('#af-assistant-input');
    if (input) {
      input.focus();
    }
  }

  function closePanel() {
    var box = root();
    var panel = part('.af-assistant-panel');
    var button = part('[data-af-assistant-open]');
    if (!box || !panel || panel.hidden) {
      return;
    }
    if (panel.hasAttribute('popover') && typeof panel.hidePopover === 'function') {
      panel.hidePopover();
    }
    panel.hidden = true;
    box.classList.remove('is-open');
    document.documentElement.classList.remove('af-chat-docked');
    box.removeAttribute('data-layout');
    try { sessionStorage.removeItem('af-chat-docked'); } catch (e) { /* opcional */ }
    if (!askedForRating && log.length && !busy) {
      askedForRating = true;
      part('[data-af-assistant-survey]').hidden = false;
    }
    document.documentElement.classList.remove('af-assistant-lock');
    if (button) {
      button.setAttribute('aria-expanded', 'false');
      button.focus();
    }
  }

  document.addEventListener('click', function (event) {
    var link = closest(event.target, '[data-chat-product] a[href]');
    if (link) { analytics({event: 'chat_product_click', item_id: closest(link, '[data-chat-product]').getAttribute('data-chat-product')}); }
    var open = closest(event.target, '[data-af-assistant-open]');
    if (open) {
      event.preventDefault();
      openPanel();
      return;
    }
    if (closest(event.target, '[data-af-assistant-close]')) {
      event.preventDefault();
      closePanel();
      return;
    }
    var remove = closest(event.target, '[data-af-remove-pref]');
    if (remove) {
      send({remove: remove.getAttribute('data-af-remove-pref')}, '', 'data-af-preferences-url');
      return;
    }
    var layout = closest(event.target, '[data-af-layout]');
    if (layout) {
      var mode = layout.getAttribute('data-af-layout');
      root().setAttribute('data-layout', mode);
      part('.af-assistant-panel').setAttribute('aria-modal', mode === 'side' && window.innerWidth >= 900 ? 'false' : 'true');
      document.documentElement.classList.toggle('af-chat-docked', mode === 'side');
      try { sessionStorage.setItem('af-chat-docked', mode === 'side' ? '1' : '0'); } catch (e) { /* opcional */ }
      part('.af-assistant-menu').open = false;
      part('#af-assistant-input').focus();
      return;
    }
    var separate = closest(event.target, 'a[data-af-chat-window]');
    if (separate) {
      var popup = window.open('about:blank', '_blank', 'popup,width=580,height=780');
      if (popup) {
        event.preventDefault();
        try { popup.sessionStorage.setItem(STORE_KEY, JSON.stringify(log.slice(-MAX_LOG))); } catch (e) { /* opcional */ }
        part('.af-assistant-menu').open = false;
        popup.opener = null;
        popup.location.replace(separate.href);
      }
      return;
    }
    if (closest(event.target, '[data-af-assistant-rate]')) {
      closePanel();
      var survey = part('[data-af-assistant-survey]');
      survey.hidden = false;
      survey.querySelector('select').focus();
      return;
    }
    if (closest(event.target, '[data-af-survey-close]')) {
      part('[data-af-assistant-survey]').hidden = true;
      part('[data-af-assistant-open]').focus();
      return;
    }
    if (closest(event.target, '[data-af-assistant-restart]')) {
      var menu = part('.af-assistant-menu');
      if (menu) { menu.open = false; }
      send({}, '', 'data-af-assistant-restart-url');
      return;
    }
    var chip = closest(event.target, '[data-af-assistant-chip]');
    if (chip) {
      event.preventDefault();
      part('.af-assistant-menu').open = false;
      submitMessage(chip.getAttribute('data-af-assistant-chip'));
      return;
    }
    var confirm = closest(event.target, '[data-af-assistant-confirm]');
    if (confirm) {
      event.preventDefault();
      if (!confirm.hasAttribute('disabled')) {
        disableConfirms();
        send({}, text('confirm'), 'data-af-assistant-confirm-url');
      }
      return;
    }
    var add = closest(event.target, '[data-af-assistant-add]');
    if (add) {
      event.preventDefault();
      var id = parseInt(add.getAttribute('data-af-assistant-add'), 10);
      if (id > 0) {
        send({add: {product_id: id, variant_id: 0, qty: 1}}, '');
      }
    }
  });

  document.addEventListener('submit', function (event) {
    var budget = closest(event.target, '[data-af-budget]');
    if (budget) {
      event.preventDefault();
      send({save_budget: 1, max_price: Math.round(Number(budget.elements.budget.value) * 100)}, '', 'data-af-preferences-url');
      return;
    }
    var survey = closest(event.target, '[data-af-assistant-survey]');
    if (survey) {
      event.preventDefault();
      if (event.submitter && event.submitter.name === 'vote') {
        send({vote: event.submitter.value, reason: survey.elements.reason.value}, '', 'data-af-assistant-rate-url');
      }
      return;
    }
    var form = closest(event.target, '[data-af-assistant-form]');
    if (!form) {
      return;
    }
    event.preventDefault();
    var input = form.querySelector('input[name="message"]');
    if (input) {
      var value = input.value;
      input.value = '';
      submitMessage(value);
    }
  });

  document.addEventListener('keydown', function (event) {
    var panel = part('.af-assistant-panel');
    if (!panel || panel.hidden) {
      return;
    }
    if (event.key === 'Escape' || event.key === 'Esc') {
      event.preventDefault();
      closePanel();
      return;
    }
    if (event.key === 'Tab' && panel.getAttribute('aria-modal') === 'true') {
      // El foco no sale de la ventana mientras está abierta.
      var items = Array.prototype.filter.call(panel.querySelectorAll(FOCUSABLE), function (node) {
        return !!(node.offsetWidth || node.offsetHeight || node.getClientRects().length);
      });
      if (!items.length) {
        return;
      }
      var first = items[0];
      var last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });

  // Se enseña el botón: sin este guion el HTML lo lleva oculto.
  var box = root();
  if (box) {
    box.hidden = false;
    try {
      if (sessionStorage.getItem('af-chat-docked') === '1') {
        box.setAttribute('data-layout', 'side');
        document.documentElement.classList.add('af-chat-docked');
      }
    } catch (e) { /* opcional */ }
    var viewport = window.visualViewport;
    if (viewport) {
      var fitKeyboard = function () {
        box.style.setProperty('--as-visible-height', Math.round(viewport.height) + 'px');
        box.style.setProperty('--as-visible-top', Math.round(viewport.offsetTop) + 'px');
      };
      fitKeyboard();
      viewport.addEventListener('resize', fitKeyboard);
      viewport.addEventListener('scroll', fitKeyboard);
    }
  }
}(typeof window !== 'undefined' ? window : null, typeof document !== 'undefined' ? document : null));

/**
 * AIFEC — editor de bloques del panel.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Mejora cada <textarea data-af-blocks>: lo oculta, pinta el editor y mantiene el área al día con el JSON de los bloques
 * (en cada cambio y antes de enviar el formulario). Sin JavaScript queda el área con el JSON, que sigue funcionando.
 *
 * Contrato de datos: una lista de bloques [{type, attrs, children}] (ver core/src/Cms/Blocks/BlockSchema.php).
 *
 * Ninguna cadena visible está escrita aquí: llegan en <script type="application/json" id="af-blocks-i18n"> que pinta
 * BlocksEditor.php, con las claves t (textos), types (nombres de los tipos), urls (importar, vista previa, productos,
 * selector de imágenes) y forms (lista de formularios; también vale un atributo data-forms en el área).
 *
 * Todo va por delegación sobre document (Aifec.on): lo que se pinta después funciona sin conectar nada. Los textos
 * con formato se editan en elementos contenteditable y solo se guardan las etiquetas de formato permitidas
 * (b, strong, i, em, a[href], br, code, mark, sub, sup): ningún dato pasa a innerHTML sin cleanInline().
 * Fuera de window.Aifec solo se expone window.Aifec.blocks = {init(textarea)}.
 */
(function (window, document) {
  'use strict';

  if (!window || !document) {
    return;
  }
  var Aifec = window.Aifec;
  if (!Aifec || typeof Aifec.on !== 'function') {
    return;
  }
  var on = Aifec.on;

  var HISTORY_MAX = 100;
  var MAX_DEPTH = 30;
  var TYPE_ORDER = ['paragraph', 'heading', 'list', 'quote', 'image', 'gallery', 'table', 'columns', 'group', 'cover', 'buttons',
    'separator', 'spacer', 'video', 'embed', 'details', 'code', 'html', 'products', 'product', 'posts', 'form', 'toc', 'company'];
  var INLINE = {b: 'strong', strong: 'strong', i: 'em', em: 'em', a: 'a', br: 'br', code: 'code', mark: 'mark', sub: 'sub', sup: 'sup'};
  var DROP = {script: 1, style: 1, iframe: 1, object: 1, embed: 1, template: 1, noscript: 1, textarea: 1, title: 1, head: 1, svg: 1, math: 1, select: 1, button: 1, input: 1};
  var BLOCKISH = {p: 1, div: 1, h1: 1, h2: 1, h3: 1, h4: 1, h5: 1, h6: 1, li: 1, blockquote: 1, pre: 1, ul: 1, ol: 1, tr: 1, section: 1, article: 1};
  var PREVIEW_CSS = 'body{font:16px/1.55 system-ui,sans-serif;margin:12px;color:#222;background:#fff}img,video,iframe{max-width:100%;height:auto}'
    + 'table{border-collapse:collapse}td,th{border:1px solid #ccc;padding:4px 8px}.af-b-columns{display:flex;gap:16px}.af-b-columns>*{flex:1}'
    + 'pre{background:#f4f4f4;padding:8px;overflow:auto}blockquote{border-left:3px solid #ccc;margin-left:0;padding-left:12px}';

  /** Configuración y textos del servidor. */
  var cfg = null;
  /** Miniaturas ya vistas de la biblioteca de medios: {id: dirección}. */
  var thumbs = {};
  /** Arrastre en curso: {ed, addr}. */
  var drag = null;

  // ------------------------------------------------------------------ utilidades

  function all(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function one(sel, root) {
    return (root || document).querySelector(sel);
  }

  function closest(el, sel) {
    while (el && el.nodeType === 1) {
      var m = el.matches || el.webkitMatchesSelector || el.msMatchesSelector;
      if (m && m.call(el, sel)) {
        return el;
      }
      el = el.parentNode;
    }
    return null;
  }

  function remove(el) {
    if (el && el.parentNode) {
      el.parentNode.removeChild(el);
    }
  }

  function isObject(v) {
    return v !== null && typeof v === 'object' && !Array.isArray(v);
  }

  function clone(v) {
    return JSON.parse(JSON.stringify(v));
  }

  /** Crea un elemento. Los textos van siempre por textContent. */
  function h(tag, attrs, kids) {
    var el = document.createElement(tag);
    if (attrs) {
      for (var k in attrs) {
        if (!Object.prototype.hasOwnProperty.call(attrs, k)) {
          continue;
        }
        var v = attrs[k];
        if (v === null || v === undefined || v === false) {
          continue;
        }
        el.setAttribute(k, v === true ? '' : String(v));
      }
    }
    if (kids !== undefined && kids !== null) {
      (Array.isArray(kids) ? kids : [kids]).forEach(function (kid) {
        if (kid === null || kid === undefined || kid === false) {
          return;
        }
        el.appendChild(typeof kid === 'object' ? kid : document.createTextNode(String(kid)));
      });
    }
    return el;
  }

  function btn(label, attrs) {
    var a = {type: 'button'};
    for (var k in attrs) {
      if (Object.prototype.hasOwnProperty.call(attrs, k)) {
        a[k] = attrs[k];
      }
    }
    return h('button', a, label);
  }

  function loadConfig() {
    if (cfg) {
      return cfg;
    }
    var data = null;
    var el = document.getElementById('af-blocks-i18n');
    if (el) {
      try {
        data = JSON.parse(el.textContent || '');
      } catch (e) {
        data = null;
      }
    }
    cfg = isObject(data) ? data : {};
    cfg.t = isObject(cfg.t) ? cfg.t : {};
    cfg.types = isObject(cfg.types) ? cfg.types : {};
    cfg.urls = isObject(cfg.urls) ? cfg.urls : {};
    cfg.forms = Array.isArray(cfg.forms) ? cfg.forms : [];

    return cfg;
  }

  /** Texto traducido por su clave, con {marcas} sustituidas. */
  function T(key, vars) {
    var s = cfg && isObject(cfg.t) && typeof cfg.t[key] === 'string' ? cfg.t[key] : key;
    if (vars) {
      for (var k in vars) {
        if (Object.prototype.hasOwnProperty.call(vars, k)) {
          s = s.split('{' + k + '}').join(String(vars[k]));
        }
      }
    }
    return s;
  }

  function typeName(type) {
    return cfg && typeof cfg.types[type] === 'string' ? cfg.types[type] : type;
  }

  function fire(el, type) {
    var ev;
    if (typeof window.Event === 'function') {
      ev = new window.Event(type, {bubbles: true});
    } else {
      ev = document.createEvent('Event');
      ev.initEvent(type, true, false);
    }
    el.dispatchEvent(ev);
  }

  // ------------------------------------------------------------------ texto con formato: lo único que pasa a innerHTML

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function escAttr(s) {
    return esc(s).replace(/"/g, '&quot;');
  }

  /** Una dirección de enlace sin esquemas peligrosos (solo http, https, mailto, tel o relativa). */
  function safeHref(value) {
    var v = '';
    var raw = String(value === null || value === undefined ? '' : value);
    for (var i = 0; i < raw.length; i++) {
      if (raw.charCodeAt(i) > 32) {
        v += raw.charAt(i);
      }
    }
    if (v === '') {
      return '';
    }
    var m = /^([a-zA-Z][a-zA-Z0-9+.-]*):/.exec(v);
    if (m) {
      var scheme = m[1].toLowerCase();
      if (scheme !== 'http' && scheme !== 'https' && scheme !== 'mailto' && scheme !== 'tel') {
        return '';
      }
    }

    return String(value).trim();
  }

  function serialize(node) {
    var out = '';
    var kids = node.childNodes;
    for (var i = 0; i < kids.length; i++) {
      var c = kids[i];
      if (c.nodeType === 3) {
        out += esc(c.nodeValue);
        continue;
      }
      if (c.nodeType !== 1) {
        continue;
      }
      var name = String(c.localName || c.nodeName).toLowerCase();
      if (DROP[name] === 1) {
        continue;
      }
      if (name === 'br') {
        out += '<br>';
        continue;
      }
      if (INLINE[name]) {
        var inner = serialize(c);
        var tag = INLINE[name];
        if (tag === 'a') {
          var href = safeHref(c.getAttribute('href'));
          out += href !== '' ? '<a href="' + escAttr(href) + '">' + inner + '</a>' : inner;
        } else if (inner !== '') {
          out += '<' + tag + '>' + inner + '</' + tag + '>';
        }
        continue;
      }
      // Cualquier otra etiqueta se quita y se conserva su texto; los bloques separan con un salto de línea.
      if (BLOCKISH[name] === 1 && out !== '' && !/<br>$/.test(out)) {
        out += '<br>';
      }
      out += serialize(c);
    }

    return out;
  }

  /**
   * Deja solo el texto y las etiquetas de formato permitidas. Se analiza en un documento inerte (DOMParser): nada se ejecuta
   * ni se carga mientras se limpia.
   */
  function cleanInline(html) {
    var s = html === null || html === undefined ? '' : String(html);
    if (s.indexOf('<') === -1 && s.indexOf('&') === -1) {
      return esc(s);
    }
    if (typeof window.DOMParser !== 'function') {
      return esc(s);
    }
    var doc = new window.DOMParser().parseFromString('<!doctype html><html><body>' + s + '</body></html>', 'text/html');
    if (!doc || !doc.body) {
      return esc(s);
    }

    return serialize(doc.body).replace(/(<br>)+$/, '');
  }

  function setHtml(el, html) {
    el.innerHTML = cleanInline(html);
  }

  /** Texto plano con saltos de línea → texto con formato. */
  function textToHtml(text) {
    return esc(text).replace(/\r\n?|\n/g, '<br>');
  }

  function isEmptyText(el) {
    return String(el.textContent || '').replace(/[​ \s]/g, '') === '' && !el.querySelector('img');
  }

  // ------------------------------------------------------------------ modelo de bloques

  function mk(type, attrs, children) {
    return {type: type, attrs: attrs || {}, children: children || []};
  }

  /** Un bloque nuevo de ese tipo. */
  function blank(type) {
    switch (type) {
      case 'paragraph':
        return mk(type, {html: ''});
      case 'heading':
        return mk(type, {level: 2, html: ''});
      case 'list':
        return mk(type, {ordered: false, items: [{html: ''}]});
      case 'quote':
        return mk(type, {html: ''});
      case 'gallery':
        return mk(type, {images: [], columns: 3});
      case 'table':
        return mk(type, {rows: [['', ''], ['', '']]});
      case 'columns':
        return mk(type, {}, [mk('column', {}, []), mk('column', {}, [])]);
      case 'cover':
        return mk(type, {dim: 50});
      case 'buttons':
        return mk(type, {items: [{text: '', href: '', variant: 'fill'}]});
      case 'spacer':
        return mk(type, {height: 40});
      case 'details':
        return mk(type, {summary: ''});
      case 'code':
        return mk(type, {code: ''});
      case 'html':
        return mk(type, {html: ''});
      case 'products':
        return mk(type, {mode: 'latest', limit: 4, columns: 4});
      case 'posts':
        return mk(type, {limit: 3});
      case 'toc':
        return mk(type, {max_level: 3});
      default:
        return mk(type, {});
    }
  }

  function normalize(list, depth, parentType) {
    if (!Array.isArray(list) || depth > MAX_DEPTH) {
      return [];
    }
    var out = [];
    list.forEach(function (raw) {
      if (!isObject(raw)) {
        return;
      }
      var type = typeof raw.type === 'string' && raw.type !== '' ? raw.type : 'html';
      var block = {type: type, attrs: isObject(raw.attrs) ? raw.attrs : {}, children: normalize(raw.children, depth + 1, type)};
      if (parentType === 'columns' && type !== 'column') {
        block = mk('column', {}, [block]);
      }
      out.push(block);
    });

    return out;
  }

  function parseBlocks(text) {
    var data = null;
    try {
      data = JSON.parse(String(text || '').trim() === '' ? '[]' : text);
    } catch (e) {
      data = null;
    }
    if (isObject(data) && Array.isArray(data.blocks)) {
      data = data.blocks;
    }

    return normalize(data, 0, '');
  }

  function countReview(list) {
    var n = 0;
    (Array.isArray(list) ? list : []).forEach(function (b) {
      if (b && b.type === 'html' && isObject(b.attrs) && b.attrs.review) {
        n++;
      }
      n += countReview(b ? b.children : []);
    });

    return n;
  }

  function parseAddr(s) {
    if (s === null || s === undefined || s === '') {
      return [];
    }
    return String(s).split('.').map(function (x) {
      return parseInt(x, 10);
    });
  }

  function listOf(ed, parent) {
    var list = ed.blocks;
    for (var i = 0; i < parent.length; i++) {
      var b = list[parent[i]];
      if (!b) {
        return null;
      }
      list = b.children;
    }

    return list;
  }

  function blockAt(ed, addr) {
    var list = listOf(ed, addr.slice(0, -1));

    return list ? (list[addr[addr.length - 1]] || null) : null;
  }

  function isPrefix(prefix, addr) {
    if (prefix.length > addr.length) {
      return false;
    }
    for (var i = 0; i < prefix.length; i++) {
      if (prefix[i] !== addr[i]) {
        return false;
      }
    }

    return true;
  }

  function getIn(obj, keys) {
    var cur = obj;
    for (var i = 0; i < keys.length; i++) {
      if (cur === null || cur === undefined || typeof cur !== 'object') {
        return undefined;
      }
      cur = cur[keys[i]];
    }

    return cur;
  }

  function setIn(obj, keys, value) {
    var cur = obj;
    for (var i = 0; i < keys.length - 1; i++) {
      var k = keys[i];
      if (cur[k] === undefined || cur[k] === null || typeof cur[k] !== 'object') {
        cur[k] = /^\d+$/.test(keys[i + 1]) ? [] : {};
      }
      cur = cur[k];
    }
    var last = keys[keys.length - 1];
    if (value === undefined) {
      if (!Array.isArray(cur)) {
        delete cur[last];
      }
    } else {
      cur[last] = value;
    }
  }

  function providerOf(url) {
    var m = /^https:\/\/([^\/?#]+)/i.exec(String(url || ''));
    var host = m ? m[1].toLowerCase().replace(/^www\./, '') : '';
    if (host === 'youtube.com' || host === 'youtu.be' || host === 'youtube-nocookie.com' || host === 'm.youtube.com') {
      return 'youtube';
    }
    if (host === 'vimeo.com' || host === 'player.vimeo.com') {
      return 'vimeo';
    }

    return 'other';
  }

  // ------------------------------------------------------------------ editor: estado e historial

  function editorOf(el) {
    var root = closest(el, '[data-afb-root]');

    return root && root.__afb ? root.__afb : null;
  }

  function sync(ed) {
    ed.ta.value = JSON.stringify(ed.blocks);
    fire(ed.ta, 'input');
    fire(ed.ta, 'change');
    schedulePreview(ed);
  }

  function updateHistoryButtons(ed) {
    if (ed.undoBtn) {
      ed.undoBtn.disabled = ed.hi <= 0;
    }
    if (ed.redoBtn) {
      ed.redoBtn.disabled = ed.hi >= ed.history.length - 1;
    }
  }

  /** Anota el estado actual como un paso del historial (los cambios seguidos del mismo campo se juntan). */
  function commit(ed, key) {
    var json = JSON.stringify(ed.blocks);
    if (json === ed.history[ed.hi]) {
      return;
    }
    var now = Date.now();
    if (key && key === ed.lastKey && now - ed.lastTime < 1200 && ed.hi === ed.history.length - 1 && ed.hi > 0) {
      ed.history[ed.hi] = json;
    } else {
      ed.history = ed.history.slice(0, ed.hi + 1);
      ed.history.push(json);
      if (ed.history.length > HISTORY_MAX) {
        ed.history.shift();
      }
      ed.hi = ed.history.length - 1;
    }
    ed.lastKey = key || '';
    ed.lastTime = now;
    sync(ed);
    updateHistoryButtons(ed);
  }

  function travel(ed, step) {
    var next = ed.hi + step;
    if (next < 0 || next >= ed.history.length) {
      return;
    }
    ed.hi = next;
    ed.lastKey = '';
    ed.blocks = parseBlocks(ed.history[ed.hi]);
    sync(ed);
    render(ed);
    if (ed.root && typeof ed.root.focus === 'function') {
      ed.root.focus();
    }
  }

  // ------------------------------------------------------------------ editor: pintado

  function build(ed) {
    var root = h('div', {'class': 'afb', 'data-afb-root': '', tabindex: '-1'});
    root.__afb = ed;
    ed.root = root;

    var bar = h('div', {'class': 'afb-toolbar', role: 'toolbar'});
    ed.undoBtn = btn('↶', {'class': 'afb-tb', 'data-afb-act': 'undo', 'aria-label': T('undo'), title: T('undo')});
    ed.redoBtn = btn('↷', {'class': 'afb-tb', 'data-afb-act': 'redo', 'aria-label': T('redo'), title: T('redo')});
    bar.appendChild(ed.undoBtn);
    bar.appendChild(ed.redoBtn);
    bar.appendChild(h('span', {'class': 'afb-sep'}));
    bar.appendChild(btn('B', {'class': 'afb-tb afb-tb-b', 'data-afb-fmt': 'bold', 'aria-label': T('bold'), title: T('bold')}));
    bar.appendChild(btn('I', {'class': 'afb-tb afb-tb-i', 'data-afb-fmt': 'italic', 'aria-label': T('italic'), title: T('italic')}));
    bar.appendChild(btn(T('link'), {'class': 'afb-tb', 'data-afb-fmt': 'link', title: T('link')}));
    bar.appendChild(btn(T('unlink'), {'class': 'afb-tb', 'data-afb-fmt': 'unlink', title: T('unlink')}));
    bar.appendChild(h('span', {'class': 'afb-grow'}));
    bar.appendChild(btn(T('wp_import'), {'class': 'afb-tb', 'data-afb-act': 'wp-toggle'}));
    bar.appendChild(btn(T('preview'), {'class': 'afb-tb', 'data-afb-act': 'preview', 'aria-pressed': 'false'}));
    root.appendChild(bar);

    ed.wp = h('div', {'class': 'afb-wp', hidden: true});
    ed.wp.appendChild(h('label', {'class': 'afb-label'}, T('wp_ph')));
    ed.wp.appendChild(h('textarea', {'class': 'af-textarea af-mono', rows: '6', 'data-afb-wp': '', spellcheck: 'false'}));
    ed.wp.appendChild(h('div', {'class': 'afb-wp-actions'}, [
      btn(T('wp_do'), {'class': 'af-btn af-btn-sm af-btn-primary', 'data-afb-act': 'wp-do'}),
      btn(T('cancel'), {'class': 'af-btn af-btn-sm', 'data-afb-act': 'wp-toggle'})
    ]));
    root.appendChild(ed.wp);

    var main = h('div', {'class': 'afb-main'});
    ed.canvas = h('div', {'class': 'afb-canvas', 'data-afb-list': '', 'data-parent': ''});
    main.appendChild(ed.canvas);
    ed.panel = h('div', {'class': 'afb-preview', hidden: true});
    ed.frame = h('iframe', {'class': 'afb-frame', sandbox: '', title: T('preview')});
    ed.panel.appendChild(ed.frame);
    main.appendChild(ed.panel);
    root.appendChild(main);
    root.appendChild(h('div', {'class': 'afb-live af-sr', 'aria-live': 'polite'}));

    ed.ta.parentNode.insertBefore(root, ed.ta.nextSibling);
    ed.ta.hidden = true;
    ed.ta.setAttribute('aria-hidden', 'true');
    ed.ta.setAttribute('tabindex', '-1');
  }

  function render(ed, focusAddr, focusBind) {
    closeMenu(ed);
    renderList(ed, ed.canvas, ed.blocks, []);
    updateHistoryButtons(ed);
    if (focusAddr) {
      focusBlock(ed, focusAddr, focusBind);
    }
  }

  function insertZone(parent, index, last) {
    var zone = h('div', {'class': 'afb-insert', 'data-afb-drop': '', 'data-parent': parent.join('.'), 'data-index': index});
    zone.appendChild(btn('+', {'class': 'afb-plus', 'data-afb-act': 'insert', 'aria-label': T('add_block'), title: T('add_block')}));
    if (last) {
      zone.setAttribute('data-last', '1');
    }

    return zone;
  }

  function renderList(ed, container, blocks, parent) {
    container.innerHTML = '';
    container.setAttribute('data-afb-list', '');
    container.setAttribute('data-parent', parent.join('.'));
    if (blocks.length === 0) {
      var empty = h('div', {'class': 'afb-empty', 'data-afb-drop': '', 'data-parent': parent.join('.'), 'data-index': 0});
      if (parent.length === 0) {
        empty.appendChild(h('p', {'class': 'af-muted'}, T('empty')));
      }
      empty.appendChild(btn('+', {'class': 'afb-plus afb-plus-lg', 'data-afb-act': 'insert', 'aria-label': T('add_block'), title: T('add_block')}));
      container.appendChild(empty);
      return;
    }
    blocks.forEach(function (b, i) {
      container.appendChild(insertZone(parent, i, false));
      container.appendChild(renderBlock(ed, b, parent.concat(i), i, blocks.length));
    });
    container.appendChild(insertZone(parent, blocks.length, true));
  }

  function renderBlock(ed, b, addr, index, count) {
    var el = h('div', {
      'class': 'afb-block afb-t-' + b.type, 'data-afb-block': '', 'data-afb-drop': '', 'data-path': addr.join('.'),
      'data-type': b.type, tabindex: '-1'
    });
    var bar = h('div', {'class': 'afb-bar'});
    bar.appendChild(h('span', {'class': 'afb-handle', draggable: 'true', 'data-afb-drag': '', title: T('drag'), 'aria-label': T('drag')}, '⋮⋮'));
    bar.appendChild(h('span', {'class': 'afb-type'}, typeName(b.type)));
    bar.appendChild(h('span', {'class': 'afb-grow'}));
    var up = btn('↑', {'class': 'afb-ib', 'data-afb-act': 'up', 'aria-label': T('move_up'), title: T('move_up')});
    var down = btn('↓', {'class': 'afb-ib', 'data-afb-act': 'down', 'aria-label': T('move_down'), title: T('move_down')});
    if (index === 0) {
      up.disabled = true;
    }
    if (index >= count - 1) {
      down.disabled = true;
    }
    bar.appendChild(up);
    bar.appendChild(down);
    bar.appendChild(btn('⧉', {'class': 'afb-ib', 'data-afb-act': 'dup', 'aria-label': T('duplicate'), title: T('duplicate')}));
    bar.appendChild(btn('✕', {'class': 'afb-ib afb-ib-del', 'data-afb-act': 'del', 'aria-label': T('delete'), title: T('delete')}));
    el.appendChild(bar);
    var body = h('div', {'class': 'afb-body'});
    (BODY[b.type] || BODY.unknown)(ed, b, addr, body);
    el.appendChild(body);

    return el;
  }

  // ------------------------------------------------------------------ campos

  function labelled(text, control, cls) {
    var l = h('label', {'class': 'afb-field' + (cls ? ' ' + cls : '')});
    l.appendChild(h('span', {'class': 'afb-label'}, text));
    l.appendChild(control);

    return l;
  }

  function richEl(bind, value, ph, opts) {
    opts = opts || {};
    var el = h('div', {
      'class': 'afb-rich' + (opts.cls ? ' ' + opts.cls : ''), contenteditable: 'true', role: 'textbox', 'aria-label': ph,
      'data-placeholder': ph, 'data-afb-rich': '', 'data-afb-bind': bind
    });
    if (opts.single) {
      el.setAttribute('data-afb-single', '');
    } else {
      el.setAttribute('aria-multiline', 'true');
    }
    setHtml(el, value);

    return el;
  }

  function textInput(bind, value, kind, ph, cls) {
    var i = h('input', {type: 'text', 'class': 'af-input' + (cls ? ' ' + cls : ''), 'data-afb-bind': bind, 'data-afb-kind': kind || 'text', placeholder: ph || null, autocomplete: 'off'});
    i.value = value === null || value === undefined ? '' : String(value);

    return i;
  }

  function numInput(bind, value, min, max, ph) {
    var i = h('input', {type: 'number', 'class': 'af-input af-input-sm', 'data-afb-bind': bind, 'data-afb-kind': 'int', min: min, max: max, placeholder: ph || null});
    i.value = value === null || value === undefined ? '' : String(value);

    return i;
  }

  function selectEl(bind, options, value, kind, rerender) {
    var s = h('select', {'class': 'af-select af-input-md', 'data-afb-bind': bind, 'data-afb-kind': kind || 'text', 'data-afb-rerender': rerender ? '1' : null});
    var seen = false;
    options.forEach(function (o) {
      var op = h('option', {value: o[0]}, o[1]);
      if (String(o[0]) === String(value === undefined || value === null ? '' : value)) {
        op.selected = true;
        seen = true;
      }
      s.appendChild(op);
    });
    if (!seen && value !== undefined && value !== null && value !== '') {
      var extra = h('option', {value: String(value)}, String(value));
      extra.selected = true;
      s.appendChild(extra);
    }

    return s;
  }

  function checkEl(bind, label, checked, rerender) {
    var c = h('input', {type: 'checkbox', 'data-afb-bind': bind, 'data-afb-kind': 'bool', 'data-afb-rerender': rerender ? '1' : null});
    c.checked = !!checked;

    return h('label', {'class': 'afb-check'}, [c, h('span', null, label)]);
  }

  function areaEl(bind, value, kind, rows, ph) {
    var t = h('textarea', {'class': 'af-textarea af-mono', rows: rows || 6, 'data-afb-bind': bind, 'data-afb-kind': kind || 'raw', spellcheck: 'false', placeholder: ph || null});
    t.value = value === null || value === undefined ? '' : String(value);

    return t;
  }

  function httpsField(label, bind, value) {
    var i = textInput(bind, value, 'https', 'https://');
    i.setAttribute('inputmode', 'url');
    var f = labelled(label, i);
    f.appendChild(h('span', {'class': 'afb-warn', hidden: true}, T('https_only')));

    return f;
  }

  function alignOptions() {
    return [['', T('align_none')], ['left', T('left')], ['center', T('center')], ['right', T('right')]];
  }

  function row(cls, kids) {
    return h('div', {'class': 'afb-row' + (cls ? ' ' + cls : '')}, kids);
  }

  // ------------------------------------------------------------------ biblioteca de medios

  function mediaHost(kind, ids, multiple) {
    var host = h('div', {'class': 'af-media', 'data-af-media': '', 'data-afb-media': kind, 'data-multiple': multiple ? '1' : '0', 'data-picker': cfg.urls.picker || ''});
    host.appendChild(h('input', {type: 'hidden', 'data-af-media-input': '', value: ids.join(',')}));
    var list = h('div', {'class': 'af-thumbs', 'data-af-media-list': ''});
    ids.forEach(function (id) {
      var item = h('div', {'class': 'af-thumb-item', 'data-id': String(id)});
      if (thumbs[id]) {
        item.appendChild(h('img', {'class': 'af-thumb', src: thumbs[id], alt: '', loading: 'lazy'}));
      } else {
        item.appendChild(h('span', {'class': 'af-thumb afb-thumb-id'}, '#' + id));
      }
      item.appendChild(btn('×', {'data-af-media-remove': '', 'aria-label': T('remove')}));
      list.appendChild(item);
    });
    host.appendChild(list);
    host.appendChild(btn(T(multiple ? 'add_images' : 'choose_image'), {'class': 'af-btn af-btn-sm', 'data-af-media-pick': ''}));

    return host;
  }

  function safeImgSrc(src) {
    var s = String(src || '').trim();

    return /^(https?:)?\/\/[^\s]+$/i.test(s) || /^\/[^\s\/][^\s]*$/.test(s) ? s : '';
  }

  // ------------------------------------------------------------------ buscador de productos

  function productPicker(ed, kind, ids) {
    var wrap = h('div', {'class': 'afb-picker'});
    wrap.appendChild(h('input', {type: 'search', 'class': 'af-input', 'data-afb-psearch': kind, placeholder: T('search_products'), autocomplete: 'off', 'aria-label': T('search_products')}));
    wrap.appendChild(h('ul', {'class': 'afb-results', 'data-afb-results': '', hidden: true}));
    var chips = h('div', {'class': 'afb-chips'});
    ids.forEach(function (id) {
      var chip = h('span', {'class': 'afb-chip'});
      chip.appendChild(h('span', null, ed.labels[id] || T('product_n', {n: id})));
      chip.appendChild(btn('×', {'class': 'afb-chip-x', 'data-afb-act': 'pr-del', 'data-id': String(id), 'aria-label': T('remove')}));
      chips.appendChild(chip);
    });
    wrap.appendChild(chips);

    return wrap;
  }

  // ------------------------------------------------------------------ cuerpo de cada tipo de bloque

  function childrenBox(ed, b, addr) {
    var box = h('div', {'class': 'afb-children'});
    renderList(ed, box, b.children, addr);

    return box;
  }

  var BODY = {
    paragraph: function (ed, b, addr, body) {
      body.appendChild(richEl('attrs.html', b.attrs.html, T('p_ph'), {cls: 'afb-p'}));
      body.appendChild(row('afb-opts', labelled(T('align'), selectEl('attrs.align', alignOptions(), b.attrs.align))));
    },
    heading: function (ed, b, addr, body) {
      var level = parseInt(b.attrs.level, 10) || 2;
      body.appendChild(richEl('attrs.html', b.attrs.html, T('h_ph'), {cls: 'afb-h afb-h' + level, single: true}));
      body.appendChild(row('afb-opts', [
        labelled(T('level'), selectEl('attrs.level', [[2, 'H2'], [3, 'H3'], [4, 'H4']], level, 'int')),
        labelled(T('anchor'), textInput('attrs.anchor', b.attrs.anchor, 'text', '', 'af-input-sm')),
        labelled(T('align'), selectEl('attrs.align', alignOptions(), b.attrs.align))
      ]));
    },
    list: function (ed, b, addr, body) {
      body.appendChild(checkEl('attrs.ordered', T('ordered'), b.attrs.ordered === true));
      var box = h('div', {'class': 'afb-items'});
      (function items(list, path, depth) {
        (Array.isArray(list) ? list : []).forEach(function (item, i) {
          var it = isObject(item) ? item : {html: String(item)};
          var line = h('div', {'class': 'afb-item', style: depth ? 'margin-left:' + (depth * 1.4) + 'rem' : null});
          line.appendChild(h('span', {'class': 'afb-bullet', 'aria-hidden': 'true'}, '•'));
          line.appendChild(richEl(path + '.' + i + '.html', it.html, T('item_ph'), {single: true, cls: 'afb-li'}));
          line.appendChild(btn('×', {'class': 'afb-ib', 'data-afb-act': 'li-del', 'data-list': path, 'data-index': i, 'aria-label': T('item_remove'), title: T('item_remove')}));
          box.appendChild(line);
          if (Array.isArray(it.items)) {
            items(it.items, path + '.' + i + '.items', depth + 1);
          }
        });
      })(b.attrs.items, 'attrs.items', 0);
      body.appendChild(box);
      body.appendChild(btn(T('item_add'), {'class': 'af-btn af-btn-sm', 'data-afb-act': 'li-add', 'data-list': 'attrs.items', 'data-index': Array.isArray(b.attrs.items) ? b.attrs.items.length : 0}));
    },
    quote: function (ed, b, addr, body) {
      body.appendChild(richEl('attrs.html', b.attrs.html, T('quote_ph'), {cls: 'afb-quote'}));
      body.appendChild(richEl('attrs.cite', b.attrs.cite, T('cite_ph'), {single: true, cls: 'afb-small'}));
    },
    image: function (ed, b, addr, body) {
      var id = parseInt(b.attrs.media_id, 10);
      body.appendChild(mediaHost('image', id > 0 ? [id] : [], false));
      var src = safeImgSrc(b.attrs.src);
      if (!(id > 0) && src !== '') {
        body.appendChild(h('img', {'class': 'afb-preview-img', src: src, alt: ''}));
      }
      body.appendChild(labelled(T('alt'), textInput('attrs.alt', b.attrs.alt, 'text', T('alt_ph'))));
      body.appendChild(richEl('attrs.caption', b.attrs.caption, T('caption_ph'), {single: true, cls: 'afb-small'}));
      body.appendChild(row('afb-opts', [
        labelled(T('href_label'), textInput('attrs.href', b.attrs.href, 'text', 'https://')),
        labelled(T('align'), selectEl('attrs.align', alignOptions(), b.attrs.align))
      ]));
    },
    gallery: function (ed, b, addr, body) {
      var images = Array.isArray(b.attrs.images) ? b.attrs.images : [];
      var ids = [];
      images.forEach(function (im) {
        var n = parseInt(isObject(im) ? im.media_id : 0, 10);
        if (n > 0) {
          ids.push(n);
        }
      });
      body.appendChild(mediaHost('gallery', ids, true));
      body.appendChild(row('afb-opts', labelled(T('columns'), numInput('attrs.columns', b.attrs.columns, 1, 6))));
      var box = h('div', {'class': 'afb-gal'});
      images.forEach(function (im, i) {
        if (!isObject(im)) {
          return;
        }
        var line = h('div', {'class': 'afb-gal-item'});
        var n = parseInt(im.media_id, 10);
        line.appendChild(h('span', {'class': 'afb-gal-n'}, n > 0 ? (thumbs[n] ? h('img', {src: thumbs[n], alt: ''}) : '#' + n) : T('image_n', {n: i + 1})));
        line.appendChild(textInput('attrs.images.' + i + '.alt', im.alt, 'text', T('alt_ph')));
        line.appendChild(richEl('attrs.images.' + i + '.caption', im.caption, T('caption_ph'), {single: true, cls: 'afb-small'}));
        line.appendChild(btn('×', {'class': 'afb-ib', 'data-afb-act': 'gi-del', 'data-index': i, 'aria-label': T('remove'), title: T('remove')}));
        box.appendChild(line);
      });
      body.appendChild(box);
    },
    table: function (ed, b, addr, body) {
      var head = Array.isArray(b.attrs.head) ? b.attrs.head : [];
      var rows = Array.isArray(b.attrs.rows) ? b.attrs.rows : [];
      var cols = 0;
      head.concat(rows).forEach(function (r) {
        cols = Math.max(cols, Array.isArray(r) ? r.length : 0);
      });
      var c = h('input', {type: 'checkbox', 'data-afb-act': 'th-toggle'});
      c.checked = head.length > 0;
      body.appendChild(h('label', {'class': 'afb-check'}, [c, h('span', null, T('header_row'))]));
      var wrap = h('div', {'class': 'afb-table-wrap'});
      var table = h('table', {'class': 'afb-table'});
      var cut = h('tr', {'class': 'afb-cut'});
      for (var j = 0; j < cols; j++) {
        cut.appendChild(h('td', null, btn('×', {'class': 'afb-ib', 'data-afb-act': 'tc-del', 'data-index': j, 'aria-label': T('col_remove'), title: T('col_remove')})));
      }
      cut.appendChild(h('td'));
      table.appendChild(cut);
      function line(cells, section, r, tag) {
        var tr = h('tr');
        for (var k = 0; k < cols; k++) {
          var cell = h(tag);
          cell.appendChild(richEl('attrs.' + section + '.' + r + '.' + k, Array.isArray(cells) ? cells[k] : '', '', {single: true}));
          tr.appendChild(cell);
        }
        var end = h('td');
        if (section === 'rows') {
          end.appendChild(btn('×', {'class': 'afb-ib', 'data-afb-act': 'tr-del', 'data-index': r, 'aria-label': T('row_remove'), title: T('row_remove')}));
        }
        tr.appendChild(end);
        table.appendChild(tr);
      }
      head.forEach(function (cells, r) {
        line(cells, 'head', r, 'th');
      });
      rows.forEach(function (cells, r) {
        line(cells, 'rows', r, 'td');
      });
      wrap.appendChild(table);
      body.appendChild(wrap);
      body.appendChild(row('afb-opts', [
        btn(T('row_add'), {'class': 'af-btn af-btn-sm', 'data-afb-act': 'tr-add'}),
        btn(T('col_add'), {'class': 'af-btn af-btn-sm', 'data-afb-act': 'tc-add'})
      ]));
      body.appendChild(richEl('attrs.caption', b.attrs.caption, T('caption_ph'), {single: true, cls: 'afb-small'}));
    },
    columns: function (ed, b, addr, body) {
      var n = b.children.length;
      var opts = [[2, '2'], [3, '3'], [4, '4']];
      if (n < 2 || n > 4) {
        opts.push([n, String(n)]);
      }
      var sel = selectEl('', opts, n);
      sel.removeAttribute('data-afb-bind');
      sel.setAttribute('data-afb-cols', '');
      body.appendChild(row('afb-opts', labelled(T('columns'), sel)));
      var grid = h('div', {'class': 'afb-cols', style: 'grid-template-columns:repeat(' + Math.max(1, n) + ',minmax(0,1fr))'});
      b.children.forEach(function (col, i) {
        var box = h('div', {'class': 'afb-col'});
        box.appendChild(h('div', {'class': 'afb-col-title'}, T('column_n', {n: i + 1})));
        var inner = h('div', {'class': 'afb-children'});
        renderList(ed, inner, col.children, addr.concat(i));
        box.appendChild(inner);
        grid.appendChild(box);
      });
      body.appendChild(grid);
    },
    group: function (ed, b, addr, body) {
      body.appendChild(row('afb-opts', [
        labelled(T('group_tag'), selectEl('attrs.tag', [['', 'div'], ['section', 'section']], b.attrs.tag)),
        labelled(T('width'), selectEl('attrs.class_hint', [['', T('width_normal')], ['wide', T('wide')], ['full', T('full')]], b.attrs.class_hint))
      ]));
      body.appendChild(childrenBox(ed, b, addr));
    },
    cover: function (ed, b, addr, body) {
      var id = parseInt(b.attrs.media_id, 10);
      body.appendChild(mediaHost('cover', id > 0 ? [id] : [], false));
      body.appendChild(row('afb-opts', [
        labelled(T('overlay'), textInput('attrs.overlay', b.attrs.overlay, 'text', '#000000', 'af-input-sm')),
        labelled(T('dim'), numInput('attrs.dim', b.attrs.dim, 0, 100)),
        labelled(T('min_height'), numInput('attrs.min_height', b.attrs.min_height, 0, 3000))
      ]));
      body.appendChild(childrenBox(ed, b, addr));
    },
    buttons: function (ed, b, addr, body) {
      var items = Array.isArray(b.attrs.items) ? b.attrs.items : [];
      var box = h('div', {'class': 'afb-items'});
      items.forEach(function (it, i) {
        var item = isObject(it) ? it : {};
        var base = 'attrs.items.' + i + '.';
        var line = h('div', {'class': 'afb-btn-row'});
        line.appendChild(labelled(T('button_text'), richEl(base + 'text', item.text, T('button_text'), {single: true, cls: 'afb-btntext'})));
        line.appendChild(labelled(T('button_href'), textInput(base + 'href', item.href, 'text', 'https://')));
        line.appendChild(labelled(T('variant'), selectEl(base + 'variant', [['fill', T('fill')], ['outline', T('outline')]], item.variant || 'fill')));
        line.appendChild(labelled(T('target'), selectEl(base + 'target', [['', T('same_tab')], ['_blank', T('new_tab')]], item.target)));
        line.appendChild(btn('×', {'class': 'afb-ib', 'data-afb-act': 'bt-del', 'data-index': i, 'aria-label': T('button_remove'), title: T('button_remove')}));
        box.appendChild(line);
      });
      body.appendChild(box);
      body.appendChild(row('afb-opts', [
        btn(T('button_add'), {'class': 'af-btn af-btn-sm', 'data-afb-act': 'bt-add'}),
        labelled(T('align'), selectEl('attrs.align', alignOptions(), b.attrs.align))
      ]));
    },
    separator: function (ed, b, addr, body) {
      body.appendChild(h('hr', {'class': 'afb-hr'}));
    },
    spacer: function (ed, b, addr, body) {
      body.appendChild(labelled(T('height'), numInput('attrs.height', b.attrs.height, 1, 400)));
    },
    video: function (ed, b, addr, body) {
      body.appendChild(httpsField(T('video_url'), 'attrs.src', b.attrs.src));
      body.appendChild(httpsField(T('poster_url'), 'attrs.poster', b.attrs.poster));
      body.appendChild(richEl('attrs.caption', b.attrs.caption, T('caption_ph'), {single: true, cls: 'afb-small'}));
    },
    embed: function (ed, b, addr, body) {
      body.appendChild(httpsField(T('embed_url'), 'attrs.url', b.attrs.url));
      body.appendChild(richEl('attrs.caption', b.attrs.caption, T('caption_ph'), {single: true, cls: 'afb-small'}));
    },
    details: function (ed, b, addr, body) {
      body.appendChild(richEl('attrs.summary', b.attrs.summary, T('summary_ph'), {single: true, cls: 'afb-summary'}));
      body.appendChild(childrenBox(ed, b, addr));
    },
    code: function (ed, b, addr, body) {
      body.appendChild(labelled(T('language'), textInput('attrs.language', b.attrs.language, 'text', '', 'af-input-sm')));
      body.appendChild(areaEl('attrs.code', b.attrs.code, 'raw', 8, T('code_ph')));
    },
    html: function (ed, b, addr, body) {
      body.appendChild(h('p', {'class': 'afb-note af-muted af-small'}, T('html_notice')));
      body.appendChild(areaEl('attrs.html', b.attrs.html, 'raw', 8, ''));
      if (b.attrs.review !== undefined) {
        body.appendChild(checkEl('attrs.review', T('needs_review'), b.attrs.review === true));
      }
    },
    products: function (ed, b, addr, body) {
      var mode = typeof b.attrs.mode === 'string' ? b.attrs.mode : 'latest';
      body.appendChild(row('afb-opts', [
        labelled(T('mode'), selectEl('attrs.mode', [['manual', T('m_manual')], ['category', T('m_category')], ['latest', T('m_latest')], ['featured', T('m_featured')], ['sale', T('m_sale')]], mode, 'text', true)),
        labelled(T('count'), numInput('attrs.limit', b.attrs.limit, 1, 100)),
        labelled(T('columns'), numInput('attrs.columns', b.attrs.columns, 1, 6))
      ]));
      if (mode === 'manual') {
        body.appendChild(productPicker(ed, 'multi', Array.isArray(b.attrs.ids) ? b.attrs.ids : []));
      } else if (mode === 'category') {
        body.appendChild(labelled(T('category_id'), numInput('attrs.category_id', b.attrs.category_id, 1, 2147483647)));
      }
    },
    product: function (ed, b, addr, body) {
      var id = parseInt(b.attrs.id, 10);
      body.appendChild(productPicker(ed, 'single', id > 0 ? [id] : []));
    },
    posts: function (ed, b, addr, body) {
      body.appendChild(row('afb-opts', [
        labelled(T('count'), numInput('attrs.limit', b.attrs.limit, 1, 100)),
        labelled(T('category_id'), numInput('attrs.category_id', b.attrs.category_id, 1, 2147483647))
      ]));
    },
    form: function (ed, b, addr, body) {
      var forms = formsOf(ed);
      var opts = [['', T('form_pick')]];
      forms.forEach(function (f) {
        opts.push([f.id, String(f.name === undefined ? f.id : f.name)]);
      });
      body.appendChild(labelled(T('form'), selectEl('attrs.form_id', opts, b.attrs.form_id, 'formid')));
    },
    toc: function (ed, b, addr, body) {
      body.appendChild(labelled(T('max_level'), numInput('attrs.max_level', b.attrs.max_level, 1, 6)));
    },
    company: function (ed, b, addr, body) {
      body.appendChild(h('p', {'class': 'afb-note'}, T('company_hint')));
      body.appendChild(checkEl('attrs.contact', T('company_contact'), b.attrs.contact === true));
    },
    unknown: function (ed, b, addr, body) {
      if (typeof b.attrs.html === 'string') {
        body.appendChild(areaEl('attrs.html', b.attrs.html, 'raw', 6, ''));
      }
    }
  };

  function formsOf(ed) {
    var raw = ed.ta.getAttribute('data-forms');
    if (raw) {
      try {
        var list = JSON.parse(raw);
        if (Array.isArray(list)) {
          return list.filter(isObject);
        }
      } catch (e) {
        // se usa la lista de la configuración
      }
    }

    return cfg.forms.filter(isObject);
  }

  // ------------------------------------------------------------------ foco

  function placeCaret(el, atStart) {
    if (typeof el.focus === 'function') {
      el.focus();
    }
    if (el.getAttribute && el.getAttribute('contenteditable') === 'true' && window.getSelection && document.createRange) {
      try {
        var r = document.createRange();
        r.selectNodeContents(el);
        r.collapse(!!atStart);
        var s = window.getSelection();
        s.removeAllRanges();
        s.addRange(r);
      } catch (e) {
        // sin selección no pasa nada
      }
    }
  }

  function blockEl(ed, addr) {
    return one('.afb-block[data-path="' + addr.join('.') + '"]', ed.root);
  }

  function focusBlock(ed, addr, bind, atStart) {
    var el = blockEl(ed, addr);
    if (!el) {
      return;
    }
    var target = null;
    if (bind) {
      target = one('[data-afb-bind="' + bind + '"]', el);
    }
    if (!target) {
      target = one('.afb-body [contenteditable="true"], .afb-body input:not([type=hidden]):not([type=checkbox]), .afb-body textarea, .afb-body select', el);
    }
    if (target) {
      placeCaret(target, atStart);
    } else if (typeof el.focus === 'function') {
      el.focus();
    }
  }

  // ------------------------------------------------------------------ operaciones sobre la lista

  function insertAt(ed, parent, index, block) {
    var list = listOf(ed, parent);
    if (!list) {
      return null;
    }
    var i = Math.max(0, Math.min(index, list.length));
    list.splice(i, 0, block);

    return parent.concat(i);
  }

  function removeAt(ed, addr) {
    var list = listOf(ed, addr.slice(0, -1));
    if (!list) {
      return null;
    }

    return list.splice(addr[addr.length - 1], 1)[0] || null;
  }

  /** Mueve un bloque a otra posición (de la misma lista o de otra). Devuelve la nueva dirección, o null si no se puede. */
  function moveBlock(ed, from, parent, index) {
    if (isPrefix(from, parent)) {
      return null;
    }
    var srcList = listOf(ed, from.slice(0, -1));
    var dstList = listOf(ed, parent);
    if (!srcList || !dstList) {
      return null;
    }
    var fi = from[from.length - 1];
    if (!srcList[fi]) {
      return null;
    }
    var blk = srcList.splice(fi, 1)[0];
    if (dstList === srcList && fi < index) {
      index--;
    }
    index = Math.max(0, Math.min(index, dstList.length));
    dstList.splice(index, 0, blk);
    var np = parent.slice();
    var depth = from.length - 1;
    if (np.length > depth && isPrefix(from.slice(0, -1), np) && np[depth] > fi) {
      np[depth]--;
    }

    return np.concat(index);
  }

  function pickType(ed, type) {
    var m = ed.menu;
    if (!m) {
      return;
    }
    closeMenu(ed);
    var block = blank(type);
    var addr;
    if (m.mode === 'replace') {
      var list = listOf(ed, m.parent);
      if (!list) {
        return;
      }
      list[m.index] = block;
      addr = m.parent.concat(m.index);
    } else {
      addr = insertAt(ed, m.parent, m.index, block);
    }
    if (!addr) {
      return;
    }
    commit(ed, '');
    render(ed, addr);
  }

  // ------------------------------------------------------------------ menú de tipos

  function closeMenu(ed) {
    if (ed && ed.menu) {
      remove(ed.menu.el);
      ed.menu = null;
    }
  }

  function openMenu(ed, host, mode, parent, index) {
    closeMenu(ed);
    var menu = h('div', {'class': 'afb-menu', role: 'dialog', 'aria-label': T('add_block')});
    menu.appendChild(h('input', {type: 'search', 'class': 'af-input', 'data-afb-menuq': '', placeholder: T('search_block'), 'aria-label': T('search_block'), autocomplete: 'off'}));
    var list = h('ul', {'class': 'afb-menu-list'});
    TYPE_ORDER.forEach(function (type) {
      var li = h('li', {'data-type': type});
      li.appendChild(btn(typeName(type), {'class': 'afb-menu-item', 'data-afb-pick': type}));
      list.appendChild(li);
    });
    menu.appendChild(list);
    menu.appendChild(h('p', {'class': 'afb-menu-none af-muted af-small', hidden: true}, T('no_match')));
    host.appendChild(menu);
    ed.menu = {el: menu, mode: mode, parent: parent, index: index, host: host};
    one('[data-afb-menuq]', menu).focus();
  }

  function filterMenu(menu, q) {
    var needle = String(q || '').toLowerCase().trim();
    var shown = 0;
    all('li', menu).forEach(function (li) {
      var type = li.getAttribute('data-type');
      var hit = needle === '' || typeName(type).toLowerCase().indexOf(needle) !== -1 || type.indexOf(needle) !== -1;
      li.hidden = !hit;
      if (hit) {
        shown++;
      }
    });
    var none = one('.afb-menu-none', menu);
    if (none) {
      none.hidden = shown > 0;
    }
  }

  function visibleItems(menu) {
    return all('li', menu).filter(function (li) {
      return !li.hidden;
    }).map(function (li) {
      return one('button', li);
    });
  }

  // ------------------------------------------------------------------ aplicar un campo al modelo

  function applyField(ed, el) {
    var host = closest(el, '.afb-block');
    if (!host) {
      return;
    }
    var addr = parseAddr(host.getAttribute('data-path'));
    var b = blockAt(ed, addr);
    var bind = el.getAttribute('data-afb-bind');
    if (!b || !bind) {
      return;
    }
    var keys = bind.split('.');
    var kind = el.getAttribute('data-afb-kind') || 'rich';
    var v;
    if (kind === 'rich') {
      v = cleanInline(el.innerHTML);
      if (v === '' && el.innerHTML !== '') {
        el.innerHTML = '';
      }
      // Un atributo opcional vacío no se guarda (los textos obligatorios y las celdas de lista, tabla y botones sí).
      if (v === '' && keys.length === 2 && keys[1] !== 'html' && keys[1] !== 'summary') {
        v = undefined;
      }
    } else if (kind === 'bool') {
      v = !!el.checked;
    } else if (kind === 'int') {
      var n = parseInt(el.value, 10);
      v = isNaN(n) ? undefined : n;
    } else if (kind === 'https') {
      var s = String(el.value || '').trim();
      v = /^https:\/\/\S+$/i.test(s) ? s : undefined;
      var bad = s !== '' && v === undefined;
      el.setAttribute('aria-invalid', bad ? 'true' : 'false');
      var warn = closest(el, '.afb-field');
      warn = warn ? one('.afb-warn', warn) : null;
      if (warn) {
        warn.hidden = !bad;
      }
    } else if (kind === 'formid') {
      var f = String(el.value || '').trim();
      v = f === '' ? undefined : (/^\d+$/.test(f) ? parseInt(f, 10) : f);
    } else if (kind === 'raw') {
      v = String(el.value);
    } else {
      var t = String(el.value).trim();
      v = t === '' ? undefined : t;
    }
    setIn(b, keys, v);
    if (b.type === 'embed' && bind === 'attrs.url') {
      b.attrs.provider = providerOf(b.attrs.url);
    }
    if (bind === 'attrs.level') {
      var rich = one('.afb-h', host);
      if (rich) {
        rich.className = 'afb-rich afb-h afb-h' + (parseInt(v, 10) || 2);
      }
    }
    commit(ed, 'f:' + addr.join('.') + ':' + bind);
    if (el.hasAttribute('data-afb-rerender')) {
      window.setTimeout(function () {
        render(ed, addr);
      }, 0);
    }
  }

  // ------------------------------------------------------------------ texto con formato: órdenes y pegado

  function execCmd(cmd, value) {
    if (typeof document.execCommand !== 'function') {
      return false;
    }
    try {
      return document.execCommand(cmd, false, value);
    } catch (e) {
      return false;
    }
  }

  function formatText(ed, cmd) {
    var rich = ed.lastRich;
    if (!rich || !rich.parentNode) {
      return;
    }
    var sel = window.getSelection ? window.getSelection() : null;
    var saved = sel && sel.rangeCount > 0 ? sel.getRangeAt(0).cloneRange() : null;
    if (typeof rich.focus === 'function') {
      rich.focus();
    }
    if (cmd === 'link') {
      var url = window.prompt(T('link_prompt'), 'https://');
      if (sel && saved) {
        sel.removeAllRanges();
        sel.addRange(saved);
      }
      var href = url ? safeHref(url) : '';
      if (href === '') {
        return;
      }
      execCmd('createLink', href);
    } else if (cmd === 'unlink') {
      execCmd('unlink');
    } else {
      execCmd(cmd);
    }
    applyField(ed, rich);
  }

  /** Inserta texto con formato (ya limpio) en el punto del cursor. */
  function insertHtmlAtCaret(el, clean) {
    if (execCmd('insertHTML', clean)) {
      return;
    }
    if (!window.getSelection || !document.createRange) {
      return;
    }
    var sel = window.getSelection();
    var range = sel && sel.rangeCount > 0 ? sel.getRangeAt(0) : null;
    if (!range || !el.contains(range.endContainer)) {
      range = document.createRange();
      range.selectNodeContents(el);
      range.collapse(false);
    }
    range.deleteContents();
    var frag = range.createContextualFragment ? range.createContextualFragment(clean) : null;
    if (frag) {
      var last = frag.lastChild;
      range.insertNode(frag);
      if (last) {
        range.setStartAfter(last);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
      }
    }
  }

  function caretAtEnd(el) {
    var sel = window.getSelection ? window.getSelection() : null;
    if (!sel || sel.rangeCount === 0) {
      return true;
    }
    var r = sel.getRangeAt(0);
    if (!r.collapsed || !el.contains(r.endContainer)) {
      return true;
    }
    var rest = r.cloneRange();
    rest.selectNodeContents(el);
    rest.setStart(r.endContainer, r.endOffset);

    return rest.toString() === '';
  }

  /** Corta el contenido desde el cursor hasta el final y lo devuelve como texto con formato limpio. */
  function splitAtCaret(el) {
    var sel = window.getSelection ? window.getSelection() : null;
    if (!sel || sel.rangeCount === 0 || !document.createRange) {
      return '';
    }
    var r = sel.getRangeAt(0);
    if (!el.contains(r.endContainer)) {
      return '';
    }
    var tail = document.createRange();
    tail.selectNodeContents(el);
    tail.setStart(r.endContainer, r.endOffset);
    var tmp = document.createElement('div');
    tmp.appendChild(tail.extractContents());

    return cleanInline(tmp.innerHTML);
  }

  function chunksOf(html, text) {
    var out = [];
    if (html && typeof window.DOMParser === 'function') {
      var doc = new window.DOMParser().parseFromString('<!doctype html><html><body>' + html + '</body></html>', 'text/html');
      var parts = doc && doc.body ? Array.prototype.slice.call(doc.body.children).filter(function (c) {
        return BLOCKISH[String(c.localName).toLowerCase()] === 1;
      }) : [];
      if (parts.length > 1) {
        parts.forEach(function (c) {
          var s = cleanInline(c.innerHTML);
          if (s !== '') {
            out.push(s);
          }
        });
        return out;
      }
    }
    if (html) {
      return [];
    }
    String(text || '').replace(/\r\n?/g, '\n').split(/\n[ \t]*\n/).forEach(function (part) {
      var p = part.trim();
      if (p !== '') {
        out.push(textToHtml(p));
      }
    });

    return out;
  }

  function importWp(ed, content, replaceAddr) {
    if (!cfg.urls['import']) {
      return;
    }
    Aifec.post(cfg.urls['import'], {content: content}).then(function (res) {
      var blocks = normalize(res && res.blocks, 0, '');
      if (blocks.length === 0) {
        Aifec.toast(T('import_failed'), 'bad');
        return;
      }
      var addr;
      var target = replaceAddr ? blockAt(ed, replaceAddr) : null;
      if (target && target.type === 'paragraph' && String(target.attrs.html || '') === '') {
        var list = listOf(ed, replaceAddr.slice(0, -1));
        var at = replaceAddr[replaceAddr.length - 1];
        Array.prototype.splice.apply(list, [at, 1].concat(blocks));
        addr = replaceAddr.slice(0, -1).concat(at);
      } else if (replaceAddr) {
        var after = replaceAddr[replaceAddr.length - 1] + 1;
        var plist = listOf(ed, replaceAddr.slice(0, -1));
        Array.prototype.splice.apply(plist, [after, 0].concat(blocks));
        addr = replaceAddr.slice(0, -1).concat(after);
      } else {
        ed.blocks = ed.blocks.concat(blocks);
        addr = [ed.blocks.length - blocks.length];
      }
      commit(ed, '');
      render(ed, addr);
      Aifec.toast(T('imported', {n: blocks.length, r: countReview(blocks)}));
    }, function () {
      Aifec.toast(T('import_failed'), 'bad');
    });
  }

  // ------------------------------------------------------------------ vista previa

  function schedulePreview(ed) {
    if (!ed.previewOpen) {
      return;
    }
    window.clearTimeout(ed.previewTimer);
    ed.previewTimer = window.setTimeout(function () {
      refreshPreview(ed);
    }, 800);
  }

  function refreshPreview(ed) {
    if (!ed.previewOpen || !cfg.urls.render) {
      return;
    }
    var seq = ++ed.previewSeq;
    Aifec.post(cfg.urls.render, {blocks: JSON.stringify(ed.blocks)}).then(function (res) {
      if (seq !== ed.previewSeq || !ed.previewOpen) {
        return;
      }
      var html = res && typeof res.html === 'string' ? res.html : '';
      // El documento va en un iframe sin permisos (sandbox vacío): nada de lo que traiga puede ejecutarse.
      ed.frame.srcdoc = '<!doctype html><meta charset="utf-8"><style>' + PREVIEW_CSS + '</style><body>' + html;
    }, function () {
      Aifec.toast(T('preview_failed'), 'bad');
    });
  }

  // ------------------------------------------------------------------ acciones de los botones

  function blockOf(btnEl) {
    var host = closest(btnEl, '.afb-block');

    return host ? parseAddr(host.getAttribute('data-path')) : null;
  }

  function afterChange(ed, addr, bind) {
    commit(ed, '');
    render(ed, addr, bind);
  }

  function ensureArray(obj, key) {
    if (!Array.isArray(obj.attrs[key])) {
      obj.attrs[key] = [];
    }

    return obj.attrs[key];
  }

  function tableCols(b) {
    var cols = 0;
    [].concat(Array.isArray(b.attrs.head) ? b.attrs.head : [], Array.isArray(b.attrs.rows) ? b.attrs.rows : []).forEach(function (r) {
      cols = Math.max(cols, Array.isArray(r) ? r.length : 0);
    });

    return cols;
  }

  function act(ed, name, el) {
    var addr = blockOf(el);
    var b = addr ? blockAt(ed, addr) : null;
    var index = parseInt(el.getAttribute('data-index'), 10);
    var live = one('.afb-live', ed.root);
    switch (name) {
      case 'undo':
        travel(ed, -1);
        return;
      case 'redo':
        travel(ed, 1);
        return;
      case 'wp-toggle':
        ed.wp.hidden = !ed.wp.hidden;
        if (!ed.wp.hidden) {
          one('[data-afb-wp]', ed.wp).focus();
        }
        return;
      case 'wp-do':
        var area = one('[data-afb-wp]', ed.wp);
        if (String(area.value).trim() !== '') {
          importWp(ed, area.value, null);
          area.value = '';
          ed.wp.hidden = true;
        }
        return;
      case 'preview':
        ed.previewOpen = !ed.previewOpen;
        ed.panel.hidden = !ed.previewOpen;
        el.setAttribute('aria-pressed', ed.previewOpen ? 'true' : 'false');
        ed.root.classList.toggle('afb-with-preview', ed.previewOpen);
        refreshPreview(ed);
        return;
      case 'insert':
        var zone = closest(el, '.afb-insert') || closest(el, '.afb-empty');
        if (!zone) {
          return;
        }
        if (ed.menu && ed.menu.host === zone) {
          closeMenu(ed);
          return;
        }
        openMenu(ed, zone, 'insert', parseAddr(zone.getAttribute('data-parent')), parseInt(zone.getAttribute('data-index'), 10) || 0);
        return;
      case 'up':
      case 'down':
        if (!addr) {
          return;
        }
        var i = addr[addr.length - 1];
        var parent = addr.slice(0, -1);
        var list = listOf(ed, parent);
        if (!list || (name === 'up' && i === 0) || (name === 'down' && i >= list.length - 1)) {
          return;
        }
        var moved = moveBlock(ed, addr, parent, name === 'up' ? i - 1 : i + 2);
        if (moved) {
          afterChange(ed, moved);
          if (live) {
            live.textContent = T('block_moved');
          }
        }
        return;
      case 'dup':
        if (!b) {
          return;
        }
        var copy = insertAt(ed, addr.slice(0, -1), addr[addr.length - 1] + 1, clone(b));
        afterChange(ed, copy);
        return;
      case 'del':
        if (!b) {
          return;
        }
        removeAt(ed, addr);
        commit(ed, '');
        render(ed);
        var prev = addr[addr.length - 1] > 0 ? addr.slice(0, -1).concat(addr[addr.length - 1] - 1) : null;
        if (prev) {
          focusBlock(ed, prev);
        }
        return;
      case 'li-add':
      case 'li-del':
        if (!b) {
          return;
        }
        var lkeys = el.getAttribute('data-list').split('.');
        var items = getIn(b, lkeys);
        if (!Array.isArray(items)) {
          items = [];
          setIn(b, lkeys, items);
        }
        if (name === 'li-add') {
          items.splice(index, 0, {html: ''});
          afterChange(ed, addr, el.getAttribute('data-list') + '.' + index + '.html');
        } else {
          items.splice(index, 1);
          if (items.length === 0 && el.getAttribute('data-list') === 'attrs.items') {
            items.push({html: ''});
          }
          afterChange(ed, addr);
        }
        return;
      case 'tr-add':
      case 'tr-del':
      case 'tc-add':
      case 'tc-del':
      case 'th-toggle':
        if (!b) {
          return;
        }
        tableAction(ed, b, name, index, el);
        afterChange(ed, addr);
        return;
      case 'bt-add':
        if (!b) {
          return;
        }
        ensureArray(b, 'items').push({text: '', href: '', variant: 'fill'});
        afterChange(ed, addr);
        return;
      case 'bt-del':
        if (!b) {
          return;
        }
        ensureArray(b, 'items').splice(index, 1);
        afterChange(ed, addr);
        return;
      case 'gi-del':
        if (!b) {
          return;
        }
        ensureArray(b, 'images').splice(index, 1);
        afterChange(ed, addr);
        return;
      case 'pr-add':
        if (!b) {
          return;
        }
        var pid = parseInt(el.getAttribute('data-id'), 10);
        if (!(pid > 0)) {
          return;
        }
        ed.labels[pid] = el.getAttribute('data-label') || '';
        if (b.type === 'product') {
          b.attrs.id = pid;
        } else {
          var ids = ensureArray(b, 'ids');
          if (ids.indexOf(pid) === -1) {
            ids.push(pid);
          }
        }
        afterChange(ed, addr);
        return;
      case 'pr-del':
        if (!b) {
          return;
        }
        var did = parseInt(el.getAttribute('data-id'), 10);
        if (b.type === 'product') {
          delete b.attrs.id;
        } else {
          b.attrs.ids = ensureArray(b, 'ids').filter(function (x) {
            return parseInt(x, 10) !== did;
          });
        }
        afterChange(ed, addr);
        return;
      default:
        return;
    }
  }

  function tableAction(ed, b, name, index, el) {
    var head = Array.isArray(b.attrs.head) ? b.attrs.head : [];
    var rows = ensureArray(b, 'rows');
    var cols = tableCols(b);
    function fit(r) {
      var cells = Array.isArray(r) ? r.slice() : [];
      while (cells.length < cols) {
        cells.push('');
      }

      return cells;
    }
    if (name === 'th-toggle') {
      if (el.checked) {
        var first = [];
        for (var k = 0; k < Math.max(cols, 1); k++) {
          first.push('');
        }
        b.attrs.head = [first];
      } else {
        delete b.attrs.head;
      }
    } else if (name === 'tr-add') {
      var blankRow = [];
      for (var j = 0; j < Math.max(cols, 1); j++) {
        blankRow.push('');
      }
      rows.push(blankRow);
    } else if (name === 'tr-del') {
      if (rows.length > 1) {
        rows.splice(index, 1);
      }
    } else if (name === 'tc-add') {
      b.attrs.rows = rows.map(fit).map(function (r) {
        return r.concat(['']);
      });
      if (head.length) {
        b.attrs.head = head.map(fit).map(function (r) {
          return r.concat(['']);
        });
      }
    } else if (name === 'tc-del') {
      if (cols > 1) {
        b.attrs.rows = rows.map(fit).map(function (r) {
          r.splice(index, 1);
          return r;
        });
        if (head.length) {
          b.attrs.head = head.map(fit).map(function (r) {
            r.splice(index, 1);
            return r;
          });
        }
      }
    }
  }

  /** Cambia el número de columnas de un bloque «columns»; las que sobran pasan sus bloques a la última que se queda. */
  function setColumns(ed, addr, n) {
    var b = blockAt(ed, addr);
    if (!b || !(n >= 1 && n <= 12)) {
      return;
    }
    while (b.children.length < n) {
      b.children.push(mk('column', {}, []));
    }
    if (b.children.length > n) {
      var gone = b.children.splice(n);
      var keep = b.children[n - 1];
      gone.forEach(function (col) {
        keep.children = keep.children.concat(col.children);
      });
    }
    afterChange(ed, addr);
  }

  // ------------------------------------------------------------------ arrastrar y soltar

  function dropTarget(ed, el, e) {
    if (el.hasAttribute('data-path') && el.classList.contains('afb-block')) {
      var addr = parseAddr(el.getAttribute('data-path'));
      var rect = el.getBoundingClientRect();
      var after = typeof e.clientY === 'number' && rect.height > 0 && e.clientY > rect.top + rect.height / 2;

      return {el: el, cls: after ? 'afb-drop-after' : 'afb-drop-before', parent: addr.slice(0, -1), index: addr[addr.length - 1] + (after ? 1 : 0)};
    }
    var parent = parseAddr(el.getAttribute('data-parent'));
    var index = parseInt(el.getAttribute('data-index'), 10) || 0;

    return {el: el, cls: 'afb-drop-here', parent: parent, index: index};
  }

  function clearDrop(ed) {
    all('.afb-drop-before, .afb-drop-after, .afb-drop-here, .afb-dragging', ed.root).forEach(function (n) {
      n.classList.remove('afb-drop-before', 'afb-drop-after', 'afb-drop-here', 'afb-dragging');
    });
  }

  // ------------------------------------------------------------------ puesta en marcha de un editor

  function init(ta) {
    if (!ta || ta.nodeType !== 1 || String(ta.tagName).toLowerCase() !== 'textarea') {
      return null;
    }
    if (ta.__afb) {
      return ta.__afb.root;
    }
    loadConfig();
    var ed = {
      ta: ta, blocks: parseBlocks(ta.value), history: [], hi: 0, lastKey: '', lastTime: 0, menu: null, root: null, canvas: null,
      labels: {}, previewOpen: false, previewSeq: 0, previewTimer: 0, lastRich: null, undoBtn: null, redoBtn: null, frame: null, panel: null, wp: null
    };
    ed.history = [JSON.stringify(ed.blocks)];
    ta.__afb = ed;
    build(ed);
    render(ed);

    return ed.root;
  }

  // ------------------------------------------------------------------ eventos (todos por delegación)

  on('input', '[data-afb-bind]', function (e, el) {
    var ed = editorOf(el);
    if (ed) {
      applyField(ed, el);
    }
  });
  on('change', '[data-afb-bind]', function (e, el) {
    var ed = editorOf(el);
    if (ed) {
      applyField(ed, el);
    }
  });

  on('focus', '[data-afb-rich]', function (e, el) {
    var ed = editorOf(el);
    if (ed) {
      ed.lastRich = el;
    }
  });

  on('mousedown', '[data-afb-fmt]', function (e) {
    e.preventDefault();
  });
  on('click', '[data-afb-fmt]', function (e, el) {
    var ed = editorOf(el);
    if (ed) {
      e.preventDefault();
      formatText(ed, el.getAttribute('data-afb-fmt'));
    }
  });

  on('click', '[data-afb-act]', function (e, el) {
    var ed = editorOf(el);
    if (!ed || el.type === 'checkbox') {
      return;
    }
    e.preventDefault();
    act(ed, el.getAttribute('data-afb-act'), el);
  });
  on('change', 'input[data-afb-act]', function (e, el) {
    var ed = editorOf(el);
    if (ed && el.type === 'checkbox') {
      act(ed, el.getAttribute('data-afb-act'), el);
    }
  });

  on('change', '[data-afb-cols]', function (e, el) {
    var ed = editorOf(el);
    var addr = blockOf(el);
    if (ed && addr) {
      setColumns(ed, addr, parseInt(el.value, 10));
    }
  });

  on('click', '[data-afb-pick]', function (e, el) {
    var ed = editorOf(el);
    if (ed) {
      e.preventDefault();
      pickType(ed, el.getAttribute('data-afb-pick'));
    }
  });

  // Un clic fuera del menú de tipos lo cierra.
  on('click', null, function (e) {
    var t = e.target;
    if (closest(t, '.afb-menu') || closest(t, '[data-afb-act="insert"]')) {
      return;
    }
    all('[data-afb-root]').forEach(function (root) {
      if (root.__afb) {
        closeMenu(root.__afb);
      }
    });
  });

  on('input', '[data-afb-menuq]', function (e, el) {
    var menu = closest(el, '.afb-menu');
    if (menu) {
      filterMenu(menu, el.value);
    }
  });

  on('keydown', '[data-afb-menuq]', function (e, el) {
    var ed = editorOf(el);
    var menu = closest(el, '.afb-menu');
    if (!ed || !menu) {
      return;
    }
    if (e.key === 'Escape') {
      e.preventDefault();
      var host = ed.menu ? ed.menu.host : null;
      closeMenu(ed);
      var opener = host ? one('[data-afb-act="insert"]', host) : null;
      if (opener) {
        opener.focus();
      }
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      var items = visibleItems(menu);
      if (items.length) {
        items[0].focus();
      }
    } else if (e.key === 'Enter') {
      e.preventDefault();
      var first = visibleItems(menu)[0];
      if (first) {
        pickType(ed, first.getAttribute('data-afb-pick'));
      }
    }
  });

  on('keydown', '[data-afb-pick]', function (e, el) {
    var menu = closest(el, '.afb-menu');
    if (!menu || (e.key !== 'ArrowDown' && e.key !== 'ArrowUp')) {
      return;
    }
    e.preventDefault();
    var items = visibleItems(menu);
    var at = items.indexOf(el);
    var next = e.key === 'ArrowDown' ? items[at + 1] : items[at - 1];
    if (next) {
      next.focus();
    } else if (e.key === 'ArrowUp') {
      one('[data-afb-menuq]', menu).focus();
    }
  });

  // Atajos del editor: deshacer y rehacer, mover el bloque con Alt + flechas y Intro dentro de un campo de una línea.
  on('keydown', '[data-afb-root]', function (e, root) {
    var ed = root.__afb;
    if (!ed) {
      return;
    }
    var key = String(e.key || '');
    var mod = e.ctrlKey || e.metaKey;
    if (mod && !e.altKey && key.toLowerCase() === 'z') {
      e.preventDefault();
      travel(ed, e.shiftKey ? 1 : -1);
      return false;
    }
    if (mod && !e.altKey && key.toLowerCase() === 'y') {
      e.preventDefault();
      travel(ed, 1);
      return false;
    }
    if (e.altKey && !mod && (key === 'ArrowUp' || key === 'ArrowDown')) {
      var host = closest(e.target, '.afb-block');
      if (host) {
        e.preventDefault();
        var el = one('[data-afb-act="' + (key === 'ArrowUp' ? 'up' : 'down') + '"]', host);
        if (el && !el.disabled) {
          act(ed, key === 'ArrowUp' ? 'up' : 'down', el);
        }
      }
      return false;
    }
    if (key === 'Enter' && e.target && e.target.tagName === 'INPUT' && !e.target.hasAttribute('data-afb-menuq')) {
      e.preventDefault();
    }
  });

  on('keydown', '[data-afb-rich]', function (e, el) {
    var ed = editorOf(el);
    var host = closest(el, '.afb-block');
    if (!ed || !host) {
      return;
    }
    var addr = parseAddr(host.getAttribute('data-path'));
    var b = blockAt(ed, addr);
    if (!b) {
      return;
    }
    var key = String(e.key || '');
    var mod = e.ctrlKey || e.metaKey;
    if (mod && !e.altKey && key.toLowerCase() === 'k') {
      e.preventDefault();
      ed.lastRich = el;
      formatText(ed, 'link');
      return;
    }
    if (mod || e.altKey) {
      return;
    }
    var bind = el.getAttribute('data-afb-bind');
    var parent = addr.slice(0, -1);
    var at = addr[addr.length - 1];

    if (key === '/' && b.type === 'paragraph' && bind === 'attrs.html' && isEmptyText(el)) {
      e.preventDefault();
      openMenu(ed, one('.afb-body', host), 'replace', parent, at);
      return;
    }
    if (key === 'Enter' && !e.shiftKey) {
      var keys = String(bind).split('.');
      if (el.classList.contains('afb-li')) {
        e.preventDefault();
        var lkeys = keys.slice(0, -2);
        var items = getIn(b, lkeys);
        var n = parseInt(keys[keys.length - 2], 10);
        if (Array.isArray(items)) {
          applyField(ed, el);
          items.splice(n + 1, 0, {html: ''});
          afterChange(ed, addr, lkeys.join('.') + '.' + (n + 1) + '.html');
        }
        return;
      }
      if ((b.type === 'paragraph' && bind === 'attrs.html') || (b.type === 'heading' && bind === 'attrs.html')) {
        e.preventDefault();
        var rest = caretAtEnd(el) ? '' : splitAtCaret(el);
        applyField(ed, el);
        var created = insertAt(ed, parent, at + 1, mk('paragraph', {html: rest}));
        afterChange(ed, created, 'attrs.html');
        if (rest !== '') {
          focusBlock(ed, created, 'attrs.html', true);
        }
        return;
      }
      if (el.hasAttribute('data-afb-single')) {
        e.preventDefault();
        return;
      }
      e.preventDefault();
      if (!execCmd('insertLineBreak')) {
        insertHtmlAtCaret(el, '<br>');
        applyField(ed, el);
      }
      return;
    }
    if (key === 'Enter' && e.shiftKey && el.hasAttribute('data-afb-single')) {
      e.preventDefault();
      return;
    }
    if (key === 'Backspace' && isEmptyText(el)) {
      var bk = String(bind).split('.');
      if (el.classList.contains('afb-li')) {
        var lk = bk.slice(0, -2);
        var list = getIn(b, lk);
        var ix = parseInt(bk[bk.length - 2], 10);
        if (Array.isArray(list) && list.length > 1) {
          e.preventDefault();
          list.splice(ix, 1);
          var prev = Math.max(0, ix - 1);
          afterChange(ed, addr, lk.join('.') + '.' + prev + '.html');
        }
        return;
      }
      if (b.type === 'paragraph' && bind === 'attrs.html' && at > 0) {
        e.preventDefault();
        removeAt(ed, addr);
        commit(ed, '');
        render(ed);
        focusBlock(ed, parent.concat(at - 1));
      }
    }
  });

  on('paste', '[data-afb-rich]', function (e, el) {
    var ed = editorOf(el);
    var host = closest(el, '.afb-block');
    var cd = e.clipboardData || window.clipboardData;
    if (!ed || !host || !cd) {
      return;
    }
    var addr = parseAddr(host.getAttribute('data-path'));
    var b = blockAt(ed, addr);
    var text = String(cd.getData('text/plain') || '');
    var html = String(cd.getData('text/html') || '');
    if (!b) {
      return;
    }
    if (text.indexOf('<!-- wp:') !== -1 || html.indexOf('<!-- wp:') !== -1) {
      e.preventDefault();
      importWp(ed, text.indexOf('<!-- wp:') !== -1 ? text : html, addr);
      return;
    }
    e.preventDefault();
    var single = el.hasAttribute('data-afb-single');
    var chunks = (b.type === 'paragraph' && el.getAttribute('data-afb-bind') === 'attrs.html') ? chunksOf(html, text) : [];
    if (chunks.length > 1) {
      insertHtmlAtCaret(el, chunks[0]);
      applyField(ed, el);
      var parent = addr.slice(0, -1);
      var at = addr[addr.length - 1] + 1;
      var made = chunks.slice(1).map(function (c) {
        return mk('paragraph', {html: c}, []);
      });
      Array.prototype.splice.apply(listOf(ed, parent), [at, 0].concat(made));
      afterChange(ed, parent.concat(at + made.length - 1), 'attrs.html');
      return;
    }
    var clean = html !== '' ? cleanInline(html) : textToHtml(text);
    if (single) {
      clean = clean.replace(/<br>/g, ' ');
    }
    insertHtmlAtCaret(el, clean);
    applyField(ed, el);
  });

  // Selector de imágenes (media.js escribe los identificadores en el campo oculto y avisa con «change»).
  on('change', '[data-afb-media] [data-af-media-input]', function (e, input) {
    var host = closest(input, '[data-afb-media]');
    var ed = editorOf(host);
    var addr = blockOf(host);
    var b = ed && addr ? blockAt(ed, addr) : null;
    if (!b) {
      return;
    }
    var ids = String(input.value || '').split(',').map(function (x) {
      return parseInt(x, 10);
    }).filter(function (n) {
      return n > 0;
    });
    all('.af-thumb-item', host).forEach(function (item) {
      var img = one('img', item);
      var id = parseInt(item.getAttribute('data-id'), 10);
      if (img && id > 0 && img.getAttribute('src')) {
        thumbs[id] = img.getAttribute('src');
      }
    });
    var kind = host.getAttribute('data-afb-media');
    if (kind === 'gallery') {
      var old = Array.isArray(b.attrs.images) ? b.attrs.images : [];
      var next = ids.map(function (id) {
        for (var i = 0; i < old.length; i++) {
          if (isObject(old[i]) && parseInt(old[i].media_id, 10) === id) {
            return old[i];
          }
        }

        return {media_id: id};
      });
      old.forEach(function (im) {
        if (isObject(im) && !(parseInt(im.media_id, 10) > 0)) {
          next.push(im);
        }
      });
      b.attrs.images = next;
    } else if (ids.length) {
      b.attrs.media_id = ids[0];
      delete b.attrs.src;
    } else {
      delete b.attrs.media_id;
    }
    commit(ed, '');
    window.setTimeout(function () {
      render(ed, addr);
    }, 0);
  });

  // Buscador de productos (ruta de búsqueda de recursos).
  var searchTimers = {};
  on('input', '[data-afb-psearch]', function (e, input) {
    var ed = editorOf(input);
    var box = closest(input, '.afb-picker');
    var results = box ? one('[data-afb-results]', box) : null;
    if (!ed || !results || !cfg.urls.search) {
      return;
    }
    var host = closest(input, '.afb-block');
    var key = host ? host.getAttribute('data-path') : '';
    window.clearTimeout(searchTimers[key]);
    var q = String(input.value || '').trim();
    if (q === '') {
      results.hidden = true;
      results.innerHTML = '';
      return;
    }
    searchTimers[key] = window.setTimeout(function () {
      var url = cfg.urls.search + (cfg.urls.search.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q);
      Aifec.get(url).then(function (res) {
        results.innerHTML = '';
        var items = res && Array.isArray(res.items) ? res.items : [];
        if (items.length === 0) {
          results.appendChild(h('li', {'class': 'afb-results-none af-muted'}, T('no_results')));
        }
        items.forEach(function (it) {
          if (!isObject(it)) {
            return;
          }
          results.appendChild(h('li', null, btn(String(it.label === undefined ? it.id : it.label), {
            'class': 'afb-result', 'data-afb-act': 'pr-add', 'data-id': String(it.id), 'data-label': String(it.label === undefined ? '' : it.label)
          })));
        });
        results.hidden = false;
      }, function () {
        results.hidden = true;
      });
    }, 250);
  });

  // Mover bloques arrastrando su asa.
  on('dragstart', '[data-afb-drag]', function (e, handle) {
    var ed = editorOf(handle);
    var addr = blockOf(handle);
    if (!ed || !addr) {
      return;
    }
    drag = {ed: ed, addr: addr};
    if (e.dataTransfer) {
      e.dataTransfer.effectAllowed = 'move';
      try {
        e.dataTransfer.setData('text/plain', 'afb');
      } catch (er) {
        // algunos navegadores no dejan escribir aquí
      }
      var host = closest(handle, '.afb-block');
      if (host && typeof e.dataTransfer.setDragImage === 'function') {
        try {
          e.dataTransfer.setDragImage(host, 12, 12);
        } catch (er2) {
          // sin imagen de arrastre
        }
      }
    }
    var card = closest(handle, '.afb-block');
    if (card) {
      card.classList.add('afb-dragging');
    }
  });

  on('dragover', '[data-afb-drop]', function (e, el) {
    var ed = editorOf(el);
    if (!drag || !ed || drag.ed !== ed) {
      return;
    }
    var t = dropTarget(ed, el, e);
    if (isPrefix(drag.addr, t.parent) || (t.parent.join('.') === drag.addr.slice(0, -1).join('.') && (t.index === drag.addr[drag.addr.length - 1] || t.index === drag.addr[drag.addr.length - 1] + 1))) {
      return;
    }
    e.preventDefault();
    all('.afb-drop-before, .afb-drop-after, .afb-drop-here', ed.root).forEach(function (n) {
      n.classList.remove('afb-drop-before', 'afb-drop-after', 'afb-drop-here');
    });
    t.el.classList.add(t.cls);
    return false;
  });

  on('drop', '[data-afb-drop]', function (e, el) {
    var ed = editorOf(el);
    if (!drag || !ed || drag.ed !== ed) {
      return;
    }
    e.preventDefault();
    var t = dropTarget(ed, el, e);
    var from = drag.addr;
    drag = null;
    clearDrop(ed);
    var moved = moveBlock(ed, from, t.parent, t.index);
    if (moved) {
      afterChange(ed, moved);
    }
    return false;
  });

  on('dragend', '[data-afb-drag]', function (e, handle) {
    var ed = editorOf(handle);
    drag = null;
    if (ed) {
      clearDrop(ed);
    }
  });

  // Antes de enviar el formulario, el área lleva el JSON al día (por si algo quedó pendiente).
  on('submit', 'form', function (e, form) {
    all('[data-afb-root]', form).forEach(function (root) {
      if (root.__afb) {
        root.__afb.ta.value = JSON.stringify(root.__afb.blocks);
      }
    });
  });

  // ------------------------------------------------------------------ arranque

  function boot() {
    var areas = all('textarea[data-af-blocks]');
    if (areas.length === 0) {
      return;
    }
    areas.forEach(init);
  }

  Aifec.blocks = {init: init};

  if (document.readyState === 'loading' && typeof document.addEventListener === 'function') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(typeof window !== 'undefined' ? window : null, typeof document !== 'undefined' ? document : null);

/**
 * AIFEC — selector de imágenes del panel y zonas de subida.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Da vida a lo que pinta Form::mediaControl():
 *   [data-af-media]            contenedor del campo (data-multiple, data-picker = dirección del fragmento)
 *   [data-af-media-input]      campo oculto con los identificadores separados por comas
 *   [data-af-media-list]       miniaturas elegidas
 *   [data-af-media-pick]       botón que abre la ventana
 *   [data-af-media-remove]     quita una miniatura
 * y a las zonas de subida ([data-af-dropzone] con un [data-af-upload-input]).
 *
 * Todo va por delegación sobre document (Aifec.on): lo que se pinta después funciona sin conectar nada.
 * Ningún texto visible está escrito aquí: llegan en atributos data-t-* / data-text-* del fragmento del servidor.
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

  /** Selector en curso: {host, dialog, selected: [{id, thumb}], multiple, url, q, page, texts}. */
  var picker = null;
  /** Arrastre de una miniatura: {item, before}. */
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

  function fire(el, type) {
    var ev;
    if (typeof window.Event === 'function') {
      ev = new window.Event(type, {bubbles: true});
    } else {
      ev = document.createEvent('Event');
      ev.initEvent(type, true, true);
    }
    el.dispatchEvent(ev);
  }

  function make(tag, className, attrs) {
    var el = document.createElement(tag);
    if (className) {
      el.className = className;
    }
    if (attrs) {
      for (var k in attrs) {
        if (Object.prototype.hasOwnProperty.call(attrs, k)) {
          el.setAttribute(k, attrs[k]);
        }
      }
    }
    return el;
  }

  // ------------------------------------------------------------------ campo de imagen (miniaturas)

  function currentIds(host) {
    var input = one('[data-af-media-input]', host);
    var raw = input ? String(input.value) : '';
    return raw === '' ? [] : raw.split(',').filter(function (s) {
      return s !== '';
    });
  }

  /** Reescribe el campo oculto con lo que hay en la lista, en ese orden. */
  function syncInput(host) {
    var input = one('[data-af-media-input]', host);
    var list = one('[data-af-media-list]', host);
    if (!input || !list) {
      return;
    }
    input.value = all('.af-thumb-item', list).map(function (item) {
      return item.getAttribute('data-id');
    }).join(',');
    fire(input, 'change');
  }

  function prepareList(host) {
    var multiple = host.getAttribute('data-multiple') === '1';
    all('.af-thumb-item', host).forEach(function (item) {
      if (multiple) {
        item.setAttribute('tabindex', '0');
      }
    });
  }

  function prepareAll() {
    all('[data-af-media]').forEach(prepareList);
  }

  function thumbItem(selected, removeLabel, multiple) {
    var item = make('div', 'af-thumb-item', {'data-id': String(selected.id)});
    var img = make('img', 'af-thumb', {alt: '', loading: 'lazy', draggable: 'false'});
    img.src = selected.thumb;
    item.appendChild(img);
    var remove = make('button', '', {type: 'button', 'data-af-media-remove': '', 'aria-label': removeLabel || ''});
    remove.textContent = '×';
    item.appendChild(remove);
    if (multiple) {
      item.setAttribute('tabindex', '0');
    }
    return item;
  }

  on('click', '[data-af-media-remove]', function (e, btn) {
    e.preventDefault();
    var host = closest(btn, '[data-af-media]');
    var item = closest(btn, '.af-thumb-item');
    if (host && item && item.parentNode) {
      item.parentNode.removeChild(item);
      syncInput(host);
    }
  });

  // ------------------------------------------------------------------ ventana del selector

  function pickerUrl() {
    var base = picker.url;
    return base + (base.indexOf('?') === -1 ? '?' : '&') + 'multiple=' + (picker.multiple ? '1' : '0')
      + '&q=' + encodeURIComponent(picker.q) + '&page=' + encodeURIComponent(String(picker.page));
  }

  function cleanup(dlg) {
    if (dlg && dlg.parentNode) {
      dlg.parentNode.removeChild(dlg);
    }
    if (picker && (!dlg || picker.dialog === dlg || picker.dialog === null)) {
      picker = null;
    }
  }

  function closePicker() {
    if (!picker) {
      return;
    }
    var dlg = picker.dialog;
    if (dlg && dlg.open && typeof dlg.close === 'function') {
      dlg.close();
    }
    cleanup(dlg);
  }

  function readTexts(root) {
    var names = ['title', 'cancel', 'select', 'close', 'remove', 'uploading', 'uploaded', 'failed', 'loading'];
    var texts = {};
    names.forEach(function (n) {
      texts[n] = root.getAttribute('data-t-' + n) || '';
    });
    return texts;
  }

  function buildDialog() {
    var dlg = make('dialog', 'af-modal af-modal-lg af-picker-modal', {'data-af-media-dialog': '', 'aria-label': picker.texts.title});
    var head = make('div', 'af-modal-head');
    var title = make('h2');
    title.textContent = picker.texts.title;
    var close = make('button', 'af-icon-btn', {type: 'button', 'data-af-picker-close': '', 'aria-label': picker.texts.close});
    close.textContent = '×';
    head.appendChild(title);
    head.appendChild(close);
    var body = make('div', 'af-modal-body', {'data-af-picker-body': ''});
    var foot = make('div', 'af-modal-foot');
    var cancel = make('button', 'af-btn', {type: 'button', 'data-af-picker-cancel': ''});
    cancel.textContent = picker.texts.cancel;
    foot.appendChild(cancel);
    if (picker.multiple) {
      var confirm = make('button', 'af-btn af-btn-primary', {type: 'button', 'data-af-picker-confirm': '', disabled: 'disabled'});
      confirm.textContent = picker.texts.select;
      foot.appendChild(confirm);
    }
    dlg.appendChild(head);
    dlg.appendChild(body);
    dlg.appendChild(foot);
    document.body.appendChild(dlg);
    return dlg;
  }

  function isSelected(id) {
    return picker.selected.some(function (s) {
      return String(s.id) === String(id);
    });
  }

  function paint() {
    if (!picker || !picker.dialog) {
      return;
    }
    all('[data-af-pick]', picker.dialog).forEach(function (btn) {
      var active = isSelected(btn.getAttribute('data-id'));
      btn.classList.toggle('is-selected', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    var confirm = one('[data-af-picker-confirm]', picker.dialog);
    if (confirm) {
      if (picker.selected.length > 0) {
        confirm.removeAttribute('disabled');
      } else {
        confirm.setAttribute('disabled', 'disabled');
      }
    }
  }

  function show(html, first) {
    if (!picker) {
      return;
    }
    var holder = make('div');
    holder.innerHTML = html;
    var root = one('[data-af-picker]', holder);
    if (!root) {
      closePicker();
      return;
    }
    if (first) {
      picker.texts = readTexts(root);
      picker.dialog = buildDialog();
      if (typeof picker.dialog.showModal === 'function') {
        picker.dialog.showModal();
      } else {
        picker.dialog.setAttribute('open', 'open');
      }
    }
    var body = one('[data-af-picker-body]', picker.dialog);
    body.innerHTML = '';
    body.appendChild(root);
    paint();
  }

  function load(first) {
    if (!picker || typeof window.fetch !== 'function') {
      return;
    }
    window.fetch(pickerUrl(), {credentials: 'same-origin', cache: 'no-store', headers: {'X-Requested-With': 'fetch'}})
      .then(function (res) {
        if (!res.ok) {
          throw new Error(String(res.status));
        }
        return res.text();
      })
      .then(function (html) {
        show(html, first);
      }, function () {
        if (first) {
          picker = null;
        }
      });
  }

  on('click', '[data-af-media-pick]', function (e, btn) {
    e.preventDefault();
    var host = closest(btn, '[data-af-media]');
    var url = host ? host.getAttribute('data-picker') : '';
    if (!host || !url || picker) {
      return;
    }
    picker = {
      host: host,
      dialog: null,
      selected: [],
      multiple: host.getAttribute('data-multiple') === '1',
      url: url,
      q: '',
      page: 1,
      texts: {}
    };
    load(true);
  });

  /** Escribe lo elegido en el campo del formulario y cierra la ventana. */
  function commit() {
    if (!picker) {
      return;
    }
    var host = picker.host;
    var input = one('[data-af-media-input]', host);
    var list = one('[data-af-media-list]', host);
    if (input && list) {
      var ids = picker.multiple ? currentIds(host) : [];
      if (!picker.multiple) {
        list.innerHTML = '';
      }
      picker.selected.forEach(function (s) {
        var id = String(s.id);
        if (ids.indexOf(id) === -1) {
          ids.push(id);
          list.appendChild(thumbItem(s, picker.texts.remove, picker.multiple));
        }
      });
      input.value = ids.join(',');
      fire(input, 'change');
    }
    closePicker();
  }

  function addSelected(id, thumb) {
    if (!isSelected(id)) {
      picker.selected.push({id: String(id), thumb: thumb});
    }
  }

  on('click', '[data-af-pick]', function (e, btn) {
    if (!picker) {
      return;
    }
    e.preventDefault();
    var id = btn.getAttribute('data-id');
    var thumb = btn.getAttribute('data-thumb');
    if (!picker.multiple) {
      picker.selected = [{id: String(id), thumb: thumb}];
      commit();
      return;
    }
    if (isSelected(id)) {
      picker.selected = picker.selected.filter(function (s) {
        return String(s.id) !== String(id);
      });
    } else {
      addSelected(id, thumb);
    }
    paint();
  });

  on('click', '[data-af-picker-confirm]', function (e) {
    e.preventDefault();
    commit();
  });

  on('click', '[data-af-picker-cancel], [data-af-picker-close]', function (e) {
    e.preventDefault();
    closePicker();
  });

  on('close', 'dialog[data-af-media-dialog]', function (e, dlg) {
    cleanup(dlg);
  });

  on('submit', '[data-af-picker-search]', function (e, form) {
    e.preventDefault();
    if (!picker) {
      return;
    }
    var field = one('input[name="q"]', form);
    picker.q = field ? String(field.value) : '';
    picker.page = 1;
    load(false);
  });

  on('click', '[data-af-picker-page]', function (e, btn) {
    e.preventDefault();
    if (!picker || btn.hasAttribute('disabled')) {
      return;
    }
    var page = parseInt(btn.getAttribute('data-af-picker-page'), 10);
    picker.page = page > 0 ? page : 1;
    load(false);
  });

  // ------------------------------------------------------------------ subida

  function upload(zone, files) {
    var url = zone.getAttribute('data-upload-url');
    if (!url || !files || !files.length) {
      return;
    }
    var data = new window.FormData();
    for (var i = 0; i < files.length; i++) {
      data.append('files[]', files[i]);
    }
    zone.classList.add('is-busy');
    Aifec.toast(zone.getAttribute('data-text-uploading'));
    Aifec.post(url, data).then(function (res) {
      zone.classList.remove('is-busy');
      var items = res && res.items ? res.items : [];
      var errors = res && res.errors ? res.errors : [];
      errors.forEach(function (er) {
        Aifec.toast((er.name ? er.name + ': ' : '') + er.message, 'bad');
      });
      if (!items.length) {
        return;
      }
      var done = zone.getAttribute('data-text-uploaded');
      if (done) {
        Aifec.toast(done.replace('{n}', String(items.length)));
      }
      if (zone.getAttribute('data-reload') === '1') {
        window.location.reload();
        return;
      }
      if (!picker) {
        return;
      }
      if (!picker.multiple) {
        picker.selected = [{id: String(items[0].id), thumb: items[0].thumb}];
        commit();
        return;
      }
      items.forEach(function (it) {
        addSelected(it.id, it.thumb);
      });
      picker.q = '';
      picker.page = 1;
      load(false);
    }, function (err) {
      zone.classList.remove('is-busy');
      var message = err && err.data && err.data.error && err.data.error.message ? err.data.error.message : zone.getAttribute('data-text-failed');
      Aifec.toast(message, 'bad');
    });
  }

  on('change', '[data-af-upload-input]', function (e, input) {
    var zone = closest(input, '[data-af-dropzone]');
    if (zone) {
      upload(zone, input.files);
    }
    input.value = '';
  });

  on('dragover', '[data-af-dropzone]', function (e, zone) {
    e.preventDefault();
    zone.classList.add('is-over');
  });

  on('dragleave', '[data-af-dropzone]', function (e, zone) {
    zone.classList.remove('is-over');
  });

  on('drop', '[data-af-dropzone]', function (e, zone) {
    e.preventDefault();
    zone.classList.remove('is-over');
    if (e.dataTransfer && e.dataTransfer.files) {
      upload(zone, e.dataTransfer.files);
    }
  });

  // ------------------------------------------------------------------ reordenar miniaturas (solo varias imágenes)

  function clearMarks() {
    all('.af-media-drop-before, .af-media-drop-after').forEach(function (el) {
      el.classList.remove('af-media-drop-before');
      el.classList.remove('af-media-drop-after');
    });
  }

  function cancelDrag() {
    if (!drag) {
      return;
    }
    drag.item.classList.remove('is-dragging');
    clearMarks();
    drag = null;
  }

  on('pointerdown', '[data-af-media][data-multiple="1"] .af-thumb-item', function (e, item) {
    if (closest(e.target, '[data-af-media-remove]')) {
      return;
    }
    if (e.pointerType === 'mouse' && e.button !== 0) {
      return;
    }
    e.preventDefault();
    drag = {item: item, before: null};
    item.classList.add('is-dragging');
  });

  on('pointermove', null, function (e) {
    if (!drag || !drag.item.parentNode) {
      return;
    }
    var sibs = Array.prototype.slice.call(drag.item.parentNode.children).filter(function (c) {
      return c !== drag.item && c.classList.contains('af-thumb-item');
    });
    var before = null;
    for (var i = 0; i < sibs.length; i++) {
      var r = sibs[i].getBoundingClientRect();
      if (e.clientY < r.top || (e.clientY <= r.bottom && e.clientX < r.left + r.width / 2)) {
        before = sibs[i];
        break;
      }
    }
    drag.before = before;
    clearMarks();
    if (before) {
      before.classList.add('af-media-drop-before');
    } else if (sibs.length) {
      sibs[sibs.length - 1].classList.add('af-media-drop-after');
    }
  });

  on('pointerup', null, function () {
    if (!drag) {
      return;
    }
    var item = drag.item;
    var before = drag.before;
    var list = item.parentNode;
    var host = closest(item, '[data-af-media]');
    cancelDrag();
    if (list) {
      list.insertBefore(item, before);
      if (host) {
        syncInput(host);
      }
    }
  });

  on('pointercancel', null, cancelDrag);

  // Con teclado: Alt + flecha mueve la miniatura enfocada.
  on('keydown', '[data-af-media][data-multiple="1"] .af-thumb-item', function (e, item) {
    if (!e.altKey || e.target !== item || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) {
      return;
    }
    var list = item.parentNode;
    var host = closest(item, '[data-af-media]');
    if (!list || !host) {
      return;
    }
    e.preventDefault();
    if (e.key === 'ArrowLeft' && item.previousElementSibling) {
      list.insertBefore(item, item.previousElementSibling);
    } else if (e.key === 'ArrowRight' && item.nextElementSibling) {
      list.insertBefore(item, item.nextElementSibling.nextElementSibling);
    }
    syncInput(host);
    item.focus();
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', prepareAll);
  } else {
    prepareAll();
  }
})(typeof window !== 'undefined' ? window : null, typeof document !== 'undefined' ? document : null);

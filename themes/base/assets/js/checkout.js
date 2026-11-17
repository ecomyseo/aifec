/*!
 * AIFEC — la caja con JavaScript (tema base). Sin dependencias.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Solo se carga en la caja. Sin este guion la caja funciona igual (el botón «Actualizar» guarda y recalcula). Ningún texto
 * visible vive aquí: viene de la página (data-*) o del servidor, ya traducido. Aquí no se suma ningún importe.
 * Cada cambio manda lo escrito a la ruta de actualización, que lo guarda en el carrito y devuelve los avisos de cada campo,
 * los fragmentos de envío, pago y resumen y el total. Todo va por delegación sobre la caja: los fragmentos se sustituyen.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-af-checkout]');
  if (!root || !window.fetch || typeof FormData === 'undefined') {
    return;
  }
  var form = root.querySelector('[data-af-checkout-form]');
  var updateUrl = root.getAttribute('data-af-update-url');
  var understandUrl = root.getAttribute('data-af-understand-url');
  if (!form || !updateUrl) {
    return;
  }

  var busy = false;
  var again = false;
  var timer = 0;
  var submitted = false;
  var shown = {};

  function all(selector, scope) {
    return Array.prototype.slice.call((scope || root).querySelectorAll(selector));
  }

  function closest(node, selector) {
    while (node && node.nodeType === 1) {
      if (node.matches(selector)) {
        return node;
      }
      node = node.parentNode;
    }
    return null;
  }

  // El primer control activo con ese nombre (la provincia tiene dos, lista y texto, y solo una está activa).
  function byName(name) {
    return all('[name="' + name + '"]', form).filter(function (control) {
      return !control.disabled;
    })[0] || null;
  }

  function send(url, body) {
    return fetch(url, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' }
    }).then(function (response) {
      return response.json().catch(function () {
        return null;
      });
    });
  }

  function fieldName(group, key) {
    return key === 'email' ? 'email' : group + '[' + key + ']';
  }

  // «Actualizar» y su aviso solo hacen falta sin JavaScript.
  function showUpdateButton(show) {
    all('[data-af-update-button], [data-af-update-hint]').forEach(function (node) {
      node.hidden = !show;
    });
  }
  showUpdateButton(false);

  // En móvil el resumen empieza plegado (con el total a la vista); en escritorio no se ve.
  all('details[data-af-summary-details]').forEach(function (details) {
    if (window.getComputedStyle(details).display !== 'none') {
      details.removeAttribute('open');
    }
  });

  // ---------------------------------------------------------------- avisos junto a cada campo

  function mark(field, message) {
    var wrap = closest(field, '.af-field');
    if (!wrap || !field.id) {
      return;
    }
    var id = field.id + '-error';
    var note = document.getElementById(id);
    if (!note) {
      note = document.createElement('span');
      note.className = 'af-error';
      note.id = id;
      note.setAttribute('role', 'alert');
      wrap.appendChild(note);
    }
    note.textContent = message;
    wrap.classList.add('has-error');
    field.setAttribute('aria-invalid', 'true');
    var described = (field.getAttribute('aria-describedby') || '').split(' ');
    if (described.indexOf(id) < 0) {
      described.push(id);
      field.setAttribute('aria-describedby', described.join(' ').replace(/^\s+/, ''));
    }
  }

  function unmark(field) {
    var wrap = closest(field, '.af-field');
    var note = field.id ? document.getElementById(field.id + '-error') : null;
    if (note && note.parentNode) {
      note.parentNode.removeChild(note);
    }
    if (wrap) {
      wrap.classList.remove('has-error');
    }
    field.removeAttribute('aria-invalid');
  }

  function showErrors(errors) {
    var now = {};
    Object.keys(errors).forEach(function (key) {
      var name = key.replace(/^(\w+)\.(\w+)$/, '$1[$2]');
      var field = byName(name);
      if (field) {
        now[name] = shown[name] = true;
        mark(field, errors[key]);
      }
    });
    Object.keys(shown).forEach(function (name) {
      var field = now[name] ? null : byName(name);
      if (!now[name]) {
        delete shown[name];
      }
      if (field) {
        unmark(field);
      }
    });
  }

  // ---------------------------------------------------------------- país, provincia y código postal

  function swapRegion(select) {
    var scope = closest(select, '[data-af-address]');
    if (!scope) {
      return;
    }
    var spain = select.value === 'ES';
    [['[data-af-region-es]', spain], ['[data-af-region-other]', !spain]].forEach(function (pair) {
      var part = scope.querySelector(pair[0]);
      if (part) {
        part.hidden = !pair[1];
        all('select, input', part).forEach(function (control) {
          control.disabled = !pair[1];
        });
      }
    });
    var postcode = scope.querySelector('[name$="[postcode]"]');
    if (postcode) {
      postcode.setAttribute('inputmode', spain ? 'numeric' : 'text');
    }
  }

  // En España el código postal da la provincia: sus dos primeras cifras son el valor de la lista.
  function setProvince(scope, prefix) {
    var select = scope.querySelector('[data-af-region-es] select');
    if (select && !select.disabled && select.querySelector('option[value="' + prefix + '"]')) {
      select.value = prefix;
    }
  }

  // ---------------------------------------------------------------- guardar y recalcular

  function schedule() {
    window.clearTimeout(timer);
    timer = window.setTimeout(refresh, 250);
  }

  function refresh() {
    if (busy) {
      again = true;
      return;
    }
    busy = true;
    root.setAttribute('aria-busy', 'true');
    send(updateUrl, new FormData(form)).then(function (data) {
      if (data && data.redirect) {
        window.location.href = data.redirect;
      } else if (data && data.ok) {
        apply(data);
      } else {
        showUpdateButton(true);
      }
    }).catch(function () {
      showUpdateButton(true);
    }).then(function () {
      busy = false;
      root.removeAttribute('aria-busy');
      if (again) {
        again = false;
        refresh();
      }
    });
  }

  // Pone lo que ha calculado el servidor y devuelve el foco al control que lo tenía si se ha sustituido.
  function apply(data) {
    var active = document.activeElement;
    var focus = active && active.name ? { name: active.name, value: active.value } : null;
    var html = data.html || {};
    Object.keys(html).forEach(function (key) {
      all('[data-af-fragment="' + key + '"]').forEach(function (node) {
        node.innerHTML = html[key];
      });
    });
    all('[data-af-checkout-total]').forEach(function (node) {
      node.textContent = data.total_formatted;
    });
    all('[data-af-checkout-submit]').forEach(function (node) {
      node.textContent = data.button;
      node.setAttribute('data-total', String(data.total));
    });
    var expected = form.querySelector('[data-af-expected-total]');
    if (expected) {
      expected.value = String(data.total);
    }
    showErrors(data.errors || {});
    if (focus && (!active || !document.body.contains(active))) {
      all('input', form).some(function (input) {
        if (input.name === focus.name && input.value === focus.value) {
          input.focus();
          return true;
        }
        return false;
      });
    }
  }

  // ---------------------------------------------------------------- «Rellénalo de una vez»

  function clearDoubt(control) {
    var wrap = closest(control, '.af-field');
    var note = wrap ? wrap.querySelector('[data-af-doubt-note]') : null;
    control.removeAttribute('data-af-doubt');
    if (note) {
      note.parentNode.removeChild(note);
    }
  }

  function markDoubt(box, field, reason) {
    var wrap = closest(field, '.af-field');
    var text = box.getAttribute('data-af-doubt-' + reason);
    field.setAttribute('data-af-doubt', reason);
    if (wrap && text) {
      var note = document.createElement('span');
      note.className = 'af-co-doubt';
      note.setAttribute('data-af-doubt-note', '');
      note.textContent = text;
      wrap.appendChild(note);
    }
  }

  function understand(box) {
    var area = box.querySelector('[data-af-understand-text]');
    var status = box.querySelector('[data-af-understand-status]');
    var group = box.getAttribute('data-af-group');
    var body = new FormData();
    body.append('text', area ? area.value : '');
    send(understandUrl, body).then(function (data) {
      if (data && data.redirect) {
        window.location.href = data.redirect;
        return;
      }
      if (!data || !data.ok) {
        return;
      }
      var filled = 0;
      all('[data-af-doubt]', form).forEach(clearDoubt);
      Object.keys(data.fields || {}).forEach(function (key) {
        var field = byName(fieldName(group, key));
        if (field) {
          field.value = data.fields[key];
          filled += 1;
        }
      });
      Object.keys(data.doubts || {}).forEach(function (key) {
        var field = byName(fieldName(group, key));
        if (field) {
          markDoubt(box, field, data.doubts[key]);
        }
      });
      if (status) {
        status.textContent = box.getAttribute(filled > 0 ? 'data-af-done' : 'data-af-none') || '';
      }
      if (filled > 0) {
        schedule();
      }
    }).catch(function () {
      if (status) {
        status.textContent = box.getAttribute('data-af-none') || '';
      }
    });
  }

  var box = root.querySelector('[data-af-understand]');
  if (box && understandUrl) {
    box.hidden = false;
  }

  // Con una dirección guardada elegida, los campos de una dirección nueva sobran.
  function toggleNewAddress() {
    var fields = root.querySelector('[data-af-new-address]');
    var chosen = form.querySelector('input[name="address_id"]:checked');
    if (fields) {
      fields.hidden = !!chosen && chosen.value !== '0';
    }
  }
  toggleNewAddress();

  // ---------------------------------------------------------------- eventos

  root.addEventListener('click', function (event) {
    if (box && closest(event.target, '[data-af-understand-run]')) {
      event.preventDefault();
      understand(box);
    }
  });

  root.addEventListener('input', function (event) {
    var control = event.target;
    if (control.hasAttribute('data-af-doubt')) {
      clearDoubt(control);
    }
    if (!control.name || !/\[postcode\]$/.test(control.name)) {
      return;
    }
    var scope = closest(control, '[data-af-address]');
    var country = scope ? scope.querySelector('[data-af-country]') : null;
    if (country && country.value === 'ES' && /^\s*[0-9]{5}\s*$/.test(control.value)) {
      setProvince(scope, control.value.replace(/\s+/g, '').slice(0, 2));
      schedule();
    }
  });

  root.addEventListener('change', function (event) {
    var control = event.target;
    if (closest(control, '[data-af-understand]')) {
      return;
    }
    // El aviso de antes (también el que dibujó el servidor) deja de valer en cuanto se toca el campo.
    if (control.id && document.getElementById(control.id + '-error')) {
      unmark(control);
    }
    if (control.matches('[data-af-country]')) {
      swapRegion(control);
    }
    if (control.name === 'address_id') {
      toggleNewAddress();
    }
    schedule();
  });

  // Una pulsación doble no manda el pedido dos veces; si el navegador vuelve a esta página desde su caché, se libera.
  form.addEventListener('submit', function (event) {
    if (submitted) {
      event.preventDefault();
      return;
    }
    submitted = true;
    all('[data-af-checkout-submit]').forEach(function (node) {
      node.setAttribute('aria-busy', 'true');
    });
  });
  window.addEventListener('pageshow', function () {
    submitted = false;
    all('[data-af-checkout-submit]').forEach(function (node) {
      node.removeAttribute('aria-busy');
    });
  });
})();

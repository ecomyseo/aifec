/*
 * AIFEC — JavaScript propio del tema Métrica (se carga detrás del del tema base, que sigue haciendo todo lo suyo).
 * Dos cosas pequeñas, por delegación sobre `document` y sin cadenas visibles; sin ellas la página funciona igual:
 *   1. La matriz de filtros: en el escritorio, pulsar un conmutador filtra al momento (en el móvil se eligen varios y se
 *      pulsa «Aplicar»).
 *   2. La tabla de medidas de la ficha: pulsar una medida elige esa variante marcando sus opciones (el tema base cambia
 *      entonces precio, existencias y referencia) y su fila queda señalada. Sin JavaScript, la medida es un enlace a la
 *      misma ficha con esa variante («?variante=…»).
 * Autor: Ecom Experts · 2026 · Ver LICENSE.md
 */
(function (window, document) {
  'use strict';

  if (!window || !document || !document.documentElement) {
    return;
  }

  function closest(node, selector) {
    while (node && node.nodeType !== 1) {
      node = node.parentNode;
    }
    return node && node.closest ? node.closest(selector) : null;
  }

  function all(selector, scope) {
    return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
  }

  // ------------------------------------------------------------------ matriz de filtros

  document.addEventListener('change', function (event) {
    var box = closest(event.target, '[data-mt-matrix] input[type="checkbox"]');
    if (!box || !box.form || !window.matchMedia || !window.matchMedia('(min-width: 900px)').matches) {
      return;
    }
    if (box.form.requestSubmit) {
      box.form.requestSubmit();
    } else {
      HTMLFormElement.prototype.submit.call(box.form);
    }
  });

  // ------------------------------------------------------------------ tabla de medidas

  function markRow(product) {
    var input = product.querySelector('[data-af-variant-id]');
    var id = input ? String(input.value) : '';
    all('[data-mt-row]', product).forEach(function (row) {
      var on = id !== '' && row.getAttribute('data-mt-row') === id;
      var link = row.querySelector('a');
      row.classList.toggle('is-on', on);
      if (link && on) {
        link.setAttribute('aria-current', 'true');
      } else if (link) {
        link.removeAttribute('aria-current');
      }
    });
  }

  /** Los valores (opción => valor) de una variante, leídos de los datos que la ficha ya trae para el tema base. */
  function valuesOf(product, id) {
    var script = product.querySelector('script[data-af-variants-data]');
    if (!script) {
      return null;
    }
    try {
      var data = JSON.parse(script.textContent);
      var list = Array.isArray(data) ? data : (data && Array.isArray(data.variants) ? data.variants : []);
      for (var i = 0; i < list.length; i++) {
        if (String(list[i].id) === id) {
          return list[i].values && typeof list[i].values === 'object' ? list[i].values : null;
        }
      }
    } catch (e) {
      return null;
    }
    return null;
  }

  /** Los radios que hay que marcar para elegir esos valores, o null si alguno falta o no se puede elegir. */
  function radiosFor(product, values) {
    var groups = all('[data-af-option]', product);
    var found = [];
    var codes = Object.keys(values);
    for (var i = 0; i < codes.length; i++) {
      var radio = null;
      for (var g = 0; g < groups.length && !radio; g++) {
        if (groups[g].getAttribute('data-af-option') !== codes[i]) {
          continue;
        }
        var inputs = all('input[type="radio"]', groups[g]);
        for (var r = 0; r < inputs.length; r++) {
          if (inputs[r].value === String(values[codes[i]])) {
            radio = inputs[r];
            break;
          }
        }
      }
      if (!radio || radio.disabled) {
        return null;
      }
      found.push(radio);
    }
    return found.length ? found : null;
  }

  document.addEventListener('click', function (event) {
    var link = closest(event.target, '[data-mt-row] a');
    if (!link || event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }
    var product = closest(link, '[data-af-variants]');
    var row = closest(link, '[data-mt-row]');
    var values = product && row ? valuesOf(product, String(row.getAttribute('data-mt-row'))) : null;
    if (!values) {
      return;
    }
    var radios = radiosFor(product, values);
    if (!radios) {
      // Sin radios que marcar (o con uno apagado), el enlace hace su trabajo: abre la ficha con esa variante.
      return;
    }
    event.preventDefault();
    radios.forEach(function (radio) {
      radio.checked = true;
      radio.dispatchEvent(new Event('change', {bubbles: true}));
    });
    markRow(product);
  });

  document.addEventListener('change', function (event) {
    var product = closest(event.target, '[data-af-variants]');
    if (product && product.querySelector('[data-mt-row]')) {
      window.setTimeout(function () {
        markRow(product);
      }, 0);
    }
  });
})(window, document);

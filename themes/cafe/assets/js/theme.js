/*
 * Café — «Tu cafetera». El cliente pulsa un sistema o una cafetera (en la regleta, en el panel, en la portada o en una
 * ficha) y el navegador lo recuerda (localStorage, clave «cf.cafetera»): nada personal viaja en el HTML, que es el mismo
 * para todos. Con una cafetera recordada, la cinta de cada tarjeta dice si ese café le vale, la ficha da el veredicto,
 * la caja del listado cuenta cuántos le sirven (con las cuentas reales de la faceta) y el botón de la cabecera enseña su
 * nombre. Todo por delegación; sin este guion la tienda dice con qué es compatible cada café, sin juzgar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
(function () {
  'use strict';

  var KEY = 'cf.cafetera';
  var doc = document;

  function all(selector) {
    return Array.prototype.slice.call(doc.querySelectorAll(selector));
  }

  function read() {
    try {
      var saved = JSON.parse(window.localStorage.getItem(KEY) || 'null');
      return saved && typeof saved.code === 'string' && saved.code !== '' ? saved : null;
    } catch (error) {
      return null;
    }
  }

  function write(value) {
    try {
      if (value) {
        window.localStorage.setItem(KEY, JSON.stringify(value));
      } else {
        window.localStorage.removeItem(KEY);
      }
    } catch (error) {
      /* Sin almacenamiento (modo privado estricto): la tienda sigue igual, sin recordar. */
    }
  }

  function keep(node) {
    if (!node.hasAttribute('data-cf-text')) {
      node.setAttribute('data-cf-text', (node.textContent || '').replace(/\s+/g, ' ').replace(/^ | $/g, ''));
    }
    return node.getAttribute('data-cf-text');
  }

  function paint() {
    var mine = read();

    all('[data-cf-chip]').forEach(function (node) {
      node.textContent = mine && mine.label ? mine.label : node.getAttribute('data-cf-none');
      if (node.closest && node.closest('.cf-chip')) {
        node.closest('.cf-chip').classList.toggle('is-mine', !!mine);
      }
    });
    all('[data-cf-mine]').forEach(function (node) {
      node.hidden = !mine;
    });
    all('[data-cf-pick]').forEach(function (node) {
      node.classList.toggle('is-mine', !!mine && node.getAttribute('data-cf-pick') === mine.code);
    });

    // Tarjetas y veredicto de la ficha: los códigos de sus sistemas van en data-cf-sys.
    all('[data-cf-sys]').forEach(function (box) {
      var label = box.querySelector('[data-cf-ribbon]');
      var fits = !!mine && (' ' + box.getAttribute('data-cf-sys') + ' ').indexOf(' ' + mine.code + ' ') !== -1;
      box.classList.toggle('is-yes', !!mine && fits);
      box.classList.toggle('is-no', !!mine && !fits);
      if (!label) {
        return;
      }
      var plain = keep(label);
      label.classList.toggle('is-yes', !!mine && fits);
      label.classList.toggle('is-no', !!mine && !fits);
      label.textContent = mine ? label.getAttribute(fits ? 'data-cf-yes' : 'data-cf-no') : plain;
    });

    // La caja del listado: cuántos cafés de esta sección valen para la cafetera recordada.
    all('[data-cf-box]').forEach(function (box) {
      var plain = keep(box);
      var counts = {};
      try {
        counts = JSON.parse(box.getAttribute('data-cf-counts') || '{}') || {};
      } catch (error) {
        counts = {};
      }
      if (!mine) {
        box.textContent = plain;
        return;
      }
      var fit = Number(counts[mine.code]) || 0;
      box.textContent = (box.getAttribute('data-cf-fit') || '')
        .replace('{maker}', mine.label || '')
        .replace('{n}', String(fit))
        .replace('{total}', box.getAttribute('data-cf-total') || '');
      if (fit > 0) {
        var link = doc.createElement('a');
        link.href = box.getAttribute('data-cf-base') + '?f%5B' + encodeURIComponent(box.getAttribute('data-cf-attr') || '') + '%5D%5B%5D=' + encodeURIComponent(mine.code);
        link.textContent = box.getAttribute('data-cf-only') || '';
        box.appendChild(link);
      }
    });
  }

  doc.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || !target.closest) {
      return;
    }
    var pick = target.closest('[data-cf-pick]');
    if (pick && pick.getAttribute('data-cf-pick')) {
      // Se recuerda y el enlace sigue su camino: la página siguiente ya responde.
      write({ code: pick.getAttribute('data-cf-pick'), label: pick.getAttribute('data-cf-label') || '' });
      return;
    }
    if (target.closest('[data-cf-clear]')) {
      write(null);
      paint();
      return;
    }
    // Un desplegable de secciones abierto se cierra al pulsar fuera de él.
    all('.cf-section-more[open]').forEach(function (menu) {
      if (!menu.contains(target)) {
        menu.removeAttribute('open');
      }
    });
  });

  window.addEventListener('pageshow', paint);
  paint();
})();

/*
 * AIFEC — JavaScript propio del tema atelier (se carga detrás del del tema base, que sigue haciendo todo lo suyo).
 * Una cosa pequeña, por delegación sobre `document` y sin estado: los filtros desplegables del listado (abrir uno cierra
 * los demás; Escape o pulsar fuera los cierra). La galería de la ficha (foto principal y miniaturas) la mueve theme.js.
 * Sin cadenas visibles. Autor: Ecom Experts · 2026 · Ver LICENSE.md
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

  function openFilters(except) {
    return Array.prototype.slice.call(document.querySelectorAll('details.at-dd[open]')).filter(function (node) {
      return node !== except;
    });
  }

  function closeAll(except) {
    openFilters(except).forEach(function (node) {
      node.removeAttribute('open');
    });
  }

  // El evento «toggle» no sube por el árbol: se escucha en la fase de captura.
  document.addEventListener('toggle', function (event) {
    var node = event.target;
    if (node && node.nodeType === 1 && node.matches && node.matches('details.at-dd') && node.open && window.matchMedia('(min-width: 900px)').matches) {
      closeAll(node);
    }
  }, true);

  document.addEventListener('click', function (event) {
    if (!window.matchMedia('(min-width: 900px)').matches) {
      return;
    }
    if (!closest(event.target, 'details.at-dd')) {
      closeAll(null);
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape' && event.key !== 'Esc') {
      return;
    }
    var open = openFilters(null);
    if (open.length === 0) {
      return;
    }
    var summary = open[0].querySelector('summary');
    closeAll(null);
    if (summary && summary.focus) {
      summary.focus();
    }
  });

})(window, document);

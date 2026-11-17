/*
 * AIFEC — JavaScript propio del tema taller (se carga detrás del del tema base, que sigue haciendo todo lo suyo).
 * Tres cosas pequeñas, todas por delegación sobre `document` y sin cadenas visibles:
 *   1. El conmutador rejilla / lista del listado (se recuerda la elección en el navegador).
 *   2. El importe del carrito en la cabecera, con el resumen que ya da la tienda (solo si existe la cookie del carrito).
 *   3. Las dos líneas «con IVA» y «sin IVA» de la ficha cuando se elige otra variante.
 * Autor: Ecom Experts · 2026 · Ver LICENSE.md
 */
(function (window, document) {
  'use strict';

  if (!window || !document || !document.documentElement) {
    return;
  }

  var root = document.documentElement;
  root.classList.add('tl-js');

  function closest(node, selector) {
    while (node && node.nodeType !== 1) {
      node = node.parentNode;
    }
    return node && node.closest ? node.closest(selector) : null;
  }

  function all(selector, scope) {
    return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
  }

  // ------------------------------------------------------------------ rejilla / lista

  function setView(view) {
    all('[data-tl-view]').forEach(function (box) {
      box.setAttribute('data-tl-view', view);
    });
    all('[data-tl-view-set]').forEach(function (button) {
      button.setAttribute('aria-pressed', button.getAttribute('data-tl-view-set') === view ? 'true' : 'false');
    });
  }

  document.addEventListener('click', function (event) {
    var button = closest(event.target, '[data-tl-view-set]');
    if (!button) {
      return;
    }
    var view = button.getAttribute('data-tl-view-set') === 'list' ? 'list' : 'grid';
    setView(view);
    try {
      window.localStorage.setItem('tl-view', view);
    } catch (error) {
      // Sin almacenamiento la elección vale solo para esta página.
    }
  });

  try {
    var saved = window.localStorage.getItem('tl-view');
    if ((saved === 'list' || saved === 'grid') && document.querySelector('[data-tl-view]')) {
      setView(saved);
    }
  } catch (error) {
    // Sin almacenamiento se queda la vista de serie.
  }

  // ------------------------------------------------------------------ importe del carrito en la cabecera

  function hasMark(name) {
    if (!name) {
      return false;
    }
    return document.cookie.split(';').some(function (part) {
      return part.replace(/^\s+/, '').indexOf(name + '=') === 0;
    });
  }

  function showAmount(data) {
    var totals = data && data.totals;
    var cents = totals && typeof totals.total === 'number' ? totals.total : null;
    all('[data-tl-cart-total]').forEach(function (node) {
      node.textContent = cents !== null && window.Aifec ? window.Aifec.money(cents) : '';
      node.hidden = cents === null || cents <= 0;
    });
  }

  function loadAmount() {
    var url = root.getAttribute('data-cart-url');
    if (!url || !window.Aifec || !document.querySelector('[data-tl-cart-total]') || !hasMark(root.getAttribute('data-cart-cookie'))) {
      return;
    }
    window.Aifec.get(url).then(showAmount, function () {
      return null;
    });
  }

  loadAmount();
  document.addEventListener('aifec:cart', loadAmount);

  // ------------------------------------------------------------------ IVA incluido y sin IVA al cambiar de variante

  function variantsOf(box) {
    var script = box.querySelector('script[data-af-variants-data]');
    if (!script) {
      return [];
    }
    try {
      var data = JSON.parse(script.textContent || '{}');
      return data && Array.isArray(data.variants) ? data.variants : [];
    } catch (error) {
      return [];
    }
  }

  function updateTax(box) {
    var tax = box.querySelector('[data-tl-tax]');
    var input = box.querySelector('[data-af-variant-id]');
    if (!tax || !input || !window.Aifec) {
      return;
    }
    var rate = parseInt(tax.getAttribute('data-tl-rate'), 10) || 0;
    var withTax = tax.getAttribute('data-tl-with-tax') === '1';
    var found = variantsOf(box).filter(function (variant) {
      return String(variant.id) === String(input.value);
    })[0];
    if (!found || !found.price || typeof found.price.amount !== 'number') {
      return;
    }
    var amount = found.price.amount;
    var net = withTax ? Math.round(amount * 10000 / (10000 + rate)) : amount;
    var gross = withTax ? amount : Math.round(amount * (10000 + rate) / 10000);
    all('[data-tl-net]', tax).forEach(function (node) {
      node.textContent = window.Aifec.money(net);
    });
    all('[data-tl-gross]', tax).forEach(function (node) {
      node.textContent = window.Aifec.money(gross);
    });
  }

  function afterVariant(event) {
    var box = closest(event.target, '[data-af-variants]');
    if (box) {
      updateTax(box);
    }
  }

  document.addEventListener('click', function (event) {
    if (closest(event.target, '[data-af-option] [data-value]')) {
      afterVariant(event);
    }
  });
  document.addEventListener('change', function (event) {
    if (closest(event.target, 'select[data-af-option]')) {
      afterVariant(event);
    }
  });
})(window, document);

/*
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Copiloto del panel. Dos cosas pequeñas, las dos por delegación sobre «document»:
 *  1. un ejemplo pulsado rellena la caja de la orden;
 *  2. un resultado del buscador que termina en «#field=…&tab=…» enseña el campo (la pestaña ya la abre admin.js).
 * Todo lo demás (enviar, aplicar, deshacer) lo hacen los formularios con data-af-ajax del propio panel.
 */
(function () {
  'use strict';

  document.addEventListener('click', function (e) {
    var target = e.target;
    while (target && target !== document && !(target.getAttribute && target.hasAttribute('data-cp-example'))) {
      target = target.parentNode;
    }
    if (!target || target === document) {
      return;
    }
    var box = document.getElementById('cp-text');
    if (box) {
      box.value = target.getAttribute('data-cp-example') || '';
      box.focus();
    }
  });

  function fieldFromHash() {
    var m = /^#(?:.*&)?field=([^&]*)/.exec(window.location.hash || '');
    if (!m) {
      return '';
    }
    try {
      return decodeURIComponent(m[1]);
    } catch (err) {
      return m[1];
    }
  }

  function point() {
    var id = fieldFromHash();
    if (id === '') {
      return;
    }
    var field = document.getElementById(id);
    if (!field) {
      return;
    }
    var box = field.closest ? (field.closest('[data-af-field]') || field) : field;
    if (typeof box.scrollIntoView === 'function') {
      box.scrollIntoView({block: 'center'});
    }
    box.classList.add('cp-pointed');
    if (typeof field.focus === 'function') {
      field.focus({preventScroll: true});
    }
    window.setTimeout(function () {
      box.classList.remove('cp-pointed');
    }, 4000);
  }

  // La pestaña se activa cuando admin.js termina de arrancar: se espera un instante.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      window.setTimeout(point, 60);
    });
  } else {
    window.setTimeout(point, 60);
  }
  window.addEventListener('hashchange', function () {
    window.setTimeout(point, 60);
  });
}());

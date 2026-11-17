/*
 * AIFEC — guion del tema «aifec». Se carga detrás del del tema base y solo añade el desplegable «Tienda» del menú.
 * Sin JavaScript, el menú sigue siendo un enlace y una lista de categorías que se abre con el ratón o el teclado (CSS).
 * Con él: el botón que acompaña a «Tienda» (nace oculto) abre y cierra la lista, con aria-expanded; Escape la cierra y devuelve
 * el foco al botón; pulsar fuera la cierra en el escritorio. Todo por delegación, nada se conecta a un nodo que aún no exista.
 * Autor: Ecom Experts · 2026 · Ver LICENSE.md
 */
(function () {
  'use strict';

  var root = document.documentElement;
  root.classList.add('af-js');

  function each(list, fn) {
    Array.prototype.forEach.call(list, fn);
  }

  function setOpen(item, open, focus) {
    var button = item.querySelector('.af-nav-toggle');
    if (!button) {
      return;
    }
    item.classList.toggle('is-open', open);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (!open && focus) {
      button.focus();
    }
  }

  function reveal() {
    each(document.querySelectorAll('.af-nav-toggle'), function (button) {
      button.removeAttribute('hidden');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', reveal);
  } else {
    reveal();
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    var button = target && target.closest ? target.closest('.af-nav-toggle') : null;
    if (button) {
      var item = button.closest('.af-nav-shop');
      if (item) {
        setOpen(item, !item.classList.contains('is-open'), false);
      }
      return;
    }
    each(document.querySelectorAll('.af-nav-shop.is-open'), function (open) {
      if (!open.contains(target) && !open.closest('.af-drawer')) {
        setOpen(open, false, false);
      }
    });
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape' && event.key !== 'Esc') {
      return;
    }
    each(document.querySelectorAll('.af-nav-shop.is-open'), function (open) {
      setOpen(open, false, open.contains(document.activeElement));
    });
  });
}());

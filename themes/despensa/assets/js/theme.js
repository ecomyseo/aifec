/*
 * AIFEC — guion propio del tema despensa. Solo comportamiento mínimo, por delegación y sin peticiones:
 *  - el buscador desplegable de la cabecera enfoca su campo al abrirse y se cierra al pulsar fuera o con Escape;
 *  - la pastilla de la categoría actual se deja a la vista en la fila desplazable;
 *  - las filas deslizables con el teclado (flechas) cuando tienen el foco.
 * El guion del tema base se sigue cargando: paneles, carrito, variantes y galería los hace él.
 * Autor: Ecom Experts · 2026 · Ver LICENSE.md
 */
(function () {
  'use strict';

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function openSearch() {
    return document.querySelector('details.ds-search[open]');
  }

  document.addEventListener('toggle', function (event) {
    var box = event.target;
    if (!box || !box.classList || !box.classList.contains('ds-search') || !box.open) {
      return;
    }
    var field = box.querySelector('input[type="search"]');
    if (field) {
      field.focus();
    }
  }, true);

  document.addEventListener('click', function (event) {
    var box = openSearch();
    if (box && !box.contains(event.target)) {
      box.removeAttribute('open');
    }
  });

  document.addEventListener('keydown', function (event) {
    var box = openSearch();
    if (box && (event.key === 'Escape' || event.key === 'Esc')) {
      box.removeAttribute('open');
      var summary = box.querySelector('summary');
      if (summary) {
        summary.focus();
      }
      return;
    }
    var track = event.target && event.target.classList && event.target.classList.contains('ds-rail-track') ? event.target : null;
    if (track && (event.key === 'ArrowRight' || event.key === 'ArrowLeft')) {
      event.preventDefault();
      track.scrollBy({ left: (event.key === 'ArrowRight' ? 1 : -1) * track.clientWidth * 0.8, behavior: reduce ? 'auto' : 'smooth' });
    }
  });

  function start() {
    var current = document.querySelector('.ds-pills [aria-current="page"]');
    if (current && current.scrollIntoView) {
      var pills = current.parentNode;
      if (pills && pills.scrollWidth > pills.clientWidth) {
        pills.scrollLeft = Math.max(0, current.offsetLeft - 16);
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();

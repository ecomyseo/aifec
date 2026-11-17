/*
 * AIFEC — catálogo: «Seguir comprando» del carrito. En el listado de una categoría apunta su dirección en la pestaña
 * (sessionStorage, nada sale del navegador); en el carrito, el enlace [data-af-continue] (que va a la portada) pasa a esa
 * categoría. Sin JavaScript, o sin categoría vista, el enlace sigue yendo a la portada. Sin cadenas visibles.
 * Autor: Ecom Experts · 2026 · Ver LICENSE.md
 */
(function (window, document) {
  'use strict';

  var KEY = 'af-last-category';
  var store = null;
  try {
    store = window.sessionStorage;
  } catch (e) {
    return;
  }
  if (!store || !document.body) {
    return;
  }
  if (/(^|\s)af-type-category(\s|$)/.test(document.body.className)) {
    try {
      store.setItem(KEY, window.location.pathname + window.location.search);
    } catch (e) {
      // Sin espacio o con el almacenamiento cerrado: el carrito seguirá llevando a la portada.
    }
    return;
  }
  var link = document.querySelector('[data-af-continue]');
  var last = null;
  try {
    last = store.getItem(KEY);
  } catch (e) {
    last = null;
  }
  // Solo una dirección de esta misma tienda: empieza por una barra y no por dos.
  if (link && typeof last === 'string' && last.charAt(0) === '/' && last.charAt(1) !== '/' && last.charAt(1) !== '\\') {
    link.setAttribute('href', last);
  }
})(window, document);

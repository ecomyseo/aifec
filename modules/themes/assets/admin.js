/**
 * AIFEC — vista previa del personalizador de temas: cambia la página y el tamaño del <iframe> de la tienda.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 *   [data-th-frame]   el <iframe> de la tienda (data-param = nombre del parámetro de vista previa)
 *   [data-th-page]    selector de página: el valor de cada opción es su dirección
 *   [data-th-size]    selector de tamaño: el valor es el ancho del marco (100 %, 820 px, 390 px)
 *
 * Todo va por delegación sobre document: no depende de que el marco exista cuando carga el guion. Ningún texto visible
 * está escrito aquí.
 */
(function (window, document) {
  'use strict';

  if (!window || !document) {
    return;
  }

  function frame() {
    return document.querySelector('[data-th-frame]');
  }

  function withParam(url, param) {
    var sep = url.indexOf('?') === -1 ? '?' : '&';
    return url + sep + encodeURIComponent(param) + '=1';
  }

  document.addEventListener('change', function (event) {
    var target = event.target;
    if (!target || !target.matches) {
      return;
    }
    var f = frame();
    if (!f) {
      return;
    }
    if (target.matches('[data-th-page]')) {
      f.setAttribute('src', withParam(target.value, f.getAttribute('data-param') || 'af_preview'));
    } else if (target.matches('[data-th-size]')) {
      f.style.width = target.value;
    }
  });
})(window, document);

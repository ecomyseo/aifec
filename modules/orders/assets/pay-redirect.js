/*!
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * La página intermedia del pago: envía sola el formulario que lleva a la pasarela. Si el navegador no ejecuta este guion,
 * el botón visible del formulario hace lo mismo.
 */
(function () {
  'use strict';

  var form = document.querySelector('form[data-af-autopost]');
  if (form) {
    // Se llama al método del prototipo por si un campo del formulario se llama «submit» y lo tapa.
    HTMLFormElement.prototype.submit.call(form);
  }
})();

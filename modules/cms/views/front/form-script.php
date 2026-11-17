<?php
/**
 * AIFEC — guion de los formularios del contenido (se pinta una sola vez, solo en las páginas que llevan alguno).
 *
 * Hace dos cosas y sin él todo funciona igual (el formulario se envía con su botón y la página recarga):
 *  1. Al enfocar el primer campo pide la marca de tiempo firmada (GET .../marca, sin caché) y rellena el campo oculto. No puede
 *     ir en el HTML porque la página se guarda en la caché de página y se sirve a todo el mundo.
 *  2. Envía el formulario por JSON y enseña el texto de gracias o los errores sin recargar. Todos los textos los pone el servidor
 *     en su respuesta: este guion no lleva ninguna cadena visible.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$af_mark = \Aifec\Modules\Cms\Cms::FORM_STAMP;
?>
<script>
(function () {
  if (window.__afCmsForms) { return; }
  window.__afCmsForms = true;
  var STAMP = <?= json_html($af_mark) ?>;

  function clearErrors(form) {
    var old = form.querySelectorAll('[data-af-form-error]');
    for (var i = 0; i < old.length; i++) { old[i].parentNode.removeChild(old[i]); }
    var bad = form.querySelectorAll('[aria-invalid]');
    for (var j = 0; j < bad.length; j++) { bad[j].removeAttribute('aria-invalid'); }
  }

  function note(form, kind, text) {
    var box = document.createElement('div');
    box.className = 'af-alert af-alert-' + kind;
    box.setAttribute('role', kind === 'ok' ? 'status' : 'alert');
    box.setAttribute('data-af-form-error', '1');
    box.textContent = text;
    form.insertBefore(box, form.firstChild);
    return box;
  }

  function stamp(form) {
    if (form.getAttribute('data-af-stamped')) { return; }
    form.setAttribute('data-af-stamped', '1');
    if (!window.fetch) { return; }
    fetch(form.getAttribute('action') + '/marca', {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var field = form.querySelector('input[name="' + STAMP + '"]');
        if (field && d && d.stamp) { field.value = d.stamp; } else { form.removeAttribute('data-af-stamped'); }
      })
      .catch(function () { form.removeAttribute('data-af-stamped'); });
  }

  document.addEventListener('focusin', function (e) {
    var form = e.target && e.target.closest ? e.target.closest('form[data-af-form]') : null;
    if (form) { stamp(form); }
  });

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.matches || !form.matches('form[data-af-form]') || !window.fetch || !window.FormData) { return; }
    e.preventDefault();
    clearErrors(form);
    var button = form.querySelector('button[type="submit"]');
    if (button) { button.disabled = true; }
    fetch(form.getAttribute('action'), {
      method: 'POST', body: new FormData(form), credentials: 'same-origin',
      headers: {'Accept': 'application/json', 'X-Requested-With': 'fetch'}
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.ok) {
        var done = document.createElement('div');
        done.className = 'af-alert af-alert-ok';
        done.setAttribute('role', 'status');
        done.id = form.id;
        done.textContent = d.message || '';
        form.parentNode.replaceChild(done, form);
        return;
      }
      if (button) { button.disabled = false; }
      if (d && d.message) { note(form, 'bad', d.message); }
      var errors = d && d.errors ? d.errors : {};
      for (var key in errors) {
        if (!Object.prototype.hasOwnProperty.call(errors, key)) { continue; }
        var input = form.querySelector('[name="f[' + key + ']"]');
        if (!input) { continue; }
        input.setAttribute('aria-invalid', 'true');
        var p = document.createElement('p');
        p.className = 'af-error';
        p.setAttribute('role', 'alert');
        p.setAttribute('data-af-form-error', '1');
        p.textContent = errors[key];
        var field = input.closest('.af-field') || input.parentNode;
        field.appendChild(p);
      }
    }).catch(function () {
      // Sin red o con una respuesta que no es JSON: se envía como un formulario de toda la vida.
      if (button) { button.disabled = false; }
      form.submit();
    });
  });
})();
</script>

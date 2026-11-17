/*!
 * AIFEC — editor de menús del panel de contenidos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Todo va por delegación sobre document: las filas que se añaden después funcionan sin volver a conectar nada. El orden de las
 * filas es el orden en que viajan los campos `items[n][…]`, así que mover una fila no necesita renumerar nada.
 */
(function () {
  'use strict';

  var doc = document;
  var timer = null;

  function closest(el, selector) {
    while (el && el.nodeType === 1) {
      if (el.matches(selector)) {
        return el;
      }
      el = el.parentNode;
    }
    return null;
  }

  function form() {
    return doc.querySelector('[data-cms-menu]');
  }

  function list() {
    var f = form();
    return f ? f.querySelector('[data-cms-list]') : null;
  }

  function kinds() {
    var holder = doc.querySelector('[data-cms-kinds]');
    try {
      return holder ? JSON.parse(holder.getAttribute('data-json') || '{}') : {};
    } catch (e) {
      return {};
    }
  }

  function setLevel(row, level) {
    row.setAttribute('data-level', String(level));
    var input = row.querySelector('[data-cms-level]');
    if (input) {
      input.value = String(level);
    }
  }

  /** La primera fila no puede ser un sub-enlace; y la lista vacía enseña su aviso. */
  function tidy() {
    var ul = list();
    var f = form();
    if (!ul || !f) {
      return;
    }
    var first = ul.firstElementChild;
    if (first && first.getAttribute('data-level') === '1') {
      setLevel(first, 0);
    }
    var empty = f.querySelector('[data-cms-empty]');
    if (empty) {
      empty.hidden = ul.children.length > 0;
    }
  }

  function move(row, step) {
    var ul = list();
    if (!row || !ul) {
      return;
    }
    if (step < 0 && row.previousElementSibling) {
      ul.insertBefore(row, row.previousElementSibling);
    } else if (step > 0 && row.nextElementSibling) {
      ul.insertBefore(row, row.nextElementSibling.nextElementSibling);
    }
    tidy();
  }

  function indent(row, deeper) {
    if (!row) {
      return;
    }
    if (deeper && row.previousElementSibling) {
      setLevel(row, 1);
    } else if (!deeper) {
      setLevel(row, 0);
    }
    tidy();
  }

  function add() {
    var f = form();
    var ul = list();
    if (!f || !ul) {
      return;
    }
    var type = f.querySelector('[data-cms-type]').value;
    var names = kinds();
    var ref = '';
    var text = names[type] || type;
    var url = '';
    if (type === 'url') {
      url = f.querySelector('[data-cms-url]').value.replace(/^\s+|\s+$/g, '');
      if (url === '') {
        return;
      }
      text = url;
    } else if (type !== 'home') {
      var pick = f.querySelector('[data-cms-pick]');
      if (!pick || pick.value === '') {
        return;
      }
      ref = pick.value;
      text = pick.options[pick.selectedIndex].text;
    }
    var template = f.querySelector('[data-cms-template]');
    if (!template) {
      return;
    }
    var index = parseInt(f.getAttribute('data-cms-next') || '0', 10) || 0;
    f.setAttribute('data-cms-next', String(index + 1));
    var holder = doc.createElement('ul');
    holder.innerHTML = template.innerHTML.split('__i__').join(String(index));
    var row = holder.firstElementChild;
    if (!row) {
      return;
    }
    row.querySelector('input[name$="[type]"]').value = type;
    row.querySelector('input[name$="[ref]"]').value = ref;
    var main = row.querySelector('.af-menu-main');
    main.innerHTML = '';
    var badge = doc.createElement('span');
    badge.className = 'af-badge';
    badge.textContent = names[type] || type;
    main.appendChild(badge);
    main.appendChild(doc.createTextNode(' '));
    if (type === 'url') {
      var input = doc.createElement('input');
      input.className = 'af-input';
      input.type = 'text';
      input.name = 'items[' + index + '][url]';
      input.value = url;
      main.appendChild(input);
    } else {
      var strong = doc.createElement('strong');
      strong.textContent = text;
      main.appendChild(strong);
    }
    ul.appendChild(row);
    if (type === 'url') {
      f.querySelector('[data-cms-url]').value = '';
    }
    tidy();
  }

  function search() {
    var f = form();
    if (!f) {
      return;
    }
    var type = f.querySelector('[data-cms-type]').value;
    var pick = f.querySelector('[data-cms-pick]');
    if (type === 'url' || type === 'home' || !pick) {
      return;
    }
    var q = f.querySelector('[data-cms-q]').value;
    var url = f.getAttribute('data-cms-search') + '?type=' + encodeURIComponent(type) + '&q=' + encodeURIComponent(q);
    fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function (response) {
        return response.ok ? response.json() : { items: [] };
      })
      .then(function (data) {
        pick.innerHTML = '';
        (data.items || []).forEach(function (item) {
          var option = doc.createElement('option');
          option.value = String(item.id);
          option.textContent = item.label;
          pick.appendChild(option);
        });
        if (pick.options.length > 0) {
          pick.selectedIndex = 0;
        }
      })
      .catch(function () {
        pick.innerHTML = '';
      });
  }

  function typeChanged() {
    var f = form();
    if (!f) {
      return;
    }
    var type = f.querySelector('[data-cms-type]').value;
    var find = f.querySelector('[data-cms-find]');
    var free = f.querySelector('[data-cms-free]');
    if (find) {
      find.hidden = type === 'url' || type === 'home';
    }
    if (free) {
      free.hidden = type !== 'url';
    }
    search();
  }

  doc.addEventListener('click', function (e) {
    var target = e.target;
    var button = closest(target, '[data-cms-up]');
    if (button) {
      move(closest(button, '[data-cms-row]'), -1);
      return;
    }
    button = closest(target, '[data-cms-down]');
    if (button) {
      move(closest(button, '[data-cms-row]'), 1);
      return;
    }
    button = closest(target, '[data-cms-indent]');
    if (button) {
      indent(closest(button, '[data-cms-row]'), true);
      return;
    }
    button = closest(target, '[data-cms-outdent]');
    if (button) {
      indent(closest(button, '[data-cms-row]'), false);
      return;
    }
    button = closest(target, '[data-cms-remove]');
    if (button) {
      var row = closest(button, '[data-cms-row]');
      if (row && row.parentNode) {
        row.parentNode.removeChild(row);
        tidy();
      }
      return;
    }
    if (closest(target, '[data-cms-add]')) {
      e.preventDefault();
      add();
    }
  });

  doc.addEventListener('change', function (e) {
    if (closest(e.target, '[data-cms-type]')) {
      typeChanged();
    }
  });

  doc.addEventListener('input', function (e) {
    if (closest(e.target, '[data-cms-q]')) {
      window.clearTimeout(timer);
      timer = window.setTimeout(search, 250);
    }
  });

  // Enter en el buscador o en la dirección libre añade el enlace en vez de guardar el menú.
  doc.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && (closest(e.target, '[data-cms-q]') || closest(e.target, '[data-cms-url]'))) {
      e.preventDefault();
      add();
    }
  });

  function start() {
    if (form()) {
      tidy();
      typeChanged();
    }
  }

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();

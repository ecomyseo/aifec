/**
 * AIFEC — AI-First E-Commerce.
 *
 * «Rellenar con IA» en el alta de un producto: manda el nombre al servidor, que propone una descripción corta y una categoría,
 * y enseña la propuesta con una casilla por campo. Solo se copia a la ficha lo que la persona marca; nada se guarda hasta que
 * pulsa «Guardar» como siempre. Todo va por delegación sobre el documento.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
(function () {
    'use strict';

    function card(el) {
        while (el && el.nodeType === 1) {
            if (el.hasAttribute('data-af-quickfill')) {
                return el;
            }
            el = el.parentNode;
        }
        return null;
    }

    // Los textos llegan ya traducidos en atributos de la tarjeta (data-af-text-<clave>): aquí no hay ninguna cadena que traducir.
    function text(root, key) {
        return root.getAttribute('data-af-text-' + key) || '';
    }

    function toast(message, kind) {
        if (window.Aifec && typeof window.Aifec.toast === 'function') {
            window.Aifec.toast(message, kind);
        }
    }

    function el(tag, attrs, content) {
        var node = document.createElement(tag);
        for (var key in attrs) {
            if (Object.prototype.hasOwnProperty.call(attrs, key)) {
                node.setAttribute(key, attrs[key]);
            }
        }
        if (content) {
            node.textContent = content;
        }
        return node;
    }

    // La descripción corta es un editor de texto: se pone en su área editable y en el campo que se envía.
    function setSummary(lang, html) {
        var field = document.querySelector('[name="f[summary][' + lang + ']"]');
        if (!field) {
            return;
        }
        field.value = html;
        var wrap = field.previousSibling;
        if (wrap && wrap.__afArea) {
            wrap.__afArea.innerHTML = html;
        }
        field.dispatchEvent(new window.Event('input', {bubbles: true}));
    }

    function setCategory(id) {
        var box = document.querySelector('input[type="checkbox"][name="f[categories][]"][value="' + String(id) + '"]');
        if (box) {
            box.checked = true;
            box.dispatchEvent(new window.Event('change', {bubbles: true}));
        } else if (typeof window.CustomEvent === 'function') {
            // El selector de categorías (category-picker.js) no tiene casillas: se le pide la categoría por su identificador.
            document.dispatchEvent(new window.CustomEvent('aifec:category-add', {detail: id}));
        }
    }

    function escapeHtml(value) {
        return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function show(root, data) {
        var out = root.querySelector('[data-af-quickfill-result]');
        var lang = root.getAttribute('data-af-fill-lang') || 'es';
        out.textContent = '';
        out.hidden = false;
        var rows = [];
        if (data.summary) {
            rows.push({key: 'summary', label: text(root, 'summary'), value: data.summary});
        }
        if (data.category_id) {
            rows.push({key: 'category', label: text(root, 'category'), value: data.category, id: data.category_id});
        }
        if (!rows.length) {
            out.appendChild(el('p', {'class': 'af-muted af-small'}, text(root, 'none')));
            return;
        }
        rows.forEach(function (row) {
            var label = el('label', {'class': 'af-check'});
            var box = el('input', {type: 'checkbox', checked: 'checked', 'data-af-quickfill-use': row.key});
            if (row.id) {
                box.setAttribute('data-af-quickfill-id', String(row.id));
            }
            box.__value = row.value;
            var span = el('span', {});
            span.appendChild(el('strong', {}, row.label + ': '));
            span.appendChild(document.createTextNode(row.value));
            label.appendChild(box);
            label.appendChild(span);
            out.appendChild(label);
        });
        var apply = el('button', {type: 'button', 'class': 'af-btn af-btn-sm af-btn-primary', 'data-af-quickfill-apply': '1'}, text(root, 'apply'));
        out.appendChild(apply);
        out.__lang = lang;
    }

    document.addEventListener('click', function (e) {
        var target = e.target && e.target.nodeType === 1 ? e.target : null;
        var run = target && target.closest ? target.closest('[data-af-quickfill-run]') : null;
        if (run) {
            e.preventDefault();
            var root = card(run);
            var lang = root.getAttribute('data-af-fill-lang') || 'es';
            var field = document.querySelector('[name="f[name][' + lang + ']"]');
            var name = field ? String(field.value).replace(/^\s+|\s+$/g, '') : '';
            if (!name) {
                toast(text(root, 'name'), 'bad');
                return;
            }
            run.disabled = true;
            window.Aifec.post(root.getAttribute('data-af-quickfill'), {name: name}).then(function (data) {
                run.disabled = false;
                if (data && data.ok) {
                    show(root, data);
                } else {
                    toast(data && data.message ? data.message : text(root, 'fail'), 'bad');
                }
            }, function (err) {
                run.disabled = false;
                var data = err && err.data ? err.data : null;
                toast(data && data.message ? data.message : text(root, 'fail'), 'bad');
            });
            return;
        }
        var apply = target && target.closest ? target.closest('[data-af-quickfill-apply]') : null;
        if (apply) {
            e.preventDefault();
            var box = card(apply);
            var lang2 = box.getAttribute('data-af-fill-lang') || 'es';
            var uses = box.querySelectorAll('[data-af-quickfill-use]');
            for (var i = 0; i < uses.length; i++) {
                if (!uses[i].checked) {
                    continue;
                }
                if (uses[i].getAttribute('data-af-quickfill-use') === 'summary') {
                    setSummary(lang2, '<p>' + escapeHtml(uses[i].__value) + '</p>');
                } else {
                    setCategory(uses[i].getAttribute('data-af-quickfill-id'));
                }
            }
        }
    });
}());

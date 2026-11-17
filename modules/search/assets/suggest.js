/**
 * AIFEC — AI-First E-Commerce.
 *
 * Sugerencias mientras se escribe, bajo cualquier <input type="search" name="q"> del tema: espera de 150 ms, flechas,
 * Intro y Escape, con roles de lista desplegable (combobox / listbox) para lectores de pantalla.
 *
 * Todo va por delegación sobre document (los buscadores que se pintan después, como el del panel móvil, funcionan sin
 * volver a conectar nada). El guion no lleva ningún texto visible: la dirección del JSON y la etiqueta accesible llegan en los
 * atributos data-af-suggest-* de su propia etiqueta <script>; las etiquetas de grupo, en la respuesta, ya traducidas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
(function () {
    'use strict';

    var SELECTOR = 'input[type="search"][name="q"]';
    var DELAY = 150;
    var MIN_CHARS = 2;
    var LIST_ID = 'af-suggest-list';

    var script = document.currentScript || document.querySelector('script[data-af-suggest-url]');
    var url = script ? script.getAttribute('data-af-suggest-url') : '';
    var label = script ? script.getAttribute('data-af-suggest-label') : '';
    if (!url) {
        return;
    }

    var box = null;
    var list = null;
    var current = null;
    var items = [];
    var active = -1;
    var timer = 0;
    var seq = 0;

    function build() {
        if (box) {
            return;
        }
        box = document.createElement('div');
        box.setAttribute('data-af-suggest-box', '');
        box.style.cssText = 'position:absolute;z-index:1000;display:none;box-sizing:border-box;max-width:100vw;'
            + 'background:var(--surface,#fff);color:var(--ink,#111);border:1px solid var(--line,#ccc);'
            + 'border-radius:var(--radius,6px);box-shadow:0 8px 24px rgba(0,0,0,.18);overflow:hidden;';
        list = document.createElement('ul');
        list.id = LIST_ID;
        list.setAttribute('role', 'listbox');
        if (label) {
            list.setAttribute('aria-label', label);
        }
        list.style.cssText = 'list-style:none;margin:0;padding:0;';
        box.appendChild(list);
        document.body.appendChild(box);
    }

    function place() {
        if (!current || !box) {
            return;
        }
        var rect = current.getBoundingClientRect();
        box.style.left = (rect.left + window.pageXOffset) + 'px';
        box.style.top = (rect.bottom + window.pageYOffset) + 'px';
        box.style.width = rect.width + 'px';
    }

    function close() {
        if (box) {
            box.style.display = 'none';
        }
        items = [];
        active = -1;
        if (current) {
            current.setAttribute('aria-expanded', 'false');
            current.removeAttribute('aria-activedescendant');
        }
    }

    function mark(index) {
        var nodes = list ? list.children : [];
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].setAttribute('aria-selected', i === index ? 'true' : 'false');
            nodes[i].style.background = i === index ? 'var(--line,#eee)' : 'transparent';
        }
        active = index;
        if (current) {
            if (index >= 0 && nodes[index]) {
                current.setAttribute('aria-activedescendant', nodes[index].id);
            } else {
                current.removeAttribute('aria-activedescendant');
            }
        }
    }

    function text(tag, value, css) {
        var node = document.createElement(tag);
        node.textContent = value;
        if (css) {
            node.style.cssText = css;
        }

        return node;
    }

    function render(data) {
        build();
        list.textContent = '';
        items = data;
        if (!items.length || !current) {
            close();

            return;
        }
        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            var li = document.createElement('li');
            li.id = LIST_ID + '-' + i;
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', 'false');
            var link = document.createElement('a');
            link.href = item.url;
            link.tabIndex = -1;
            link.style.cssText = 'display:flex;align-items:center;gap:.6rem;padding:.5rem .75rem;color:inherit;text-decoration:none;min-height:44px;';
            if (item.image) {
                var img = document.createElement('img');
                img.src = item.image;
                img.alt = '';
                img.width = 40;
                img.height = 40;
                img.loading = 'lazy';
                img.style.cssText = 'width:40px;height:40px;object-fit:contain;flex:none;';
                link.appendChild(img);
            }
            link.appendChild(text('span', item.label, 'flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;'));
            if (item.group) {
                link.appendChild(text('span', item.group, 'font-size:.8em;opacity:.7;flex:none;'));
            }
            if (item.price) {
                link.appendChild(text('span', item.price, 'font-weight:600;flex:none;'));
            }
            li.appendChild(link);
            list.appendChild(li);
        }
        current.setAttribute('aria-expanded', 'true');
        place();
        box.style.display = 'block';
        mark(-1);
    }

    function ask(input) {
        var value = input.value.replace(/^\s+|\s+$/g, '');
        var mine = ++seq;
        if (value.length < MIN_CHARS) {
            close();

            return;
        }
        fetch(url + '?q=' + encodeURIComponent(value), { cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : { items: [] }; })
            .then(function (data) {
                // Una respuesta que llega tarde (ya se escribió otra cosa) no pisa a la última.
                if (mine === seq && current === input) {
                    render(data && data.items ? data.items : []);
                }
            })
            .catch(function () { close(); });
    }

    function attach(input) {
        if (current !== input) {
            current = input;
            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-autocomplete', 'list');
            input.setAttribute('aria-expanded', 'false');
            input.setAttribute('aria-controls', LIST_ID);
            input.setAttribute('autocomplete', 'off');
        }
    }

    document.addEventListener('input', function (event) {
        var input = event.target;
        if (!input || !input.matches || !input.matches(SELECTOR)) {
            return;
        }
        attach(input);
        window.clearTimeout(timer);
        timer = window.setTimeout(function () { ask(input); }, DELAY);
    });

    document.addEventListener('keydown', function (event) {
        var input = event.target;
        if (!input || !input.matches || !input.matches(SELECTOR) || input !== current || !box || box.style.display === 'none') {
            return;
        }
        var key = event.key;
        if (key === 'ArrowDown') {
            event.preventDefault();
            mark(active + 1 >= items.length ? 0 : active + 1);
        } else if (key === 'ArrowUp') {
            event.preventDefault();
            mark(active <= 0 ? items.length - 1 : active - 1);
        } else if (key === 'Enter') {
            if (active >= 0 && items[active]) {
                event.preventDefault();
                window.location.href = items[active].url;
            }
        } else if (key === 'Escape') {
            event.preventDefault();
            close();
        }
    });

    // Elegir con el ratón o el dedo: se sigue el enlace de la opción (también vale el enlace normal).
    document.addEventListener('mousedown', function (event) {
        var target = event.target;
        if (box && target && box.contains(target)) {
            return;
        }
        if (target && target.matches && target.matches(SELECTOR)) {
            return;
        }
        close();
    });

    // «Preguntar al asistente» en la página de resultados: abre el asistente de la tienda y le manda la frase buscada, usando
    // solo sus atributos públicos (data-af-assistant-open y data-af-assistant-chip).
    document.addEventListener('click', function (event) {
        var target = event.target;
        var button = target && target.closest ? target.closest('[data-af-search-ask]') : null;
        if (!button) {
            return;
        }
        var open = document.querySelector('[data-af-assistant-open]');
        if (!open) {
            return;
        }
        event.preventDefault();
        open.click();
        var chip = document.createElement('span');
        chip.setAttribute('data-af-assistant-chip', button.getAttribute('data-af-search-ask') || '');
        chip.hidden = true;
        document.body.appendChild(chip);
        chip.click();
        document.body.removeChild(chip);
    });

    document.addEventListener('submit', function () { close(); });
    window.addEventListener('resize', place);
}());

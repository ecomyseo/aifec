/**
 * AIFEC catalog: buying from a listing card. The theme script already updates the price, the availability and the variant
 * id when an option is chosen (the card is a [data-af-variants] box, like the product page). This adds what only a card
 * needs: the photo of the chosen variant, the maximum quantity and the link to the product page with that variant chosen.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
(function (document) {
    'use strict';

    function variants(card) {
        var node = card.querySelector('[data-af-variants-data]');
        if (!node) {
            return [];
        }
        try {
            var data = JSON.parse(node.textContent || '[]');
            return Array.isArray(data) ? data : [];
        } catch (error) {
            return [];
        }
    }

    function showPhoto(card, variant) {
        var img = card.querySelector('img.af-product-card-img');
        if (!img) {
            return;
        }
        if (!img.hasAttribute('data-af-src')) {
            img.setAttribute('data-af-src', img.getAttribute('src') || '');
            img.setAttribute('data-af-srcset', img.getAttribute('srcset') || '');
        }
        var own = variant.image && variant.image.src;
        var src = own ? String(variant.image.src) : img.getAttribute('data-af-src');
        var srcset = own ? String(variant.image.srcset || '') : img.getAttribute('data-af-srcset');
        if (!src || img.getAttribute('src') === src) {
            return;
        }
        if (srcset) {
            img.setAttribute('srcset', srcset);
        } else {
            img.removeAttribute('srcset');
        }
        img.setAttribute('src', src);
    }

    function showLimit(card, variant) {
        var qty = card.querySelector('[data-af-co] input[name="qty"]');
        if (!qty) {
            return;
        }
        var max = parseInt(variant.max, 10);
        if (max > 0) {
            qty.setAttribute('max', String(max));
            if (parseInt(qty.value, 10) > max) {
                qty.value = String(max);
            }
        } else {
            qty.removeAttribute('max');
        }
    }

    function showLinks(card, variant) {
        var url = card.getAttribute('data-af-card-url') || '';
        if (!url) {
            return;
        }
        var links = card.querySelectorAll('a[href]');
        for (var i = 0; i < links.length; i++) {
            var href = links[i].getAttribute('href') || '';
            if (href.split('?')[0] === url) {
                links[i].setAttribute('href', url + '?variante=' + encodeURIComponent(String(variant.id)));
            }
        }
    }

    function sync(card) {
        var input = card.querySelector('[data-af-variant-id]');
        if (!input) {
            return;
        }
        var id = String(input.value);
        var list = variants(card);
        for (var i = 0; i < list.length; i++) {
            if (String(list[i].id) === id) {
                showPhoto(card, list[i]);
                showLimit(card, list[i]);
                showLinks(card, list[i]);
                return;
            }
        }
    }

    // After the theme script has put the chosen variant in the hidden field (whatever the order of the listeners).
    function later(event) {
        var target = event.target;
        var option = target && target.closest ? target.closest('[data-af-option]') : null;
        var card = option ? option.closest('[data-af-card]') : null;
        if (card) {
            window.setTimeout(function () {
                sync(card);
            }, 0);
        }
    }

    document.addEventListener('change', later);
    document.addEventListener('click', later);
}(document));

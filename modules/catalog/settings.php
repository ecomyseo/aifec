<?php
/**
 * AIFEC — ajustes del catálogo: cómo se enseñan los listados y las palabras de las direcciones. Todos son por sitio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Catalog',
    'description' => 'Listings, stock warning and the words in the addresses of products, categories and brands',
    'icon' => 'box',
    'cap' => 'catalog.edit',
    'help' => "Listings: how many products fit in a page, whether the ones that are out of stock are shown, and for how long a product counts as new.\nAddresses: the first word of the address of every product, category and brand. When you change one, all the addresses of that kind are rewritten and the old ones keep redirecting to the new ones.",
    'tabs' => [
        'cglisting' => ['label' => 'Listings', 'fields' => [
            'catalog.per_page' => ['type' => 'number', 'label' => 'Products per page', 'default' => 24, 'rules' => 'required|between:1,200', 'width' => 'sm'],
            'catalog.show_out_of_stock' => ['type' => 'toggle', 'label' => 'Show the products that are out of stock', 'default' => true],
            'catalog.new_days' => ['type' => 'number', 'label' => 'Days a product counts as new', 'default' => 30, 'rules' => 'required|between:0,365', 'width' => 'sm'],
            'catalog.low_stock' => ['type' => 'number', 'label' => 'Warn when the stock is at or below', 'default' => 5, 'rules' => 'required|between:0,100000', 'width' => 'sm'],
            'catalog.card_options' => ['type' => 'toggle', 'label' => 'Choose the combination and the quantity in the listings', 'default' => true,
                'hint' => 'Each product card lets the customer pick size, colour or format, set the units and add to the cart without opening the product page.'],
            'catalog.card_options_max' => ['type' => 'number', 'label' => 'Combinations that fit in a card', 'default' => 24, 'rules' => 'nullable|int|between:2,60', 'width' => 'sm',
                'hint' => 'A product with more combinations shows «Choose options» and opens its page.'],
            'catalog.related' => ['type' => 'toggle', 'label' => 'Show «You may also like» on the product page and in the cart', 'default' => true,
                'hint' => 'Up to four products: the related ones you chose or, if there are none, others from the same category, the ones in stock first.'],
        ]],
        'cgurls' => ['label' => 'Addresses', 'fields' => [
            'urls.notice' => ['type' => 'heading', 'label' => 'Changing these words moves every address of that kind',
                'hint' => 'The old addresses keep working: they redirect to the new ones.'],
            'urls.product' => ['type' => 'slug', 'label' => 'Word for products', 'default' => 'producto', 'rules' => 'required|max:60', 'width' => 'md'],
            'urls.category' => ['type' => 'slug', 'label' => 'Word for categories', 'default' => 'categoria', 'rules' => 'required|max:60', 'width' => 'md'],
            'urls.brand' => ['type' => 'slug', 'label' => 'Word for brands', 'default' => 'marca', 'rules' => 'required|max:60', 'width' => 'md'],
        ]],
    ],
];

<?php
/**
 * AIFEC — ajustes de los contenidos: cuántas entradas salen por página y las palabras de las direcciones del blog.
 * Todos son por sitio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Content',
    'description' => 'Posts per page and the words in the addresses of the blog',
    'icon' => 'file',
    'cap' => 'content.edit',
    'help' => "Blog: how many posts are listed in each page.\nAddresses: the first words of the address of every post, category and tag. When you change one, all the addresses of that kind are rewritten and the old ones keep redirecting to the new ones.",
    'tabs' => [
        'cmblog' => ['label' => 'Blog', 'fields' => [
            'cms.posts_per_page' => ['type' => 'number', 'label' => 'Posts per page', 'default' => 10, 'rules' => 'required|between:1,100', 'width' => 'sm'],
        ]],
        'cmurls' => ['label' => 'Addresses', 'fields' => [
            'urls.cms_notice' => ['type' => 'heading', 'label' => 'Changing these words moves every address of that kind',
                'hint' => 'The old addresses keep working: they redirect to the new ones.'],
            'urls.post' => ['type' => 'slug', 'label' => 'Word for the blog and its posts', 'default' => 'blog', 'rules' => 'required|max:60', 'width' => 'md'],
            'urls.term_category' => ['type' => 'slug', 'label' => 'Word for blog categories', 'default' => 'categoria', 'rules' => 'required|max:60', 'width' => 'md'],
            'urls.term_tag' => ['type' => 'slug', 'label' => 'Word for blog tags', 'default' => 'etiqueta', 'rules' => 'required|max:60', 'width' => 'md'],
        ]],
    ],
];

<?php
/**
 * AIFEC — GM AI notice: ajustes. Las claves de pestaña llevan las iniciales del plugin y las de ajuste, su zona.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'AI notice',
    'description' => 'Tell visitors that the texts were written with the help of AI',
    'icon' => 'sparkles',
    'cap' => 'settings.edit',
    'help' => "The notice appears under the summary of the product, at the end of the product page, or both.\nWith «only where a person approved AI text», it appears just on the products and pages that have an approval recorded by the AI module.\nThe empty texts use the standard wording, which is already translated.",
    'tabs' => [
        'gaigeneral' => ['label' => 'General', 'fields' => [
            'gm_aviso_ia.enabled' => ['type' => 'toggle', 'label' => 'Show the notice', 'default' => true],
            'gm_aviso_ia.placement' => ['type' => 'select', 'label' => 'Where in the product page', 'options' => ['summary' => 'Under the summary', 'bottom' => 'At the end of the page', 'both' => 'In both places'], 'default' => 'summary'],
            'gm_aviso_ia.scope' => ['type' => 'select', 'label' => 'Where it appears', 'options' => ['all' => 'On every product and page', 'marked' => 'Only where a person approved AI text'], 'default' => 'all'],
            'gm_aviso_ia.categories' => ['type' => 'text', 'label' => 'Only in these categories', 'hint' => 'Category numbers separated by commas. Empty: all the products.', 'default' => '', 'rules' => 'max:200'],
            'gm_aviso_ia.pages' => ['type' => 'toggle', 'label' => 'Also on pages and blog posts', 'default' => true],
            'gm_aviso_ia.footer' => ['type' => 'toggle', 'label' => 'Short notice in the footer of every page', 'default' => false],
        ]],
        'gaitexts' => ['label' => 'Texts', 'fields' => [
            'gm_aviso_ia.title' => ['type' => 'text', 'label' => 'Title', 'default' => '', 'rules' => 'max:120'],
            'gm_aviso_ia.text' => ['type' => 'textarea', 'label' => 'Text', 'default' => '', 'rules' => 'max:600'],
            'gm_aviso_ia.link_label' => ['type' => 'text', 'label' => 'Link text', 'default' => '', 'rules' => 'max:120'],
            'gm_aviso_ia.footer_text' => ['type' => 'text', 'label' => 'Footer text', 'default' => '', 'rules' => 'max:200'],
            'gm_aviso_ia.page_id' => ['type' => 'number', 'label' => 'Information page', 'hint' => 'Number of a page of the shop that explains it. 0: the page of this plugin.', 'default' => 0, 'rules' => 'int|min:0', 'width' => 'sm'],
            'gm_aviso_ia.url' => ['type' => 'text', 'label' => 'Address of the page of this plugin', 'hint' => 'Without the domain. Changing it takes effect after clearing the cache.', 'default' => 'textos-con-ia', 'rules' => 'max:80'],
        ]],
    ],
];

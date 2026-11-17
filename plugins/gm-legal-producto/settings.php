<?php
/**
 * AIFEC — GM legal texts per product: ajustes. Las claves de pestaña llevan las iniciales del plugin y las de ajuste, su zona.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Legal texts per product',
    'description' => 'Legal text blocks in the product page, the checkout and the order email',
    'icon' => 'shield',
    'cap' => 'gmlegal.edit',
    'help' => "The blocks are written in Content, Legal text blocks, and assigned to categories or products.\nThe waiver box appears in the checkout only when the cart has digital products. It does not stop the purchase: it records what the customer answered.\nChange the version of the texts every time you rewrite them: it is saved with each answer.",
    'tabs' => [
        'gplgeneral' => ['label' => 'Where they appear', 'fields' => [
            'gm_legal_producto.placement' => ['type' => 'select', 'label' => 'In the product page', 'options' => ['summary' => 'Under the summary', 'bottom' => 'At the end of the page'], 'default' => 'summary'],
            'gm_legal_producto.checkout_blocks' => ['type' => 'toggle', 'label' => 'Show the texts in the checkout', 'default' => true],
        ]],
        'gplwaiver' => ['label' => 'Withdrawal waiver', 'fields' => [
            'gm_legal_producto.waiver_enabled' => ['type' => 'toggle', 'label' => 'Ask about the waiver when the cart has digital products', 'default' => true],
            'gm_legal_producto.waiver_text' => ['type' => 'textarea', 'label' => 'Text of the box', 'hint' => 'Empty: the standard wording.', 'default' => '', 'rules' => 'max:600'],
            'gm_legal_producto.version' => ['type' => 'text', 'label' => 'Version of the texts', 'hint' => 'For example 1.1 · 2026-11-13. It is saved with each answer.', 'default' => '', 'rules' => 'max:40', 'width' => 'md'],
        ]],
    ],
];

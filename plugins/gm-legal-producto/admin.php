<?php
/**
 * AIFEC — GM legal texts per product en el panel: permisos, menú y los bloques legales.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Gm\LegalProducto\BlockAdmin;

return [
    'caps' => [
        'gmlegal.view' => 'See the legal text blocks',
        'gmlegal.edit' => 'Create and change the legal text blocks',
    ],
    'menu' => [
        ['group' => 'content', 'items' => [
            ['label' => 'Legal text blocks', 'resource' => 'gm_legal_blocks', 'cap' => 'gmlegal.view', 'order' => 70],
        ]],
        ['group' => 'settings', 'items' => [
            ['label' => 'Legal texts per product', 'settings' => 'gm-legal-producto', 'cap' => 'gmlegal.edit', 'order' => 70],
        ]],
    ],
    'resources' => [
        'gm_legal_blocks' => [
            'table' => 'gm_legal_blocks', 'title' => 'Legal text blocks', 'singular' => 'Legal text block', 'label_field' => 'title', 'cap' => 'gmlegal',
            'tags' => ['catalog'],
            'list' => [
                'columns' => [
                    'title' => ['label' => 'Title', 'link' => true, 'sort' => true],
                    'code' => ['label' => 'Code', 'sort' => true, 'hide_sm' => true],
                    'kind' => ['label' => 'Type', 'type' => 'badge', 'map' => [
                        'guarantee' => ['Guarantee', 'info'], 'withdrawal' => ['Withdrawal of digital content', 'warn'], 'compatibility' => ['Compatibility', 'info'],
                        'support' => ['Support', 'info'], 'other' => ['Other', ''],
                    ]],
                    'active' => ['label' => 'Active', 'type' => 'bool'],
                    'position' => ['label' => 'Position', 'sort' => true, 'hide_sm' => true],
                ],
                'search' => ['title', 'code', 'body'],
                'sort' => ['position', 'asc'],
                'per_page' => 25,
            ],
            'form' => static function (App $app, ?int $id): array {
                return BlockAdmin::form($app, $id);
            },
            'after_save' => [BlockAdmin::class, 'afterSave'],
            'after_delete' => [BlockAdmin::class, 'afterDelete'],
            'timestamps' => true,
            'help' => "A block is a legal text you write once: guarantee, withdrawal of digital content, compatibility, support.\nAssign it to categories or to products and it appears in the product page, in the checkout and, if you choose it, in the order email.\nEach line of the text is a paragraph.",
        ],
    ],
];

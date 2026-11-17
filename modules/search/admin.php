<?php
/**
 * AIFEC — buscador en el panel: permisos, menú y el recurso «synonyms» (la pantalla de búsquedas es propia, ver routes.php).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Search\SearchAdmin;

return [
    'caps' => [
        'search.view' => 'See the searches and the synonyms',
        'search.edit' => 'Change the synonyms and rebuild the search index',
    ],
    'menu' => [
        ['group' => 'marketing', 'items' => [
            ['label' => 'Search', 'url' => '/admin/search', 'cap' => 'search.view', 'order' => 30, 'tabs' => 'search', 'tab' => 'Queries', 'menu_label' => 'Search engine'],
            ['label' => 'Synonyms', 'resource' => 'synonyms', 'cap' => 'search.view', 'order' => 31, 'tabs' => 'search', 'menu_label' => 'Search engine'],
            ['label' => 'Search', 'settings' => 'search', 'cap' => 'search.edit', 'order' => 32, 'tabs' => 'search', 'tab' => 'Settings', 'menu_label' => 'Search engine'],
        ]],
    ],
    'resources' => [
        'synonyms' => [
            'table' => 'synonyms', 'title' => 'Synonyms', 'singular' => 'Synonym', 'label_field' => 'terms', 'cap' => 'search',
            'tags' => ['search'],
            'list' => static function (App $app): array {
                return SearchAdmin::listConfig($app);
            },
            'form' => static function (App $app, ?int $id): array {
                return SearchAdmin::formDefinition($app, $id);
            },
            'before_save' => [SearchAdmin::class, 'beforeSave'],
            'help' => "Synonyms let the search find a product when the customer uses another word for it.\nBoth ways: all the words mean the same. One way: searching the first word also finds the others, but not the other way round.\nA word can be a phrase, like «sofa bed».",
        ],
    ],
];

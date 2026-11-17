<?php
/**
 * AIFEC — catálogo en el panel: permisos, menú y los recursos de categorías, marcas, atributos y tipos de producto.
 *
 * Una sección por recurso, en este orden; el panel de productos (otro paquete) añade la suya al final de «resources».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Catalog\Admin\AttributeAdmin;
use Aifec\Modules\Catalog\Admin\BrandAdmin;
use Aifec\Modules\Catalog\Admin\CategoryAdmin;
use Aifec\Modules\Catalog\Admin\FamilyAdmin;
use Aifec\Modules\Catalog\Admin\ProductAdmin;
use Aifec\Modules\Catalog\Admin\ProductController;
use Aifec\Modules\Catalog\Admin\ProductForm;

return [
    'caps' => [
        'catalog.view' => 'See the catalog',
        'catalog.edit' => 'Change the catalog',
    ],
    'menu' => [
        ['group' => 'catalog', 'items' => [
            ['label' => 'Products', 'resource' => 'products', 'cap' => 'catalog.view', 'order' => 10],
            ['label' => 'Categories', 'resource' => 'categories', 'cap' => 'catalog.view', 'order' => 20],
            ['label' => 'Brands', 'resource' => 'brands', 'cap' => 'catalog.view', 'order' => 30],
            ['label' => 'Attributes', 'resource' => 'attributes', 'cap' => 'catalog.view', 'order' => 40, 'tabs' => 'attributes', 'menu_label' => 'Attributes'],
            ['label' => 'Product types', 'resource' => 'families', 'cap' => 'catalog.view', 'order' => 41, 'tabs' => 'attributes', 'menu_label' => 'Attributes'],
            ['label' => 'Stock and prices', 'url' => '/admin/catalog/stock', 'cap' => 'catalog.view', 'order' => 50],
        ]],
        ['group' => 'settings', 'items' => [
            ['label' => 'Catalog', 'settings' => 'catalog', 'cap' => 'catalog.edit', 'order' => 60],
        ]],
    ],
    'dashboard' => [
        ['id' => 'catalog.lowstock', 'cap' => 'catalog.view', 'size' => 6, 'order' => 30, 'render' => [ProductAdmin::class, 'widget']],
    ],
    'resources' => [
        // ------------------------------------------------------------------ categorías
        // El listado en árbol es una pantalla propia (routes.php); alta, ficha, guardado y borrado son los del recurso.
        'categories' => [
            'table' => 'categories', 'title' => 'Categories', 'singular' => 'Category', 'label_field' => 'name', 'cap' => 'catalog',
            'tags' => ['catalog'],
            'i18n' => ['table' => 'category_i18n', 'fk' => 'category_id', 'lang' => 'lang'],
            'list' => static function (App $app): array {
                return CategoryAdmin::listConfig($app);
            },
            'form' => static function (App $app, ?int $id): array {
                return CategoryAdmin::formDefinition($app, $id);
            },
            'before_save' => [CategoryAdmin::class, 'beforeSave'],
            'after_save' => [CategoryAdmin::class, 'afterSave'],
            'before_delete' => [CategoryAdmin::class, 'beforeDelete'],
            'after_delete' => [CategoryAdmin::class, 'afterDelete'],
            'actions' => [CategoryAdmin::class, 'actions'],
            'help' => "The address of a category includes the one of its parents. Moving a category changes the addresses of all its subcategories and the old ones keep redirecting.\nThe number of products counts the published ones, subcategories included.",
        ],

        // ------------------------------------------------------------------ marcas
        'brands' => [
            'table' => 'brands', 'title' => 'Brands', 'singular' => 'Brand', 'label_field' => 'name', 'cap' => 'catalog',
            'tags' => ['catalog'],
            'list' => static function (App $app): array {
                return BrandAdmin::listConfig($app);
            },
            'form' => static function (App $app, ?int $id): array {
                return BrandAdmin::formDefinition($app, $id);
            },
            'before_save' => [BrandAdmin::class, 'beforeSave'],
            'after_save' => [BrandAdmin::class, 'afterSave'],
            'before_delete' => [BrandAdmin::class, 'beforeDelete'],
            'after_delete' => [BrandAdmin::class, 'afterDelete'],
            'actions' => [BrandAdmin::class, 'actions'],
            'help' => 'A brand that has products cannot be deleted: turn it off instead.',
        ],

        // ------------------------------------------------------------------ atributos
        // Los valores de un atributo de lista se editan en su pantalla propia (routes.php).
        'attributes' => [
            'table' => 'attributes', 'title' => 'Attributes', 'singular' => 'Attribute', 'label_field' => 'name', 'cap' => 'catalog',
            'timestamps' => false,
            'tags' => ['catalog'],
            'i18n' => ['table' => 'attribute_i18n', 'fk' => 'attribute_id', 'lang' => 'lang'],
            'list' => static function (App $app): array {
                return AttributeAdmin::listConfig($app);
            },
            'form' => static function (App $app, ?int $id): array {
                return AttributeAdmin::formDefinition($app, $id);
            },
            'before_save' => [AttributeAdmin::class, 'beforeSave'],
            'after_save' => [AttributeAdmin::class, 'afterSave'],
            'before_delete' => [AttributeAdmin::class, 'beforeDelete'],
            'after_delete' => [AttributeAdmin::class, 'afterDelete'],
            'actions' => [AttributeAdmin::class, 'actions'],
            'help' => "Choice lists and colours have their own values: open the attribute and press Values.\nOnly choice lists and colours can make variants.",
        ],

        // ------------------------------------------------------------------ tipos de producto (familias)
        'families' => [
            'table' => 'families', 'title' => 'Product types', 'singular' => 'Product type', 'label_field' => 'name', 'cap' => 'catalog',
            'timestamps' => false,
            'tags' => ['catalog'],
            'list' => static function (App $app): array {
                return FamilyAdmin::listConfig($app);
            },
            'form' => static function (App $app, ?int $id): array {
                return FamilyAdmin::formDefinition($app, $id);
            },
            'after_save' => [FamilyAdmin::class, 'afterSave'],
            'before_delete' => [FamilyAdmin::class, 'beforeDelete'],
            'after_delete' => [FamilyAdmin::class, 'afterDelete'],
            'help' => 'A product type says which attributes are asked for when a product of that type is created.',
        ],

        // ------------------------------------------------------------------ productos
        // Alta, ficha, guardado y borrado son los del recurso; las variantes y la edición rápida de existencias son pantallas propias (routes.php).
        'products' => [
            'table' => 'products', 'title' => 'Products', 'singular' => 'Product', 'label_field' => 'name', 'cap' => 'catalog',
            'tags' => ['catalog'],
            'i18n' => ['table' => 'product_i18n', 'fk' => 'product_id', 'lang' => 'lang'],
            'list' => static function (App $app): array {
                return ProductAdmin::listConfig($app);
            },
            'form' => static function (App $app, ?int $id): array {
                return ProductForm::definition($app, $id);
            },
            'before_save' => [ProductAdmin::class, 'beforeSave'],
            'after_save' => [ProductAdmin::class, 'afterSave'],
            'before_delete' => [ProductAdmin::class, 'beforeDelete'],
            'after_delete' => [ProductAdmin::class, 'afterDelete'],
            'actions' => [ProductForm::class, 'actions'],
            'side' => [ProductController::class, 'side'],
            'bulk' => [
                'publish' => ['label' => 'Publish', 'confirm' => 'Publish the selected products?', 'run' => [ProductAdmin::class, 'bulkPublish']],
                'draft' => ['label' => 'Turn into drafts', 'confirm' => 'Turn the selected products into drafts? They disappear from the shop.', 'run' => [ProductAdmin::class, 'bulkDraft']],
                'archive' => ['label' => 'Archive', 'confirm' => 'Archive the selected products? They disappear from the shop.', 'run' => [ProductAdmin::class, 'bulkArchive']],
            ],
            'help' => "A product that appears in orders cannot be deleted: archive it instead.\nThe categories, images and other data are saved together with the product: if something fails, they stay as they were.\nA product with variants (colours, sizes…) is managed in its Variants screen.",
        ],
    ],
];

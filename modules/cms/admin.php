<?php
/**
 * AIFEC — contenidos en el panel: permisos, menú y los recursos de páginas, entradas, categorías y etiquetas del blog,
 * formularios, mensajes recibidos y redirecciones. Los menús, las direcciones no encontradas y las páginas legales son pantallas
 * propias (routes.php).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Cms\ContentAdmin;
use Aifec\Modules\Cms\EntryAdmin;
use Aifec\Modules\Cms\FormAdmin;
use Aifec\Modules\Cms\PageAdmin;
use Aifec\Modules\Cms\PostAdmin;
use Aifec\Modules\Cms\RedirectAdmin;
use Aifec\Modules\Cms\TermAdmin;

return [
    'caps' => [
        'content.view' => 'See the pages, posts, forms and redirections',
        'content.edit' => 'Write and change content',
        'content.publish' => 'Publish content',
    ],
    'menu' => [
        ['group' => 'content', 'items' => [
            ['label' => 'Pages', 'resource' => 'pages', 'cap' => 'content.view', 'order' => 10],
            ['label' => 'Posts', 'resource' => 'posts', 'cap' => 'content.view', 'order' => 20, 'tabs' => 'blog', 'menu_label' => 'Blog'],
            ['label' => 'Blog categories', 'resource' => 'post_categories', 'cap' => 'content.view', 'order' => 21, 'tabs' => 'blog', 'tab' => 'Categories', 'menu_label' => 'Blog'],
            ['label' => 'Blog tags', 'resource' => 'post_tags', 'cap' => 'content.view', 'order' => 22, 'tabs' => 'blog', 'tab' => 'Tags', 'menu_label' => 'Blog'],
            ['label' => 'Menus', 'url' => '/admin/cms/menus', 'cap' => 'content.view', 'order' => 40],
            ['label' => 'Forms', 'resource' => 'forms', 'cap' => 'content.view', 'order' => 50],
            ['label' => 'Legal pages', 'url' => '/admin/cms/legal', 'cap' => 'content.view', 'order' => 60],
            ['label' => 'Redirections', 'resource' => 'redirects', 'cap' => 'content.view', 'order' => 70, 'tabs' => 'redirects', 'menu_label' => 'Redirections'],
            ['label' => 'Not found', 'url' => '/admin/cms/not-found', 'cap' => 'content.view', 'order' => 71, 'tabs' => 'redirects', 'tab' => 'No result', 'menu_label' => 'Redirections'],
        ]],
        ['group' => 'customers', 'items' => [
            ['label' => 'Form messages', 'resource' => 'form_entries', 'cap' => 'content.view', 'order' => 30, 'menu_label' => 'Contact messages'],
        ]],
        ['group' => 'settings', 'items' => [
            ['label' => 'Content', 'settings' => 'cms', 'cap' => 'content.edit', 'order' => 70],
        ]],
    ],
    'resources' => [
        // ------------------------------------------------------------------ páginas
        'pages' => [
            'table' => 'pages', 'title' => 'Pages', 'singular' => 'Page', 'label_field' => 'title', 'cap' => 'content',
            'tags' => ['cms'],
            'i18n' => ['table' => 'page_i18n', 'fk' => 'page_id', 'lang' => 'lang'],
            'slug' => ['entity' => 'page', 'field' => 'slug', 'from' => 'title', 'prefix' => ''],
            'list' => static function (App $app): array {
                return PageAdmin::listConfig($app);
            },
            'form' => static function (App $app, ?int $id): array {
                return PageAdmin::formDefinition($app, $id);
            },
            'before_save' => [PageAdmin::class, 'beforeSave'],
            'after_save' => [PageAdmin::class, 'afterSave'],
            'before_delete' => [PageAdmin::class, 'beforeDelete'],
            'after_delete' => [PageAdmin::class, 'afterDelete'],
            'side' => [PageAdmin::class, 'side'],
            'bulk' => PageAdmin::bulk(),
            'help' => "A page is saved as a draft until someone with permission to publish changes its status.\nPages that are scheduled appear by themselves when their date arrives.",
        ],

        // ------------------------------------------------------------------ entradas del blog
        'posts' => [
            'table' => 'posts', 'title' => 'Posts', 'singular' => 'Post', 'label_field' => 'title', 'cap' => 'content',
            'tags' => ['cms'],
            'i18n' => ['table' => 'post_i18n', 'fk' => 'post_id', 'lang' => 'lang'],
            'slug' => ['entity' => 'post', 'field' => 'slug', 'from' => 'title', 'prefix' => ContentAdmin::prefix('post')],
            'list' => static function (App $app): array {
                return PostAdmin::listConfig($app);
            },
            'form' => static function (App $app, ?int $id): array {
                return PostAdmin::formDefinition($app, $id);
            },
            'before_save' => [PostAdmin::class, 'beforeSave'],
            'after_save' => [PostAdmin::class, 'afterSave'],
            'after_delete' => [PostAdmin::class, 'afterDelete'],
            'side' => [PostAdmin::class, 'side'],
            'bulk' => PostAdmin::bulk(),
            'help' => "The first image of a post is used as its featured image when you do not choose one.\nThe address starts with the word set in the Content settings.",
        ],

        // ------------------------------------------------------------------ categorías y etiquetas del blog
        'post_categories' => [
            'table' => 'terms', 'title' => 'Blog categories', 'singular' => 'Category', 'label_field' => 'name', 'cap' => 'content',
            'timestamps' => false, 'tags' => ['cms'],
            'scope' => ['taxonomy' => 'category'],
            'i18n' => ['table' => 'term_i18n', 'fk' => 'term_id', 'lang' => 'lang'],
            'slug' => ['entity' => 'term', 'field' => 'slug', 'from' => 'name', 'prefix' => ContentAdmin::prefix('category')],
            'list' => static function (App $app): array {
                return TermAdmin::listConfig($app, 'category');
            },
            'form' => static function (App $app, ?int $id): array {
                return TermAdmin::formDefinition($app, $id, 'category');
            },
            'before_save' => [TermAdmin::class, 'beforeSave'],
            'after_save' => [TermAdmin::class, 'afterSave'],
            'before_delete' => [TermAdmin::class, 'beforeDelete'],
            'after_delete' => [TermAdmin::class, 'afterDelete'],
            'help' => 'Categories group the posts of the blog. A post can have several.',
        ],
        'post_tags' => [
            'table' => 'terms', 'title' => 'Blog tags', 'singular' => 'Tag', 'label_field' => 'name', 'cap' => 'content',
            'timestamps' => false, 'tags' => ['cms'],
            'scope' => ['taxonomy' => 'tag'],
            'i18n' => ['table' => 'term_i18n', 'fk' => 'term_id', 'lang' => 'lang'],
            'slug' => ['entity' => 'term', 'field' => 'slug', 'from' => 'name', 'prefix' => ContentAdmin::prefix('tag')],
            'list' => static function (App $app): array {
                return TermAdmin::listConfig($app, 'tag');
            },
            'form' => static function (App $app, ?int $id): array {
                return TermAdmin::formDefinition($app, $id, 'tag');
            },
            'before_save' => [TermAdmin::class, 'beforeSave'],
            'after_save' => [TermAdmin::class, 'afterSave'],
            'after_delete' => [TermAdmin::class, 'afterDelete'],
            'help' => 'Tags are free words. You can also create them while writing a post.',
        ],

        // ------------------------------------------------------------------ formularios y mensajes
        'forms' => [
            'table' => 'forms', 'title' => 'Forms', 'singular' => 'Form', 'label_field' => 'name', 'cap' => 'content',
            'tags' => ['cms'],
            'list' => static function (App $app): array {
                return FormAdmin::listConfig($app);
            },
            'form' => static function (App $app, ?int $id): array {
                return FormAdmin::formDefinition($app, $id);
            },
            'actions' => [FormAdmin::class, 'actions'],
            'before_save' => [FormAdmin::class, 'beforeSave'],
            'after_save' => [FormAdmin::class, 'afterSave'],
            'before_delete' => [FormAdmin::class, 'beforeDelete'],
            'after_delete' => [FormAdmin::class, 'afterDelete'],
            'help' => "Put a form in any page with the Form block. The contact form is created with the shop.\nThe messages arrive in Form messages and, if you set an email address, are also sent to it.",
        ],
        'form_entries' => [
            'table' => 'form_entries', 'title' => 'Form messages', 'singular' => 'Message', 'label_field' => 'id', 'cap' => 'content',
            'timestamps' => false, 'can_create' => false, 'tags' => ['cms'],
            'list' => static function (App $app): array {
                return EntryAdmin::listConfig($app);
            },
            'form' => static function (App $app, ?int $id): array {
                return EntryAdmin::formDefinition($app, $id);
            },
            'bulk' => [
                'read' => ['label' => 'Mark as read', 'run' => static function (array $ids, App $app): string {
                    return EntryAdmin::markRead($ids, $app);
                }],
            ],
            'help' => 'The messages are written by visitors: they are shown as plain text. To download them all, open a form and press Export messages.',
        ],

        // ------------------------------------------------------------------ redirecciones
        'redirects' => [
            'table' => 'redirects', 'title' => 'Redirections', 'singular' => 'Redirection', 'label_field' => 'source', 'cap' => 'content',
            'timestamps' => false,
            'list' => static function (App $app): array {
                return RedirectAdmin::listConfig($app);
            },
            'form' => static function (App $app, ?int $id): array {
                return RedirectAdmin::formDefinition($app, $id);
            },
            'before_save' => [RedirectAdmin::class, 'beforeSave'],
            'help' => "A redirection sends the visitors of an address that no longer exists to another one.\nThe addresses of pages and posts that you change are redirected automatically: you do not need to add them here.",
        ],
    ],
];

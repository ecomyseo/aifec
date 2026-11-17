<?php
/**
 * AIFEC — esquema de los contenidos: páginas, entradas, categorías y etiquetas, versiones, menús y formularios.
 *
 * status: draft, scheduled (con published_at; se ve cuando llega su hora) o published. Los textos por idioma van en las
 * tablas *_i18n; los bloques, como JSON en texto. Las direcciones amigables viven en la tabla «slugs» del núcleo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;

return static function (Schema $schema, Db $db): void {
    $schema->create('pages', static function (TableDef $t): void {
        $t->id();
        $t->string('status', 12)->default('draft');
        $t->string('template', 40)->default('default');
        $t->bigInteger('parent_id')->nullable();
        $t->integer('position')->default(0);
        $t->boolean('noindex')->default(0);
        $t->boolean('is_home')->default(0);
        $t->datetime('published_at')->nullable();
        $t->bigInteger('author_id')->nullable();
        $t->timestamps();
        $t->index(['status', 'published_at']);
        $t->index(['parent_id']);
        $t->index(['is_home']);
    });

    $schema->create('page_i18n', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('page_id');
        $t->string('lang', 8);
        $t->string('title', 191);
        $t->text('blocks')->nullable();
        $t->text('excerpt')->nullable();
        $t->string('meta_title', 191)->nullable();
        $t->string('meta_description', 320)->nullable();
        $t->unique(['page_id', 'lang']);
    });

    $schema->create('posts', static function (TableDef $t): void {
        $t->id();
        $t->string('status', 12)->default('draft');
        $t->bigInteger('media_id')->nullable();
        $t->bigInteger('author_id')->nullable();
        $t->boolean('noindex')->default(0);
        $t->datetime('published_at')->nullable();
        $t->timestamps();
        $t->index(['status', 'published_at']);
    });

    $schema->create('post_i18n', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('post_id');
        $t->string('lang', 8);
        $t->string('title', 191);
        $t->text('blocks')->nullable();
        $t->text('excerpt')->nullable();
        $t->string('meta_title', 191)->nullable();
        $t->string('meta_description', 320)->nullable();
        $t->unique(['post_id', 'lang']);
    });

    // taxonomy: category o tag.
    $schema->create('terms', static function (TableDef $t): void {
        $t->id();
        $t->string('taxonomy', 12)->default('category');
        $t->bigInteger('parent_id')->nullable();
        $t->integer('position')->default(0);
        $t->index(['taxonomy', 'position']);
    });

    $schema->create('term_i18n', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('term_id');
        $t->string('lang', 8);
        $t->string('name', 191);
        $t->text('description')->nullable();
        $t->unique(['term_id', 'lang']);
    });

    $schema->create('post_terms', static function (TableDef $t): void {
        $t->bigInteger('post_id');
        $t->bigInteger('term_id');
        $t->primary(['post_id', 'term_id']);
        $t->index(['term_id']);
    });

    // Una fila por guardado. entity: page o post. lang vale «all»: cada versión guarda todos los idiomas a la vez.
    $schema->create('revisions', static function (TableDef $t): void {
        $t->id();
        $t->string('entity', 12);
        $t->bigInteger('entity_id');
        $t->string('lang', 8)->default('all');
        $t->text('data');
        $t->bigInteger('user_id')->nullable();
        $t->datetime('created_at');
        $t->index(['entity', 'entity_id', 'id']);
    });

    // code = ubicación del tema (main, footer, legal…).
    $schema->create('menus', static function (TableDef $t): void {
        $t->id();
        $t->string('code', 40);
        $t->string('name', 120);
        $t->unique(['code']);
    });

    // type: url, page, post, category (del catálogo), product, term (del blog) o home. labels: JSON idioma => etiqueta.
    $schema->create('menu_items', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('menu_id');
        $t->bigInteger('parent_id')->nullable();
        $t->integer('position')->default(0);
        $t->string('type', 12)->default('url');
        $t->bigInteger('ref')->nullable();
        $t->text('url')->nullable();
        $t->boolean('blank')->default(0);
        $t->text('labels')->nullable();
        $t->index(['menu_id', 'position']);
    });

    // fields: lista JSON de campos [{key, type, label, required, options}]. settings: JSON (notify_to, thanks).
    $schema->create('forms', static function (TableDef $t): void {
        $t->id();
        $t->string('code', 60);
        $t->string('name', 120);
        $t->text('fields')->nullable();
        $t->text('settings')->nullable();
        $t->timestamps();
        $t->unique(['code']);
    });

    // status: new o read.
    $schema->create('form_entries', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('form_id');
        $t->text('data');
        $t->string('ip', 45)->nullable();
        $t->string('status', 12)->default('new');
        $t->datetime('created_at');
        $t->index(['form_id', 'created_at']);
        $t->index(['status']);
    });

    // El formulario de contacto de serie. Los textos van en inglés y se traducen al pintarlos.
    // La fecha es fija a propósito: una migración no tiene reloj y así la base de una instalación nueva sale siempre igual.
    $now = '2026-01-01 00:00:00';
    $db->insertIgnore('forms', [
        'code' => 'contact',
        'name' => 'Contact',
        'fields' => json_encode([
            ['key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => 1, 'options' => []],
            ['key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => 1, 'options' => []],
            ['key' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => 1, 'options' => []],
            ['key' => 'consent', 'type' => 'consent', 'label' => 'I have read and accept the privacy policy', 'required' => 1, 'options' => []],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'settings' => json_encode(['notify_to' => '', 'thanks' => 'Thank you. We have received your message and we will answer soon.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
};

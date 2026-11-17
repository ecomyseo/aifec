<?php
/**
 * AIFEC — esquema base del núcleo: ajustes, acceso, tareas, direcciones, correo y medios.
 * Las tablas de catálogo, venta y contenidos las crea cada módulo en su carpeta migrations/.
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
    // Ajustes del sitio: clave => valor en JSON.
    $schema->create('settings', static function (TableDef $t): void {
        $t->string('k', 191);
        $t->text('v');
        $t->primary(['k']);
    });

    // Sesiones del panel (guard = admin) y de la tienda (guard = customer). id = resumen de la ficha de la cookie.
    $schema->create('sessions', static function (TableDef $t): void {
        $t->string('id', 64);
        $t->string('guard', 16);
        $t->bigInteger('user_id')->nullable();
        $t->text('data')->nullable();
        $t->datetime('created_at')->nullable();
        $t->datetime('last_at');
        $t->datetime('expires_at');
        $t->primary(['id']);
        $t->index(['expires_at']);
        $t->index(['guard', 'user_id']);
    });

    // Personas del equipo que entran al panel.
    $schema->create('users', static function (TableDef $t): void {
        $t->id();
        $t->string('email', 191);
        $t->string('name', 120);
        $t->string('password', 255);
        $t->string('role', 40);
        $t->string('locale', 8)->default('es');
        $t->string('totp_secret', 255)->nullable();
        $t->boolean('active')->default(1);
        $t->datetime('last_login_at')->nullable();
        $t->timestamps();
        $t->unique(['email']);
    });

    // Papeles y sus permisos (caps = lista JSON; «*» = todos; «catalog.*» = toda un área).
    $schema->create('roles', static function (TableDef $t): void {
        $t->id();
        $t->string('code', 40);
        $t->string('name', 80);
        $t->text('caps');
        $t->boolean('system')->default(0);
        $t->unique(['code']);
    });

    // Intentos de acceso, para frenar la fuerza bruta.
    $schema->create('login_attempts', static function (TableDef $t): void {
        $t->id();
        $t->string('guard', 16);
        $t->string('identifier', 191);
        $t->string('ip', 45);
        $t->boolean('ok')->default(0);
        $t->datetime('created_at');
        $t->index(['guard', 'identifier', 'created_at']);
        $t->index(['ip', 'created_at']);
    });

    // Fichas de un solo uso: acceso por enlace, restablecer contraseña, confirmar correo. Se guarda el resumen.
    $schema->create('tokens', static function (TableDef $t): void {
        $t->id();
        $t->string('kind', 32);
        $t->string('subject', 191);
        $t->string('hash', 64);
        $t->text('data')->nullable();
        $t->datetime('expires_at');
        $t->datetime('used_at')->nullable();
        $t->datetime('created_at');
        $t->unique(['hash']);
        $t->index(['kind', 'subject']);
    });

    // Tareas en segundo plano. status: pending, running, done, failed. Toda tarea acaba en done o failed con su motivo.
    $schema->create('jobs', static function (TableDef $t): void {
        $t->id();
        $t->string('queue', 32)->default('default');
        $t->string('type', 80);
        $t->text('payload')->nullable();
        $t->string('status', 16)->default('pending');
        $t->integer('attempts')->default(0);
        $t->integer('max_attempts')->default(3);
        $t->datetime('run_at');
        $t->datetime('locked_at')->nullable();
        $t->datetime('finished_at')->nullable();
        $t->text('error')->nullable();
        $t->text('result')->nullable();
        $t->string('unique_key', 120)->nullable();
        $t->datetime('created_at');
        $t->index(['status', 'run_at']);
        $t->index(['unique_key']);
        $t->index(['type', 'status']);
    });

    // Tareas periódicas: cuándo corrió cada una por última vez y cómo acabó.
    $schema->create('cron_runs', static function (TableDef $t): void {
        $t->string('task', 80);
        $t->datetime('last_run_at')->nullable();
        $t->datetime('last_ok_at')->nullable();
        $t->text('last_error')->nullable();
        $t->integer('runs')->default(0);
        $t->primary(['task']);
    });

    // Registro de auditoría: quién cambió qué.
    $schema->create('audit', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('user_id')->nullable();
        $t->string('user_name', 120)->nullable();
        $t->string('action', 80);
        $t->string('entity', 60)->nullable();
        $t->string('entity_id', 60)->nullable();
        $t->string('summary', 191)->nullable();
        $t->text('changes')->nullable();
        $t->string('ip', 45)->nullable();
        $t->datetime('created_at');
        $t->index(['entity', 'entity_id']);
        $t->index(['created_at']);
    });

    // Contadores de límite de uso (intentos, peticiones a la API).
    $schema->create('rate_limits', static function (TableDef $t): void {
        $t->string('k', 120);
        $t->integer('hits')->default(0);
        $t->integer('window_start')->default(0);
        $t->primary(['k']);
    });

    // Direcciones amigables: ruta completa por idioma => entidad. canonical = 0 son rutas antiguas que redirigen.
    $schema->create('slugs', static function (TableDef $t): void {
        $t->id();
        $t->string('lang', 8);
        $t->string('path', 191);
        $t->string('type', 32);
        $t->bigInteger('entity_id');
        $t->boolean('canonical')->default(1);
        $t->unique(['lang', 'path']);
        $t->index(['type', 'entity_id', 'lang']);
    });

    // Redirecciones manuales. source = ruta sin barra inicial ni final.
    $schema->create('redirects', static function (TableDef $t): void {
        $t->id();
        $t->string('source', 191);
        $t->text('target');
        $t->integer('code')->default(301);
        $t->integer('hits')->default(0);
        $t->boolean('active')->default(1);
        $t->string('note', 191)->nullable();
        $t->datetime('created_at');
        $t->unique(['source']);
    });

    // Direcciones pedidas que no existen, para que el comerciante pueda redirigirlas.
    $schema->create('not_found', static function (TableDef $t): void {
        $t->string('path', 191);
        $t->integer('hits')->default(1);
        $t->string('referer', 191)->nullable();
        $t->datetime('first_at');
        $t->datetime('last_at');
        $t->primary(['path']);
    });

    // Correo: cola y registro en la misma tabla. status: pending, held (retenido por el interruptor), sent, failed.
    $schema->create('mail_messages', static function (TableDef $t): void {
        $t->id();
        $t->string('to_email', 191);
        $t->string('to_name', 120)->nullable();
        $t->string('subject', 191);
        $t->string('template', 80);
        $t->string('locale', 8)->default('es');
        $t->text('data')->nullable();
        $t->text('html')->nullable();
        $t->text('text_body')->nullable();
        $t->string('status', 16)->default('pending');
        $t->integer('attempts')->default(0);
        $t->text('error')->nullable();
        $t->string('related', 60)->nullable();
        $t->datetime('created_at');
        $t->datetime('sent_at')->nullable();
        $t->index(['status', 'created_at']);
        $t->index(['related']);
    });

    // Biblioteca de medios. path = ruta relativa dentro de la carpeta de medios del sitio.
    $schema->create('media', static function (TableDef $t): void {
        $t->id();
        $t->string('path', 191);
        $t->string('filename', 191);
        $t->string('mime', 80);
        $t->string('kind', 16)->default('image');
        $t->integer('bytes')->default(0);
        $t->integer('width')->nullable();
        $t->integer('height')->nullable();
        $t->string('hash', 64)->nullable();
        $t->string('dominant', 7)->nullable();
        $t->text('alt')->nullable();
        $t->string('title', 191)->nullable();
        $t->text('variants')->nullable();
        $t->string('folder', 120)->nullable();
        $t->bigInteger('created_by')->nullable();
        $t->datetime('created_at');
        $t->index(['hash']);
        $t->index(['kind', 'created_at']);
    });

    // Claves para programas que usan la API privada. Se guarda el resumen de la clave.
    $schema->create('api_keys', static function (TableDef $t): void {
        $t->id();
        $t->string('name', 120);
        $t->string('key_hash', 64);
        $t->text('scopes')->nullable();
        $t->boolean('active')->default(1);
        $t->datetime('last_used_at')->nullable();
        $t->datetime('created_at');
        $t->unique(['key_hash']);
    });

    // Papeles de serie. Los permisos concretos los declaran los módulos (cada uno con el prefijo de su área:
    // catalog., orders., customers., coupons., reviews., invoices., media., content., seo., appearance., shipping., tax.…).
    // Un papel solo ve lo que aquí se le da: al añadir un área nueva, se decide a quién le toca.
    $roles = [
        ['admin', 'Administrator', ['*']],
        ['manager', 'Store manager', ['dashboard.*', 'orders.*', 'customers.*', 'catalog.*', 'coupons.*', 'reviews.*', 'invoices.*', 'media.*', 'content.*',
            'seo.*', 'search.view', 'shipping.view', 'tax.view', 'assistant.view', 'ai.view', 'ai.use', 'returns.*']],
        ['editor', 'Editor', ['dashboard.view', 'content.*', 'media.*', 'seo.*', 'reviews.view', 'ai.use']],
        ['support', 'Support', ['dashboard.view', 'orders.view', 'orders.notes', 'customers.view', 'reviews.view', 'invoices.view', 'returns.view', 'assistant.view']],
        ['demo', 'Demo', ['dashboard.*', 'orders.*', 'customers.view', 'catalog.*', 'coupons.*', 'reviews.*', 'invoices.view', 'media.*', 'content.*', 'seo.*',
            'appearance.view', 'appearance.edit', 'search.view', 'shipping.view', 'tax.view', 'assistant.view', 'ai.view', 'ai.use', 'plugins.view', 'tools.view']],
    ];
    foreach ($roles as $role) {
        $db->insertIgnore('roles', ['code' => $role[0], 'name' => $role[1], 'caps' => json_encode($role[2]), 'system' => 1]);
    }
};

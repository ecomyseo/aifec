<?php
/**
 * AIFEC — AI-First E-Commerce.
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
    // Un ERP externo = una conexión = una clave de api_keys (de ahí salen el resumen de la clave y sus ámbitos).
    // key_hint son los últimos caracteres de la clave, para reconocerla en el panel; la clave entera no se guarda nunca.
    $schema->create('erp_connections', static function (TableDef $t): void {
        $t->id();
        $t->string('name', 120);
        $t->bigInteger('api_key_id');
        $t->string('key_hint', 24);
        $t->text('scopes')->nullable();
        $t->boolean('active')->default(1);
        $t->datetime('revoked_at')->nullable();
        $t->datetime('last_used_at')->nullable();
        $t->timestamps();
        $t->unique(['api_key_id']);
    });

    // Una suscripción de avisos por conexión. since_at es el «cursor» de la regla bloqueante: solo salen avisos de lo que ocurre
    // desde esa hora; se pone en «ahora» al crear y al reactivar. failures = intentos fallidos seguidos (a los 5 se pausa).
    $schema->create('erp_webhooks', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('connection_id');
        $t->string('url', 255);
        $t->text('events')->nullable();
        $t->boolean('active')->default(1);
        $t->boolean('paused')->default(0);
        $t->string('paused_reason', 255)->nullable();
        $t->integer('failures')->default(0);
        $t->datetime('since_at');
        $t->timestamps();
        $t->unique(['connection_id']);
    });

    // status: pending (espera su turno), held (apartado: avisos apagados o suscripción pausada), sending, sent, failed, expired
    // (tenía más de 24 horas y no se envía solo; se puede reenviar a mano).
    $schema->create('erp_deliveries', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('webhook_id');
        $t->bigInteger('connection_id');
        $t->string('event', 40);
        $t->string('event_id', 40);
        $t->text('payload');
        $t->string('status', 12)->default('pending');
        $t->integer('attempts')->default(0);
        $t->integer('response_code')->nullable();
        $t->string('error', 255)->nullable();
        $t->datetime('next_attempt_at')->nullable();
        $t->datetime('sent_at')->nullable();
        $t->datetime('created_at');
        $t->datetime('updated_at');
        $t->index(['status', 'next_attempt_at']);
        $t->index(['webhook_id', 'created_at']);
    });

    // Perfiles de exportación y de importación. direction: export o import. kind: orders, order_lines, customers, products, stock,
    // invoices (importación: products). columns = [{field, header, value}] (field «_fixed» = valor fijo). options, filter y dest son
    // JSON. destination: download, folder, ftp, ftps, post, email (importación: folder, url, ftp, ftps). schedule: manual, minutes,
    // hourly, daily. next_run_at se calcula a partir de «ahora»: nunca se recupera lo que no corrió.
    $schema->create('erp_exports', static function (TableDef $t): void {
        $t->id();
        $t->string('name', 120);
        $t->string('direction', 8)->default('export');
        $t->string('kind', 24);
        $t->boolean('enabled')->default(0);
        $t->string('format', 8)->default('csv');
        $t->text('columns')->nullable();
        $t->text('options')->nullable();
        $t->text('filter')->nullable();
        $t->string('destination', 12)->default('download');
        $t->text('dest')->nullable();
        $t->string('schedule', 10)->default('manual');
        $t->integer('schedule_every')->nullable();
        $t->string('schedule_at', 5)->nullable();
        $t->datetime('next_run_at')->nullable();
        $t->datetime('last_run_at')->nullable();
        $t->timestamps();
        $t->index(['direction', 'enabled', 'next_run_at']);
    });

    // trigger: manual, schedule, cli, test. status: running, ok, empty, failed, blocked, rejected (importación con demasiadas líneas malas).
    $schema->create('erp_export_runs', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('profile_id');
        $t->string('direction', 8)->default('export');
        $t->string('trigger_kind', 10)->default('manual');
        $t->string('status', 10)->default('running');
        $t->integer('records')->default(0);
        $t->integer('bytes')->default(0);
        $t->string('destination', 12)->nullable();
        $t->string('file', 190)->nullable();
        $t->string('file_name', 190)->nullable();
        $t->datetime('expires_at')->nullable();
        $t->text('error')->nullable();
        $t->text('report')->nullable();
        $t->datetime('started_at');
        $t->datetime('finished_at')->nullable();
        $t->index(['profile_id', 'started_at']);
        $t->index(['expires_at']);
    });

    // Por dónde va cada perfil «solo lo nuevo»: scope «export:<id>», value = marca de lo último entregado.
    $schema->create('erp_cursors', static function (TableDef $t): void {
        $t->string('scope', 60);
        $t->string('value', 120);
        $t->datetime('updated_at');
        $t->primary(['scope']);
    });

    // El ERP confirma que ha recibido un pedido y dice con qué identificador lo guarda.
    $schema->create('erp_acks', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('order_id');
        $t->bigInteger('connection_id');
        $t->string('external_id', 120);
        $t->datetime('acked_at');
        $t->unique(['order_id', 'connection_id']);
    });

    // Respuestas ya dadas a una escritura con Idempotency-Key. status_code 0 = en curso.
    $schema->create('erp_idempotency', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('connection_id');
        $t->string('idem_key', 120);
        $t->string('fingerprint', 64);
        $t->integer('status_code')->default(0);
        $t->text('body')->nullable();
        $t->datetime('created_at');
        $t->unique(['connection_id', 'idem_key']);
        $t->index(['created_at']);
    });

    // Tres perfiles de ejemplo, apagados y a mano.
    $seed = '2026-11-13 00:00:00';
    $db->insert('erp_exports', [
        'name' => 'Order lines (CSV, semicolon)', 'direction' => 'export', 'kind' => 'order_lines', 'enabled' => 0, 'format' => 'csv',
        'columns' => json_encode([
            ['field' => 'number', 'header' => 'Order', 'value' => ''], ['field' => 'placed_at', 'header' => 'Date', 'value' => ''],
            ['field' => 'email', 'header' => 'Email', 'value' => ''], ['field' => 'line_sku', 'header' => 'SKU', 'value' => ''],
            ['field' => 'line_name', 'header' => 'Description', 'value' => ''], ['field' => 'line_qty', 'header' => 'Quantity', 'value' => ''],
            ['field' => 'line_unit_price', 'header' => 'Unit price', 'value' => ''], ['field' => 'line_total', 'header' => 'Line total', 'value' => ''],
            ['field' => 'line_tax_rate', 'header' => 'Tax rate', 'value' => ''],
        ]),
        'options' => json_encode(['delimiter' => ';', 'quote' => '"', 'encoding' => 'utf8bom', 'decimal' => 'comma', 'date_format' => 'date', 'eol' => 'crlf']),
        'filter' => json_encode(['statuses' => ['processing', 'shipped', 'completed'], 'only_new' => true]),
        'destination' => 'download', 'dest' => '{}', 'schedule' => 'manual', 'created_at' => $seed, 'updated_at' => $seed,
    ]);
    $db->insert('erp_exports', [
        'name' => 'Stock levels (CSV)', 'direction' => 'export', 'kind' => 'stock', 'enabled' => 0, 'format' => 'csv',
        'columns' => json_encode([
            ['field' => 'sku', 'header' => 'SKU', 'value' => ''], ['field' => 'stock', 'header' => 'Stock', 'value' => ''],
        ]),
        'options' => json_encode(['delimiter' => ',', 'quote' => '"', 'encoding' => 'utf8', 'decimal' => 'dot', 'date_format' => 'iso', 'eol' => 'lf']),
        'filter' => json_encode(['statuses' => [], 'only_new' => false]),
        'destination' => 'download', 'dest' => '{}', 'schedule' => 'manual', 'created_at' => $seed, 'updated_at' => $seed,
    ]);
    $db->insert('erp_exports', [
        'name' => 'Customers (JSON)', 'direction' => 'export', 'kind' => 'customers', 'enabled' => 0, 'format' => 'json',
        'columns' => json_encode([
            ['field' => 'email', 'header' => 'email', 'value' => ''], ['field' => 'first_name', 'header' => 'first_name', 'value' => ''],
            ['field' => 'last_name', 'header' => 'last_name', 'value' => ''], ['field' => 'company', 'header' => 'company', 'value' => ''],
            ['field' => 'vat_id', 'header' => 'vat_id', 'value' => ''], ['field' => 'phone', 'header' => 'phone', 'value' => ''],
        ]),
        'options' => json_encode(['layout' => 'list', 'root' => 'customers', 'item' => 'customer']),
        'filter' => json_encode(['statuses' => [], 'only_new' => true]),
        'destination' => 'download', 'dest' => '{}', 'schedule' => 'manual', 'created_at' => $seed, 'updated_at' => $seed,
    ]);
};

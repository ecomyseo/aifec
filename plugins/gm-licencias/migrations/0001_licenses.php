<?php
/**
 * AIFEC — GM licences: las tablas de las licencias, los dominios activados, lo que se comprueba y los productos con licencia.
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
    // Qué productos llevan licencia. kind: domain (clave aleatoria con dominios activados) o signed (clave firmada atada a un NIF,
    // que se comprueba sin conexión). max_domains: 0 = sin tope. update_months: meses de actualizaciones (0 = sin caducidad).
    $schema->create('gm_license_products', static function (TableDef $t): void {
        $t->bigInteger('product_id');
        $t->string('kind', 10)->default('domain');
        $t->integer('max_domains')->default(1);
        $t->integer('update_months')->default(12);
        $t->datetime('updated_at');
        $t->primary(['product_id']);
    });

    // Una licencia por unidad comprada (la firmada, una por línea: lleva el número de unidades dentro de la clave). La clave se
    // guarda tal cual porque el cliente tiene que poder verla. status: active, revoked, pending (firmada sin NIF válido).
    // order_item_id y unit son nulos en las licencias dadas de alta a mano. updates_until: hasta cuándo incluye actualizaciones.
    $schema->create('gm_licenses', static function (TableDef $t): void {
        $t->id();
        $t->string('license_key', 64);
        $t->string('kind', 10)->default('domain');
        $t->string('status', 10)->default('active');
        $t->bigInteger('order_id')->nullable();
        $t->bigInteger('order_item_id')->nullable();
        $t->integer('unit')->nullable();
        $t->bigInteger('customer_id')->nullable();
        $t->string('email', 191);
        $t->bigInteger('product_id')->nullable();
        $t->string('product_name', 191)->default('');
        $t->integer('max_domains')->default(1);
        $t->integer('units')->default(1);
        $t->string('holder', 40)->default('');
        $t->datetime('updates_until')->nullable();
        $t->text('note')->nullable();
        $t->datetime('created_at');
        $t->datetime('updated_at');
        $t->datetime('revoked_at')->nullable();
        $t->unique(['license_key']);
        $t->unique(['order_item_id', 'unit']);
        $t->index(['order_id']);
        $t->index(['email']);
        $t->index(['customer_id']);
    });

    // Los dominios en los que está activada cada licencia.
    $schema->create('gm_license_domains', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('license_id');
        $t->string('domain', 191);
        $t->datetime('activated_at');
        $t->datetime('last_seen_at');
        $t->unique(['license_id', 'domain']);
    });

    // Registro de comprobaciones de la API: qué pasó y cuándo, sin dirección ni dominio ni correo. Se borra a los 90 días.
    $schema->create('gm_license_checks', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('license_id')->nullable();
        $t->string('action', 12);
        $t->string('result', 16);
        $t->datetime('created_at');
        $t->index(['created_at']);
        $t->index(['license_id']);
    });
};

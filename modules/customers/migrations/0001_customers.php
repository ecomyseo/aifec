<?php
/**
 * AIFEC — esquema de clientes: cuentas, direcciones, grupos, consentimientos y desistimientos.
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
    // guest = compró sin crear cuenta (sin contraseña). El correo es único entre las cuentas con contraseña;
    // un invitado que vuelve a comprar reutiliza su fila.
    $schema->create('customers', static function (TableDef $t): void {
        $t->id();
        $t->string('email', 191);
        $t->string('password', 255)->nullable();
        $t->boolean('guest')->default(0);
        $t->string('first_name', 80)->nullable();
        $t->string('last_name', 120)->nullable();
        $t->string('company', 160)->nullable();
        $t->string('vat_id', 40)->nullable();
        $t->string('phone', 40)->nullable();
        $t->bigInteger('group_id')->nullable();
        $t->string('locale', 8)->default('es');
        $t->boolean('active')->default(1);
        $t->boolean('marketing')->default(0);
        $t->datetime('marketing_at')->nullable();
        $t->text('notes')->nullable();
        $t->integer('orders_count')->default(0);
        $t->integer('total_spent')->default(0);
        $t->string('source', 40)->nullable();
        $t->string('legacy_id', 60)->nullable();
        $t->datetime('email_verified_at')->nullable();
        $t->datetime('last_login_at')->nullable();
        $t->timestamps();
        $t->unique(['email']);
        $t->index(['group_id']);
        $t->index(['legacy_id']);
    });

    // region = código de provincia o estado (ES: dos cifras, «35» Las Palmas). country = ISO de dos letras.
    $schema->create('addresses', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('customer_id');
        $t->string('label', 60)->nullable();
        $t->string('first_name', 80);
        $t->string('last_name', 120)->nullable();
        $t->string('company', 160)->nullable();
        $t->string('vat_id', 40)->nullable();
        $t->string('phone', 40)->nullable();
        $t->string('line1', 191);
        $t->string('line2', 191)->nullable();
        $t->string('postcode', 16);
        $t->string('city', 120);
        $t->string('region', 16)->nullable();
        $t->string('country', 2)->default('ES');
        $t->boolean('default_shipping')->default(0);
        $t->boolean('default_billing')->default(0);
        $t->timestamps();
        $t->index(['customer_id']);
    });

    // discount = descuento del grupo en centésimas de punto (500 = 5 %). tax_exempt = no paga impuestos (intracomunitario).
    $schema->create('customer_groups', static function (TableDef $t): void {
        $t->id();
        $t->string('name', 80);
        $t->integer('discount')->default(0);
        $t->boolean('tax_exempt')->default(0);
        $t->boolean('is_default')->default(0);
    });

    // Prueba de cada consentimiento: qué aceptó, cuándo y con qué versión del texto. kind: terms, privacy, marketing, withdrawal_waiver.
    $schema->create('consents', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('customer_id')->nullable();
        $t->string('email', 191)->nullable();
        $t->string('kind', 32);
        $t->boolean('granted')->default(1);
        $t->string('version', 40)->nullable();
        $t->string('context', 60)->nullable();
        $t->string('ip', 45)->nullable();
        $t->datetime('created_at');
        $t->index(['customer_id', 'kind']);
        $t->index(['email', 'kind']);
    });

    // Desistimientos (devoluciones dentro del plazo legal). status: requested, accepted, rejected, received, refunded.
    $schema->create('withdrawals', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('order_id');
        $t->bigInteger('customer_id')->nullable();
        $t->string('status', 16)->default('requested');
        $t->text('items')->nullable();
        $t->text('reason')->nullable();
        $t->text('resolution')->nullable();
        $t->datetime('created_at');
        $t->datetime('resolved_at')->nullable();
        $t->index(['order_id']);
        $t->index(['status', 'created_at']);
    });

    $db->insertIgnore('customer_groups', ['id' => 1, 'name' => 'Customers', 'discount' => 0, 'tax_exempt' => 0, 'is_default' => 1]);
};

<?php
/**
 * AIFEC — apoyo de las pruebas de cupones: aplicación con el módulo cargado y ayudas para preparar datos.
 *
 * No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\Totals;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;
use Aifec\Kernel\App;
use Aifec\Modules\Coupons\Coupons;

/** Sitio de prueba con solo el módulo de cupones (y el núcleo), ya arrancado. */
function couponsTestApp(): App
{
    $app = testApp(['modules' => ['coupons']]);
    $app->boot();

    return $app;
}

function couponsService(App $app): Coupons
{
    $service = $app->service('coupons');
    check($service instanceof Coupons, 'el servicio coupons es el del módulo');

    return $service;
}

/**
 * Da de alta un cupón (por defecto: OTONO, 10 %, activo, sin límites) y devuelve su identificador.
 *
 * @param array<string,mixed> $data
 */
function couponsMake(App $app, array $data = []): int
{
    $now = $app->clock()->sql();

    return $app->db()->insert('coupons', $data + [
        'code' => 'OTONO', 'kind' => 'percent', 'percent' => 1000, 'amount' => 0, 'min_subtotal' => 0,
        'max_uses' => 0, 'max_uses_per_customer' => 0, 'uses' => 0, 'first_order_only' => 0, 'exclude_sale' => 0,
        'combinable' => 0, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
}

/**
 * @return array<string,mixed>
 */
function couponsRow(App $app, int $id): array
{
    $row = $app->db()->row('SELECT * FROM {coupons} WHERE id = ?', [$id]);
    check($row !== null, 'el cupón ' . $id . ' existe');

    return (array) $row;
}

function couponsCount(App $app, string $table, string $where = '1 = 1', array $params = []): int
{
    return (int) $app->db()->value('SELECT COUNT(*) FROM {' . $table . '} WHERE ' . $where, $params);
}

/**
 * Una línea de carrito con la forma del contrato.
 *
 * @param array<int,int> $categories
 *
 * @return array<string,mixed>
 */
function couponsLine(string $key, int $productId, int $unitPrice, int $qty = 1, array $categories = [], bool $onSale = false): array
{
    return ['key' => $key, 'product_id' => $productId, 'category_ids' => $categories, 'unit_price' => $unitPrice, 'qty' => $qty, 'on_sale' => $onSale];
}

/**
 * Carrito de prueba. Por defecto: p1 (100,00 €, categoría 10) y p2 (2 × 25,00 €, categoría 20) = 150,00 € (15000 céntimos).
 *
 * @param array<int,array<string,mixed>>|null $lines
 * @param array<string,mixed>                 $extra customer_id, email, items_total…
 *
 * @return array<string,mixed>
 */
function couponsCart(?array $lines = null, array $extra = []): array
{
    $lines = $lines !== null ? $lines : [couponsLine('p1', 1, 10000, 1, [10]), couponsLine('p2', 2, 2500, 2, [20])];
    $total = 0;
    foreach ($lines as $line) {
        $total += $line['unit_price'] * $line['qty'];
    }

    return $extra + ['lines' => $lines, 'items_total' => $total, 'customer_id' => null, 'email' => ''];
}

/**
 * Pasa los descuentos por la cuenta única del dinero con el carrito de prueba por defecto (precios con IVA dentro).
 *
 * @param array<int,array<string,mixed>> $discounts
 * @param array<string,mixed>|null       $shipping
 *
 * @return array<string,mixed>
 */
function couponsTotals(array $discounts, ?array $shipping = null): array
{
    return Totals::calculate([
        'prices_include_tax' => true,
        'lines' => [
            ['key' => 'p1', 'unit_price' => 10000, 'qty' => 1, 'tax_rate' => 2100, 'tax_name' => 'IVA 21 %'],
            ['key' => 'p2', 'unit_price' => 2500, 'qty' => 2, 'tax_rate' => 2100, 'tax_name' => 'IVA 21 %'],
        ],
        'discounts' => $discounts,
        'shipping' => $shipping,
    ]);
}

/** Un uso ya apuntado (sin pasar por redeem), para preparar escenarios. */
function couponsUse(App $app, int $couponId, int $orderId, ?int $customerId, ?string $email, int $amount = 0, ?string $at = null): int
{
    return $app->db()->insert('coupon_uses', [
        'coupon_id' => $couponId, 'order_id' => $orderId, 'customer_id' => $customerId, 'email' => $email,
        'amount' => $amount, 'created_at' => $at !== null ? $at : $app->clock()->sql(),
    ]);
}

/** La tabla de pedidos con lo mínimo que mira el módulo (el de pedidos no se carga en estas pruebas). */
function couponsMakeOrdersTable(App $app): void
{
    (new Schema($app->db()))->create('orders', static function (TableDef $t): void {
        $t->id();
        $t->string('number', 32)->nullable();
        $t->bigInteger('customer_id')->nullable();
        $t->string('email', 191);
        $t->string('payment_status', 20)->default('pending');
    });
}

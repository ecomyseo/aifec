<?php
/**
 * AIFEC — escritor de cupones: crea con sus usos ya hechos (el límite sigue valiendo), repite sin cambios, actualiza sin perder los usos de
 * aquí, choca con un código que ya existía, convierte el importe al criterio de impuestos de aquí y NUNCA deja un cupón más generoso que en
 * el origen: lo que no se pueda restringir entra inactivo y en «revisa a mano».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Writers\CouponsWriter;

require_once __DIR__ . '/WritersKit2.php';

/** Un cupón neutro: el de ejemplo con cambios. */
function pwCoupon(array $over = []): array
{
    return wr('coupons', $over);
}

pwTest('el cupón de ejemplo se escribe campo a campo, con sus usos y sus categorías de aquí', function (): void {
    [$app, $transport, $ctx] = pwOrderSite();
    $out = (new CouponsWriter($app))->write(pwCoupon(), $ctx);
    same(TypeWriter::CREATED, $out['result']);
    $row = $app->db()->find('coupons', ['id' => $out['id']]);
    $category = $ctx->localId('categories', '12');

    same(['OTOÑO10', 'percent', 1000, 0, 5000], [$row['code'], $row['kind'], (int) $row['percent'], (int) $row['amount'], (int) $row['min_subtotal']]);
    same(['2026-09-21 00:00:00', '2026-12-21 00:00:00'], [$row['starts_at'], $row['ends_at']], 'las fechas del origen');
    same([200, 1, 37], [(int) $row['max_uses'], (int) $row['max_uses_per_customer'], (int) $row['uses']], 'los usos ya hechos');
    same([0, 1, 0, 1], [(int) $row['first_order_only'], (int) $row['exclude_sale'], (int) $row['combinable'], (int) $row['active']]);
    same([null, null, json_encode([$category]), 'Campaña de otoño'], [$row['customer_id'], $row['products'], $row['categories'], $row['note']]);
    same([], $ctx->noteTexts('coupons'), 'no hay nada que avisar');
    pwSilent($app, $transport, 'tras traer un cupón');
});

pwTest('el límite de usos cuenta los de antes: el canje número max_uses + 1 se rechaza con el servicio de cupones', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $writer = new CouponsWriter($app);
    $id = (int) $writer->write(pwCoupon(['max_uses' => 38, 'uses' => 37]), $ctx)['id'];
    $service = $app->service('coupons');

    same(true, $service->redeem($id, 9001, null, 'a@cliente.test', 100), 'queda un uso');
    same(false, $service->redeem($id, 9002, null, 'b@cliente.test', 100), 'el siguiente se rechaza');
    same(38, (int) wrValue($app, 'SELECT uses FROM {coupons} WHERE id = ?', [$id]));

    $full = (int) $writer->write(pwCoupon(['id' => '9', 'code' => 'AGOTADO', 'max_uses' => 20, 'uses' => 20]), $ctx)['id'];
    $check = $service->check('AGOTADO', ['lines' => [], 'items_total' => 10000, 'customer_id' => null, 'email' => 'a@cliente.test']);
    same(false, $check['ok']);
    contains('agotado', $check['error'], 'agotado desde el primer día');
    same(false, $service->redeem($full, 9003, null, 'c@cliente.test', 100));
});

pwTest('repetir el cupón no cambia ninguna tabla; cambiarlo lo actualiza sin perder los usos que se canjearon aquí', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $writer = new CouponsWriter($app);
    $id = (int) $writer->write(pwCoupon(), $ctx)['id'];
    $counts = wrCounts($app);

    same(TypeWriter::UNCHANGED, $writer->write(pwCoupon(), $ctx)['result']);
    same($counts, wrCounts($app));
    $app->service('coupons')->redeem($id, 9001, null, 'a@cliente.test', 100);
    $app->db()->run('UPDATE {coupons} SET updated_at = ? WHERE id = ?', [wrValue($app, 'SELECT created_at FROM {coupons} WHERE id = ?', [$id]), $id]);
    $changed = $writer->write(pwCoupon(['percent' => 1500, 'uses' => 38]), $ctx);
    same(TypeWriter::UPDATED, $changed['result']);
    same(1500, (int) wrValue($app, 'SELECT percent FROM {coupons} WHERE id = ?', [$id]));
    same(38, (int) wrValue($app, 'SELECT uses FROM {coupons} WHERE id = ?', [$id]), 'el mayor de los dos: el de aquí (38) no se pisa con el del origen');
    $writer->write(pwCoupon(['percent' => 1600, 'uses' => 10]), $ctx);
    same(38, (int) wrValue($app, 'SELECT uses FROM {coupons} WHERE id = ?', [$id]), 'y nunca baja');
});

pwTest('un cupón editado aquí después de traerlo choca; overwrite_local lo pisa; el simulacro no escribe nada', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $writer = new CouponsWriter($app);
    $id = (int) $writer->write(pwCoupon(), $ctx)['id'];
    $app->clock()->advance(300);
    $app->db()->update('coupons', ['percent' => 500, 'updated_at' => $app->clock()->sql()], ['id' => $id]);
    $app->clock()->advance(300);

    $out = $writer->write(pwCoupon(['percent' => 2000]), $ctx);
    same(TypeWriter::CONFLICT, $out['result']);
    same($id, $out['id']);
    same(500, (int) wrValue($app, 'SELECT percent FROM {coupons} WHERE id = ?', [$id]), 'lo de aquí queda intacto');

    $dry = writersContext($app)->dry(true);
    $counts = wrCounts($app);
    same(TypeWriter::CREATED, $writer->write(pwCoupon(['id' => '9', 'code' => 'NUEVO']), $dry)['result']);
    same(TypeWriter::CONFLICT, $writer->write(pwCoupon(['percent' => 2000]), $dry)['result']);
    same($counts, wrCounts($app));

    same(TypeWriter::UPDATED, $writer->write(pwCoupon(['percent' => 2000]), writersContext($app, ['overwrite_local' => true]))['result']);
    same(2000, (int) wrValue($app, 'SELECT percent FROM {coupons} WHERE id = ?', [$id]));
});

pwTest('un código que ya existe aquí y no vino de este origen choca y no se toca', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $now = $app->clock()->sql();
    $mine = $app->db()->insert('coupons', ['code' => 'OTOÑO10', 'kind' => 'fixed', 'percent' => 0, 'amount' => 500, 'uses' => 3, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $out = (new CouponsWriter($app))->write(pwCoupon(), $ctx);
    same(TypeWriter::CONFLICT, $out['result']);
    same($mine, $out['id']);
    contains('already exists', $out['reason']);
    same(['fixed', 500, 3], [wrValue($app, 'SELECT kind FROM {coupons} WHERE id = ?', [$mine]), (int) wrValue($app, 'SELECT amount FROM {coupons} WHERE id = ?', [$mine]), (int) wrValue($app, 'SELECT uses FROM {coupons} WHERE id = ?', [$mine])]);
    same(1, wrCount($app, 'coupons'));
    same(0, wrCount($app, 'import_map', "type = 'coupons'"), 'ni se casa con él');
});

pwTest('una restricción que aquí no se puede poner deja el cupón inactivo, con nota y en «revisa a mano»; una facilidad que se pierde, activo con nota', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $writer = new CouponsWriter($app);
    $generous = [
        'excluded products: 5,6', 'restricted to emails: ana@cliente.test', 'maximum spend 500', 'limit of 3 items', 'carrier_restriction', 'country_restriction',
        'cheapest_product', 'fixed discount per product (applied here to the whole cart)', 'algo que nadie conoce',
    ];
    foreach ($generous as $i => $reason) {
        $id = (int) $writer->write(pwCoupon(['id' => 'g' . $i, 'code' => 'G' . $i, 'unsupported' => [$reason]]), $ctx)['id'];
        same(0, (int) wrValue($app, 'SELECT active FROM {coupons} WHERE id = ?', [$id]), 'inactivo por: ' . $reason);
    }
    contains('more generous', implode(' ', $ctx->noteTexts('coupons')));
    same(count($generous), $ctx->unmappedCount('review', 'Coupon brought inactive'), 'todos en «revisa a mano»');

    $before = count($ctx->noteTexts('coupons'));
    foreach (['gift', 'free_shipping_with_discount', 'combinations'] as $i => $facility) {
        $id = (int) $writer->write(pwCoupon(['id' => 'f' . $i, 'code' => 'F' . $i, 'unsupported' => [$facility]]), $ctx)['id'];
        same(1, (int) wrValue($app, 'SELECT active FROM {coupons} WHERE id = ?', [$id]), 'activo aunque pierda: ' . $facility);
    }
    same($before + 3, count($ctx->noteTexts('coupons')), 'cada una con su nota');
    same(count($generous), $ctx->unmappedCount('review', 'Coupon brought inactive'), 'y ninguna de estas va a «revisa a mano»');
});

pwTest('un cupón apagado en el origen entra apagado', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $id = (int) (new CouponsWriter($app))->write(pwCoupon(['active' => false]), $ctx)['id'];
    same(0, (int) wrValue($app, 'SELECT active FROM {coupons} WHERE id = ?', [$id]));
    same([], $ctx->noteTexts('coupons'), 'sin nota: no ha cambiado nada');
});

pwTest('si la lista de productos o categorías a la que iba limitado no se trajo, entra inactivo; si solo falta una parte, activo con nota', function (): void {
    [$app, , $ctx, $product] = pwOrderSite();
    $writer = new CouponsWriter($app);
    $none = (int) $writer->write(pwCoupon(['id' => 'p1', 'code' => 'SOLOP', 'product_ids' => ['998'], 'category_ids' => []]), $ctx)['id'];
    same(0, (int) wrValue($app, 'SELECT active FROM {coupons} WHERE id = ?', [$none]), 'sin ninguno de sus productos, valdría para todo');
    $partial = (int) $writer->write(pwCoupon(['id' => 'p2', 'code' => 'ALGUNOS', 'product_ids' => ['101', '998'], 'category_ids' => []]), $ctx)['id'];
    same([1, json_encode([$product])], [(int) wrValue($app, 'SELECT active FROM {coupons} WHERE id = ?', [$partial]), wrValue($app, 'SELECT products FROM {coupons} WHERE id = ?', [$partial])], 'con una parte: más estricto, no más generoso');
    contains('were not brought', implode(' ', $ctx->noteTexts('coupons')));
    $cats = (int) $writer->write(pwCoupon(['id' => 'p3', 'code' => 'SOLOC', 'category_ids' => ['777']]), $ctx)['id'];
    same(0, (int) wrValue($app, 'SELECT active FROM {coupons} WHERE id = ?', [$cats]));
});

pwTest('un cupón dirigido a un cliente que no se trajo entra inactivo; con el cliente traído, queda unido a él', function (): void {
    [$app, , $ctx, , $customer] = pwOrderSite();
    $writer = new CouponsWriter($app);
    $lost = (int) $writer->write(pwCoupon(['id' => 'c1', 'code' => 'DE-OTRO', 'customer_id' => '5000']), $ctx)['id'];
    same([0, null], [(int) wrValue($app, 'SELECT active FROM {coupons} WHERE id = ?', [$lost]), wrValue($app, 'SELECT customer_id FROM {coupons} WHERE id = ?', [$lost])]);
    $mine = (int) $writer->write(pwCoupon(['id' => 'c2', 'code' => 'DE-ANA', 'customer_id' => '77']), $ctx)['id'];
    same([1, $customer], [(int) wrValue($app, 'SELECT active FROM {coupons} WHERE id = ?', [$mine]), (int) wrValue($app, 'SELECT customer_id FROM {coupons} WHERE id = ?', [$mine])]);
});

pwTest('el importe fijo pasa al criterio de impuestos de aquí con cifras hechas a mano', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $writer = new CouponsWriter($app);
    $fixed = ['kind' => 'amount', 'percent' => 0, 'category_ids' => []];
    $net = (int) $writer->write(pwCoupon($fixed + ['id' => 'a1', 'code' => 'SINIVA', 'amount' => 1000, 'amount_includes_tax' => false]), $ctx)['id'];
    $gross = (int) $writer->write(pwCoupon($fixed + ['id' => 'a2', 'code' => 'CONIVA', 'amount' => 1210, 'amount_includes_tax' => true]), $ctx)['id'];
    same(['fixed', 1210], [wrValue($app, 'SELECT kind FROM {coupons} WHERE id = ?', [$net]), (int) wrValue($app, 'SELECT amount FROM {coupons} WHERE id = ?', [$net])], 'aquí los precios llevan IVA: 10,00 sin IVA son 12,10 con el 21 %');
    same(1210, (int) wrValue($app, 'SELECT amount FROM {coupons} WHERE id = ?', [$gross]), 'el que ya lleva IVA queda igual');

    [$other, , $otherCtx] = pwOrderSite(['tax.prices_include_tax' => false]);
    $w2 = new CouponsWriter($other);
    $a = (int) $w2->write(pwCoupon($fixed + ['id' => 'a3', 'code' => 'UNO', 'amount' => 1210, 'amount_includes_tax' => true]), $otherCtx)['id'];
    $b = (int) $w2->write(pwCoupon($fixed + ['id' => 'a4', 'code' => 'DOS', 'amount' => 1000, 'amount_includes_tax' => false]), $otherCtx)['id'];
    same([1000, 1000], [(int) wrValue($other, 'SELECT amount FROM {coupons} WHERE id = ?', [$a]), (int) wrValue($other, 'SELECT amount FROM {coupons} WHERE id = ?', [$b])], 'con precios sin IVA: 12,10 con IVA son 10,00; el que ya es neto, igual');
});

pwTest('el envío gratis y las cifras del destino', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $writer = new CouponsWriter($app);
    $free = (int) $writer->write(pwCoupon(['id' => 'e1', 'code' => 'ENVIOGRATIS', 'kind' => 'free_shipping', 'percent' => 0, 'amount' => 0, 'category_ids' => []]), $ctx)['id'];
    same(['free_shipping', 0, 0], [wrValue($app, 'SELECT kind FROM {coupons} WHERE id = ?', [$free]), (int) wrValue($app, 'SELECT percent FROM {coupons} WHERE id = ?', [$free]), (int) wrValue($app, 'SELECT amount FROM {coupons} WHERE id = ?', [$free])]);
    $writer->write(pwCoupon(), $ctx);
    $now = $app->clock()->sql();
    $app->db()->insert('coupons', ['code' => 'PROPIO', 'kind' => 'percent', 'percent' => 100, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    same(['coupons_count' => 2], $writer->checksums($ctx), 'solo cuenta lo traído de este origen');
});

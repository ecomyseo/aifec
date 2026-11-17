<?php
/**
 * AIFEC — pruebas de los usos de un cupón: canjear con sentencia condicionada, no pasar nunca del máximo,
 * canjear dos veces el mismo pedido y devolver los usos de un pedido cancelado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/CouponsKit.php';

test('canjear suma un uso y apunta quién, en qué pedido, cuánto y cuándo', function (): void {
    $app = couponsTestApp();
    $id = couponsMake($app);
    $svc = couponsService($app);

    same(true, $svc->redeem($id, 101, 7, ' Ana@Tienda.TEST ', 1500));
    same(1, (int) couponsRow($app, $id)['uses']);
    $use = $app->db()->row('SELECT * FROM {coupon_uses} WHERE coupon_id = ?', [$id]);
    check($use !== null);
    same(101, (int) $use['order_id']);
    same(7, (int) $use['customer_id']);
    same('ana@tienda.test', $use['email'], 'el correo se guarda en minúsculas y sin espacios');
    same(1500, (int) $use['amount']);
    same('2026-11-12 12:00:00', $use['created_at']);
});

test('canjear sin cliente ni correo guarda nulos, no cadenas vacías', function (): void {
    $app = couponsTestApp();
    $id = couponsMake($app);
    same(true, couponsService($app)->redeem($id, 5, null, '', 0));
    $use = $app->db()->row('SELECT * FROM {coupon_uses} WHERE order_id = 5');
    check($use !== null);
    same(null, $use['customer_id']);
    same(null, $use['email']);
});

test('un cupón nunca pasa de sus usos máximos: el segundo canje del último uso devuelve falso', function (): void {
    $app = couponsTestApp();
    $id = couponsMake($app, ['max_uses' => 1]);
    $svc = couponsService($app);

    same(true, $svc->redeem($id, 1, null, 'a@tienda.test', 100));
    same(false, $svc->redeem($id, 2, null, 'b@tienda.test', 100), 'el último uso ya está gastado');
    same(1, (int) couponsRow($app, $id)['uses']);
    same(0, couponsCount($app, 'coupon_uses', 'order_id = 2'), 'y no queda ni rastro del pedido que no lo consiguió');

    $ilimitado = couponsMake($app, ['code' => 'LIBRE', 'max_uses' => 0]);
    for ($order = 10; $order < 15; ++$order) {
        same(true, $svc->redeem($ilimitado, $order, null, '', 0));
    }
    same(5, (int) couponsRow($app, $ilimitado)['uses'], 'sin máximo no hay tope');
});

test('con tres usos y cinco pedidos, entran tres', function (): void {
    $app = couponsTestApp();
    $id = couponsMake($app, ['max_uses' => 3]);
    $svc = couponsService($app);
    $dentro = 0;
    for ($order = 1; $order <= 5; ++$order) {
        $dentro += $svc->redeem($id, $order, null, '', 0) ? 1 : 0;
    }
    same(3, $dentro);
    same(3, (int) couponsRow($app, $id)['uses']);
    same(3, couponsCount($app, 'coupon_uses'));
});

test('canjear dos veces el mismo pedido no suma, ni siquiera con el cupón lleno', function (): void {
    $app = couponsTestApp();
    $id = couponsMake($app, ['max_uses' => 1]);
    $svc = couponsService($app);

    same(true, $svc->redeem($id, 50, 3, 'a@tienda.test', 100));
    same(true, $svc->redeem($id, 50, 3, 'a@tienda.test', 100), 'el pedido ya lo había canjeado: sigue siendo verdadero');
    same(1, (int) couponsRow($app, $id)['uses']);
    same(1, couponsCount($app, 'coupon_uses'));
});

test('un cupón que no existe no se canjea y no deja filas', function (): void {
    $app = couponsTestApp();
    same(false, couponsService($app)->redeem(999, 1, null, 'a@tienda.test', 100));
    same(0, couponsCount($app, 'coupon_uses'));
});

test('devolver un pedido cancelado resta su uso, y hacerlo otra vez no resta más', function (): void {
    $app = couponsTestApp();
    $id = couponsMake($app);
    $svc = couponsService($app);
    $svc->redeem($id, 1, null, 'a@tienda.test', 100);
    $svc->redeem($id, 2, null, 'b@tienda.test', 100);
    same(2, (int) couponsRow($app, $id)['uses']);

    $svc->release(1);
    same(1, (int) couponsRow($app, $id)['uses']);
    same(0, couponsCount($app, 'coupon_uses', 'order_id = 1'));

    $svc->release(1);
    same(1, (int) couponsRow($app, $id)['uses'], 'la segunda vez no resta');
    $svc->release(777);
    same(1, (int) couponsRow($app, $id)['uses'], 'un pedido sin cupones no toca nada');
    same(1, couponsCount($app, 'coupon_uses'));
});

test('devolver un pedido con dos cupones resta de los dos y los usos nunca bajan de cero', function (): void {
    $app = couponsTestApp();
    $a = couponsMake($app, ['code' => 'AAA']);
    $b = couponsMake($app, ['code' => 'BBB']);
    $svc = couponsService($app);
    $svc->redeem($a, 9, null, '', 0);
    $svc->redeem($b, 9, null, '', 0);
    $svc->release(9);
    same(0, (int) couponsRow($app, $a)['uses']);
    same(0, (int) couponsRow($app, $b)['uses']);

    // Un contador que ya estaba a cero por otro camino no se queda en negativo.
    couponsUse($app, $a, 20, null, null);
    same(0, (int) couponsRow($app, $a)['uses']);
    $svc->release(20);
    same(0, (int) couponsRow($app, $a)['uses']);
    same(0, couponsCount($app, 'coupon_uses'));
});

test('tras devolver el uso, un cupón agotado vuelve a valer, y lo canjeado cuenta para el límite por cliente', function (): void {
    $app = couponsTestApp();
    $id = couponsMake($app, ['max_uses' => 1, 'max_uses_per_customer' => 1]);
    $svc = couponsService($app);
    $cart = couponsCart(null, ['customer_id' => 7]);

    same(true, $svc->check('OTONO', $cart)['ok']);
    $svc->redeem($id, 1, 7, 'ana@tienda.test', 1000);
    same('Este cupón se ha agotado', $svc->check('OTONO', $cart)['error']);

    $svc->release(1);
    same(true, $svc->check('OTONO', $cart)['ok'], 'libre otra vez');

    $app->db()->update('coupons', ['max_uses' => 5], ['id' => $id]);
    $svc->redeem($id, 2, 7, 'ana@tienda.test', 1000);
    same('Ya has usado este cupón todas las veces permitidas', $svc->check('OTONO', $cart)['error']);
});

test('el gancho de pedido cancelado devuelve los usos de ese pedido', function (): void {
    $app = couponsTestApp();
    $id = couponsMake($app);
    $svc = couponsService($app);
    $svc->redeem($id, 31, null, 'a@tienda.test', 100);
    $svc->redeem($id, 32, null, 'b@tienda.test', 100);

    $app->hooks()->fire('order.cancelled', ['id' => 31]);
    same(1, (int) couponsRow($app, $id)['uses']);
    same(0, couponsCount($app, 'coupon_uses', 'order_id = 31'));
    same(1, couponsCount($app, 'coupon_uses', 'order_id = 32'));

    $app->hooks()->fire('order.cancelled', ['number' => 'sin id']);
    same(1, (int) couponsRow($app, $id)['uses'], 'sin identificador de pedido no hace nada');
});

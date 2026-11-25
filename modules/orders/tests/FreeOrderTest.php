<?php
/**
 * AIFEC — un pedido de cero euros (un producto gratuito, o un cupón que lo cubre todo) se puede terminar: la caja ofrece
 * solo «sin pago», el pedido nace pagado y ninguna pasarela se entera.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/FrontKit.php';

/** Una tienda con una pasarela y un producto de 0 € (o del precio que se diga) en el carrito. @return array{0:App,1:TestClient,2:int} */
function freeShop(int $price = 0): array
{
    $app = frontShop([]);
    $web = new TestClient($app);
    $p = frontProduct($app, ['price' => $price, 'weight' => 0, 'kind' => 'digital']);
    frontAdd($web, $p, 1);

    return [$app, $web, $p];
}

test('con cero que pagar solo se ofrece «sin pago», y con algo que pagar no sale', function (): void {
    [$app, $web] = freeShop(0);
    $methods = aifecCartRun($web, static function ($cart): array {
        return array_column($cart->summary()['payment_methods'], 'code');
    });
    same(['free'], $methods, 'la pasarela no se ofrece con cero');

    [$app2, $web2] = freeShop(12100);
    $methods2 = aifecCartRun($web2, static function ($cart): array {
        return array_column($cart->summary()['payment_methods'], 'code');
    });
    check(!in_array('free', $methods2, true), 'con un precio, «sin pago» no se ofrece');
    check(in_array('gw', $methods2, true), 'con un precio, la pasarela sí');
});

test('el pedido de cero euros se hace, queda pagado y no llama a ninguna pasarela', function (): void {
    [$app, $web] = freeShop(0);
    $res = aifecCartRun($web, static function ($cart, App $app): array {
        $co = $app->service('checkout');
        $co->fill(['email' => 'ana@cliente.test', 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'phone' => '999345678',
            'line1' => 'Calle Mayor 1', 'postcode' => '28001', 'city' => 'Madrid', 'country' => 'ES']);
        $set = $co->setPayment('free');
        $review = $co->review();

        return ['set' => $set, 'review' => $review, 'place' => $co->place((string) $review['confirm_token'], ['accept_terms' => true])];
    });
    same(true, $res['set']['ok'], 'se puede elegir «sin pago»');
    same(0, $res['review']['total'], 'la revisión dice cero');
    same(true, $res['place']['ok'], (string) $res['place']['error']);
    $order = $app->service('orders')->findBySecret($res['place']['number'], $res['place']['secret']);
    check($order !== null, 'el pedido existe');
    same(0, $order['total']);
    same('free', $order['payment_method']);
    same('paid', $order['payment_status'], 'nace pagado');
    same(0, count(aifecOrderPayment($app)->started), 'la pasarela no recibe nada');
});

test('«sin pago» no vale para un pedido que cuesta algo', function (): void {
    [$app, $web] = freeShop(12100);
    $set = aifecCartRun($web, static function ($cart, App $app): array {
        return $app->service('checkout')->setPayment('free');
    });
    same(false, $set['ok'], 'no se puede elegir con un total mayor que cero');
});

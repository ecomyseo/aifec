<?php
/**
 * AIFEC — Cart::ensure() (abrir el carrito antes de que salgan las cabeceras de una respuesta por partes) y la tarea que borra
 * los carritos que ya no puede abrir nadie.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Orders\OrderTasks;

require_once __DIR__ . '/helpers.php';

/**
 * Una fila de carrito puesta a mano con la fecha que se quiera.
 */
function cartRowAged(App $app, int $daysAgo, ?int $orderId = null): int
{
    $when = $app->clock()->sql($app->clock()->now() - $daysAgo * 86400);

    return (int) $app->db()->insert('carts', [
        'token' => hash('sha256', 'viejo-' . $daysAgo . '-' . ($orderId === null ? 'x' : (string) $orderId) . '-' . mt_rand()),
        'lang' => 'es', 'items' => '[]', 'source' => 'web', 'order_id' => $orderId, 'created_at' => $when, 'updated_at' => $when,
    ]);
}

test('ensure() sin carrito: lo crea vacío y encola sus dos cookies en esa misma petición', function (): void {
    $app = aifecCartApp();
    $app->router()->get('/prueba/asegurar', static function (Request $q, App $app): Response {
        $app->service('cart')->ensure();

        return Response::json(['count' => $app->service('cart')->count(), 'exists' => $app->service('cart')->exists()]);
    });
    $web = new TestClient($app);

    $r = $web->get('/prueba/asegurar');

    same(200, $r->status);
    same(['count' => 0, 'exists' => true], $web->json());
    check(isset($r->cookies[$app->cookieName('k')], $r->cookies[$app->cookieName('ck')]), 'la cookie del carrito y su marca salen en esta respuesta');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'));

    // Con esa cookie, lo que se añada después va al mismo carrito.
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 1210, 'stock' => 10]);
    $add = $web->postJson('/carrito/add', ['product_id' => $p, 'variant_id' => 0, 'qty' => 2]);
    same(200, $add->status);
    check(!isset($add->cookies[$app->cookieName('k')]), 'no hace falta otra cookie: el carrito ya existía');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'), 'sigue siendo un solo carrito');
});

test('ensure() con un carrito vivo no hace nada: ni otra fila ni otra cookie', function (): void {
    $app = aifecCartApp();
    $app->router()->get('/prueba/asegurar', static function (Request $q, App $app): Response {
        $app->service('cart')->ensure();

        return Response::json(['count' => $app->service('cart')->count()]);
    });
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 1210, 'stock' => 10]);
    $web = new TestClient($app);
    $web->postJson('/carrito/add', ['product_id' => $p, 'variant_id' => 0, 'qty' => 1]);
    $token = $web->cookies[$app->cookieName('k')];

    $r = $web->get('/prueba/asegurar');

    same(['count' => 1], $web->json());
    check(!isset($r->cookies[$app->cookieName('k')]), 'no se manda otra cookie');
    same($token, $web->cookies[$app->cookieName('k')]);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'));
});

test('la tarea borra los carritos sin pedido que llevan 45 días sin tocarse y deja los recientes', function (): void {
    $app = aifecCartApp();
    $old = cartRowAged($app, OrderTasks::CART_DAYS + 1);
    $edge = cartRowAged($app, OrderTasks::CART_DAYS - 1);
    $new = cartRowAged($app, 0);

    same('1 carts deleted', OrderTasks::cleanCarts($app));

    same(null, $app->db()->find('carts', ['id' => $old]), 'el de hace 46 días ya no está');
    check($app->db()->find('carts', ['id' => $edge]) !== null, 'el de hace 44 días sigue: su cookie puede estar viva');
    check($app->db()->find('carts', ['id' => $new]) !== null, 'el de hoy sigue');
    same(null, OrderTasks::cleanCarts($app), 'sin nada que borrar, la tarea no dice nada');
});

test('el carrito de un pedido ya hecho se guarda 90 días (para reintentar un pago) y después se borra', function (): void {
    $app = aifecCartApp();
    $recent = cartRowAged($app, OrderTasks::CART_DAYS + 5, 7);
    $ancient = cartRowAged($app, OrderTasks::ORDERED_CART_DAYS + 1, 8);

    same('1 carts deleted', OrderTasks::cleanCarts($app));

    check($app->db()->find('carts', ['id' => $recent]) !== null, 'el de un pedido de hace 50 días sigue, aunque un carrito sin pedido de esa edad se borraría');
    same(null, $app->db()->find('carts', ['id' => $ancient]), 'el de hace 91 días, no');
});

test('la tarea está declarada en el módulo, una vez al día, con su descripción traducida', function (): void {
    $app = aifecCartApp();
    $jobs = require $app->root() . '/modules/orders/jobs.php';

    check(isset($jobs['cron']['orders.carts']), 'la tarea existe');
    same(86400, $jobs['cron']['orders.carts']['every']);
    same([OrderTasks::class, 'cleanCarts'], $jobs['cron']['orders.carts']['run']);
    $es = require $app->root() . '/modules/orders/lang/es.php';
    check(isset($es[$jobs['cron']['orders.carts']['description']]), 'su descripción está en castellano');
});

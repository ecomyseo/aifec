<?php
/**
 * AIFEC — pruebas del carrito: sin carrito no hay nada, añadir, cantidades, existencias, variantes y datos propios.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('sin carrito: el resumen sale vacío y no consulta la base de datos, ni crea fila ni cookie', function (): void {
    $app = aifecCartApp();
    $cart = $app->service('cart');
    $before = $app->db()->queries;

    $summary = $cart->summary();
    same(null, $summary['id']);
    same(0, $summary['count']);
    same([], $summary['lines']);
    same(0, $summary['totals']['total']);
    same([], $summary['coupons']);
    same(false, $summary['needs_shipping']);
    same('EUR', $summary['currency']);
    same('ES', $summary['destination']['country']);
    same(0, $cart->count());
    same(false, $cart->exists());
    same($before, $app->db()->queries, 'ninguna consulta nueva');
    same(0, shippingCount($app, 'carts'));
});

test('añadir lo primero crea la fila y pone la cookie; en la tabla solo queda el SHA-256 de la ficha', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['price' => 1999, 'name' => 'Camiseta']);
    $web = new TestClient($app);

    $result = aifecCartRun($web, static function ($cart) use ($p): array {
        return $cart->add($p, 0, 2);
    });
    same(true, $result['ok']);
    same('', $result['error']);
    same('p' . $p . '-v0-' . substr(md5('[]'), 0, 6), $result['key']);

    $name = $app->cookieName('k');
    check(isset($web->cookies[$name]), 'la cookie del carrito está puesta');
    check(preg_match('/^[a-f0-9]{48}$/', $web->cookies[$name]) === 1, 'ficha de 24 bytes en hexadecimal');
    $cookie = $web->last->cookies[$name];
    same(true, $cookie['options']['httponly']);
    same('Lax', $cookie['options']['samesite']);

    $rows = $app->db()->select('SELECT * FROM {carts}');
    same(1, count($rows));
    same(hash('sha256', $web->cookies[$name]), $rows[0]['token'], 'la ficha en claro no se guarda');
    notContains($web->cookies[$name], (string) $rows[0]['items']);
    same([['key' => $result['key'], 'product_id' => $p, 'variant_id' => 0, 'qty' => 2, 'data' => []]], json_decode((string) $rows[0]['items'], true));
    same(null, $rows[0]['order_id']);

    // En la petición siguiente, con la cookie, el carrito está ahí.
    same(2, aifecCartRun($web, static function ($cart): int {
        return $cart->count();
    }));
});

test('la misma combinación de producto, variante y datos es la misma línea: las cantidades se suman', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['price' => 1000, 'stock' => 50]);
    $web = new TestClient($app);

    aifecCartRun($web, static function ($cart) use ($p): void {
        $cart->add($p, 0, 2);
    });
    $second = aifecCartRun($web, static function ($cart) use ($p): array {
        return $cart->add($p, 0, 3);
    });
    same(true, $second['ok']);
    $summary = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same(1, count($summary['lines']));
    same(5, $summary['lines'][$second['key']]['qty']);
    same(5, $summary['count']);
    same(1, shippingCount($app, 'carts'), 'sigue habiendo un solo carrito');
});

test('un producto con variantes pide elegir; con la variante buena entra y la clave lleva su identificador', function (): void {
    $app = aifecCartApp();
    aifecAttribute($app, 'talla', 'Talla', ['m' => 'M', 'l' => 'L']);
    $p = aifecProduct($app, ['price' => 1000, 'variants' => [
        ['sku' => 'CAM-M', 'price' => 1200, 'stock' => 4, 'values' => ['talla' => 'm']],
        ['sku' => 'CAM-L', 'price' => 1300, 'stock' => 0, 'values' => ['talla' => 'l']],
    ]]);
    $m = (int) $app->db()->value('SELECT id FROM {variants} WHERE sku = ?', ['CAM-M']);
    $l = (int) $app->db()->value('SELECT id FROM {variants} WHERE sku = ?', ['CAM-L']);
    $web = new TestClient($app);

    $none = aifecCartRun($web, static function ($cart) use ($p): array {
        return $cart->add($p, 0, 1);
    });
    same(false, $none['ok']);
    same('Elige primero una opción', $none['error']);
    same(0, shippingCount($app, 'carts'), 'un error no crea carrito');
    same([], $web->cookies, 'ni cookie');

    $soldOut = aifecCartRun($web, static function ($cart) use ($p, $l): array {
        return $cart->add($p, $l, 1);
    });
    same(false, $soldOut['ok']);
    same('Agotado', $soldOut['error']);

    $ok = aifecCartRun($web, static function ($cart) use ($p, $m): array {
        return $cart->add($p, $m, 2);
    });
    same(true, $ok['ok']);
    same('p' . $p . '-v' . $m . '-' . substr(md5('[]'), 0, 6), $ok['key']);
    $line = aifecCartRun($web, static function ($cart): array {
        return array_values($cart->summary()['lines'])[0];
    });
    same(1200, $line['unit_price'], 'el precio de la variante');
    same('CAM-M', $line['sku']);
});

test('sin existencias no se añade, y con «pedidos sin existencias» sí', function (): void {
    $app = aifecCartApp();
    $out = aifecProduct($app, ['stock' => 0]);
    $backorder = aifecProduct($app, ['stock' => 0, 'backorders' => 'yes']);
    $unmanaged = aifecProduct($app, ['stock' => 0, 'manage_stock' => 0]);
    $web = new TestClient($app);

    $result = aifecCartRun($web, static function ($cart) use ($out): array {
        return $cart->add($out, 0, 1);
    });
    same(false, $result['ok']);
    same('Agotado', $result['error']);
    same(0, shippingCount($app, 'carts'));

    same(true, aifecCartRun($web, static function ($cart) use ($backorder): array {
        return $cart->add($backorder, 0, 7);
    })['ok'], 'con pedidos sin existencias se puede pedir más de lo que hay');
    same(true, aifecCartRun($web, static function ($cart) use ($unmanaged): array {
        return $cart->add($unmanaged, 0, 3);
    })['ok'], 'si no se controlan existencias, siempre');
});

test('«solo quedan 2»: no se pasa de las existencias, y cuenta lo que ya hay en el carrito', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['stock' => 2]);
    $web = new TestClient($app);

    $first = aifecCartRun($web, static function ($cart) use ($p): array {
        return $cart->add($p, 0, 3);
    });
    same(false, $first['ok']);
    same('Solo quedan 2 unidades', $first['error']);
    same(0, shippingCount($app, 'carts'));

    same(true, aifecCartRun($web, static function ($cart) use ($p): array {
        return $cart->add($p, 0, 1);
    })['ok']);
    $more = aifecCartRun($web, static function ($cart) use ($p): array {
        return $cart->add($p, 0, 2);
    });
    same(false, $more['ok']);
    same('Solo quedan 2 unidades en existencias y ya tienes 1 en tu carrito', $more['error']);
    same(1, aifecCartRun($web, static function ($cart): int {
        return $cart->count();
    }), 'lo que ya había no se toca');
});

test('cantidad mínima y máxima por pedido; 0 en el máximo es sin tope', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['stock' => 100, 'min_qty' => 3, 'max_qty' => 5]);
    $free = aifecProduct($app, ['stock' => 1000, 'min_qty' => 0, 'max_qty' => 0]);
    $web = new TestClient($app);

    $low = aifecCartRun($web, static function ($cart) use ($p): array {
        return $cart->add($p, 0, 2);
    });
    same(false, $low['ok']);
    same('La cantidad mínima es 3', $low['error']);
    $high = aifecCartRun($web, static function ($cart) use ($p): array {
        return $cart->add($p, 0, 6);
    });
    same(false, $high['ok']);
    same('La cantidad máxima es 5', $high['error']);
    same(true, aifecCartRun($web, static function ($cart) use ($p): array {
        return $cart->add($p, 0, 5);
    })['ok'], 'justo el máximo');
    $over = aifecCartRun($web, static function ($cart) use ($p): array {
        return $cart->add($p, 0, 1);
    });
    same(false, $over['ok'], 'sumando lo que ya había se pasa del máximo');
    same(true, aifecCartRun($web, static function ($cart) use ($free): array {
        return $cart->add($free, 0, 500);
    })['ok']);

    $zero = aifecCartRun($web, static function ($cart) use ($free): array {
        return $cart->add($free, 0, 0);
    });
    same(false, $zero['ok']);
    same('Escribe una cantidad válida', $zero['error']);
});

test('un producto en borrador, uno que no existe y uno oculto por fecha no se pueden añadir', function (): void {
    $app = aifecCartApp();
    $draft = aifecProduct($app, ['status' => 'draft']);
    $future = aifecProduct($app, ['published_at' => '2027-01-01 00:00:00']);
    $web = new TestClient($app);

    foreach ([$draft, $future] as $id) {
        $result = aifecCartRun($web, static function ($cart) use ($id): array {
            return $cart->add($id, 0, 1);
        });
        same(false, $result['ok']);
        same('Este producto no está disponible', $result['error']);
    }
    $missing = aifecCartRun($web, static function ($cart): array {
        return $cart->add(987654, 0, 1);
    });
    same(false, $missing['ok']);
    same('Este producto no está disponible', $missing['error']);
    same(0, shippingCount($app, 'carts'));
    same([], $web->cookies);
});

test('datos propios distintos son líneas distintas; el mismo contenido en otro orden es la misma línea', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['stock' => 50]);
    $web = new TestClient($app);

    $keys = aifecCartRun($web, static function ($cart) use ($p): array {
        $a = $cart->add($p, 0, 1, ['grabado' => 'Ana', 'color' => 'rojo']);
        $b = $cart->add($p, 0, 1, ['color' => 'rojo', 'grabado' => 'Ana']);
        $c = $cart->add($p, 0, 1, ['grabado' => 'Luis']);
        $d = $cart->add($p, 0, 1);
        $e = $cart->add($p, 0, 1, ['grabado' => '   ']);

        return [$a['key'], $b['key'], $c['key'], $d['key'], $e['key']];
    });
    same($keys[0], $keys[1], 'el orden de las claves no importa');
    check($keys[0] !== $keys[2], 'otro dato, otra línea');
    check($keys[0] !== $keys[3], 'sin datos, otra línea');
    same($keys[3], $keys[4], 'un dato en blanco no crea otra línea');
    check(preg_match('/^p' . $p . '-v0-[a-f0-9]{6}$/', $keys[0]) === 1, 'forma de la clave');

    $summary = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same(3, count($summary['lines']));
    same(2, $summary['lines'][$keys[0]]['qty']);
    same(['grabado' => 'Ana', 'color' => 'rojo'], $summary['lines'][$keys[0]]['data'], 'los datos llegan intactos, con tildes y eñes');
    $accents = aifecCartRun($web, static function ($cart) use ($p): array {
        $r = $cart->add($p, 0, 1, ['nota' => 'Cariño & «fiesta» 🎉']);

        return $cart->summary()['lines'][$r['key']]['data'];
    });
    same(['nota' => 'Cariño & «fiesta» 🎉'], $accents);
});

test('cambiar la cantidad, ponerla a 0 o quitar la última línea; al vaciarse, fila y cookie desaparecen', function (): void {
    $app = aifecCartApp();
    $a = aifecProduct($app, ['stock' => 10]);
    $b = aifecProduct($app, ['stock' => 10]);
    $web = new TestClient($app);

    $keys = aifecCartRun($web, static function ($cart) use ($a, $b): array {
        return [$cart->add($a, 0, 1)['key'], $cart->add($b, 0, 2)['key']];
    });
    $changed = aifecCartRun($web, static function ($cart) use ($keys): array {
        return $cart->update($keys[0], 4);
    });
    same(true, $changed['ok']);
    $tooMany = aifecCartRun($web, static function ($cart) use ($keys): array {
        return $cart->update($keys[0], 11);
    });
    same(false, $tooMany['ok']);
    same('Solo quedan 10 unidades', $tooMany['error']);
    $unknown = aifecCartRun($web, static function ($cart): array {
        return $cart->update('p1-v0-000000', 1);
    });
    same(false, $unknown['ok']);
    same('Este artículo ya no está en tu carrito', $unknown['error']);
    same(6, aifecCartRun($web, static function ($cart): int {
        return $cart->count();
    }));

    aifecCartRun($web, static function ($cart) use ($keys): void {
        $cart->update($keys[0], 0);
    });
    same([$keys[1]], array_keys(aifecCartRun($web, static function ($cart): array {
        return $cart->summary()['lines'];
    })), 'con 0 la línea se va');
    check(isset($web->cookies[$app->cookieName('k')]), 'queda otra línea: sigue la cookie');

    aifecCartRun($web, static function ($cart) use ($keys): void {
        $cart->remove($keys[1]);
    });
    same(0, shippingCount($app, 'carts'), 'la última línea se llevó el carrito');
    check(!isset($web->cookies[$app->cookieName('k')]), 'y la cookie');

    aifecCartRun($web, static function ($cart) use ($a): void {
        $cart->add($a, 0, 1);
    });
    aifecCartRun($web, static function ($cart): void {
        $cart->clear();
    });
    same(0, shippingCount($app, 'carts'));
    check(!isset($web->cookies[$app->cookieName('k')]), 'clear() también olvida la cookie');
});

test('cada cambio avisa con la acción cart.changed y el resumen nuevo', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['price' => 1000, 'stock' => 10]);
    $seen = [];
    $app->hooks()->on('cart.changed', static function (array $summary) use (&$seen): void {
        $seen[] = $summary['count'];
    });
    $web = new TestClient($app);

    aifecCartRun($web, static function ($cart) use ($p): void {
        $key = $cart->add($p, 0, 2)['key'];
        $cart->update($key, 5);
        $cart->remove($key);
    });
    same([2, 5, 0], $seen);
});

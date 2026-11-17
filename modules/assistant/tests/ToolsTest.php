<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Assistant\Tools;

require_once __DIR__ . '/helpers.php';

if (!function_exists('assistantTools')) {
    function assistantTools(App $app): Tools
    {
        $tools = $app->service('tools');
        check($tools instanceof Tools, 'el servicio tools es el del módulo');

        return $tools;
    }

    /** Un carrito de mentira: apunta lo que se le pide y no toca nada más. */
    final class AssistantFakeCart
    {
        /** @var array<int,array<string,mixed>> */
        public $added = [];

        /** @return array<string,mixed> */
        public function summary(): array
        {
            return ['count' => 0, 'lines' => [], 'totals' => ['total' => 0, 'items_total' => 0], 'currency' => 'EUR', 'problems' => []];
        }

        /** @return array<string,mixed> */
        public function add(int $productId, int $variantId, int $qty): array
        {
            $this->added[] = [$productId, $variantId, $qty];

            return ['ok' => true, 'error' => '', 'key' => 'p' . $productId . '-v0-fake'];
        }

        /** @return array<string,mixed> */
        public function update(string $key, int $qty): array
        {
            return ['ok' => true, 'error' => '', 'key' => $key];
        }
    }
}

test('el juego de herramientas trae las doce del contrato (con compare_products) y las ocho de la compra en el chat, cada una con descripción, esquema y función; solo cart_add, cart_update y checkout_place escriben', function (): void {
    $app = assistantApp();
    $all = assistantTools($app)->all();
    $names = ['search_products', 'get_product', 'compare_products', 'list_categories', 'check_availability', 'shipping_quote', 'store_info', 'cart_view', 'cart_add', 'cart_update', 'checkout_link', 'order_status',
        'checkout_status', 'checkout_set_customer', 'checkout_shipping_options', 'checkout_set_shipping', 'checkout_payment_options', 'checkout_set_payment', 'checkout_review', 'checkout_place'];
    same($names, array_keys($all));
    $writers = [];
    foreach ($all as $name => $tool) {
        check($tool['description'] !== '', $name . ' tiene descripción');
        same('object', $tool['schema']['type'], $name . ' tiene un esquema de objeto');
        same(false, $tool['schema']['additionalProperties'], $name . ' cierra sus propiedades');
        check(is_callable($tool['handler']), $name . ' tiene función');
        if ($tool['writes']) {
            $writers[] = $name;
        }
    }
    same(['cart_add', 'cart_update', 'checkout_place'], $writers);
    same(20, count(assistantTools($app)->forModel()), 'y se ofrecen al modelo como AiTool');

    $off = assistantApp(['assistant.checkout' => false]);
    same(array_slice($names, 0, 12), array_keys(assistantTools($off)->all()), 'con «assistant.checkout» apagado solo quedan las doce de serie');
    same(12, count(assistantTools($off)->forModel()));
});

test('search_products: entrada válida da productos con precio en céntimos y formateado, disponibilidad, dirección, imagen y por qué casan', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $r = assistantTools($app)->call('search_products', ['query' => 'silla roja', 'limit' => 3]);

    check(!isset($r['error']), 'sin error');
    check($r['total'] >= 1 && $r['items'] !== [], 'encuentra la silla');
    $first = $r['items'][0];
    same($ids['silla_roja'], $first['id']);
    same('Silla roja', $first['name']);
    same(5990, $first['price_cents']);
    same(money(5990), $first['price']);
    same('EUR', $first['currency']);
    check(isset($first['availability']['label']) && $first['availability']['label'] !== '', 'disponibilidad con su texto');
    contains('https://tienda.test/producto/', $first['url'], 'dirección completa');
    check($first['image'] !== '' && strpos($first['image'], 'https://tienda.test/') === 0, 'imagen con dirección completa');
    check(in_array('Color: Rojo', $first['matches'], true), 'por qué casa: sus datos clave (' . json_encode($first['matches']) . ')');
    check(count($r['items']) <= 3, 'respeta el límite');
});

test('search_products: sin resultados da una lista vacía y una nota; con filtros solo salen los que los cumplen', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $tools = assistantTools($app);

    $none = $tools->call('search_products', ['query' => 'xyzzy plugh']);
    same([], $none['items']);
    same(0, $none['total']);
    check(isset($none['note']), 'con su nota para el modelo');

    $cheap = $tools->call('search_products', ['query' => 'silla', 'max_price_cents' => 6000]);
    foreach ($cheap['items'] as $item) {
        check($item['price_cents'] <= 6000, $item['name'] . ' pasa del tope de precio');
    }
    check(in_array($ids['silla_roja'], array_column($cheap['items'], 'id'), true), 'la silla de 59,90 € sí sale');
    check(!in_array($ids['silla_azul'], array_column($cheap['items'], 'id'), true), 'la de 64,90 € no');

    $stock = $tools->call('search_products', ['query' => 'silla', 'in_stock_only' => true]);
    check(!in_array($ids['silla_azul'], array_column($stock['items'], 'id'), true), 'la agotada no sale si se piden solo las que hay');

    $needs = $tools->call('search_products', []);
    check(isset($needs['error']), 'sin palabras ni categoría no hay nada que buscar');
});

test('search_products: una entrada que no cumple el esquema es un error y nunca una excepción', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $tools = assistantTools($app);
    foreach ([['query' => 'silla', 'limit' => 50], ['query' => 'silla', 'limit' => 'muchos'], ['query' => str_repeat('a', 121)], ['query' => 'silla', 'sort' => 'price']] as $input) {
        $r = $tools->call('search_products', $input);
        check(isset($r['error']), 'error para ' . json_encode($input));
    }
    $ok = $tools->call('search_products', ['query' => ' silla ', 'limit' => '2']);
    check(!isset($ok['error']) && count($ok['items']) <= 2, 'un «2» como texto se acepta como 2');
});

test('get_product: lo esencial en texto sin HTML, con variantes, precios, plazo y preguntas frecuentes; un borrador es un error', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $faqId = aifecProduct($app, [
        'name' => 'Lámpara Aurora', 'price' => 8900, 'summary' => '<p>Luz <strong>cálida</strong></p>',
        'description' => '<h2>Detalles</h2><p>Latón &amp; cristal.</p><p>Hecha a mano.</p>', 'delivery_min_days' => 2, 'delivery_max_days' => 4,
        'facts' => [['label' => 'Material', 'value' => 'Latón']], 'faqs' => [['question' => '¿Lleva bombilla?', 'answer' => '<p>Sí, LED.</p>']],
    ]);
    $draft = aifecProduct($app, ['name' => 'Borrador', 'status' => 'draft']);
    $tools = assistantTools($app);

    $r = $tools->call('get_product', ['id' => $faqId]);
    check(!isset($r['error']), 'sin error');
    same('Lámpara Aurora', $r['name']);
    same(8900, $r['price_cents']);
    same('Luz cálida', $r['summary']);
    notContains('<', $r['description']);
    contains('Latón & cristal.', $r['description']);
    same(['Material: Latón'], $r['facts']);
    same([2, 4], $r['delivery_days']);
    contains('2', $r['delivery']);
    same([['question' => '¿Lleva bombilla?', 'answer' => 'Sí, LED.']], $r['faqs']);

    $v = $tools->call('get_product', ['id' => $ids['variantes']]);
    same(2, count($v['variants']));
    same(['Talla S', 'Talla L'], array_column($v['variants'], 'label'));
    same([49900, 59900], array_column($v['variants'], 'price_cents'));
    same(true, $v['has_variants']);

    $error = $tools->call('get_product', ['id' => $draft]);
    check(isset($error['error']), 'un borrador no se enseña');
    same(['error'], array_keys($error));
    check(isset($tools->call('get_product', ['id' => 999999])['error']), 'y uno que no existe, tampoco');
    check(isset($tools->call('get_product', [])['error']), 'ni sin id');
});

test('list_categories y check_availability: las categorías salen con su dirección; una agotada no se puede comprar y una con existencias, sí', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $tools = assistantTools($app);

    $categories = $tools->call('list_categories', []);
    $names = array_column($categories['categories'], 'name');
    check(in_array('Sillas', $names, true) && in_array('Mesas', $names, true), 'salen las categorías (' . json_encode($names) . ')');
    contains('https://tienda.test/', $categories['categories'][0]['url']);

    $sold = $tools->call('check_availability', ['product_id' => $ids['silla_azul']]);
    same(false, $sold['available']);
    same(false, $sold['enough']);
    check($sold['reason'] !== '', 'dice por qué');

    $ok = $tools->call('check_availability', ['product_id' => $ids['silla_roja'], 'qty' => 2]);
    same(true, $ok['available']);
    same(true, $ok['enough']);
    same(2, $ok['qty']);

    $many = $tools->call('check_availability', ['product_id' => $ids['mesa'], 'qty' => 50]);
    same(true, $many['available']);
    same(false, $many['enough'], 'hay 5 y se piden 50');

    $variant = $tools->call('check_availability', ['product_id' => $ids['variantes']]);
    same(false, $variant['available'], 'un producto con variantes pide elegir una');
    check(isset($tools->call('check_availability', ['product_id' => 987654])['error']), 'uno que no existe es un error');
});

test('shipping_quote: cuesta lo que dicen las tarifas, con un producto o con el carrito; sin zona no hay opciones y sin datos es un error', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $tools = assistantTools($app);

    $r = $tools->call('shipping_quote', ['postcode' => '28001', 'product_id' => $ids['silla_roja']]);
    check(!isset($r['error']), 'sin error: ' . json_encode($r));
    same('ES', $r['destination']['country']);
    same('28', $r['destination']['region'], 'la provincia sale del código postal');
    same(1, count($r['options']));
    same(495, $r['options'][0]['price_cents']);
    same(money(495), $r['options'][0]['price']);
    same(false, $r['options'][0]['free']);
    contains('Estándar', $r['options'][0]['name']);

    $noCart = $tools->call('shipping_quote', ['postcode' => '28001']);
    check(isset($noCart['error']) && $noCart['need'] === 'product', 'sin producto ni carrito se pide uno');
    check(isset($tools->call('shipping_quote', [])['error']), 'sin código postal ni país tampoco');

    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($ids): void {
        $cart->add($ids['silla_roja'], 0, 1);
    });
    $withCart = aifecCartRun($web, static function ($cart, App $app): array {
        return $app->service('tools')->call('shipping_quote', ['postcode' => '35001'], ['cart' => $cart]);
    });
    check(!isset($withCart['error']), 'con el carrito: ' . json_encode($withCart));
    same('35', $withCart['destination']['region']);
    same(495, $withCart['options'][0]['price_cents']);
});

test('store_info: contacto, horario, devoluciones y formas de pago salen de los ajustes y de los módulos; sin dato, vacío y no un invento', function (): void {
    $app = assistantApp(['assistant.hours' => 'L-V 9:00 a 18:00', 'assistant.returns_text' => 'Devoluciones en 30 días.', 'store.address' => 'Calle Mayor 1, Madrid']);
    $tools = assistantTools($app);

    $all = $tools->call('store_info', []);
    same('Muebles Ejemplo', $all['name']);
    same(['email' => 'tienda@ejemplo.test', 'phone' => '999000001', 'address' => 'Calle Mayor 1, Madrid'], $all['contact']);
    same('L-V 9:00 a 18:00', $all['hours']);
    same('Devoluciones en 30 días.', $all['returns']);
    same(['Pasarela de pruebas'], $all['payment_methods']);

    $only = $tools->call('store_info', ['topic' => 'hours']);
    same(['name', 'hours'], array_keys($only));
    check(isset($tools->call('store_info', ['topic' => 'secretos'])['error']), 'un tema que no existe es un error');

    $bare = assistantApp();
    $empty = assistantTools($bare)->call('store_info', ['topic' => 'all']);
    same('', $empty['hours']);
    same('', $empty['returns']);
    check(!array_key_exists('about', $empty), 'sin texto de la tienda no hay «about» (ni vacío ni inventado)');

    // Lo que la tienda cuenta de sí misma (13/11/2026: «¿qué es AIFEC?» → «no dispongo de detalles»): sale con el tema «all» y con «about».
    $told = assistantApp(['assistant.about' => "  Vendemos muebles de oficina hechos en Valencia.\nNo hacemos montaje.  "]);
    $about = assistantTools($told)->call('store_info', ['topic' => 'about']);
    same(['name', 'about'], array_keys($about));
    same("Vendemos muebles de oficina hechos en Valencia.\nNo hacemos montaje.", $about['about']);
    same($about['about'], assistantTools($told)->call('store_info', [])['about'], 'también con el tema de serie');
    check(!array_key_exists('about', assistantTools($told)->call('store_info', ['topic' => 'hours'])), 'y no con otro tema');
    $long = assistantApp(['assistant.about' => str_repeat('ñ', 2000)]);
    same(1500, mb_strlen(assistantTools($long)->call('store_info', ['topic' => 'about'])['about'], 'UTF-8'), 'como mucho 1.500 caracteres');
    $settings = require dirname(__DIR__) . '/settings.php';
    same('textarea', $settings['tabs']['asinfo']['fields']['assistant.about']['type'], 'el dueño lo escribe en los ajustes del asistente');
});

test('cart_add actúa sobre el carrito del contexto y no sobre otro; sin contexto usa el de la sesión', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $tools = assistantTools($app);
    $fake = new AssistantFakeCart();

    $r = $tools->call('cart_add', ['product_id' => $ids['silla_roja'], 'qty' => 2], ['cart' => $fake]);
    check(!isset($r['error']), 'sin error');
    same([[$ids['silla_roja'], 0, 2]], $fake->added, 'el carrito del contexto recibe la llamada');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'), 'y el carrito de verdad no se ha tocado');

    $web = new TestClient($app);
    $real = aifecCartRun($web, static function ($cart, App $app) use ($ids): array {
        return $app->service('tools')->call('cart_add', ['product_id' => $ids['silla_roja']]);
    });
    check(!isset($real['error']), 'sin carrito en el contexto, el de la sesión');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'));
    same('Silla roja', $real['name']);
});

test('ninguna herramienta acepta un precio: cart_add con «price» o «unit_price» es un error y el carrito no cambia', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $tools = assistantTools($app);
    $fake = new AssistantFakeCart();
    foreach (['price', 'unit_price', 'price_cents', 'discount'] as $field) {
        $r = $tools->call('cart_add', ['product_id' => $ids['silla_roja'], $field => 1], ['cart' => $fake]);
        check(isset($r['error']), $field . ' se rechaza');
    }
    same([], $fake->added);
    foreach ($tools->all() as $name => $tool) {
        foreach (array_keys($tool['schema']['properties']) as $property) {
            check(!in_array($property, ['price', 'unit_price', 'amount', 'total', 'discount'], true), $name . ' no pide ' . $property);
        }
    }
    check(isset($tools->call('cart_add', ['product_id' => $ids['silla_roja'], 'qty' => 0])['error']), 'cantidad 0');
    check(isset($tools->call('cart_add', ['product_id' => $ids['silla_roja'], 'qty' => 100])['error']), 'cantidad 100');
    check(isset($tools->call('cart_add', ['product_id' => 0])['error']), 'producto 0');
});

test('el carrito: añadir, ver, cambiar la cantidad y quitar usan el precio del catálogo; el enlace de pago apunta a la caja', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);

    $out = aifecCartRun($web, static function ($cart, App $app) use ($ids): array {
        $tools = $app->service('tools');
        $out = [];
        $out['empty_link'] = $tools->call('checkout_link', []);
        $out['add'] = $tools->call('cart_add', ['product_id' => $ids['silla_roja'], 'qty' => 2]);
        $out['view'] = $tools->call('cart_view', []);
        $out['link'] = $tools->call('checkout_link', []);
        $out['update'] = $tools->call('cart_update', ['key' => $out['add']['key'], 'qty' => 3]);
        $out['gone'] = $tools->call('cart_update', ['key' => $out['add']['key'], 'qty' => 0]);
        $out['bad'] = $tools->call('cart_update', ['key' => 'no-existe', 'qty' => 1]);

        return $out;
    });
    check(isset($out['empty_link']['error']), 'sin nada en el carrito no hay enlace de pago');
    same(2, $out['add']['cart']['count']);
    same(11980, $out['add']['cart']['items_total_cents'], '2 × 59,90 €, el precio del catálogo');
    same(1, count($out['view']['lines']));
    same(5990, $out['view']['lines'][0]['unit_price_cents']);
    same(11980, $out['view']['lines'][0]['total_cents']);
    same(money(11980), $out['view']['lines'][0]['total']);
    same('Silla roja', $out['view']['lines'][0]['name']);
    contains('https://tienda.test/finalizar-compra', $out['link']['url'], 'apunta a la caja');
    same(2, $out['link']['cart']['count']);
    same(17970, $out['update']['cart']['items_total_cents']);
    same(0, $out['gone']['cart']['count']);
    check(isset($out['bad']['error']), 'una línea que no existe es un error');
});

test('order_status: con el número y el correo del pedido da estado, envío y seguimiento; un correo que no coincide no filtra nada', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    $orderId = aifecOrderPlace($app, $web, [[$ids['silla_roja'], 0, 1]])['id'];
    $number = adminOrderNumberOf($app, (int) $orderId);
    $app->db()->update('orders', ['carrier_title' => 'Estándar', 'tracking_code' => 'TRK-1234', 'shipped_at' => '2026-11-12 10:00:00'], ['id' => (int) $orderId]);
    $tools = assistantTools($app);

    $ok = $tools->call('order_status', ['number' => $number, 'email' => 'Ana@Cliente.test']);
    check(!isset($ok['error']), 'el correo se compara sin distinguir mayúsculas: ' . json_encode($ok));
    same($number, $ok['number']);
    same('Estándar', $ok['carrier']);
    same('TRK-1234', $ok['tracking_code']);
    same('2026-11-12', $ok['shipped_on']);
    foreach (['billing', 'shipping', 'address', 'total', 'items', 'email', 'line1', 'postcode'] as $private) {
        check(!array_key_exists($private, $ok), 'no devuelve «' . $private . '»');
    }
    $json = json_encode($ok);
    notContains('Calle Mayor', (string) $json);
    notContains('ana@cliente.test', strtolower((string) $json));

    $wrong = $tools->call('order_status', ['number' => $number, 'email' => 'otra@cliente.test']);
    $missing = $tools->call('order_status', ['number' => '2099-999999', 'email' => 'ana@cliente.test']);
    same(['error'], array_keys($wrong), 'solo un error, sin un solo dato del pedido');
    same($missing, $wrong, 'la misma respuesta si el pedido no existe: no se confirma que exista');
    check(isset($tools->call('order_status', ['number' => $number])['error']), 'sin correo tampoco');
});

test('otro módulo añade herramientas con el filtro assistant.tools; una que lanza da un error limpio y una desconocida también', function (): void {
    $app = assistantApp();
    $app->hooks()->filter('assistant.tools', static function (array $tools): array {
        $tools['ping'] = [
            'description' => 'Replies pong.',
            'schema' => ['type' => 'object', 'properties' => ['word' => ['type' => 'string']], 'additionalProperties' => false],
            'handler' => static function (array $in): array {
                return ['pong' => isset($in['word']) ? $in['word'] : 'x'];
            },
        ];
        $tools['boom'] = [
            'description' => 'Always fails.',
            'schema' => ['type' => 'object', 'properties' => []],
            'handler' => static function (): array {
                throw new \RuntimeException('secreto interno');
            },
        ];
        $tools['sin_funcion'] = ['description' => 'No handler', 'schema' => []];

        return $tools;
    });
    $tools = assistantTools($app);
    $all = $tools->all();
    check(isset($all['ping']) && isset($all['boom']), 'las herramientas nuevas se ofrecen');
    check(!isset($all['sin_funcion']), 'una sin función se descarta');
    same(['pong' => 'hola'], $tools->call('ping', ['word' => 'hola']));
    same(false, $all['ping']['writes']);

    $boom = $tools->call('boom', []);
    same(['error'], array_keys($boom));
    notContains('secreto', (string) $boom['error'], 'el mensaje interno no sale');
    $unknown = $tools->call('no_existe', []);
    contains('no_existe', (string) $unknown['error']);
    same(22, count($tools->forModel()), "las 12 de serie, las 8 de la compra en el chat y las dos nuevas");
});

if (!function_exists('adminOrderNumberOf')) {
    function adminOrderNumberOf(App $app, int $id): string
    {
        return (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$id]);
    }
}

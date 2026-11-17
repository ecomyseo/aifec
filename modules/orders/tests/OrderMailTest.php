<?php
/**
 * AIFEC — pruebas de los correos del pedido (idioma del pedido, escapado, desglose, enlace, instrucciones de pago) y de
 * las lecturas (find, findByNumber, findBySecret).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;
use Aifec\Kernel\App;
use Aifec\Kernel\Response;

require_once __DIR__ . '/helpers.php';

test('los correos salen con el idioma del pedido aunque el cobro llegue en otra petición', function (): void {
    $app = aifecOrderApp(['settings' => ['i18n.languages' => ['es', 'en']]]);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200, 'i18n' => ['en' => ['name' => 'T-shirt']]]);
    $web = new TestClient($app);
    $placed = aifecCartRun($web, static function ($cart, App $app) use ($p): array {
        $app->setLang('en');
        $cart->add($p, 0, 1);
        $cart->setPaymentMethod('gw');

        return $app->service('orders')->place(aifecOrderCheckout());
    });
    same(true, $placed['ok'], (string) $placed['error']);
    $orders = $app->service('orders');
    same('en', $orders->find((int) $placed['id'])['lang']);

    // El cobro llega en una petición en castellano.
    $app->setLang('es');
    $orders->markPaid((int) $placed['id'], 'gw', 'PAY-1', (int) $orders->find((int) $placed['id'])['total']);

    $customerMail = aifecOrderMails($app, 'order_confirmed')[0];
    same('en', $customerMail['locale']);
    contains('Order 2026-000001 confirmed', (string) $customerMail['subject']);
    contains('Thank you for your order 2026-000001', (string) $customerMail['html']);
    contains('Subtotal', (string) $customerMail['html']);
    contains('T-shirt', (string) $customerMail['html']);
    notContains('Gracias', (string) $customerMail['html']);
    contains('€20.00', (string) $customerMail['html']);

    // El aviso a la tienda va en el idioma principal del sitio.
    $adminMail = aifecOrderMails($app, 'admin_new_order')[0];
    same('es', $adminMail['locale']);
    contains('Pedido nuevo 2026-000001', (string) $adminMail['subject']);
});

test('un nombre de producto con «&» y etiquetas sale escapado en el correo', function (): void {
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['name' => 'Café & Té <b>x</b>', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    aifecOrderPaid($app, new TestClient($app), [[$p, 0, 1]]);

    foreach (['order_confirmed', 'admin_new_order'] as $template) {
        $html = (string) aifecOrderMails($app, $template)[0]['html'];
        contains('Café &amp; Té &lt;b&gt;x&lt;/b&gt;', $html);
        notContains('<b>x</b>', $html);
    }
});

test('el correo lleva la tabla de líneas y el desglose con subtotal, descuentos, envío, impuestos por tipo y total', function (): void {
    $app = aifecOrderApp();
    couponsMake($app, ['code' => 'OTONO', 'percent' => 1000]);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    $web = new TestClient($app);
    $placed = aifecCartRun($web, static function ($cart, App $app) use ($p): array {
        $cart->add($p, 0, 2);
        $cart->applyCoupon('OTONO');
        $cart->setPaymentMethod('gw');

        return $app->service('orders')->place(aifecOrderCheckout());
    });
    $orders = $app->service('orders');
    $order = $orders->find((int) $placed['id']);
    $orders->markPaid((int) $placed['id'], 'gw', 'PAY-1', $order['total']);
    $html = (string) aifecOrderMails($app, 'order_confirmed')[0]['html'];
    $money = static function (int $cents) use ($app): string {
        return $app->i18n()->money($cents, 'EUR');
    };

    contains('Camiseta', $html);
    contains($money(4000), $html);
    contains('Descuento: ', $html);
    contains('-' . $money($order['discount_total']), $html);
    contains('Envío: Estándar', $html);
    contains($money($order['shipping_total']), $html);
    contains('Incluye ' . $order['taxes'][0]['name'], $html);
    contains($money($order['taxes'][0]['amount']), $html);
    contains($money($order['total']), $html);
    contains('Dirección de facturación', $html);
    contains('Calle Mayor 1', $html);
});

test('con precios sin impuestos el desglose suma el impuesto en vez de decir que está incluido', function (): void {
    $app = aifecOrderApp(['prices_include_tax' => false]);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    aifecOrderPaid($app, new TestClient($app), [[$p, 0, 1]]);
    $html = (string) aifecOrderMails($app, 'order_confirmed')[0]['html'];

    notContains('Incluye', $html);
    contains('IVA', $html);
});

test('el enlace para ver el pedido lleva la ficha, al crearlo y en los avisos posteriores', function (): void {
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    // La ruta «order.thanks» pide la ficha en claro, que se conoce mientras dura la petición que creó el pedido.
    $first = aifecOrderPaid($app, new TestClient($app), [[$p, 0, 1]]);
    contains('Ver tu pedido', (string) aifecOrderMails($app, 'order_confirmed')[0]['html'], 'con la ficha a mano sale el enlace');

    aifecOrderPayment($app)->action = PaymentAction::paid('SIM-1');
    $placed = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]]);
    $mails = aifecOrderMails($app, 'order_confirmed');
    $html = (string) $mails[1]['html'];
    contains('Ver tu pedido', $html);
    contains('https://tienda.test/pedido/2026-000002/' . $placed['secret'], $html);

    // El aviso de otra petición vuelve a calcular la ficha: quien compró sin cuenta también puede seguir su pedido.
    $orders = $app->service('orders');
    aifecCartRun(new TestClient($app), static function ($cart, App $app) use ($placed): void {
        $app->service('orders')->setStatus((int) $placed['id'], 'shipped', '', null, true);
    });
    $shipped = (string) aifecOrderMails($app, 'order_shipped')[0]['html'];
    contains('Ver tu pedido', $shipped, 'aviso de otra petición');
    contains('https://tienda.test/pedido/2026-000002/' . $placed['secret'], $shipped);
    same('processing', $orders->find($first)['status']);
});

test('al volver a poner un pedido en espera, el correo lleva otra vez las instrucciones que dejó la forma de pago', function (): void {
    $app = aifecOrderApp();
    aifecOrderPayment($app)->action = PaymentAction::instructions('<p>Transfiere a ES00 1234</p>');
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 200]);
    $placed = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]]);
    $orders = $app->service('orders');
    $id = (int) $placed['id'];

    same(true, $orders->setStatus($id, 'processing'));
    same(true, $orders->setStatus($id, 'on_hold'));
    $mails = aifecOrderMails($app, 'order_on_hold');
    same(2, count($mails));
    contains('<p>Transfiere a ES00 1234</p>', (string) $mails[1]['html']);
});

test('las siete plantillas se pintan con su ejemplo en castellano y en inglés', function (): void {
    $app = aifecOrderApp();
    $mailer = $app->service('mailer');
    $keys = ['order_confirmed', 'order_on_hold', 'order_shipped', 'order_completed', 'order_cancelled', 'order_refunded', 'admin_new_order'];
    $templates = $mailer->templates();

    foreach ($keys as $key) {
        check(isset($templates[$key]), 'la plantilla ' . $key . ' está declarada');
        same('orders', $templates[$key]['module']);
        foreach (['es', 'en'] as $locale) {
            $rendered = $mailer->render($key, $templates[$key]['sample'], $locale);
            check($rendered['subject'] !== '' && strpos($rendered['subject'], '{') === false, 'asunto de ' . $key . ' en ' . $locale . ': ' . $rendered['subject']);
            contains('Producto de ejemplo', $rendered['html']);
            contains('2026-000123', $rendered['subject'] . $rendered['html']);
            check(trim($rendered['text']) !== '', 'versión en texto de ' . $key);
        }
    }
});

test('la cola del correo solo lleva lo imprescindible del pedido', function (): void {
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 200]);
    aifecOrderPaid($app, new TestClient($app), [[$p, 0, 1]]);
    $data = (string) aifecOrderMails($app, 'order_confirmed')[0]['data'];

    foreach (['"customer"', '"payments"', '"history"', '"secret"', '"raw"', 'password'] as $forbidden) {
        notContains($forbidden, $data);
    }
    contains('"number":"2026-000001"', $data);
});

test('sin módulo de correo el pedido se crea y se cobra igual', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 200]);
    $web = new TestClient($app);
    $placed = aifecCartRun($web, static function ($cart, App $app) use ($p): array {
        $cart->add($p, 0, 1);
        $cart->setPaymentMethod('fake');

        return $app->service('orders')->place(aifecOrderCheckout());
    });

    same(true, $placed['ok'], (string) $placed['error']);
    same(false, $app->has('mailer'));
    $orders = $app->service('orders');
    same('on_hold', $orders->find((int) $placed['id'])['status']);
    same(true, $orders->markPaid((int) $placed['id'], 'fake', 'F-1', (int) $orders->find((int) $placed['id'])['total']));
    same('processing', $orders->find((int) $placed['id'])['status']);
});

test('sin correo de la tienda no se manda el aviso al comerciante', function (): void {
    $app = aifecOrderApp(['settings' => ['store.email' => '']]);
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 200]);
    aifecOrderPaid($app, new TestClient($app), [[$p, 0, 1]]);

    same(0, count(aifecOrderMails($app, 'admin_new_order')));
    same(1, count(aifecOrderMails($app, 'order_confirmed')));
});

test('findBySecret solo da el pedido con la ficha buena y find no enseña la ficha', function (): void {
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 200]);
    $placed = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]]);
    $orders = $app->service('orders');

    $found = $orders->findBySecret($placed['number'], $placed['secret']);
    check($found !== null, 'con la ficha buena aparece');
    same((int) $placed['id'], $found['id']);
    same(false, array_key_exists('secret', $found));
    same(null, $orders->findBySecret($placed['number'], str_repeat('0', 48)));
    same(null, $orders->findBySecret($placed['number'], ''));
    same(null, $orders->findBySecret($placed['number'], strtoupper($placed['secret'])));
    same(null, $orders->findBySecret('2026-999999', $placed['secret']));
    same(null, $orders->findBySecret($placed['number'], hash('sha256', $placed['secret'])), 'el resumen guardado no sirve como ficha');
});

test('find devuelve el pedido con todo descodificado y sus partes, y findByNumber lo encuentra', function (): void {
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    $id = aifecOrderPaid($app, new TestClient($app), [[$p, 0, 1]]);
    $orders = $app->service('orders');
    $order = $orders->find($id);

    foreach (['billing', 'shipping', 'taxes', 'adjustments', 'coupons', 'attribution', 'items', 'history', 'payments', 'refunds'] as $key) {
        check(is_array($order[$key]), $key . ' es una lista');
    }
    same('Ana', $order['billing']['first_name']);
    same('processing', $order['state']['code']);
    same('Processing', $order['state']['name']);
    same('ana@cliente.test', $order['customer']['email']);
    same(false, array_key_exists('password', $order['customer']));
    same('product', $order['items'][0]['kind']);
    same(true, is_int($order['total']) && is_int($order['items'][0]['unit_price']));
    same($id, $orders->findByNumber($order['number'])['id']);
    same(null, $orders->findByNumber('no-existe'));
    same(null, $orders->find(0));
    same(null, $orders->find(999));
});

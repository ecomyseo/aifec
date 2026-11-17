<?php
/**
 * AIFEC — pruebas de las tres formas de pago sencillas juntas: aparecen en el servicio «payments» en el orden del ajuste
 * orders.payment_order y cada una con su código, y la caja las ofrece según su propia regla.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/PaymentsKit.php';

/**
 * Una tienda con las tres formas de pago, con IBAN puesto (si no, la transferencia no se ofrece).
 *
 * @param array<string,mixed> $settings
 */
function paymentsAll(array $settings = []): App
{
    return paymentsApp(['payments-simulated', 'payments-transfer', 'payments-cod'], $settings + ['payments.transfer.iban' => 'ES8200000000000000000000']);
}

test('las tres aparecen en el servicio payments, cada una con su código', function (): void {
    $app = paymentsAll();
    $all = $app->service('payments')->all();

    same(['cod', 'simulated', 'transfer'], array_keys($all), 'registradas en el orden en que cargan los módulos');
    foreach ($all as $code => $method) {
        same($code, $method->code());
        $info = $method->info();
        check($info['title'] !== '' && $info['description'] !== '', 'la forma de pago ' . $code . ' tiene título y descripción');
    }
});

test('available() las da en el orden de orders.payment_order y, las que no se nombran, después en orden de registro', function (): void {
    $app = paymentsAll();
    $payments = $app->service('payments');
    $context = paymentsContext();

    same(['cod', 'simulated', 'transfer'], paymentsCodes($payments->available($context)), 'sin ajuste, en orden de registro');

    $app->settings()->set('orders.payment_order', 'transfer, cod ,simulated');
    same(['transfer', 'cod', 'simulated'], paymentsCodes($payments->available($context)), 'un texto con comas');

    $app->settings()->set('orders.payment_order', ['simulated', 'transfer']);
    same(['simulated', 'transfer', 'cod'], paymentsCodes($payments->available($context)), 'una lista, y el que falta va detrás');

    $app->settings()->set('orders.payment_order', ['cod', 'paypal', 'COD']);
    same(['cod', 'simulated', 'transfer'], paymentsCodes($payments->available($context)), 'un código que no existe o repetido no estorba');
});

test('cada una se ofrece según su regla: solo digital deja fuera al contra reembolso, otro país también, y sin IBAN falta la transferencia', function (): void {
    $app = paymentsAll();
    $payments = $app->service('payments');

    same(['cod', 'simulated', 'transfer'], paymentsCodes($payments->available(paymentsContext())));
    same(['simulated', 'transfer'], paymentsCodes($payments->available(paymentsContext(['needs_shipping' => false]))));
    same(['simulated', 'transfer'], paymentsCodes($payments->available(paymentsContext(['country' => 'PT']))));

    $app->settings()->set('payments.transfer.iban', '');
    same(['cod', 'simulated'], paymentsCodes($payments->available(paymentsContext())));

    $app->site()->config['debug'] = false;
    same(['cod'], paymentsCodes($payments->available(paymentsContext())), 'en una tienda real, el simulado se va');
});

test('el carrito las enseña con su título, su descripción y su recargo, y deja elegir cualquiera de ellas', function (): void {
    $app = paymentsAll(['payments.cod.fee' => 300, 'payments.cod.percent' => 0]);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    $web = new TestClient($app);

    $summary = aifecCartRun($web, static function ($cart, App $app) use ($p): array {
        $cart->add($p, 0, 1);

        return $cart->summary();
    });
    same(['cod', 'simulated', 'transfer'], array_column($summary['payment_methods'], 'code'));
    same([300, 0, 0], array_column($summary['payment_methods'], 'fee'));
    same(['Contra reembolso', 'Pago de prueba', 'Transferencia bancaria'], array_column($summary['payment_methods'], 'title'));
    same(null, $summary['payment_method'], 'sin elegir ninguna');

    foreach (['cod', 'simulated', 'transfer'] as $code) {
        $chosen = aifecCartRun($web, static function ($cart, App $app) use ($code): ?string {
            $cart->setPaymentMethod($code);

            return $cart->summary()['payment_method'];
        });
        same($code, $chosen);
    }
});

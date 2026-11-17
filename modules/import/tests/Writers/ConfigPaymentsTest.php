<?php
/**
 * AIFEC — escritor de formas de pago: copia lo que casa y deja cada forma APAGADA y en PRUEBAS pase lo que pase en el origen. Ni una clave sale
 * en los ajustes en claro, en el registro, en una nota o en un motivo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Writers\PaymentMethodsWriter;

require_once __DIR__ . '/WritersKit.php';

/** Los códigos de las formas de pago que la caja ofrece ahora para un pedido de España con envío. */
function wrOffered(App $app): array
{
    $codes = [];
    foreach ($app->service('payments')->available(['total' => 10000, 'currency' => 'EUR', 'country' => 'ES', 'needs_shipping' => true, 'customer_id' => 0, 'lang' => 'es']) as $method) {
        $codes[] = $method->code();
    }

    return $codes;
}

/** Un registro de forma de pago. */
function wrPay(string $id, string $code, array $settings, array $over = []): array
{
    return wr('payment_methods', $over + ['id' => $id, 'code' => $code, 'title' => ['es' => 'Pago ' . $code], 'enabled' => true, 'sandbox' => false, 'settings' => $settings, 'countries' => []]);
}

test('Redsys importada queda apagada y en pruebas, con sus datos, aunque en el origen estuviera encendida y en real', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $out = (new PaymentMethodsWriter($app))->write(wr('payment_methods'), $ctx);

    same(TypeWriter::CREATED, $out['result']);
    $settings = $app->settings();
    same('000000000', $settings->str('payments.redsys.merchant_code'));
    same('1', $settings->str('payments.redsys.terminal'));
    same('Tarjeta', $settings->str('payments.redsys.title'));
    same('test', $settings->str('payments.redsys.environment'), 'en pruebas, aunque el origen no estuviera en pruebas');
    check(!in_array('redsys', wrOffered($app), true), 'apagada: la caja no la ofrece');
    same(1, $ctx->unmappedCount('review', 'Payment method «redsys»'), 'queda en «revisa a mano»');
});

test('la clave de mentira no está en claro en ningún ajuste, ni en el registro, ni en una nota; solo cifrada', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    (new PaymentMethodsWriter($app))->write(wr('payment_methods'), $ctx);

    foreach ($app->db()->select('SELECT k, v FROM {settings}') as $row) {
        notContains('clave-de-mentira', (string) $row['v'], 'ajuste «' . $row['k'] . '»');
    }
    foreach ($app->db()->select('SELECT reason FROM {import_log}') as $row) {
        notContains('clave-de-mentira', (string) $row['reason'], 'registro de la migración');
    }
    foreach ($ctx->notes as $note) {
        notContains('clave-de-mentira', $note[3], 'nota');
    }
    foreach ($ctx->unmapped as $item) {
        notContains('clave-de-mentira', $item['note'] . $item['name']);
    }
    same('clave-de-mentira', $app->secret('import.payment.redsys.secret'), 'se recupera cifrada con la clave del sitio');
});

test('un fallo al escribir no filtra la clave en el motivo', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $app->db()->pdo()->exec('DROP TABLE af_settings');
    $out = (new PaymentMethodsWriter($app))->write(wr('payment_methods'), $ctx);

    same(TypeWriter::SKIPPED, $out['result']);
    notContains('clave-de-mentira', $out['reason']);
    notContains('000000000', $out['reason']);
});

test('activar Redsys de un origen en pruebas la deja lista (en pruebas); la de un origen real sigue sin ofrecerse', function (): void {
    $sandbox = writersApp();
    (new PaymentMethodsWriter($sandbox))->write(wr('payment_methods', ['sandbox' => true]), writersContext($sandbox));
    check(!in_array('redsys', wrOffered($sandbox), true));
    same(true, PaymentMethodsWriter::activate($sandbox, 'redsys'));
    check(in_array('redsys', wrOffered($sandbox), true), 'ya se ofrece');
    same('test', $sandbox->settings()->str('payments.redsys.environment'));
    same('', $sandbox->secret('import.payment.redsys.secret'), 'lo guardado aparte se vacía');
    same(false, PaymentMethodsWriter::activate($sandbox, 'redsys'), 'una segunda vez no hay nada que mover');

    $real = writersApp();
    (new PaymentMethodsWriter($real))->write(wr('payment_methods', ['sandbox' => false]), writersContext($real));
    PaymentMethodsWriter::activate($real, 'redsys');
    check(!in_array('redsys', wrOffered($real), true), 'la clave real va a su sitio, pero con el entorno en pruebas no se ofrece');
    same(true, $real->secret('payments.redsys.secret_live') === 'clave-de-mentira');
});

test('la transferencia (bacs) conserva titular y banco, deja el IBAN aparte y no se ofrece hasta activarla', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $record = wrPay('b1', 'bacs', ['account_name' => 'Ñandú Muebles S.L.', 'iban' => 'ES9121000418450200051332', 'bank_name' => 'Banco de Prueba']);
    $out = (new PaymentMethodsWriter($app))->write($record, $ctx);

    same(TypeWriter::CREATED, $out['result']);
    same('Ñandú Muebles S.L.', $app->settings()->str('payments.transfer.holder'));
    same('Banco de Prueba', $app->settings()->str('payments.transfer.bank'));
    same('Pago bacs', $app->settings()->str('payments.transfer.title'));
    same('', $app->settings()->str('payments.transfer.iban'));
    check(!in_array('transfer', wrOffered($app), true), 'apagada');
    same(true, PaymentMethodsWriter::activate($app, 'transfer'));
    check(in_array('transfer', wrOffered($app), true), 'activada');
    same('ES9121000418450200051332', $app->settings()->str('payments.transfer.iban'));
});

test('lo que el comerciante ya tenía puesto (un IBAN, el entorno) no se toca al traer la misma forma de pago', function (): void {
    $app = writersApp(['payments.transfer.iban' => 'ES9121000418450200051332', 'payments.redsys.environment' => 'live']);
    $ctx = writersContext($app);
    $writer = new PaymentMethodsWriter($app);
    $writer->write(wrPay('b1', 'ps_wirepayment', ['iban' => 'ES0000000000000000000000']), $ctx);
    $writer->write(wr('payment_methods'), $ctx);

    same('ES9121000418450200051332', $app->settings()->str('payments.transfer.iban'), 'su IBAN sigue ahí');
    same('live', $app->settings()->str('payments.redsys.environment'), 'y su entorno');
});

test('el contra reembolso copia el recargo y no se ofrece en ningún país hasta activarlo', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $record = wrPay('c1', 'cod', ['fee' => '300', 'percent' => '100', 'min_fee' => '500'], ['countries' => ['es', 'PT'], 'max_amount' => 30000]);
    (new PaymentMethodsWriter($app))->write($record, $ctx);
    $settings = $app->settings();

    same(300, $settings->int('payments.cod.fee'));
    same(100, $settings->int('payments.cod.percent'));
    same(500, $settings->int('payments.cod.min_fee'));
    same(30000, $settings->int('payments.cod.max_total'));
    check(!in_array('cod', wrOffered($app), true), 'sin países no se ofrece');
    same(true, PaymentMethodsWriter::activate($app, 'cod'));
    same(['ES', 'PT'], $settings->arr('payments.cod.countries'));
    check(in_array('cod', wrOffered($app), true), 'activado, se ofrece en España');
});

test('PayPal (ppcp-gateway) queda en sandbox con su identificador y su clave aparte', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    (new PaymentMethodsWriter($app))->write(wrPay('p1', 'ppcp-gateway', ['client_id' => 'AbCdEfGh12345678', 'secret' => 'otra-clave-de-mentira'], ['sandbox' => true]), $ctx);

    same('sandbox', $app->settings()->str('payments.paypal.environment'));
    same('AbCdEfGh12345678', $app->settings()->str('payments.paypal.client_id_sandbox'));
    check(!in_array('paypal', wrOffered($app), true));
    foreach ($app->db()->select('SELECT v FROM {settings}') as $row) {
        notContains('otra-clave-de-mentira', (string) $row['v']);
    }
});

test('una pasarela que aquí no existe se descarta, queda en «sin equivalente» y la equivalencia elegida manda', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $unknown = (new PaymentMethodsWriter($app))->write(wrPay('s1', 'stripe', ['secret_key' => 'sk_prueba_de_mentira']), $ctx);
    same(TypeWriter::SKIPPED, $unknown['result']);
    same(1, $ctx->unmappedCount('payment_method', 'stripe'));
    notContains('sk_prueba_de_mentira', $unknown['reason']);

    $chosen = writersContext($app, [], ['payment_method' => ['m7' => 'transfer']]);
    $out = (new PaymentMethodsWriter($app))->write(wrPay('m7', 'mi-pasarela', ['holder' => 'Ana Ruiz']), $chosen);
    same(TypeWriter::CREATED, $out['result']);
    same('Ana Ruiz', $app->settings()->str('payments.transfer.holder'));
});

test('repetir una forma de pago no cambia nada, y cambiarla en el origen la actualiza sin conflicto', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new PaymentMethodsWriter($app);
    $writer->write(wr('payment_methods'), $ctx);

    same(TypeWriter::UNCHANGED, $writer->write(wr('payment_methods'), $ctx)['result']);
    $app->clock()->advance(300);
    same(TypeWriter::UPDATED, $writer->write(wr('payment_methods', ['title' => ['es' => 'Tarjeta o Bizum']]), $ctx)['result']);
    same('Tarjeta o Bizum', $app->settings()->str('payments.redsys.title'));
    same(1, wrCount($app, 'import_map', "type = 'payment_methods'"));
});

test('simulacro de formas de pago: nada se escribe, ni siquiera la clave cifrada', function (): void {
    $app = writersApp();
    $dry = writersContext($app)->dry(true);
    $counts = wrCounts($app);
    $out = (new PaymentMethodsWriter($app))->write(wr('payment_methods'), $dry);

    same(TypeWriter::CREATED, $out['result']);
    same($counts, wrCounts($app), 'todas las tablas, iguales');
    same('', $app->secret('import.payment.redsys.secret'));
});

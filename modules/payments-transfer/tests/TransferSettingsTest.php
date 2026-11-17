<?php
/**
 * AIFEC — pruebas de los ajustes de la transferencia: el IBAN se valida al guardar (forma y dígitos de control), uno malo no
 * se queda guardado, uno bueno se guarda en forma canónica, y la clase Iban aguanta lo raro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\PaymentsTransfer\Iban;

require_once __DIR__ . '/TransferKit.php';

test('la pantalla de ajustes abre con sus pestañas y guarda los datos de la cuenta, con el «&» tal cual', function (): void {
    $app = paymentsApp(['payments-transfer']);
    $web = adminClient($app);
    $r = $web->get('/admin/settings/payments-transfer');
    same(200, $r->status);
    contains('data-af-tab="trmain"', $r->body);
    contains('data-af-tab="trtexts"', $r->body);
    same('5', inputValue($r->body, 'f[payments.transfer.days]'), 'el plazo de fábrica son cinco días');
    contains('/admin/settings/payments-transfer', $web->get('/admin/settings')->body, 'aparece en el índice de ajustes');

    $r = paymentsSaveSettings($web, 'payments-transfer', transferForm(['payments.transfer.holder' => 'Sillas & Mesas', 'payments.transfer.days' => '7']), 'trmain');
    same(302, $r->status);
    contains('#tab=trmain', $r->headers['Location'], 'vuelve a la misma pestaña');
    $app->settings()->reload();
    same('Sillas & Mesas', $app->settings()->str('payments.transfer.holder'));
    same(7, $app->settings()->int('payments.transfer.days'));
    same('', $app->settings()->str('payments.transfer.iban'));
});

test('un IBAN bueno se guarda en forma canónica y vuelve a pintarse en la pantalla', function (): void {
    $app = paymentsApp(['payments-transfer']);
    $web = adminClient($app);

    same(302, paymentsSaveSettings($web, 'payments-transfer', transferForm(['payments.transfer.iban' => ' es82 0000 0000-0000 0000 0000 ']), 'trmain')->status);
    $app->settings()->reload();
    same('ES8200000000000000000000', $app->settings()->str('payments.transfer.iban'), 'sin espacios ni guiones y en mayúsculas');
    same('ES8200000000000000000000', $app->settings()->str('payments.transfer.iban_last_valid'));
    same(true, $app->service('payments')->get('transfer')->available(paymentsContext()), 'y ya se ofrece');
    same('ES8200000000000000000000', inputValue($web->get('/admin/settings/payments-transfer')->body, 'f[payments.transfer.iban]'));
});

test('un IBAN con los dígitos de control malos no se guarda: se queda el anterior y se avisa', function (): void {
    $app = paymentsApp(['payments-transfer']);
    $web = adminClient($app);
    paymentsSaveSettings($web, 'payments-transfer', transferForm(['payments.transfer.iban' => TRANSFER_IBAN]), 'trmain');

    $r = paymentsSaveSettings($web, 'payments-transfer', transferForm(['payments.transfer.iban' => 'ES83 0000 0000 0000 0000 0000', 'payments.transfer.bank' => 'Otro banco']), 'trmain');
    same(302, $r->status);
    $app->settings()->reload();
    same(TRANSFER_IBAN, $app->settings()->str('payments.transfer.iban'), 'sigue el IBAN bueno de antes');
    same('Otro banco', $app->settings()->str('payments.transfer.bank'), 'el resto de los datos sí se guardan');
    $page = $web->follow()->body;
    contains('El IBAN no es válido', $page);
    contains('af-alert-bad', $page);

    // Sin ningún IBAN bueno detrás, queda vacío y la transferencia no se ofrece.
    $app2 = paymentsApp(['payments-transfer']);
    $web2 = adminClient($app2);
    paymentsSaveSettings($web2, 'payments-transfer', transferForm(['payments.transfer.iban' => TRANSFER_IBAN_BAD]), 'trmain');
    $app2->settings()->reload();
    same('', $app2->settings()->str('payments.transfer.iban'));
    same(false, $app2->service('payments')->get('transfer')->available(paymentsContext()));
});

test('un IBAN con forma de otra cosa se rechaza en el formulario y no se guarda nada', function (): void {
    $app = paymentsApp(['payments-transfer']);
    $web = adminClient($app);
    paymentsSaveSettings($web, 'payments-transfer', transferForm(['payments.transfer.iban' => TRANSFER_IBAN]), 'trmain');

    foreach (['XX', '12345678901234567890', 'ES82 0000 0000 0000 0000 000$', "ES82 <script>alert(1)</script>"] as $junk) {
        $r = paymentsSaveSettings($web, 'payments-transfer', transferForm(['payments.transfer.iban' => $junk, 'payments.transfer.bank' => 'No debe guardarse']), 'trmain');
        same(422, $r->status, 'se rechaza: ' . $junk);
        $app->settings()->reload();
        same(TRANSFER_IBAN, $app->settings()->str('payments.transfer.iban'));
        same('Banco de Mentira', $app->settings()->str('payments.transfer.bank'), 'un formulario con un error no guarda nada');
    }
});

test('vaciar el IBAN es una decisión: la transferencia deja de ofrecerse', function (): void {
    $app = paymentsApp(['payments-transfer']);
    $web = adminClient($app);
    paymentsSaveSettings($web, 'payments-transfer', transferForm(['payments.transfer.iban' => TRANSFER_IBAN]), 'trmain');
    same(true, $app->service('payments')->get('transfer')->available(paymentsContext()));

    same(302, paymentsSaveSettings($web, 'payments-transfer', transferForm(['payments.transfer.iban' => '']), 'trmain')->status);
    $app->settings()->reload();
    same('', $app->settings()->str('payments.transfer.iban'));
    same('', $app->settings()->str('payments.transfer.iban_last_valid'));
    same(false, $app->service('payments')->get('transfer')->available(paymentsContext()));
});

test('Iban: normaliza, comprueba el módulo 97 y escribe en grupos de cuatro, también con lo raro', function (): void {
    same('ES8200000000000000000000', Iban::normalize(' es82-0000 0000.0000 0000 0000 '));
    same('ES82 0000 0000 0000 0000 0000', Iban::format('es8200000000000000000000'));
    same('DE36 0000 0000 0000 0000 00', Iban::format('DE36000000000000000000'), 'el último grupo puede ser más corto');
    same('', Iban::format(''));
    same('', Iban::format(' - '));

    same(true, Iban::isValid('ES82 0000 0000 0000 0000 0000'));
    same(true, Iban::isValid('es8200000000000000000000'));
    same(true, Iban::isValid('DE36000000000000000000'), 'otro país');
    same(true, Iban::isValid('GB05TEST00000000000000'), 'con letras en la cuenta');
    same(false, Iban::isValid('ES83 0000 0000 0000 0000 0000'), 'un dígito de control cambiado');
    same(false, Iban::isValid('ES82 0000 0000 0000 0000 0001'), 'un dígito de la cuenta cambiado');
    same(false, Iban::isValid(''));
    same(false, Iban::isValid('ES82'), 'demasiado corto');
    same(false, Iban::isValid('ES82' . str_repeat('0', 31)), 'demasiado largo: 35 caracteres');
    same(false, Iban::isValid('8282 0000 0000 0000 0000 0000'), 'sin país');
    same(false, Iban::isValid('ESXX 0000 0000 0000 0000 0000'), 'sin dígitos de control');
    same(false, Iban::isValid("ES82 0000 0000 0000 0000 0000\u{00a0}ñ"), 'un carácter que no es de IBAN, aunque se quiten los separadores, cambia las cuentas');
    same(false, Iban::isValid('😀S82 0000 0000 0000 0000 0000'), 'un emoji no es un país');
});

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Redsys en una demostración: se ofrece solo en su entorno de pruebas; en real, la demo no la enseña.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/RedsysKit.php';

test('una demostración ofrece Redsys y Bizum solo en el entorno de pruebas: en real desaparecen, y lo demás sigue sin ofrecerse', function (): void {
    $app = rdsApp(['payments.redsys.bizum' => true]);
    $payments = $app->service('payments');
    check($payments->get('redsys') !== null, 'en una tienda normal, Redsys está');

    $app->site()->config['demo'] = true;
    check($payments->get('redsys') !== null, 'en una demo, Redsys en pruebas se ofrece');
    check($payments->get('bizum') !== null, 'y Bizum en pruebas también');
    same(true, $payments->get('redsys')->sandboxOnly());
    same(null, $payments->get('transfer'), 'una forma de pago que no declara entorno de pruebas sigue fuera de la demo');

    $app->settings()->set('payments.redsys.environment', 'live');
    same(null, $payments->get('redsys'), 'en real, una demo no ofrece Redsys: no se puede cobrar dinero de verdad');
    same(null, $payments->get('bizum'));
    $codes = array_keys($payments->available(['currency' => 'EUR', 'total' => 1000]));
    check(!in_array('redsys', $codes, true) && !in_array('bizum', $codes, true), 'ni aparece en la lista de formas de pago');

    $app->site()->config['demo'] = false;
    check($payments->get('redsys') !== null, 'fuera de la demo vuelve a estar');
});

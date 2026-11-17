<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * PayPal en una demostración: se ofrece solo en sandbox; en real, la demo no lo enseña.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/PayPalKit.php';

test('una demostración ofrece PayPal solo en sandbox: con el entorno en real desaparece', function (): void {
    $app = ppApp();
    $payments = $app->service('payments');
    check($payments->get('paypal') !== null, 'en una tienda normal, PayPal está');

    $app->site()->config['demo'] = true;
    check($payments->get('paypal') !== null, 'en una demo, PayPal en sandbox se ofrece');
    same(true, $payments->get('paypal')->sandboxOnly());

    $app->settings()->set('payments.paypal.environment', 'live');
    same(null, $payments->get('paypal'), 'en real, una demo no ofrece PayPal: no se puede cobrar dinero de verdad');
    $app->site()->config['demo'] = false;
    check($payments->get('paypal') !== null, 'fuera de la demo vuelve a estar');
});

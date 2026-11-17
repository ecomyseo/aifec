<?php
/**
 * AIFEC — «Ajustes» lleva también a lo que una dueña busca ahí aunque viva en su propia pantalla: el aspecto, los menús y las formas
 * de pago (14/11/2026: «no aparece aspecto, tema ni diseño»), y solo para quien puede abrirlas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

test('Ajustes enseña Aspecto de la tienda, Menús y Formas de pago, y no a quien no puede abrirlas', function (): void {
    $app = testApp(['modules' => '*']);
    $body = adminClient($app)->get('/admin/settings')->body;
    contains('href="/admin/themes"', $body);
    contains('Aspecto de la tienda', $body);
    contains('href="/admin/cms/menus"', $body);
    contains('href="/admin/payments"', $body);
    contains('Formas de pago', $body);
    $support = adminClient($app, 'support')->get('/admin/settings');
    notContains('href="/admin/payments"', (string) $support->body, 'atención al cliente no cambia las formas de pago');
});

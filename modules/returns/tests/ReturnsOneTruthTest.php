<?php
/**
 * AIFEC — una sola verdad para el plazo de devolución. Lo que la tienda publica para las máquinas (datos estructurados, feeds,
 * auditoría AIFEC) sale del módulo «semantic»; el plazo de verdad lo gestiona el módulo de devoluciones. Si en «semantic» no
 * se ha escrito nada, vale el del módulo de devoluciones: la auditoría decía «falta la política de devoluciones» en una tienda
 * que las acepta (lo encontró quien escribía el manual, 13/11).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('con el módulo de devoluciones activo y sin plazo escrito en lo que se publica, vale el plazo del módulo', function (): void {
    $app = returnsApp(['modules' => ['semantic']]);
    $app->boot();
    same(0, $app->settings()->int('semantic.returns.days'), 'en lo que se publica no hay nada escrito');
    same(30, $app->settings()->int('returns.days'), 'el módulo de devoluciones trae 30 días de serie');

    $policy = $app->service('semantic')->returns();

    check(is_array($policy), 'hay política que publicar');
    same(30, $policy['days']);

    $app->settings()->set('returns.days', 21);
    same(21, $app->service('semantic')->returns()['days'], 'si la tienda cambia su plazo, lo publicado cambia con él');
});

test('lo escrito a propósito en lo que se publica manda sobre el plazo del módulo', function (): void {
    $app = returnsApp(['modules' => ['semantic'], 'settings' => ['semantic.returns.days' => 14, 'semantic.returns.payer' => 'merchant']]);
    $app->boot();

    $policy = $app->service('semantic')->returns();

    same(14, $policy['days']);
    same('merchant', $policy['payer']);
});

test('si el módulo de devoluciones dice 0 días, no se publica ninguna política', function (): void {
    $app = returnsApp(['modules' => ['semantic'], 'settings' => ['returns.days' => 0]]);
    $app->boot();

    same(null, $app->service('semantic')->returns());
});

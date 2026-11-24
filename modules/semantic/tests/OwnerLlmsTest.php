<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/*
 * Verificación del 24/11/2026: llms.txt no decía quién está detrás de la tienda. Ahora nombra al titular con la razón social y el NIF de
 * Ajustes → General (store.legal_name y store.vat_id); sin ellos, no sale nada. Datos inventados.
 */

test('llms.txt nombra al titular con su razón social y su NIF', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media'], ['store.legal_name' => 'Ana Ruiz Pérez', 'store.vat_id' => '12345678Z']);
    $text = (new TestClient($app))->get('/llms.txt')->body;
    contains('**Titular:** Ana Ruiz Pérez, NIF 12345678Z', $text);
    $only = semanticApp(['semantic', 'catalog', 'media'], ['store.legal_name' => 'Ana Ruiz Pérez']);
    contains('**Titular:** Ana Ruiz Pérez', (new TestClient($only))->get('/llms.txt')->body);
    notContains('NIF', (new TestClient($only))->get('/llms.txt')->body, 'sin NIF no se inventa');
});

test('sin razón social ni NIF, llms.txt no cambia: no hay línea de titular', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media']);
    notContains('Titular', (new TestClient($app))->get('/llms.txt')->body);
});

test('la razón social y el NIF salen también en los datos de la organización', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media'], ['store.legal_name' => 'Ana Ruiz Pérez', 'store.vat_id' => '12345678Z']);
    $org = semanticFind(semanticLd((new TestClient($app))->get('/')->body), 'Organization');
    same('Ana Ruiz Pérez', $org['legalName'] ?? '');
    same('12345678Z', $org['vatID'] ?? '');
});

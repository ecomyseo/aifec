<?php
/**
 * AIFEC — en otro idioma, lo que aún no tiene dirección traducida se queda en ese idioma (/en/producto/…) en vez de saltar al
 * principal, y la dirección del principal dentro de /en/ se sirve (14/11/2026: una visitante en inglés pinchaba un producto y
 * aterrizaba en la tienda en castellano; /en/categoria/… daba 404).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/../../modules/catalog/tests/helpers.php';

/** Una tienda en castellano e inglés con un producto sin traducir y otro traducido. @return array{0:\Aifec\Kernel\App,1:int,2:int} */
function langFallbackShop(): array
{
    $app = testApp(['modules' => ['catalog'], 'settings' => ['i18n.languages' => ['es', 'en']]]);
    $app->boot();
    $plain = aifecProduct($app, ['name' => 'Silla de roble', 'stock' => 5]);
    $done = aifecProduct($app, ['name' => 'Mesa de pino', 'stock' => 5, 'i18n' => ['en' => ['name' => 'Pine table']]]);

    return [$app, $plain, $done];
}

test('el enlace en inglés de un producto sin traducir se queda en /en/ con la dirección del principal', function (): void {
    [$app, $plain, $done] = langFallbackShop();
    same('/en/producto/silla-de-roble', $app->url()->entity('product', $plain, 'en'));
    same('/en/producto/pine-table', $app->url()->entity('product', $done, 'en'));
    same('/producto/silla-de-roble', $app->url()->entity('product', $plain, 'es'));
});

test('/en/ con la dirección del principal se sirve en inglés si no hay traducida, y lleva a la traducida si la hay', function (): void {
    [$app, , ] = langFallbackShop();
    $web = new TestClient($app);
    $page = $web->get('/en/producto/silla-de-roble');
    same(200, $page->status, 'se sirve');
    contains('<html lang="en"', $page->body, 'en inglés');
    contains('Silla de roble', $page->body, 'con el texto del principal mientras no esté traducido');
    $moved = $web->get('/en/producto/mesa-de-pino');
    same(301, $moved->status);
    same('/en/producto/pine-table', $moved->headers['Location']);
    same(404, $web->get('/en/producto/no-existe')->status);
});

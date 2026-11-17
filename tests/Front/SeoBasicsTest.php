<?php
/**
 * AIFEC — lo que vio la visita de SEO del 13/11/2026: la portada y las categorías sin descripción, y una página de un listado que no
 * existe («?page=99») contestando 200 con el contenido de la última.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/modules/catalog/tests/helpers.php';

test('la portada y una categoría llevan descripción aunque nadie la haya escrito', function (): void {
    $app = testApp(['modules' => '*', 'settings' => ['store.name' => 'Tienda & Co']]);
    $cat = aifecCategory($app, 'Sillas');
    aifecProduct($app, ['name' => 'Silla uno', 'price' => 1000, 'categories' => [$cat]]);
    $home = (string) (new TestClient($app))->get('/')->body;
    check(preg_match('/<meta name="description" content="Tienda &amp; Co: tienda en línea\./u', $home) === 1, 'la portada con la frase de serie');
    $listing = (string) (new TestClient($app))->get('/categoria/sillas')->body;
    check(preg_match('/<meta name="description" content="Sillas: 1 productos en Tienda &amp; Co\."/u', $listing) === 1, 'la categoría con su nombre y su número de productos');
    $app->settings()->set('seo.home_description', 'La que escribe el dueño');
    contains('<meta name="description" content="La que escribe el dueño">', (string) (new TestClient($app))->get('/')->body, 'la escrita manda');
});

test('una página de un listado que no existe lleva a la última de verdad con un 301', function (): void {
    $app = testApp(['modules' => '*']);
    $cat = aifecCategory($app, 'Mesas');
    aifecProduct($app, ['name' => 'Mesa uno', 'price' => 1000, 'categories' => [$cat]]);
    $r = (new TestClient($app))->get('/categoria/mesas?page=99');
    same(301, $r->status);
    notContains('page=99', (string) ($r->headers['Location'] ?? ''), 'no se queda en la página que no existe');
    same(200, (new TestClient($app))->get('/categoria/mesas')->status);
});

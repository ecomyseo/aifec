<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El origen del sistema (licencia AGPL-3.0 con los términos adicionales de LICENSE.md): la tienda lleva el aviso «Creado con AIFEC»,
 * la marca del generador y el panel dice qué es y de quién. Quitar cualquiera de las tres piezas es incumplir la licencia.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Kernel\App;

test('cada tema de serie lleva el aviso «Creado con AIFEC» y la marca del generador en portada, ficha y carrito', function (): void {
    foreach (['base', 'aifec', 'atelier', 'cafe', 'despensa', 'gmartos', 'lookbook', 'metrica', 'taller'] as $theme) {
        $app = testApp();
        $app->settings()->set('theme.active', $theme);
        $web = new TestClient($app);
        foreach (['/', '/carrito'] as $path) {
            $r = $web->get($path);
            contains('<a href="https://aifec.gmartos.es" rel="noopener"', $r->body, $theme . ' ' . $path . ': el aviso de origen');
            contains('Creado con AIFEC', $r->body, $theme . ' ' . $path . ': en castellano');
            contains('<meta name="generator" content="AIFEC ' . App::VERSION . '">', $r->body, $theme . ' ' . $path . ': el generador');
        }
    }
});

test('el panel dice que es AIFEC, su versión, su autor y su licencia', function (): void {
    $web = adminClient(testApp());
    $page = $web->get('/admin');
    same(200, $page->status);
    contains('AIFEC ' . App::VERSION, $page->body);
    contains('Ecom Experts', $page->body);
    contains('AGPL-3.0', $page->body);
});

test('la licencia es la AGPL-3.0 con sus términos adicionales, con el NOTICE y la Apache de las versiones anteriores', function (): void {
    $root = Autoload::root();
    $license = (string) file_get_contents($root . '/LICENSE.md');
    contains('GNU AFFERO GENERAL PUBLIC LICENSE', $license);
    contains('ADDITIONAL TERMS (GNU AGPL v3, section 7)', $license);
    contains('Created with AIFEC', $license);
    contains('Copyright 2026 Ecom Experts', $license);
    notContains('Apache License, Version 2.0', $license, 'la licencia vigente ya no es la Apache (solo se nombra para las versiones anteriores)');
    $notice = (string) file_get_contents($root . '/NOTICE');
    contains('Ecom Experts', $notice);
    contains('GNU Affero General Public License', $notice);
    contains('Apache License, Version 2.0', (string) file_get_contents($root . '/LICENSES/Apache-2.0.txt'));
    contains('AGPL', (string) file_get_contents($root . '/README.md'));
    check(is_file($root . '/CONTRIBUTING.md'), 'CONTRIBUTING con el acuerdo de contribución');
});

test('/.well-known/aifec.json declara el generador, el autor y la licencia', function (): void {
    $app = testApp(['modules' => ['semantic', 'catalog', 'media']]);
    $r = (new TestClient($app))->get('/.well-known/aifec.json');
    same(200, $r->status);
    $doc = json_decode($r->body, true);
    same('AIFEC', $doc['generator']['name']);
    same(App::VERSION, $doc['generator']['version']);
    same('Ecom Experts', $doc['generator']['author']);
    contains('AGPL-3.0', $doc['generator']['license']);
    same('https://aifec.gmartos.es', $doc['generator']['url']);
});

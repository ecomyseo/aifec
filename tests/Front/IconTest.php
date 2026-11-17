<?php
/**
 * AIFEC — el icono del sitio y el logotipo: de serie, elegidos en los ajustes y por la dirección de siempre.
 *
 * Fallo que cubre: la tienda no declaraba icono y cada visita pedía /favicon.ico, que respondía una página 404 entera.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Front\Page;

/** Biblioteca de medios de mentira: solo conoce la imagen 5. */
function iconTestMedia(): object
{
    return new class() {
        /** @return array<string,mixed>|null */
        public function image(int $id, ?string $alt = null): ?array
        {
            return $id === 5 ? ['url' => '/media/2026/logo.png', 'thumb' => '/media/2026/logo-thumb.webp', 'alt' => '', 'width' => 512, 'height' => 512, 'srcset' => '/media/2026/logo-480.webp 480w'] : null;
        }
    };
}

test('icono de serie: va en la cabecera como SVG con la inicial y /favicon.ico responde sin página de error', function (): void {
    $app = testApp(['settings' => ['store.name' => 'Ñandú & Cía']]);
    $app->boot();
    $head = Page::head($app, ['title' => 'Portada']);
    contains('<link rel="icon" href="data:image/svg+xml,', $head);
    $svg = Page::defaultIcon($app);
    contains('>Ñ</text>', $svg, 'la inicial de la tienda, con su eñe');
    notContains('&amp;amp;', $svg);

    $web = new TestClient($app);
    $r = $web->get('/favicon.ico');
    same(200, $r->status);
    contains('image/svg+xml', $r->contentType());
    contains('<svg', $r->body);
    contains('max-age=86400', (string) $r->headers['Cache-Control']);
    same('Ñandú &amp; Cía', shop_logo(), 'sin logotipo, la cabecera enseña el nombre, escapado');
});

test('icono y logotipo elegidos en los ajustes: salen de la biblioteca de medios y /favicon.ico redirige', function (): void {
    $app = testApp(['settings' => ['store.name' => 'Tienda <b>', 'store.icon' => 5, 'store.logo' => 5]]);
    $app->boot();
    $app->bind('media', static function () {
        return iconTestMedia();
    });
    same('/media/2026/logo-thumb.webp', Page::iconUrl($app));
    contains('<link rel="icon" href="/media/2026/logo-thumb.webp">', Page::head($app, ['title' => 'Portada']));

    $logo = shop_logo(['class' => 'af-logo-img mine']);
    contains('<img', $logo);
    contains('src="/media/2026/logo.png"', $logo);
    contains('alt="Tienda &lt;b&gt;"', $logo, 'el texto alternativo es el nombre, escapado');
    contains('class="af-logo-img mine"', $logo);
    notContains('srcset', $logo);

    $web = new TestClient($app);
    $r = $web->get('/favicon.ico');
    same(302, $r->status);
    same('/media/2026/logo-thumb.webp', (string) $r->headers['Location']);
});

test('icono elegido que ya no existe, o sin módulo de medios: vuelve el de serie', function (): void {
    $app = testApp(['settings' => ['store.icon' => 99, 'store.logo' => 99]]);
    $app->boot();
    check(strpos(Page::iconUrl($app), 'data:image/svg+xml,') === 0, 'sin módulo de medios');
    $app->bind('media', static function () {
        return iconTestMedia();
    });
    check(strpos(Page::iconUrl($app), 'data:image/svg+xml,') === 0, 'con una imagen borrada');
    same(null, Page::logo($app));
    same('AIFEC', shop_logo());
});

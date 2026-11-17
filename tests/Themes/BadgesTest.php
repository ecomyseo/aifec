<?php
/**
 * AIFEC — pruebas del hueco de insignias de la tarjeta (P092): todos los temas que sustituyen la tarjeta pintan el hueco «af-badges»
 * con las insignias que un plugin añade, cambia o quita (filtro product.badges), escapadas, sin botón de comprar si el producto no se
 * puede comprar y sin precio si un plugin dice que no se enseña.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once dirname(__DIR__, 2) . '/modules/catalog/tests/helpers.php';

/** Los temas del proyecto que traen su propia tarjeta (aifec la hereda de base). */
function badgesThemes(): array
{
    return ['aifec', 'atelier', 'base', 'despensa', 'gmartos', 'taller'];
}

/** Tienda de pruebas con ese tema activo, el catálogo y el carrito (para el botón de añadir de las tarjetas que lo llevan). */
function badgesApp(string $theme): App
{
    $app = testApp(['modules' => ['catalog', 'media', 'orders'], 'settings' => ['theme.active' => $theme]]);
    $app->boot();

    return $app;
}

/**
 * Engancha un filtro como lo haría un módulo del sistema.
 */
function badgesFilter(App $app, string $hook, callable $filter): void
{
    $hooks = $app->hooks();
    $hooks->setCurrentModule('catalog');
    $hooks->filter($hook, $filter);
    $hooks->setCurrentModule('core');
    $app->invalidate(['catalog']);
}

/**
 * La tarjeta de un producto pintada por la plantilla del tema activo.
 */
function badgesCard(App $app, int $id): string
{
    $card = $app->service('catalog')->card($id);
    check(is_array($card), 'la tarjeta existe');

    return $app->view()->render('partials/product-card', ['product' => $card]);
}

foreach (badgesThemes() as $badgesTheme) {
    test('tema ' . $badgesTheme . ': la tarjeta pinta en «af-badges» las insignias del catálogo y las que añade un plugin, escapadas', function () use ($badgesTheme): void {
        $app = badgesApp($badgesTheme);
        $id = aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'price' => 2000, 'sale_price' => 1500, 'stock' => 5]);
        badgesFilter($app, 'product.badges', static function (array $badges): array {
            $badges[] = ['code' => 'gm-soon', 'label' => 'Próximamente <script>alert(1)</script>', 'kind' => 'info'];
            $badges[] = ['code' => 'gm-ok', 'label' => 'Envío 24 h', 'kind' => 'ok'];

            return $badges;
        });
        $html = badgesCard($app, $id);
        contains('af-badges', $html);
        contains('Próximamente &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        notContains('<script', $html);
        contains('Envío 24 h', $html);
        contains('af-badge-sale', $html, 'la oferta del catálogo sigue ahí');
        contains('af-badge-new', $html, 'y la insignia «ok» pinta como «nuevo»');
        check(substr_count($html, 'class="af-badge') >= 3, 'las tres insignias, cada una en su sitio');
    });

    test('tema ' . $badgesTheme . ': un plugin que quita «Agotado» y pone «Próximamente» lo deja en la tarjeta', function () use ($badgesTheme): void {
        $app = badgesApp($badgesTheme);
        $id = aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'stock' => 0, 'created_at' => '2026-01-01 00:00:00']);
        $plain = badgesCard($app, $id);
        contains('af-badge-out', $plain);
        badgesFilter($app, 'product.badges', static function (array $badges): array {
            $out = [];
            foreach ($badges as $badge) {
                if ($badge['code'] !== 'out') {
                    $out[] = $badge;
                }
            }
            $out[] = ['code' => 'gm-soon', 'label' => 'Próximamente', 'kind' => 'info'];

            return $out;
        });
        $html = badgesCard($app, $id);
        contains('Próximamente', $html);
        notContains('af-badge-out', $html, 'ya no pone «Agotado»');
    });

    test('tema ' . $badgesTheme . ': una tarjeta sin insignias no pinta el hueco vacío', function () use ($badgesTheme): void {
        $app = badgesApp($badgesTheme);
        $id = aifecProduct($app, ['name' => 'Mesa', 'slug' => 'mesa', 'stock' => 20, 'created_at' => '2026-01-01 00:00:00']);
        notContains('af-badges', badgesCard($app, $id));
    });

    test('tema ' . $badgesTheme . ': un producto que un plugin deja como «no se puede comprar» no lleva botón, y sin precio no lo enseña', function () use ($badgesTheme): void {
        $app = badgesApp($badgesTheme);
        $soon = aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'price' => 12345, 'stock' => 20]);
        $normal = aifecProduct($app, ['name' => 'Mesa', 'slug' => 'mesa', 'price' => 54321, 'stock' => 20]);
        badgesFilter($app, 'product.availability', static function (array $a, array $context) use ($soon): array {
            if ($context['id'] === $soon) {
                $a['purchasable'] = false;
                $a['show_price'] = false;
                $a['label'] = 'Próximamente';
            }

            return $a;
        });
        $html = badgesCard($app, $soon);
        notContains('data-af-cart-add', $html);
        notContains('data-af-add', $html);
        notContains('123,45', $html);
        notContains('af-price-now', $html);
        $other = badgesCard($app, $normal);
        contains('543,21', $other);
        contains('af-price-now', $other);
        if ($badgesTheme === 'despensa' || $badgesTheme === 'gmartos' || $badgesTheme === 'taller') {
            contains('data-af-cart-add', $other, 'la tarjeta que sí se compra conserva su formulario');
        }
    });
}

test('una tarjeta hecha a mano con los códigos de siempre se pinta como siempre en todos los temas', function (): void {
    foreach (badgesThemes() as $theme) {
        $app = badgesApp($theme);
        $html = $app->view()->render('partials/product-card', ['product' => [
            'id' => 1, 'name' => 'Silla', 'url' => '/producto/silla', 'image' => null,
            'price' => ['amount' => 8000, 'compare' => 10000, 'discount_percent' => 20, 'currency' => 'EUR', 'with_tax' => true, 'from' => false],
            'badges' => ['sale', 'new', 'out'],
        ]]);
        contains('af-badge-sale', $html, $theme);
        contains('af-badge-new', $html, $theme);
        contains('af-badge-out', $html, $theme);
        contains('af-badges', $html, $theme);
    }
});

test('el bloque de precio oculto no pinta nada en ningún tema y el normal sigue igual', function (): void {
    foreach (badgesThemes() as $theme) {
        $app = badgesApp($theme);
        $hidden = trim($app->view()->render('partials/price', ['price' => ['amount' => 0, 'hidden' => true, 'currency' => 'EUR', 'with_tax' => true], 'size' => 'lg']));
        same('', $hidden, $theme);
        $shown = $app->view()->render('partials/price', ['price' => ['amount' => 1999, 'currency' => 'EUR', 'with_tax' => true], 'size' => 'lg']);
        contains('19,99', $shown, $theme);
    }
});

test('la ficha pinta las insignias de un plugin en todos los temas, sin la de oferta', function (): void {
    foreach (badgesThemes() as $theme) {
        $app = badgesApp($theme);
        $id = aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'price' => 2000, 'sale_price' => 1500, 'stock' => 5, 'created_at' => '2026-01-01 00:00:00']);
        badgesFilter($app, 'product.badges', static function (array $badges): array {
            $badges[] = ['code' => 'gm-ok', 'label' => 'Envío 24 h', 'kind' => 'ok'];

            return $badges;
        });
        $page = (new TestClient($app))->get('/producto/silla');
        same(200, $page->status, $theme);
        contains('af-product-badges', $page->body, $theme);
        contains('Envío 24 h', $page->body, $theme);
        unset($id);
    }
});

test('la ficha de un producto no comprable no lleva el formulario de compra en ningún tema', function (): void {
    foreach (badgesThemes() as $theme) {
        $app = badgesApp($theme);
        $soon = aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'price' => 12345, 'stock' => 20]);
        aifecProduct($app, ['name' => 'Mesa', 'slug' => 'mesa', 'price' => 54321, 'stock' => 20]);
        badgesFilter($app, 'product.availability', static function (array $a, array $context) use ($soon): array {
            if ($context['id'] === $soon) {
                $a['purchasable'] = false;
                $a['show_price'] = false;
            }

            return $a;
        });
        $web = new TestClient($app);
        $page = $web->get('/producto/silla')->body;
        // «También te puede interesar» puede traer la tarjeta de la mesa, con su compra: se mira la ficha de la silla.
        $own = strstr($page, 'id="af-related"', true);
        notContains('data-af-cart-add', $own !== false ? $own : $page, $theme);
        notContains('123,45', $page, $theme);
        $other = $web->get('/producto/mesa')->body;
        contains('data-af-cart-add', $other, $theme);
        contains('543,21', $other, $theme);
    }
});

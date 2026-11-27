<?php
/** Comparación conversacional con fichas públicas, con reglas y proveedor simulado. */
declare(strict_types=1);

use Aifec\Ai\FakeProvider;

require_once __DIR__ . '/helpers.php';

test('la comparación explica las diferencias de dos fichas, también con IA activada y sin proveedor disponible', function (): void {
    foreach ([false, true] as $ai) {
        $app = assistantApp(['ai.enabled' => $ai], ['modules' => ['ai']]);
        $fake = new FakeProvider();
        $app->bind('ai.provider.economy', static function () use ($fake) { return $fake; });
        $one = aifecProduct($app, ['name' => 'Caja Azul', 'price' => 1200, 'description' => 'Contiene doce piezas de madera.']);
        $two = aifecProduct($app, ['name' => 'Caja Verde', 'price' => 1500, 'description' => 'Incluye dos piezas de metal.']);
        $web = new TestClient($app);
        $r = assistantSay($web, '¿En qué se diferencian Caja Azul y Caja Verde?');
        same([$one, $two], array_column($r['cards'], 'id'));
        contains('doce piezas de madera', $r['text']);
        contains('dos piezas de metal', $r['text']);
        contains(money(300), $r['text']);
        $r = assistantSay($web, '¿En qué se diferencian?');
        same([$one, $two], array_column($r['cards'], 'id'));
    }
});
test('buscar por título completo en un catálogo mixto conserva la ficha exacta entre las opciones', function (): void {
    $app = assistantApp([], ['modules' => ['sites']]);
    $id = aifecProduct($app, ['name' => 'Modelo Lino', 'description' => 'Tejido de lino lavado.']);
    $pkg = \Aifec\Modules\Sites\Package::load(\Aifec\Autoload::root() . '/demos', 'moda', \Aifec\Autoload::root());
    (new \Aifec\Modules\Sites\CatalogLoader($app, $pkg, static function (): int { return 0; }))->load();
    searchService($app)->reindexNow();
    $r = assistantSay(new TestClient($app), 'Busco Modelo Lino');
    check(in_array($id, array_column($r['cards'], 'id'), true), json_encode([$r['text'],array_column($r['cards'],'name')],JSON_UNESCAPED_UNICODE));
});

test('comparar una ficha incompleta o una pareja ambigua conserva lo conocido y solicita los nombres que faltan', function (): void {
    $app = assistantApp();
    $one = aifecProduct($app, ['name' => 'Taza Lisa', 'price' => 800]);
    $two = aifecProduct($app, ['name' => 'Taza Alta', 'description' => 'Incluye tapa.']);
    aifecProduct($app, ['name' => 'Taza Lisa', 'visibility' => 'hidden', 'description' => 'Información privada.']);
    $web = new TestClient($app);
    $r = assistantSay($web, 'Compara Taza Lisa y Taza inexistente');
    same([], $r['cards']); contains('dos productos', $r['text']);
    $r = assistantSay($web, 'Taza Lisa y Taza Alta');
    same([$one, $two], array_column($r['cards'], 'id'));
    contains('Taza Lisa no incluye una descripción', $r['text']);
    contains('Incluye tapa', $r['text']);
    notContains('Información privada', $r['text']);
    aifecProduct($app, ['name' => 'Taza Lisa', 'stock' => 5]);
    $r = assistantSay($web, 'Compara Taza Lisa y Taza Alta');
    same([], $r['cards']); contains('dos productos', $r['text']);
});

test('un precio oculto figura bajo consulta en el texto y en ambas tarjetas, sin convertirlo en cero', function (): void {
    $app = assistantApp();
    aifecProduct($app, ['name' => 'Pieza Norte', 'price' => 1900]);
    aifecProduct($app, ['name' => 'Pieza Oeste', 'price' => 2500]);
    $hooks = $app->hooks();
    $hooks->setCurrentModule('catalog');
    $hooks->filter('product.availability', static function (array $value): array {
        $value['show_price'] = false;
        return $value;
    });
    $hooks->setCurrentModule('core');
    $app->invalidate(['catalog']);
    $r = assistantSay(new TestClient($app), 'Compara Pieza Norte y Pieza Oeste');
    same(2, count($r['cards']));
    foreach ($r['cards'] as $card) {
        same(t('Price on request'), $card['price']);
    }
    contains(t('Price on request'), $r['text']);
    notContains(money(0), $r['text']);
});

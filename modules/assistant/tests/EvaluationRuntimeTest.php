<?php
/** AIFEC — contrastes con el catálogo de origen y latencia con el proveedor nativo simulado. */
declare(strict_types=1);

use Aifec\Ai\FakeProvider;
use Aifec\Modules\Assistant\CatalogueEvaluation;
require_once __DIR__ . '/helpers.php';

test('la evaluación separada usa los precios y portes del origen y deja intactos sus pedidos y conversaciones', function (): void {
    $app = assistantApp();
    $app->boot();
    aifecProduct($app, ['name' => 'Lámpara de lectura', 'price' => 3950, 'stock' => 4]);
    $result = CatalogueEvaluation::measure($app);
    check($result['passed'], json_encode($result, JSON_UNESCAPED_UNICODE));
    same(4, $result['phrases']);
    same(3, $result['shipping_quotes']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {conversations}'));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
});

test('el primer dato de catálogo y el final cumplen los percentiles con el proveedor nativo simulado', function (): void {
    $app = assistantApp(['ai.enabled' => true], ['modules' => ['ai']]);
    assistantShop($app);
    $fake = new FakeProvider();
    $app->bind('ai.provider.economy', static function () use ($fake) { return $fake; });
    $service = $app->service('assistant');
    $first = $total = [];
    for ($i = 0; $i < 20; ++$i) {
        $fake->script(assistantTool('search_products', ['query' => 'silla roja']), assistantText('Estos artículos están en el catálogo.'));
        $id = $service->conversations()->create('es')['id'];
        $start = microtime(true);
        $factTime = null;
        $r = $service->reply($id, 'Busco una silla roja', null, '', static function ($fact) use (&$factTime, $start): void {
            if ($factTime === null) {
                contains('Precio de catálogo', $fact);
                $factTime = 1000 * (microtime(true) - $start);
            }
        });
        check($factTime !== null, 'el primer dato se emite antes de terminar');
        $first[] = $factTime;
        $total[] = 1000 * (microtime(true) - $start);
        check($r['text'] !== '');
    }
    sort($first); sort($total);
    echo 'FakeProvider P95: ' . round($first[18]) . ' ms hasta el primer dato, ' . round($total[18]) . ' ms hasta el final.' . PHP_EOL;
    check($first[18] < 1500 && $total[18] < 4000);
});

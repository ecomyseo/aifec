<?php
/** AIFEC — menciones literales con paginación y búsqueda híbrida por la herramienta compartida del asistente. */
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

test('una equivalencia editorial recupera todas las fichas que mencionan el ingrediente y excluye los parecidos', function (): void {
    $app = assistantApp(['search.sector' => 'beauty']);
    $ids = [];
    for ($i = 1; $i <= 7; ++$i) {
        $ids[] = aifecProduct($app, ['name' => 'Muestra ' . $i, 'facts' => [['label' => 'Ingredientes', 'value' => $i % 2 ? 'Astaxanthin' : 'Astaxantina']]]);
    }
    aifecProduct($app, ['name' => 'Muestra sin el ingrediente', 'description' => 'Contiene otro pigmento.']);
    $app->db()->insert('synonyms', ['lang' => 'es', 'mode' => 'oneway', 'sector' => 'beauty', 'source' => 'astaxatina', 'created_at' => $app->clock()->sql(), 'updated_at' => $app->clock()->sql(), 'terms' => 'astaxantina, astaxanthin']);
    $web = new TestClient($app);
    $r = assistantSay($web, '¿Qué productos llevan astaxatina?');
    contains('astaxantina', $r['text']);
    same(array_slice($ids, 0, 6), array_column($r['cards'], 'id'));
    $r = assistantSay($web, $r['suggestions'][0]);
    same(array_slice($ids, 6), array_column($r['cards'], 'id'));
    $other = assistantSay($web, '¿Qué productos llevan retinol?');
    same([], $other['cards']);
});

test('una errata corta solicita aclaración y el botón consulta el producto elegido', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    $search = searchService($app);
    check(in_array('silla', $search->spellingChoices('slla', 'es'), true));
    $r = assistantSay($web, 'Busco slla');
    contains('Querías decir', $r['text']);
    same([], $r['cards']);
    $r = assistantSay($web, $r['suggestions'][0]);
    check(in_array($ids['silla_roja'], array_column($r['cards'], 'id'), true));
});

test('el chat usa los vectores nativos cuando el proveedor simulado ya calculó su significado', function (): void {
    $app = assistantApp(['ai.enabled' => true, 'search.vectors' => true], ['modules' => ['ai','jobs']]);
    $app->boot();
    $provider = new \Aifec\Ai\FakeProvider();
    $app->bind('ai.provider.economy', static function () use ($provider) { return $provider; });
    $a = aifecProduct($app, ['name' => 'Banqueta alta']);
    $b = aifecProduct($app, ['name' => 'Banqueta baja']);
    $search = searchService($app);
    $search->reindexNow();
    $ask = static function () use ($app, $provider) {
        $provider->script(assistantTool('search_products', ['query' => 'banqueta']), assistantText('Estas son las banquetas disponibles.'));
        return array_column(assistantSay(new TestClient($app), 'Busco banqueta')['cards'], 'id');
    };
    same([$a,$b], $ask());
    $vector = $app->db()->value("SELECT vector FROM {search_docs} WHERE type = 'product' AND entity_id = ? AND lang = 'es'", [$b]);
    check(is_string($vector) && $vector !== '');
    $search->storeQueryVector('banqueta', 'es', \Aifec\Search\Vector::unpack(base64_decode($vector, true)));
    $search->forget();
    same([$b,$a], $ask());
});

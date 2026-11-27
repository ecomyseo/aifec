<?php
/** AIFEC — preferencia editorial sobre los resultados de búsqueda del comercio. */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('los destacados pertinentes se muestran primero con plazo y los artículos ajenos no se cuelan', function (): void {
    $app = assistantApp();
    $ordinary = aifecProduct($app, ['name' => 'Silla sencilla', 'price' => 1900]);
    $chosen = aifecProduct($app, ['name' => 'Silla de mimbre', 'price' => 3900, 'delivery_min_days' => 2, 'delivery_max_days' => 4]);
    $missing = aifecProduct($app, ['name' => 'Silla de cuero', 'stock' => 0]);
    $unrelated = aifecProduct($app, ['name' => 'Bufanda de lana']);
    $app->settings()->set('assistant.featured_ids', implode(',', [$unrelated, $missing, $chosen]));
    searchService($app)->reindexNow();
    $reply = assistantSay(new TestClient($app), 'Busco sillas');
    same($chosen, $reply['cards'][0]['id']);
    same(true, $reply['cards'][0]['featured']);
    contains('2', $reply['cards'][0]['delivery']);
    contains('4', $reply['cards'][0]['delivery']);
    check(!in_array($unrelated, array_column($reply['cards'], 'id'), true));
    $tools = new \Aifec\Modules\Assistant\ShopTools($app);
    $cheap = $tools->searchProducts(['query' => 'silla barata'], []);
    same($ordinary, $cheap['items'][0]['id'], 'la prioridad explícita de precio del cliente se respeta');
});

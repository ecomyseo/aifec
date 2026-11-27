<?php
/**
 * AIFEC — las ofertas salen del catálogo y los cupones públicos requieren autorización por tienda.
 * @author Ecom Experts
 * @license Ver LICENSE.md
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('Black Friday muestra la rebaja vigente y mantiene privados los códigos internos y ajenos', function (): void {
    $app = assistantApp(['assistant.public_coupons' => 'PUBLICO,AJENO,CADUCADO'], ['modules' => ['coupons', 'reviews']]);
    $app->boot();
    $id = aifecProduct($app, ['name' => 'Bolsa de muestra', 'price' => 4000, 'sale_price' => 3000, 'rating_sum' => 9, 'rating_count' => 2]);
    aifecProduct($app, ['name' => 'Bolsa sin rebaja', 'price' => 4000]);
    foreach (['PUBLICO' => [], 'INTERNO' => [], 'AJENO' => ['customer_id' => 9999], 'CADUCADO' => ['ends_at' => '2001-01-01 00:00:00']] as $code => $extra) {
        $app->db()->insert('coupons', $extra + ['code' => $code, 'percent' => 1000, 'created_at' => $app->clock()->sql(), 'updated_at' => $app->clock()->sql()]);
    }
    $web = new TestClient($app);
    foreach (['¿Qué ofertas hay en Black Friday?', '¿Hay algún código?'] as $question) {
        $reply = assistantSay($web, $question);
        same([$id], array_column($reply['cards'], 'id'));
        same(25, $reply['cards'][0]['discount_percent']);
        same(4.5, $reply['cards'][0]['rating']['average']);
        contains('PUBLICO', $reply['text']);
        foreach (['INTERNO', 'AJENO', 'CADUCADO'] as $private) {
            notContains($private, json_encode($reply));
        }
    }
});

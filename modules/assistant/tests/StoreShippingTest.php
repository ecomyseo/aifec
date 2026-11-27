<?php
/**
 * AIFEC — store_info cuenta lo que cuesta enviar con los transportistas de verdad y desde qué importe sale gratis (14/11/2026: el
 * asistente decía «no se especifican los costes de envío» con «Envío desde 4,90 €» en el carrito).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('store_info de envíos: los transportistas con su precio desde, y el envío gratis si lo hay', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $app->db()->run('UPDATE {carriers} SET free_from = 6000 WHERE active = 1');
    $r = $app->service('tools')->call('store_info', ['topic' => 'shipping']);
    check(isset($r['shipping']['options'][0]['from_cents']) && $r['shipping']['options'][0]['from_cents'] > 0, 'con su precio: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    same(6000, $r['shipping']['free_from_cents'], 'gratis desde 60 €');
    contains('60,00', (string) $r['shipping']['free_from']);
    check(isset($app->service('tools')->call('store_info', ['topic' => 'all'])['shipping']), 'y también en la ficha entera de la tienda');
});

test('el destino expresado conserva la zona y evita cotizar Canarias con un código peninsular', function (): void {
    foreach ([false, true] as $enabled) {
        $app = assistantApp();
        assistantShop($app);
        $fake = assistantAi($app);
        $fake->on = $enabled;
        $web = new TestClient($app);
        $reply = assistantSay($web, 'Vivo en Canarias. ¿Enviáis aquí?');
        contains('código postal', $reply['text']);
        contains('canarias', strtolower($reply['text']));
        contains('código postal', assistantSay($web, '28001')['text']);
        $valid = assistantSay($web, '35001');
        notContains('¿Cuál es el código postal', $valid['text']);
        same([], $fake->asked);
    }
    $uk = \Aifec\Modules\Assistant\QuoteDestination::named('do you ship to the uk', false);
    same('GB', $uk['country']);
    same('SW1A 1AA', \Aifec\Modules\Assistant\QuoteDestination::postcode('SW1A 1AA', $uk));
});

test('el tiempo para hacer efecto no inicia una cotización de transporte', function (): void {
    foreach ([false, true] as $enabled) {
        $app = assistantApp();
        $fake = assistantAi($app);
        $fake->on = $enabled;
        $answer = assistantSay(new TestClient($app), '¿Cuánto tarda en hacer efecto?');
        contains('indicaciones de uso', $answer['text']);
        notContains('postal', $answer['text']);
        same([], $answer['cards']);
        same([], $fake->asked);
    }
});

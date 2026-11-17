<?php
/**
 * AIFEC — «¿puedo pagar al recibirlo?» es una pregunta por las formas de pago (ronda 3, 14/11/2026: el asistente contestaba con la
 * recomendación del producto anterior).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('sin IA, pagar al recibir, contra reembolso o en la entrega se contestan con las formas de pago', function (): void {
    $app = assistantApp();
    assistantShop($app);
    foreach (['puedo pagar al recibirlo', '¿Se puede pagar cuando llegue?', 'pago a la entrega?', 'can I pay on delivery?'] as $question) {
        $web = new TestClient($app);
        $r = assistantSay($web, $question);
        check(strpos((string) $r['text'], 'Puedes pagar con') !== false || strpos((string) $r['text'], 'You can pay with') !== false, $question . ' → ' . $r['text']);
    }
});

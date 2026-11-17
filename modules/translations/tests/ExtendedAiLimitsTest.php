<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Translations\Fill;

require_once __DIR__ . '/TranslationsKit.php';

/** @return array<string,array{area:string,origin:string}> */
function trxExtendedLimitStrings(): array
{
    $strings = [];
    for ($index = 1; $index <= 90; ++$index) {
        $strings[sprintf('Shop text %03d', $index)] = ['area' => 'shop', 'origin' => 'core'];
    }

    return $strings;
}

test('el tope mensual pausa la traducción con su motivo real y no consume la tanda pendiente', function (): void {
    [$app, $provider] = trxAiApp(['ai.monthly_limit_cents' => 1]);
    trxBindStrings($app, trxExtendedLimitStrings());
    $fill = new Fill($app);
    $id = $fill->start('fr', 'shop');
    $first = $fill->runChunk($id);
    $usage = (int) $app->service('ai')->lastUsageId();
    $app->db()->update('ai_usage', ['cost_micro' => 1000000], ['id' => $usage]);
    $last = $fill->runChunk($id);
    same('paused', $last['status']);
    same(Fill::MONTHLY, $last['reason']);
    same($first['cursor'], $last['cursor']);
    same(1, count($provider->requests));
    same('Se ha llegado al tope mensual de la IA', Fill::reason((string) $last['reason']));
});

test('el tope de fichas pausa la traducción sin confundirlo con el gasto o las llamadas', function (): void {
    [$app, $provider] = trxAiApp(['ai.daily_limit_tokens' => 1]);
    trxBindStrings($app, trxExtendedLimitStrings());
    $fill = new Fill($app);
    $id = $fill->start('fr', 'shop');
    $first = $fill->runChunk($id);
    $last = $fill->runChunk($id);
    same('paused', $last['status']);
    same(Fill::TOKENS, $last['reason']);
    same($first['cursor'], $last['cursor']);
    same(1, count($provider->requests));
    same('Se ha llegado al tope diario de fichas de la IA', Fill::reason((string) $last['reason']));
});

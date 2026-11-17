<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Ai\AiResult;
use Aifec\Modules\Ai\AiLimitReached;
use Aifec\Modules\Ai\Usage;

require_once __DIR__ . '/helpers.php';

test('el tope mensual usa el mes natural UTC y no se reinicia al cambiar el día', function (): void {
    $app = aiApp(['ai.monthly_limit_cents' => 1, 'ai.economy.price_in' => 100]);
    $app->clock()->set(strtotime('2026-11-14 23:59:00 UTC'));
    $ai = $app->service('ai');
    aiFake($app)->script(new AiResult('noviembre', [], null, 10000, 0), new AiResult('diciembre', [], null, 10000, 0));
    same('noviembre', $ai->ask('test.month', ['prompt' => 'a'])->text);
    same(Usage::MICRO, Usage::spentMonthMicro($app));
    same('Monthly AI limit reached', Usage::limitReason($app));
    throws(static function () use ($ai): void { $ai->ask('test.month', ['prompt' => 'b']); }, AiLimitReached::class);
    $app->clock()->advance(120);
    same(0, Usage::spentMicro($app));
    check($ai->limitReached(), 'el cambio de día no abre otro presupuesto mensual');
    same(1, count(aiFake($app)->requests), 'no se llama al proveedor cuando el presupuesto está agotado');
    $app->clock()->set(strtotime('2026-12-01 00:00:00 UTC'));
    same(0, Usage::spentMonthMicro($app));
    check(!$ai->limitReached());
    same('diciembre', $ai->ask('test.month', ['prompt' => 'b'])->text);
});

test('el cálculo mensual incluye febrero bisiesto y excluye los meses contiguos', function (): void {
    $app = aiApp(['ai.monthly_limit_cents' => 1]);
    $app->clock()->set(strtotime('2028-02-29 23:59:00 UTC'));
    same(['2028-02-01', '2028-03-01'], Usage::monthBounds($app));
    foreach (['2028-01-31', '2028-02-01', '2028-02-29', '2028-03-01'] as $day) {
        $app->db()->insert('ai_usage', ['day' => $day, 'task' => 'test.month', 'tier' => 'economy', 'provider' => 'fake', 'model' => 'fake', 'cost_micro' => 10, 'created_at' => $day . ' 00:00:00']);
    }
    same(20, Usage::spentMonthMicro($app));
});

test('el tope de fichas suma entrada y salida, respeta la caché local y se reinicia en UTC', function (): void {
    $app = aiApp(['ai.daily_limit_tokens' => 15, 'ai.economy.price_in' => 0, 'ai.economy.price_out' => 0]);
    $ai = $app->service('ai');
    aiFake($app)->script(new AiResult('primera', [], null, 10, 5), new AiResult('mañana', [], null, 2, 1));
    $request = ['prompt' => 'a', 'cache_ttl' => 300];
    same('primera', $ai->ask('test.tokens', $request)->text);
    same(15, Usage::tokens($app));
    same('Daily AI token limit reached', Usage::limitReason($app));
    same('primera', $ai->ask('test.tokens', $request)->text, 'la caché no gasta fichas');
    throws(static function () use ($ai): void { $ai->ask('test.tokens', ['prompt' => 'b']); }, AiLimitReached::class);
    throws(static function () use ($ai): void { $ai->embed(['texto']); }, AiLimitReached::class);
    same(1, count(aiFake($app)->requests));
    $app->clock()->advance(86400);
    check(!$ai->limitReached());
    same('mañana', $ai->ask('test.tokens', ['prompt' => 'b'])->text);
    same(3, Usage::tokens($app));
});

test('los nuevos topes opcionales a cero conservan los topes diarios existentes', function (): void {
    $app = aiApp(['ai.monthly_limit_cents' => 0, 'ai.daily_limit_tokens' => 0, 'ai.daily_limit_calls' => 1]);
    $ai = $app->service('ai');
    aiFake($app)->script(new AiResult('hecho', [], null, 3000, 2000));
    $ai->ask('test.defaults', ['prompt' => 'a']);
    same('Daily AI limit reached', Usage::limitReason($app));
    same(0, Usage::limitMonthMicro($app));
    same(0, Usage::limitTokens($app));
    throws(static function () use ($ai): void { $ai->ask('test.defaults', ['prompt' => 'b']); }, AiLimitReached::class);
});

test('Uso y ajustes enseñan los topes mensuales y las fichas de hoy en el panel', function (): void {
    $app = aiApp(['ai.monthly_limit_cents' => 500, 'ai.daily_limit_tokens' => 2000]);
    $web = adminClient($app);
    $settings = $web->get('/admin/settings/ai');
    contains('Tope mensual de gasto', $settings->body);
    contains('Tope diario de fichas', $settings->body);
    $usage = $web->get('/admin/ai/usage');
    same(200, $usage->status);
    contains('Mes natural (UTC)', $usage->body);
    contains('Fichas de hoy', $usage->body);
    contains('2000', $usage->body);
});

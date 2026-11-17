<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Ai\AiException;
use Aifec\Ai\AiResult;

require_once __DIR__ . '/helpers.php';

test('la prueba de conexión exige permiso y CSRF, y no conecta al abrir la pantalla', function (): void {
    $app = aiApp(['ai.economy.provider' => 'fake']);
    $web = adminClient($app);
    $page = $web->get('/admin/ai/connection');
    same(200, $page->status);
    contains('Probar la conexión guardada', $page->body);
    contains('name="_token"', $page->body);
    same(0, count(aiFake($app)->requests));
    same(419, $web->postJson('/admin/ai/connection', ['tier' => 'economy'])->status, 'sin ficha del panel la petición JSON se rechaza; el formulario HTML redirige con el aviso');
    same(0, count(aiFake($app)->requests));
    same(403, adminClient(aiApp(), 'support')->get('/admin/ai/connection')->status);
});

test('probar la conexión usa el nivel elegido, registra su coste y distingue el simulador', function (): void {
    $app = aiApp(['ai.standard.provider' => 'fake']);
    aiFake($app, 'standard')->script(new AiResult('AIFEC', [], null, 20, 2));
    $web = adminClient($app);
    $web->get('/admin/ai/connection');
    same(302, $web->post('/admin/ai/connection', ['_token' => aifecToken($web), 'tier' => 'standard'])->status);
    $result = $web->follow();
    contains('El proveedor simulado ha respondido. No se ha probado ninguna conexión externa.', $result->body);
    same(0, count(aiFake($app)->requests));
    same(1, count(aiFake($app, 'standard')->requests));
    $usage = aiRows($app, 'ai_usage');
    same('connection.test', $usage[0]['task']);
    same('standard', $usage[0]['tier']);
    same(20, (int) $usage[0]['tokens_in']);
    same(256, aiFake($app, 'standard')->requests[0]->maxOutputTokens);
    same([], aiRows($app, 'ai_proposals'), 'la prueba no redacta contenidos');
});

test('la prueba respeta los topes y no permite un nivel inventado ni filtra errores del proveedor', function (): void {
    $app = aiApp(['ai.daily_limit_calls' => 0]);
    $web = adminClient($app);
    $web->get('/admin/ai/connection');
    $web->post('/admin/ai/connection', ['_token' => aifecToken($web), 'tier' => 'economy']);
    contains('Se alcanzó el tope diario de la IA', $web->follow()->body);
    same(0, count(aiFake($app)->requests));
    $app->settings()->set('ai.daily_limit_calls', 500);
    $web->post('/admin/ai/connection', ['_token' => aifecToken($web), 'tier' => '../bad']);
    contains('Elige un nivel de IA.', $web->follow()->body);
    $failed = aiApp();
    $failed->bind('ai.provider.economy', static function (): AiFailingProvider { return new AiFailingProvider('clave-de-mentira-en-un-error'); });
    $failedWeb = adminClient($failed);
    $failedWeb->get('/admin/ai/connection');
    $failedWeb->post('/admin/ai/connection', ['_token' => aifecToken($failedWeb), 'tier' => 'economy']);
    $body = $failedWeb->follow()->body;
    contains('No se ha podido confirmar la conexión.', $body);
    notContains('clave-de-mentira-en-un-error', $body);
});

test('un endpoint personalizado de una demo nunca recibe una clave heredada', function (): void {
    $app = testApp(['modules' => ['ai', 'catalog', 'media'], 'config' => ['demo' => true], 'settings' => ['ai.enabled' => true, 'ai.economy.provider' => 'openai', 'ai.economy.base_url' => 'https://otro.example.com', 'ai.economy.model' => 'modelo-de-prueba']]);
    $app->boot();
    try {
        $app->service('ai')->provider('economy');
        check(false, 'el proveedor de destino arbitrario debe bloquearse antes de crearse');
    } catch (AiException $e) {
        same('Custom AI endpoints are unavailable in public demos', $e->getMessage());
    }
});

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Assistant\AiBrain;
use Aifec\Modules\Assistant\Tools;

require_once __DIR__ . '/helpers.php';

function assistantMonthlyFixture(): array
{
    $app = assistantApp(['subscriptions.enabled' => true, 'subscriptions.provider' => 'simulated',
        'modules.active' => ['catalog', 'customers', 'tax', 'shipping', 'coupons', 'orders', 'mail', 'media', 'jobs', 'search', 'assistant', 'subscriptions']], ['modules' => ['subscriptions']]);
    $plan = $app->db()->insert('subscription_plans', ['name' => 'Asistencia mensual de ejemplo', 'description' => 'Ayuda periódica de prueba.', 'amount' => 1000, 'active' => 1,
        'created_at' => $app->clock()->sql(), 'updated_at' => $app->clock()->sql()]);
    $app->db()->insert('subscription_plans', ['name' => 'Plan retirado', 'description' => '', 'amount' => 2000, 'active' => 0,
        'created_at' => $app->clock()->sql(), 'updated_at' => $app->clock()->sql()]);

    return [$app, $plan];
}

test('la consulta de planes del asistente solo aparece con suscripciones disponibles', function (): void {
    $without = assistantApp();
    check(!isset((new Tools($without))->all()['list_subscription_plans']));
    [$app] = assistantMonthlyFixture();
    $tools = new Tools($app);
    check(isset($tools->all()['list_subscription_plans']));
    check(!$tools->writes('list_subscription_plans'));
    $app->settings()->set('subscriptions.enabled', false);
    check(!isset($tools->all()['list_subscription_plans']));
});

test('los planes usan precios de su servicio y enlaces de confirmación sin aceptar ni cobrar una cuota', function (): void {
    [$app, $id] = assistantMonthlyFixture();
    $tools = new Tools($app);
    $result = $tools->call('list_subscription_plans', []);
    same(1, count($result['plans']), 'no se muestran planes retirados');
    same(1000, $result['plans'][0]['base_price_cents']);
    contains('antes de ajustar los impuestos', $result['plans'][0]['price_note']);
    same(true, $result['requires_human_confirmation']);
    same('MONTH', $result['plans'][0]['interval']);
    $one = $tools->call('list_subscription_plans', ['plan_id' => $id]);
    contains('/mi-cuenta/suscripciones/plan/' . $id, $one['url']);
    same($one['plans'][0]['confirmation_url'], $one['url']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {subscriptions}'));
    check(isset($tools->call('list_subscription_plans', ['plan_id' => $id, 'amount' => 1])['error']), 'el modelo no puede introducir un importe');
    check(isset($tools->call('list_subscription_plans', ['plan_id' => 999999])['error']));
});

test('el asistente sin IA explica los planes y ofrece el enlace para la confirmación humana', function (): void {
    [$app] = assistantMonthlyFixture();
    $web = new TestClient($app);
    $result = assistantSay($web, '¿Qué servicios mensuales tenéis?');
    contains('Asistencia mensual de ejemplo', $result['text']);
    contains('antes de ajustar los impuestos', $result['text']);
    contains('autoriza el pago recurrente en tu cuenta', $result['text']);
    $links = array_values(array_filter($result['actions'], static function (array $action): bool { return $action['type'] === 'subscriptions'; }));
    same(1, count($links));
    contains('/suscripciones/planes', $links[0]['url']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {subscriptions}'));
});

test('el modelo recibe la consulta de planes y la prohibición de aceptar pagos recurrentes por el cliente', function (): void {
    [$app] = assistantMonthlyFixture();
    $system = (new AiBrain($app, new Tools($app)))->system('es');
    contains('call list_subscription_plans', $system);
    contains('never present them as the final monthly charge', $system);
    contains('You cannot subscribe, renew, cancel or charge on their behalf', $system);
    $fake = assistantAi($app);
    $fake->script(assistantTool('list_subscription_plans', []), assistantText('Puedes revisar los planes mensuales y confirmar el pago en tu cuenta.'));
    $result = assistantSay(new TestClient($app), 'Muéstrame los servicios mensuales');
    check(array_filter($result['actions'], static function (array $action): bool { return $action['type'] === 'subscriptions'; }) !== []);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {subscriptions}'));
});

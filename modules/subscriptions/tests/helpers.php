<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpRequest;
use Aifec\Http\HttpResponse;
use Aifec\Kernel\App;

require_once __DIR__ . '/../../orders/tests/FrontKit.php';

function subsFixture(bool $paypal = false): array
{
    $app = aifecOrderApp(['modules' => ['subscriptions'], 'settings' => ['modules.active' => ['orders', 'mail', 'media', 'jobs', 'subscriptions'], 'subscriptions.enabled' => true, 'subscriptions.provider' => $paypal ? 'paypal' : 'simulated']]);
    $customer = aifecCartCustomer($app);
    $address = $app->service('customers')->saveAddress($customer, ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Mayor 1', 'postcode' => '28001', 'city' => 'Madrid', 'country' => 'ES', 'phone' => '999000001']);
    $plan = $app->db()->insert('subscription_plans', ['name' => 'Servicio mensual de prueba', 'description' => 'Atención mensual de ejemplo.', 'amount' => 1210, 'active' => 1, 'created_at' => $app->clock()->sql(), 'updated_at' => $app->clock()->sql()]);
    $state = new stdClass();
    $state->status = 'ACTIVE';
    $state->identity = '';
    $state->verify = true;
    $state->createCalls = 0;
    $state->unsafeUrl = false;
    $state->billing = [];
    if ($paypal) {
        $app->settings()->setMany(['subscriptions.paypal.client_id_sandbox' => 'subscription-client-example', 'subscriptions.paypal.webhook_id_sandbox' => 'webhook-subscriptions-example']);
        $app->setSecret('subscriptions.paypal.secret_sandbox', 'example-secret-for-tests');
        $http = $app->http();
        check($http instanceof FakeHttpClient, 'el conector usa el cliente de pruebas');
        $http->when('POST', '/v1/oauth2/token', static function (): HttpResponse { return subsJson(200, ['access_token' => 'test-subscription-token', 'expires_in' => 3600]); });
        $http->when('POST', '/v1/catalogs/products', static function () use ($state): HttpResponse { ++$state->createCalls; return subsJson(201, ['id' => 'PROD-EXAMPLE123']); });
        $http->when('POST', '/v1/billing/plans', static function (): HttpResponse { return subsJson(201, ['id' => 'P-EXAMPLE123']); });
        $http->when('POST', '/v1/billing/subscriptions', static function (HttpRequest $request) use ($state): HttpResponse {
            if (substr($request->url, -7) === '/cancel' || substr($request->url, -8) === '/suspend' || substr($request->url, -9) === '/activate') {
                return new HttpResponse(204);
            }
            $body = json_decode((string) $request->body, true);
            $state->identity = $body['custom_id'];
            return subsJson(201, ['id' => 'I-EXAMPLE123', 'links' => [['rel' => 'approve', 'href' => $state->unsafeUrl ? 'https://another.example.test/approve' : 'https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=example']]]);
        });
        $http->when('GET', '/v1/billing/subscriptions/', static function () use ($state): HttpResponse {
            return subsJson(200, ['id' => 'I-EXAMPLE123', 'custom_id' => $state->identity, 'plan_id' => 'P-EXAMPLE123', 'status' => $state->status, 'billing_info' => $state->billing + ['next_billing_time' => '2026-12-14T12:00:00Z']]);
        });
        $http->when('POST', '/v1/notifications/verify-webhook-signature', static function () use ($state): HttpResponse { return subsJson(200, ['verification_status' => $state->verify ? 'SUCCESS' : 'FAILURE']); });
    }
    return [$app, $plan, $customer, $address, $state];
}

function subsJson(int $status, array $data): HttpResponse
{
    return new HttpResponse($status, ['Content-Type' => 'application/json'], (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function subsStart(array $fixture, string $nonce = '11111111111111111111111111111111'): array
{
    [$app, $plan, $customer, $address] = $fixture;
    $service = $app->service('subscriptions');
    $quote = $service->quote($plan, $customer, $address);
    return $service->start($plan, $customer, $address, true, $quote['amount'], $nonce);
}

function subsHeaders(): array
{
    return ['paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/example', 'paypal-transmission-id' => 'transmission-example', 'paypal-transmission-sig' => 'signature-example', 'paypal-transmission-time' => '2026-11-14T12:00:00Z'];
}

function subsSale(App $app, string $id = 'SALE-EXAMPLE1', string $event = 'WH-EXAMPLE1'): array
{
    return ['id' => $event, 'event_type' => 'PAYMENT.SALE.COMPLETED', 'resource' => ['id' => $id, 'billing_agreement_id' => 'I-EXAMPLE123', 'state' => 'completed', 'amount' => ['total' => '12.10', 'currency' => 'EUR'], 'create_time' => str_replace(' ', 'T', $app->clock()->sql()) . 'Z']];
}

function subsNotify(App $app, array $event): bool
{
    return $app->service('subscriptions')->notify('sandbox', subsHeaders(), (string) json_encode($event));
}

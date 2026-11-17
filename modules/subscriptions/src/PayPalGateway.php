<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Subscriptions;

use Aifec\Http\HttpRequest;
use Aifec\Kernel\App;
use Aifec\Payments\PayPal\PayPalClient;
use Aifec\Payments\PayPal\PayPalConfig;

/** Conector de Subscriptions v1; OAuth y firma comparten la implementación del núcleo. */
final class PayPalGateway
{
    private App $app;
    private PayPalConfig $config;
    private PayPalClient $client;

    public function __construct(App $app, string $environment)
    {
        if (!in_array($environment, ['sandbox', 'live'], true) || ($app->site()->isDemo() && $environment === 'live')) {
            throw new \InvalidArgumentException('PayPal environment is not allowed');
        }
        $prefix = 'subscriptions.paypal.';
        $id = trim($app->settings()->str($prefix . 'client_id_' . $environment));
        $secret = $app->secret($prefix . 'secret_' . $environment);
        if ($id === '' || $secret === '') {
            throw new \InvalidArgumentException('PayPal subscription credentials are missing');
        }
        $this->app = $app;
        $this->config = new PayPalConfig($id, $secret, $environment, $app->settings()->str($prefix . 'webhook_id_' . $environment));
        $this->client = new PayPalClient($this->config, $app->http());
    }

    /** @return array<string,mixed> */
    public function create(array $subscription, array $snapshot): array
    {
        $key = (string) $subscription['public_id'];
        // Cada alta conserva su precio; editar el plan de la tienda no cambia un mandato existente.
        $product = $this->api('POST', '/v1/catalogs/products', ['name' => mb_substr((string) $snapshot['name'], 0, 127), 'type' => 'SERVICE'], $key . '-product');
        $productId = self::identifier($product, 'id', 'PROD-');
        $plan = $this->api('POST', '/v1/billing/plans', [
            'product_id' => $productId, 'name' => mb_substr((string) $snapshot['name'], 0, 127), 'status' => 'ACTIVE',
            'billing_cycles' => [['frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1], 'tenure_type' => 'REGULAR', 'sequence' => 1, 'total_cycles' => 0,
                'pricing_scheme' => ['fixed_price' => ['value' => PayPalClient::formatAmount((int) $snapshot['amount'], (string) $snapshot['currency']), 'currency_code' => $snapshot['currency']]]]],
            'payment_preferences' => ['auto_bill_outstanding' => false, 'payment_failure_threshold' => 2],
        ], $key . '-plan');
        $planId = self::identifier($plan, 'id', 'P-');
        $base = $this->app->url()->origin();
        $sub = $this->api('POST', '/v1/billing/subscriptions', [
            'plan_id' => $planId, 'custom_id' => $key,
            'subscriber' => ['email_address' => $snapshot['email']],
            'application_context' => ['brand_name' => mb_substr($this->app->settings()->str('store.name', 'AIFEC'), 0, 127), 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'SUBSCRIBE_NOW',
                'return_url' => $base . $this->app->url()->route('subscriptions.back', ['id' => $key]),
                'cancel_url' => $base . $this->app->url()->route('subscriptions.account')],
        ], $key . '-subscription');
        $providerId = self::identifier($sub, 'id', 'I-');
        $approval = '';
        foreach (isset($sub['links']) && is_array($sub['links']) ? $sub['links'] : [] as $link) {
            if (is_array($link) && ($link['rel'] ?? '') === 'approve') {
                $approval = isset($link['href']) && is_string($link['href']) ? $link['href'] : '';
            }
        }
        $host = (string) parse_url($approval, PHP_URL_HOST);
        $allowed = $this->config->environment() === 'live' ? ['www.paypal.com', 'paypal.com'] : ['www.sandbox.paypal.com', 'sandbox.paypal.com'];
        if (parse_url($approval, PHP_URL_SCHEME) !== 'https' || !in_array($host, $allowed, true)) {
            throw new \UnexpectedValueException('PayPal returned no safe approval URL');
        }

        return ['id' => $providerId, 'plan_id' => $planId, 'approval_url' => $approval];
    }

    public function details(string $id): array
    {
        if (preg_match('/^I-[A-Z0-9]{1,78}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid PayPal subscription ID');
        }

        return $this->api('GET', '/v1/billing/subscriptions/' . rawurlencode($id) . '?fields=last_failed_payment');
    }

    public function change(string $id, string $action, string $key): void
    {
        if (!in_array($action, ['cancel', 'suspend', 'activate'], true) || preg_match('/^I-[A-Z0-9]{1,78}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid subscription action');
        }
        $this->api('POST', '/v1/billing/subscriptions/' . rawurlencode($id) . '/' . $action, ['reason' => 'Requested in the customer account or shop administration'], $key);
    }

    public function verify(array $headers, string $body): bool
    {
        return $this->client->verifyWebhook($headers, $body, $this->app->clock()->now());
    }

    private function api(string $method, string $path, ?array $body = null, string $key = ''): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->client->accessToken($this->app->clock()->now()), 'Accept' => 'application/json', 'Prefer' => 'return=representation'];
        if ($key !== '') {
            $headers['PayPal-Request-Id'] = substr(hash('sha256', $this->app->site()->id . ':' . $key), 0, 38);
        }
        $request = $body === null ? new HttpRequest($method, $this->config->baseUrl() . $path, $headers)
            : HttpRequest::postJson($this->config->baseUrl() . $path, $body, $headers);
        $response = $this->app->http()->send($request);
        if ($response->status < 200 || $response->status >= 300) {
            throw new \RuntimeException('PayPal Subscriptions returned HTTP ' . $response->status);
        }
        if ($response->status === 204) {
            return [];
        }
        $json = $response->json();
        if (!is_array($json)) {
            throw new \UnexpectedValueException('PayPal Subscriptions returned invalid JSON');
        }

        return $json;
    }

    private static function identifier(array $row, string $key, string $prefix): string
    {
        $id = isset($row[$key]) && is_string($row[$key]) ? $row[$key] : '';
        if (strpos($id, $prefix) !== 0 || preg_match('/^[A-Z0-9-]{1,80}$/', $id) !== 1) {
            throw new \UnexpectedValueException('PayPal returned an invalid resource ID');
        }

        return $id;
    }
}

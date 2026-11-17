<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

return ['handlers' => [], 'cron' => [
    'subscriptions.simulated' => ['every' => 60, 'description' => 'Records due simulated monthly payments in demos and debugging mode only', 'run' => static function (App $app): ?string {
        $service = $app->service('subscriptions');
        if (!$service->simulationAllowed() || !$app->settings()->bool('subscriptions.enabled', false)) {
            return null;
        }
        // Solo mandatos creados expresamente y cuotas vencidas en las últimas 24 horas; nunca cobra a un proveedor real.
        $rows = $app->db()->column("SELECT id FROM {subscriptions} WHERE provider = 'simulated' AND status = 'active' AND next_payment_at <= ? AND next_payment_at >= ? ORDER BY id LIMIT 20", [$app->clock()->sql(), $app->clock()->sql($app->clock()->now() - 86400)]);
        foreach ($rows as $id) {
            $service->simulate((int) $id, 'paid');
        }
        return $rows !== [] ? 'Recorded ' . count($rows) . ' simulated monthly payments' : null;
    }],
]];

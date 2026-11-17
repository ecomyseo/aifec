<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Assistant;

use Aifec\Kernel\App;

/** Consulta pública de los planes: el chat nunca autoriza ni da de alta una cuota. */
final class SubscriptionTools
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function definitions(): array
    {
        if (!$this->app->has('subscriptions') || !$this->app->service('subscriptions')->available()) {
            return [];
        }

        return ['list_subscription_plans' => [
            'description' => 'List active monthly service plans, or read one plan_id from that list. Prices are before billing-address tax adjustments, never the final monthly total. The confirmation_url lets the person review the exact monthly total and explicitly authorise recurring payments in their account. This tool never creates a subscription, accepts terms or charges anything.',
            'schema' => ['type' => 'object', 'properties' => ['plan_id' => ['type' => 'integer', 'minimum' => 1]], 'additionalProperties' => false],
            'handler' => [$this, 'plans'],
            'writes' => false,
        ]];
    }

    public function plans(array $input, array $context = []): array
    {
        if (!$this->app->has('subscriptions') || !$this->app->service('subscriptions')->available()) {
            return ['error' => t('Monthly plans are not available right now.')];
        }
        $id = isset($input['plan_id']) ? (int) $input['plan_id'] : 0;
        $currency = $this->app->settings()->str('shop.currency', 'EUR');
        $plans = [];
        foreach ($this->app->service('subscriptions')->plans() as $plan) {
            if ($id > 0 && (int) $plan['id'] !== $id) {
                continue;
            }
            $plans[] = ['id' => (int) $plan['id'], 'name' => (string) $plan['name'],
                'description' => (string) $plan['description'], 'interval' => 'MONTH', 'interval_count' => 1,
                'base_price_cents' => (int) $plan['amount'], 'base_price_formatted' => $this->app->i18n()->money((int) $plan['amount'], $currency), 'currency' => $currency,
                'price_note' => t('Per month, before billing-address tax adjustments. The exact total is shown before you authorise recurring payments.'),
                'confirmation_url' => $this->app->url()->origin() . $this->app->url()->route('subscriptions.checkout', ['id' => (int) $plan['id']])];
        }
        if ($id > 0 && $plans === []) {
            return ['error' => t('That monthly plan is not available.')];
        }

        return ['plans' => $plans, 'requires_human_confirmation' => true,
            'url' => $id > 0 ? $plans[0]['confirmation_url'] : $this->app->url()->origin() . $this->app->url()->route('subscriptions.plans'),
            'note' => t('Review the monthly amount and authorise the recurring payment in your account. The assistant cannot subscribe or charge you.')];
    }
}

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

final class Labels
{
    public static function status(string $code): string
    {
        $labels = ['setup_failed' => t('Subscription setup not completed'), 'starting' => t('Subscription payment is being prepared'), 'pending' => t('Subscription awaiting approval'),
            'active' => t('Active subscription'), 'past_due' => t('Subscription payment overdue'), 'suspended' => t('Suspended subscription'), 'cancelled' => t('Cancelled subscription'), 'expired' => t('Expired subscription')];
        return $labels[$code] ?? t('Unknown subscription status');
    }

    /** Distingue las pruebas de un mandato real sin mostrar códigos internos. */
    public static function paymentMode(string $provider, string $environment): string
    {
        if ($provider === 'simulated' && $environment === 'simulated') {
            return t('Simulated subscription (no real money)');
        }
        if ($provider === 'paypal' && $environment === 'sandbox') {
            return t('PayPal subscriptions in sandbox (no real money)');
        }
        if ($provider === 'paypal' && $environment === 'live') {
            return t('PayPal subscriptions in production');
        }
        return t('Unknown subscription payment mode');
    }
}

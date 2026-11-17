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
use Aifec\Modules\Subscriptions\Subscriptions;

return static function (App $app): void {
    $app->bind('subscriptions', static function (App $app): Subscriptions {
        return new Subscriptions($app);
    });
    $app->hooks()->slot('account.dashboard', static function (array $customer) use ($app): string {
        return '<section class="af-card"><h2>' . e(t('My subscriptions')) . '</h2><p><a class="af-btn" href="'
            . e($app->url()->route('subscriptions.account')) . '">' . e(t('Manage my monthly services')) . '</a></p></section>';
    });
};

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

return ['handlers' => [
    'search_learning.run' => static function (array $payload, App $app): string {
        return $app->service('search_learning')->tick(isset($payload['id']) ? (int) $payload['id'] : 0);
    },
], 'cron' => []];

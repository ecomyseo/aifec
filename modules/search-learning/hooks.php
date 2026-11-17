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
use Aifec\Modules\SearchLearning\Engine\Learning;
use Aifec\Modules\SearchLearning\Engine\Memory;

return static function (App $app): void {
    $app->bind('search_learning.memory', static function (App $app): Memory { return new Memory($app); });
    $app->bind('search_learning', static function (App $app): Learning { return new Learning($app); });
    $app->hooks()->filter('search.plan.text', static function (string $text, array $plan) use ($app): string {
        return $app->service('search_learning.memory')->rewrite($text, $plan);
    });
    $app->hooks()->filter('search.result.ids', static function (array $ids, string $query, string $lang, string $type) use ($app): array {
        return $app->service('search_learning.memory')->rerank($ids, $query, $lang, $type);
    });
};

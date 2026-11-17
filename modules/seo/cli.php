<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Seo\IndexNow;

return [
    'seo:indexnow' => ['help' => 'Tells Bing and the other IndexNow search engines about the product pages: seo:indexnow [--dry-run]', 'run' => static function (App $app, array $args, Console $io): int {
        $urls = IndexNow::productUrls($app);
        if ($urls === []) {
            $io->error('There are no product pages in the sitemap.');

            return 1;
        }
        if (!empty($args['dry-run']) || in_array('--dry-run', $args, true)) {
            $io->line(count($urls) . ' product addresses would be sent, with the key at ' . IndexNow::keyLocation($app) . '. First: ' . $urls[0]);

            return 0;
        }
        $result = IndexNow::submit($app, $urls);
        $ok = $result['status'] === 200 || $result['status'] === 202;
        $line = 'IndexNow: ' . $result['count'] . ' addresses sent, answer ' . $result['status'] . ($ok ? ' (received)' : '');
        if ($ok) {
            $io->line($line);

            return 0;
        }
        $io->error($line);

        return 1;
    }],
];

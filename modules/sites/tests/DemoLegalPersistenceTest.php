<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Cms\Legal;
use Aifec\Modules\Sites\DemoBuilder;

require_once __DIR__ . '/helpers.php';

test('las páginas legales de un paquete se reconstruyen al reiniciarlo y no se duplican', function (): void {
    $packages = aifecSitesPackages();
    aifecSitesEditJson($packages . '/minimo/package.json', static function (array $package): array {
        $package['settings']['cms.demo_legal_pages'] = true;
        return $package;
    });
    $w = aifecSitesFresh($packages);
    $result = $w['demos']->create(['package' => 'minimo', 'slug' => 'legal']);
    check(!empty($result['ok']), (string) ($result['error'] ?? ''));
    $demo = aifecSitesDemoApp($w, 'demo-legal');
    $first = (new Legal($demo))->status();
    same(5, count(array_filter($first, static function (array $page): bool { return $page['status'] === 'published'; })));
    same(7, (int) $demo->db()->value('SELECT COUNT(*) FROM {pages}'));
    aifecSitesRelease($demo);
    DemoBuilder::for($w['master'], $packages)->build('demo-legal', 'minimo');
    $demo = aifecSitesDemoApp($w, 'demo-legal');
    same($first, (new Legal($demo))->status());
    same(7, (int) $demo->db()->value('SELECT COUNT(*) FROM {pages}'), 'la segunda construcción conserva una sola página por política');
    aifecSitesRelease($demo);
    $reset = $w['demos']->reset('demo-legal');
    check(!empty($reset['ok']), (string) ($reset['error'] ?? ''));
    $demo = aifecSitesDemoApp($w, 'demo-legal');
    same(5, count(array_filter((new Legal($demo))->status(), static function (array $page): bool { return $page['status'] === 'published'; })));
    same(7, (int) $demo->db()->value('SELECT COUNT(*) FROM {pages}'));
});

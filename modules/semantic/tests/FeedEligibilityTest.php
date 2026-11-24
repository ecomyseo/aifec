<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Semantic\FeedEligibility;

require_once __DIR__ . '/../../agentic/tests/UcpKit.php';
require_once __DIR__ . '/../../cms/tests/helpers.php';
require_once dirname(__DIR__, 3) . '/tests/Install/support.php';

/*
 * Auditoría externa del 24/11/2026 sobre gmartos.es: la API decía 149 productos y los feeds de ACP y Google, 140. Los nueve que faltaban
 * eran siete gratuitos (precio 0) y dos sin imagen. La regla estaba repetida en tres generadores y no se contaba en ninguna parte:
 * ahora hay una sola (FeedEligibility), con su motivo, y se cuenta en llms.txt, /.well-known/aifec.json y el diagnóstico.
 */

/**
 * Una tienda con un producto normal, uno sin imagen y uno gratuito.
 *
 * @param array<string,mixed> $settings
 *
 * @return array<string,mixed>
 */
function eligibilityShop(array $settings = []): array
{
    $modules = ['agentic', 'orders', 'catalog', 'media', 'cms', 'semantic'];
    $app = aifecOrderApp(['modules' => $modules, 'settings' => $settings + ['modules.active' => $modules, 'agentic.acp_enabled' => true]]);

    return [
        'app' => $app,
        'ok' => aifecProduct($app, ['name' => 'Silla de roble', 'slug' => 'silla', 'price' => 1999, 'images' => 2, 'stock' => 5]),
        'sin_imagen' => aifecProduct($app, ['name' => 'Ampliación de módulo', 'slug' => 'ampliacion', 'price' => 5000, 'images' => 0, 'stock' => 5]),
        'gratis' => aifecProduct($app, ['name' => 'Módulo gratuito', 'slug' => 'gratis', 'price' => 0, 'images' => 2, 'stock' => 5]),
    ];
}

/** El cuerpo de un feed en streaming, ya escrito. */
function eligibilityBody(\Aifec\Kernel\App $app, string $path): string
{
    $app->cache()->flushTags(['catalog', 'semantic', 'settings']);
    $response = ucpCall($app, 'GET', $path);
    $response->drain();

    return (string) $response->body;
}

/** @return string[] ids de producto que salen en el feed ACP */
function eligibilityAcpIds(\Aifec\Kernel\App $app): array
{
    $ids = [];
    foreach (array_filter(explode("\n", eligibilityBody($app, '/acp/feed.jsonl'))) as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && isset($row['item_id'])) {
            $ids[] = preg_replace('/-.*/', '', (string) $row['item_id']);
        }
    }

    return $ids;
}

test('el recuento dice cuántos productos hay, cuántos entran en los feeds y el motivo de cada uno que no', function (): void {
    $shop = eligibilityShop();
    $report = FeedEligibility::report($shop['app']);
    same(3, $report['total']);
    same(1, $report['eligible']);
    $reasons = [];
    foreach ($report['excluded'] as $row) {
        $reasons[$row['id']] = $row['reason'];
    }
    same('no_image', $reasons[$shop['sin_imagen']]);
    same('free', $reasons[$shop['gratis']]);
    check(!isset($reasons[$shop['ok']]), 'el normal sí entra');
    foreach ($report['excluded'] as $row) {
        check(in_array($row['reason'], FeedEligibility::REASONS, true), 'el motivo es uno de los conocidos');
        check(FeedEligibility::label($row['reason']) !== '', 'y tiene su texto');
    }
});

test('los generadores y el recuento aplican la misma regla: lo que cuenta como elegible es lo que sale en el feed', function (): void {
    $shop = eligibilityShop();
    $app = $shop['app'];
    $inFeed = eligibilityAcpIds($app);
    $report = FeedEligibility::report($app);
    same($report['eligible'], count($inFeed), 'el feed ACP tiene tantos como el recuento');
    check(in_array((string) $shop['ok'], $inFeed, true), 'sale el normal');
    check(!in_array((string) $shop['gratis'], $inFeed, true) && !in_array((string) $shop['sin_imagen'], $inFeed, true), 'no salen el gratuito ni el que no tiene imagen');
    $tsv = array_filter(explode("\n", eligibilityBody($app, '/acp/feed-google.tsv')));
    same(1 + $report['eligible'], count($tsv), 'el feed de Google, igual (la primera línea es la cabecera)');
});

test('con «Incluir los productos gratuitos» el gratuito entra en los dos feeds, y el que no tiene imagen sigue fuera', function (): void {
    $shop = eligibilityShop(['semantic.feeds.include_free' => true]);
    $app = $shop['app'];
    $inFeed = eligibilityAcpIds($app);
    check(in_array((string) $shop['gratis'], $inFeed, true), 'el gratuito sale en ACP');
    check(!in_array((string) $shop['sin_imagen'], $inFeed, true), 'el que no tiene imagen sigue fuera');
    same(2, FeedEligibility::report($app)['eligible']);
    $tsv = array_filter(explode("\n", eligibilityBody($app, '/acp/feed-google.tsv')));
    same(3, count($tsv), 'y en el de Google');
    $fields = [];
    foreach ((array) (require dirname(__DIR__) . '/settings.php')['tabs'] as $tab) {
        $fields += $tab['fields'];
    }
    same(false, $fields['semantic.feeds.include_free']['default'], 'apagado de serie: no se cambia lo que hay');
});

test('/.well-known/aifec.json cuenta los productos y los que están en los feeds, y el diagnóstico avisa de los que se quedan fuera', function (): void {
    $shop = eligibilityShop();
    $app = $shop['app'];
    $doc = json_decode(ucpCall($app, 'GET', '/.well-known/aifec.json')->body, true);
    same(['products' => 3, 'in_feeds' => 1], $doc['catalog']);
    $checks = $app->hooks()->apply('doctor.checks', [], $app);
    $found = null;
    foreach (is_array($checks) ? $checks : [] as $one) {
        if (is_array($one) && ($one['id'] ?? '') === 'semantic.feeds') {
            $found = $one;
        }
    }
    check($found !== null, 'hay una comprobación de feeds en el diagnóstico');
    $result = $found['run']($app);
    same('warn', $result['status'], 'avisa: hay uno que se arregla (sin imagen)');
    contains('Ampliación de módulo', $result['detail'], 'dice cuál');
    contains('1 de 3', $result['detail'], 'y cuántos están en los feeds');
    contains('#' . $shop['sin_imagen'], $result['detail'], 'y por su ID');
});

test('feeds:excluded lista por ID los productos que no entran en los feeds con su motivo, y cuenta lo mismo que llms.txt y aifec.json', function (): void {
    $shop = eligibilityShop();
    $app = $shop['app'];
    $io = new \Aifec\Cli\Console(false);
    $commands = $io->commands($app);
    check(isset($commands['feeds:excluded']), 'la orden existe');
    same(0, $commands['feeds:excluded']['run']($app, [], $io));
    $out = $io->output;
    contains((string) $shop['sin_imagen'], $out, 'el ID del producto sin imagen');
    contains('no_image', $out, 'su motivo');
    contains((string) $shop['gratis'], $out, 'el ID del gratuito');
    contains('free', $out, 'su motivo');
    notContains('Silla de roble', $out, 'el que entra en los feeds no sale');
    contains('1 of 3 products are in the feeds, 2 left out', $out, 'el recuento sale de la misma regla');
    $doc = json_decode(ucpCall($app, 'GET', '/.well-known/aifec.json')->body, true);
    same(1, $doc['catalog']['in_feeds'], 'aifec.json cuenta igual');
    same(1, count(array_filter(explode("\n", eligibilityBody($app, '/acp/feed.jsonl')))), 'y el feed lleva el mismo número');
});

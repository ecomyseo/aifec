<?php
/**
 * AIFEC — las demos no ejecutan código de medición configurable ni envían conversiones reales.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function dlDemoSettings(): array
{
    return ['datalayer.enabled' => true, 'datalayer.server_events' => true, 'datalayer.exclude_staff' => false,
        'datalayer.gtm_id' => 'GTM-DEMO1234', 'datalayer.ga4_id' => 'G-ABCDE12345', 'datalayer.meta_pixel' => '123456789012345',
        'datalayer.custom_necessary' => 'window.aifecNecessaryMarker=1;',
        'datalayer.custom_statistics' => 'window.aifecStatisticsMarker=1;',
        'datalayer.custom_marketing' => 'window.aifecMarketingMarker=1;'];
}

test('una demo conserva los ajustes de medición pero nunca emite etiquetas externas ni código personalizado', function (): void {
    foreach ([false, true] as $public) {
        $app = dlApp(['settings' => dlDemoSettings()]);
        $app->site()->config['demo'] = true;
        $app->site()->config['public_admin_demo'] = $public;
        $head = $app->service('datalayer')->head();
        $html = $head->render();
        same('GTM-DEMO1234', $app->settings()->str('datalayer.gtm_id'));
        same('window.aifecStatisticsMarker=1;', $app->settings()->str('datalayer.custom_statistics'));
        contains('af-dl-config', $html, 'se conserva la configuración local de eventos');
        contains('datalayer.js', $html, 'el código interno de la tienda sigue disponible');
        foreach (['googletagmanager.com', 'facebook.net', 'aifecNecessaryMarker', 'aifecStatisticsMarker', 'aifecMarketingMarker'] as $unsafe) {
            notContains($unsafe, $html);
        }
        same(false, $head->hasLoaders());
        same(0, count(dlHttp($app)->requests));
    }
});

test('la medición configurada de una demo no relaja la política de scripts de la respuesta real', function (): void {
    $app = dlApp(['settings' => dlDemoSettings()]);
    $app->site()->config['demo'] = true;
    $app->site()->config['public_admin_demo'] = true;
    $response = dlWeb($app, 'sm')->get('/');
    same(200, $response->status);
    $policy = (string) ($response->headers['Content-Security-Policy'] ?? '');
    check(preg_match('/(?:^|;)\s*script-src\s+([^;]+)/', $policy, $match) === 1, 'la respuesta tiene política de scripts');
    notContains("'unsafe-inline'", $match[1]);
    notContains('aifecStatisticsMarker', $response->body);
    same(false, $app->service('datalayer')->config()->serverEvents());
});

test('un evento previo se omite al convertir la tienda en demo aunque el ajuste del servidor siga encendido', function (): void {
    $app = dlApp(['settings' => dlDemoSettings()]);
    $app->setSecret('datalayer.ga4_api_secret', 'secreto-local-ficticio');
    $app->setSecret('datalayer.meta_token', 'token-local-ficticio');
    dlOrder($app, dlWeb($app, 'sm'));
    $ids = $app->db()->column("SELECT id FROM {datalayer_events} WHERE status = 'queued'");
    check(count($ids) > 0, 'el control de tienda normal sí encola eventos');
    $app->site()->config['demo'] = true;
    same(true, $app->settings()->bool('datalayer.server_events'));
    same(false, $app->service('datalayer')->config()->serverEvents());
    foreach ($ids as $id) {
        same('skipped', $app->service('datalayer')->server()->send((int) $id));
        same('The switch is off', $app->db()->value('SELECT response FROM {datalayer_events} WHERE id = ?', [(int) $id]));
    }
    same(0, count(dlHttp($app)->requests));
});

test('una tienda real conserva etiquetas y código configurados y la política cambia al marcarla como demo', function (): void {
    $app = dlApp(['settings' => dlDemoSettings()]);
    $head = $app->service('datalayer')->head();
    contains('googletagmanager.com', $head->render());
    contains('aifecStatisticsMarker', $head->render());
    same(true, $head->hasLoaders());
    same(true, $app->service('datalayer')->config()->serverEvents());
    $app->site()->config['demo'] = true;
    notContains('aifecStatisticsMarker', $head->render());
    same(false, $head->hasLoaders());
});

<?php
/**
 * AIFEC — los lugares de menú del tema salen en el panel con su nombre traducido. El tema los escribe en inglés en su
 * theme.json («Main menu», «Footer», «Legal links») y los traduce en su lang/: en una tienda en castellano el panel
 * enseñaba el inglés tal cual (lo encontró quien escribía el manual, 13/11).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('los lugares de menú del tema base salen traducidos en el servicio y en la pantalla de menús', function (): void {
    $app = cmsApp(['cms'], [], false);
    $app->boot();

    $locations = $app->service('cms')->menus()->locations();

    same(['main', 'footer', 'legal'], array_keys($locations), 'los tres lugares del tema base, con su código de siempre');
    same('Menú principal', $locations['main']);
    same('Pie de página', $locations['footer']);
    same('Enlaces legales', $locations['legal']);

    $page = adminClient($app)->get('/admin/cms/menus');
    same(200, $page->status);
    contains('Menú principal', $page->body);
    contains('Enlaces legales', $page->body);
    notContains('Main menu', $page->body, 'ni rastro del nombre en inglés');
    notContains('Legal links', $page->body);
});

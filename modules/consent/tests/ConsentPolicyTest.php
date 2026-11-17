<?php
/**
 * AIFEC — /cookies: la política publicada, o la tabla de lo que de verdad se pone y el botón para cambiar la elección (13/11/2026:
 * /cookies daba 404 y el aviso decía «Usamos cookies» sin explicar cuáles).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('sin política publicada, /cookies enseña las cookies de verdad, en castellano, y el botón para cambiar la elección', function (): void {
    $app = consentApp();
    $r = (new TestClient($app))->get('/cookies');
    same(200, $r->status);
    $body = (string) $r->body;
    contains('<h1 class="af-title">Política de cookies</h1>', $body);
    contains('Qué cookies usamos', $body);
    contains('class="af-consent-table"', $body, 'la tabla de las cookies que se ponen');
    contains('data-af-consent-open', $body, 'el botón para cambiar la elección');
    contains('Rechazar es tan fácil como aceptar', $body);
    $cookie = $app->service('consent')->cookies();
    check($cookie !== [], 'hay cookies declaradas');
    $first = reset($cookie);
    contains(e((string) (is_array($first) && isset($first['name']) ? $first['name'] : key($cookie))), $body, 'con su nombre');
});

test('con la política de cookies publicada como página legal, /cookies lleva a ella', function (): void {
    $app = consentApp(['modules' => ['cms']]);
    $created = (new \Aifec\Modules\Cms\Legal($app))->create();
    check(in_array('cookies', $created, true), 'se crea la página legal');
    $id = (int) $app->settings()->arr('cms.legal')['cookies'];
    $app->db()->update('pages', ['status' => 'published'], ['id' => $id]);
    $app->invalidate(['cms']);
    $r = (new TestClient($app))->get('/cookies');
    same(302, $r->status);
    contains('politica-de-cookies', (string) ($r->headers['Location'] ?? ''));
});

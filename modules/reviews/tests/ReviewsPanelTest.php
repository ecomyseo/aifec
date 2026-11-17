<?php
/**
 * AIFEC — pruebas del panel de valoraciones: bandeja por estado, publicar, rechazar, responder, permisos y los datos escapados.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('la bandeja se abre con permiso, filtra por estado, busca y enseña los textos escapados; la ficha enseña los datos del cliente', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta & Co']);
    $a = reviewsSubmit($app, $p, ['name' => 'Ana <b>', 'title' => 'Muy "buena"', 'body' => 'Texto con <script>alert(1)</script> & más.'])['id'];
    $b = reviewsSubmit($app, $p, ['email' => 'luis@cliente.test', 'name' => 'Luis', 'body' => 'Opinión ya publicada de Luis en la tienda.'])['id'];
    $app->service('reviews')->publish($b);
    $web = adminClient($app);

    $list = $web->get('/admin/reviews');
    same(200, $list->status);
    contains('Valoraciones', $list->body);
    contains('Camiseta &amp; Co', $list->body);
    contains('Ana &lt;b&gt;', $list->body);
    notContains('<script>alert', $list->body);
    contains('/admin/reviews/' . $a, $list->body);

    $pending = $web->get('/admin/reviews?f[status]=pending')->body;
    contains('Ana &lt;b&gt;', $pending);
    notContains('Luis', $pending);
    $published = $web->get('/admin/reviews?f[status]=published')->body;
    contains('Luis', $published);
    notContains('Ana &lt;b&gt;', $published);
    contains('Luis', $web->get('/admin/reviews?q=luis')->body);
    notContains('Ana &lt;b&gt;', $web->get('/admin/reviews?q=luis')->body);

    $show = $web->get('/admin/reviews/' . $a);
    same(200, $show->status);
    contains('Texto con &lt;script&gt;alert(1)&lt;/script&gt; &amp; más.', $show->body);
    contains('ana@cliente.test', $show->body);
    contains('Pendiente', $show->body);
    contains('/admin/reviews/' . $a . '/publish', $show->body);
    same(404, $web->get('/admin/reviews/99999')->status);
});

test('publicar, rechazar y responder desde el panel pasan por el servicio: la nota del producto cambia al momento y queda en la auditoría', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta']);
    $id = reviewsSubmit($app, $p, ['rating' => '4'])['id'];
    $web = adminClient($app);
    $web->get('/admin/reviews/' . $id);

    $r = $web->post('/admin/reviews/' . $id . '/publish', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same('published', reviewsRows($app)[0]['status']);
    same(['average' => 4.0, 'count' => 1], $app->service('catalog')->product($p)['rating']);
    contains('La valoración está publicada.', $web->follow()->body);

    $web->post('/admin/reviews/' . $id . '/reply', ['_token' => aifecToken($web), 'reply' => 'Gracias & hasta pronto <3']);
    same('Gracias & hasta pronto <3', reviewsRows($app)[0]['reply'], 'el texto se guarda tal cual, sin &amp;');
    $page = $web->follow()->body;
    contains('Gracias &amp; hasta pronto &lt;3', $page);
    contains('La respuesta está guardada.', $page);

    $web->post('/admin/reviews/' . $id . '/reject', ['_token' => aifecToken($web)]);
    same('rejected', reviewsRows($app)[0]['status']);
    same(null, $app->service('catalog')->product($p)['rating'], 'rechazarla la quita de la media');
    contains('ya no se muestra', $web->follow()->body);

    $web->post('/admin/reviews/' . $id . '/publish', ['_token' => aifecToken($web)]);
    same('published', reviewsRows($app)[0]['status'], 'se puede volver a publicar');
    $actions = array_column($app->db()->select("SELECT action FROM {audit} WHERE entity = 'review' ORDER BY id"), 'action');
    same(['publish', 'reply', 'reject', 'publish'], $actions);
});

test('una respuesta demasiado larga se rechaza con un aviso y no se guarda', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $id = reviewsSubmit($app, $p)['id'];
    $web = adminClient($app);
    $web->get('/admin/reviews/' . $id);

    $web->post('/admin/reviews/' . $id . '/reply', ['_token' => aifecToken($web), 'reply' => str_repeat('r', 1001)]);

    same(null, reviewsRows($app)[0]['reply']);
    contains('demasiado larga', $web->follow()->body);
});

test('sin permiso no se entra: quien solo puede ver abre la bandeja pero recibe 403 al publicar, rechazar o responder; sin sesión se va al acceso', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $id = reviewsSubmit($app, $p)['id'];

    $viewer = adminClient($app, 'support');
    same(200, $viewer->get('/admin/reviews')->status);
    $show = $viewer->get('/admin/reviews/' . $id);
    same(200, $show->status);
    $token = aifecToken($viewer);
    notContains('/admin/reviews/' . $id . '/publish', $show->body, 'los botones no salen a quien no puede');
    foreach (['publish', 'reject', 'reply'] as $action) {
        same(403, $viewer->post('/admin/reviews/' . $id . '/' . $action, ['_token' => $token, 'reply' => 'x'])->status, $action);
    }
    same('pending', reviewsRows($app)[0]['status'], 'nada cambió');
    same(null, reviewsRows($app)[0]['reply']);

    $anonymous = new TestClient($app);
    check($anonymous->get('/admin/reviews')->status !== 200, 'sin sesión no se ve la bandeja');
});

test('el panel declara sus permisos, su entrada en el menú de catálogo y la pantalla de ajustes que guarda lo que se cambia', function (): void {
    $app = reviewsApp();
    $web = adminClient($app);

    $home = $web->get('/admin');
    contains('/admin/reviews', $home->body);
    $caps = \Aifec\Admin\Admin::capabilities($app);
    check(isset($caps['reviews.view']) && isset($caps['reviews.edit']), 'los permisos están declarados');

    $settings = $web->get('/admin/settings/reviews');
    same(200, $settings->status);
    contains('rvgeneral', $settings->body);
    $web->post('/admin/settings/reviews', ['_token' => aifecToken($web), 'f' => ['reviews.auto_publish_verified' => '1', 'reviews.ask' => '0', 'reviews.ask_days' => '10']]);
    $app->settings()->reload();
    same(true, $app->settings()->bool('reviews.auto_publish_verified'));
    same(false, $app->settings()->bool('reviews.ask', true));
    same(10, $app->settings()->int('reviews.ask_days'));
    same(403, adminClient($app, 'support')->get('/admin/settings/reviews')->status);
});

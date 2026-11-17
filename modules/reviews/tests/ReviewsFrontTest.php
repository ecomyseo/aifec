<?php
/**
 * AIFEC — pruebas de las valoraciones en la tienda: la sección de la ficha, el formulario, los errores, el campo trampa y el límite.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** La dirección de la ficha de un producto. */
function reviewsProductUrl(\Aifec\Kernel\App $app, int $productId): string
{
    return (string) $app->service('catalog')->product($productId)['url'];
}

test('la ficha enseña la sección de valoraciones: media, reparto, compra verificada y la respuesta de la tienda', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta']);
    reviewsPaidOrder($app, $p, 'ana@cliente.test');
    $a = reviewsSubmit($app, $p, ['rating' => '5', 'name' => 'Ana', 'title' => 'Genial', 'body' => 'Me ha encantado esta camiseta, la recomiendo.'])['id'];
    $b = reviewsSubmit($app, $p, ['rating' => '3', 'name' => 'Luis', 'email' => 'luis@cliente.test', 'body' => 'Cumple, aunque la talla es algo pequeña.'])['id'];
    $app->service('reviews')->publish($a);
    $app->service('reviews')->publish($b);
    $app->service('reviews')->reply($a, "Gracias Ana\nVuelve pronto");

    $web = new TestClient($app);
    $r = $web->get(reviewsProductUrl($app, $p));

    same(200, $r->status);
    contains('id="valoraciones"', $r->body);
    contains('Valoraciones de clientes', $r->body);
    contains('Me ha encantado esta camiseta, la recomiendo.', $r->body);
    contains('Cumple, aunque la talla es algo pequeña.', $r->body);
    contains('Compra verificada', $r->body);
    contains('2 valoraciones', $r->body);
    contains('af-stars', $r->body);
    contains('Respuesta de la tienda', $r->body);
    contains('Gracias Ana<br />', $r->body);
    contains('action="' . $app->url()->route('reviews.submit', ['productId' => $p]) . '"', $r->body);
    same(1, substr_count($r->body, 'Compra verificada'), 'solo la de Ana es verificada');
    preg_match_all('/<form\b[^>]*>/i', $r->body, $forms);
    $posts = 0;
    foreach ($forms[0] as $tag) {
        if (preg_match('/\bmethod="post"/i', $tag) !== 1) {
            continue;
        }
        ++$posts;
        $action = preg_match('/\baction="([^"]*)"/i', $tag, $a) === 1 ? html_entity_decode($a[1]) : '';
        $match = $app->router()->match('POST', (string) parse_url($action, PHP_URL_PATH));
        check($match !== null && in_array('csrf', $match['route']->middleware, true), 'el formulario ' . $action . ' pasa por el intermediario csrf');
    }
    check($posts >= 1, 'la ficha lleva el formulario de valoración');
    notContains('href=""', $r->body);
});

test('una opinión pendiente o rechazada no se enseña a nadie y una ficha sin opiniones lo dice y ofrece el formulario', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta']);
    $web = new TestClient($app);

    $empty = $web->get(reviewsProductUrl($app, $p));
    contains('Todavía no hay valoraciones', $empty->body);
    contains('name="body"', $empty->body);
    contains('name="website"', $empty->body, 'el campo trampa va en el formulario');

    reviewsSubmit($app, $p, ['body' => 'Texto pendiente de moderar por la tienda.']);
    $rejected = reviewsSubmit($app, $p, ['email' => 'r@cliente.test', 'body' => 'Texto rechazado por la tienda sin más.'])['id'];
    $app->service('reviews')->reject($rejected);
    $r = $web->get(reviewsProductUrl($app, $p));
    notContains('Texto pendiente de moderar', $r->body);
    notContains('Texto rechazado', $r->body);
});

test('un texto con <script> se pinta escapado en la ficha: nunca llega al HTML como etiqueta', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $id = reviewsSubmit($app, $p, [
        'name' => 'Eva <i>&</i>', 'title' => '"Ojo" <b>', 'body' => 'Hola <script>alert(1)</script> & adiós, y <img src=x onerror=alert(2)> más.',
    ])['id'];
    $app->service('reviews')->publish($id);
    $app->service('reviews')->reply($id, '<script>alert(3)</script> gracias');

    $body = (new TestClient($app))->get(reviewsProductUrl($app, $p))->body;

    contains('Hola &lt;script&gt;alert(1)&lt;/script&gt; &amp; adiós', $body);
    contains('&lt;img src=x onerror=alert(2)&gt;', $body);
    contains('Eva &lt;i&gt;&amp;&lt;/i&gt;', $body);
    contains('&lt;script&gt;alert(3)&lt;/script&gt; gracias', $body);
    notContains('<script>alert', $body);
    notContains('<img src=x', $body);
});

test('la ficha con sus valoraciones está en la caché de página y publicar, retirar o responder la invalidan', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $id = reviewsSubmit($app, $p, ['body' => 'Una opinión que sale y se va de la ficha.'])['id'];
    $web = new TestClient($app);
    $url = reviewsProductUrl($app, $p);

    same('MISS', $web->get($url)->headers['X-Cache']);
    $copy = reviewsCached($app, $url);
    check($copy !== null, 'la ficha quedó guardada');
    notContains('Una opinión que sale', $copy->body);

    $app->service('reviews')->publish($id);
    same(null, reviewsCached($app, $url), 'publicar vacía la copia de la ficha');
    $shown = $web->get($url);
    same('MISS', $shown->headers['X-Cache']);
    contains('Una opinión que sale', $shown->body);
    contains('Una opinión que sale', (string) (reviewsCached($app, $url) !== null ? reviewsCached($app, $url)->body : ''), 'y se vuelve a guardar con la opinión');

    $app->service('reviews')->reply($id, 'Gracias por escribirnos');
    same(null, reviewsCached($app, $url), 'responder también la vacía');
    contains('Gracias por escribirnos', $web->get($url)->body);

    $app->service('reviews')->reject($id);
    same(null, reviewsCached($app, $url), 'retirar también');
    notContains('Una opinión que sale', $web->get($url)->body);
});

test('la lista de la ficha se pagina de cinco en cinco en la dirección ?valoraciones=2', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    for ($i = 1; $i <= 12; ++$i) {
        $id = reviewsSubmit($app, $p, ['email' => 'u' . $i . '@cliente.test', 'name' => 'Cliente' . $i, 'body' => 'Opinión número ' . $i . ' de este producto.'])['id'];
        $app->service('reviews')->publish($id);
        $app->clock()->advance(60);
    }
    $url = reviewsProductUrl($app, $p);

    $first = (new TestClient($app))->get($url);
    same(5, substr_count($first->body, 'class="af-rv-item"'), 'cinco por página');
    contains('Opinión número 12 de', $first->body);
    contains('Opinión número 8 de', $first->body);
    notContains('Opinión número 7 de', $first->body);
    contains('?valoraciones=2#valoraciones', $first->body);
    $second = (new TestClient($app))->get($url . '?valoraciones=2');
    same(5, substr_count($second->body, 'class="af-rv-item"'));
    contains('Opinión número 7 de', $second->body);
    contains('Opinión número 3 de', $second->body);
    notContains('Opinión número 12 de', $second->body);
    $third = (new TestClient($app))->get($url . '?valoraciones=3');
    same(2, substr_count($third->body, 'class="af-rv-item"'));
    contains('Opinión número 1 de', $third->body);
});

test('el formulario de la ficha guarda la opinión como pendiente, vuelve a la ficha con el aviso y no publica nada', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta']);
    $web = new TestClient($app);

    $r = reviewsPost($web, $p);

    same(302, $r->status);
    contains('valoracion=sent#valoraciones', $r->headers['Location']);
    $row = reviewsRows($app)[0];
    same('pending', $row['status']);
    same('Ana', $row['name']);
    same('es', $row['lang']);
    $page = $web->follow();
    contains('aparecerá en cuanto la hayamos revisado', $page->body);
    notContains('La camiseta es justo lo que esperaba', $page->body);
});

test('con la publicación automática, una compra verificada enviada desde la ficha sale al momento y el aviso lo dice', function (): void {
    $app = reviewsApp(['settings' => ['reviews.auto_publish_verified' => true]]);
    $p = aifecProduct($app, ['name' => 'Camiseta']);
    reviewsPaidOrder($app, $p, 'ana@cliente.test');
    $web = new TestClient($app);

    $r = reviewsPost($web, $p);

    contains('valoracion=published', $r->headers['Location']);
    $page = $web->follow();
    contains('ya está publicada', $page->body);
    contains('La camiseta es justo lo que esperaba', $page->body);
});

test('un error de validación devuelve 422 con la página del formulario, el error en su campo y lo escrito, escapado', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta']);
    $web = new TestClient($app);

    $r = reviewsPost($web, $p, ['body' => 'corta <&', 'name' => 'Ana "la" <i>', 'rating' => '']);

    same(422, $r->status);
    contains('Escribe al menos 10 caracteres.', $r->body);
    contains('Elige una nota de 1 a 5 estrellas.', $r->body);
    contains('value="Ana &quot;la&quot; &lt;i&gt;"', $r->body);
    contains('corta &lt;&amp;', $r->body);
    contains('noindex', $r->body);
    same(0, count(reviewsRows($app)));
});

test('el envío por JavaScript contesta JSON: bien con ok y mensaje, mal con 422 y los errores por campo', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $web = new TestClient($app);

    $bad = $web->postJson('/valoracion/' . $p, reviewsData(['rating' => '9']));
    same(422, $bad->status);
    $json = $web->json();
    same(false, $json['ok']);
    check(isset($json['errors']['rating']), 'el error va en el campo rating');

    $ok = $web->postJson('/valoracion/' . $p, reviewsData());
    same(200, $ok->status);
    same(true, $web->json()['ok']);
    contains('Cache-Control', implode(',', array_keys($ok->headers)));
    same(1, count(reviewsRows($app)));
});

test('el campo trampa lleno contesta «gracias» como si nada y no guarda nada; un envío de otra web se rechaza con 403', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $web = new TestClient($app);

    $robot = reviewsPost($web, $p, ['website' => 'http://spam.test']);
    same(302, $robot->status);
    contains('valoracion=sent', $robot->headers['Location']);
    same(0, count(reviewsRows($app)));

    $foreign = $web->post('/valoracion/' . $p, reviewsData(), ['sec-fetch-site' => 'cross-site']);
    same(403, $foreign->status);
    same(0, count(reviewsRows($app)));
    same(404, $web->post('/valoracion/99999', reviewsData())->status, 'un producto que no existe');
});

test('el límite por dirección deja cinco opiniones por hora; los errores no gastan el límite y pasada la hora vuelve a dejar', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $web = new TestClient($app);

    for ($i = 1; $i <= 3; ++$i) {
        same(422, reviewsPost($web, $p, ['body' => 'x'])->status, 'los fallos no cuentan');
    }
    for ($i = 1; $i <= 5; ++$i) {
        same(302, reviewsPost($web, $p, ['email' => 'u' . $i . '@cliente.test'])->status, 'opinión ' . $i);
    }
    $sixth = reviewsPost($web, $p, ['email' => 'u6@cliente.test']);
    same(429, $sixth->status);
    same(5, count(reviewsRows($app)));

    $app->clock()->advance(3601);
    same(302, reviewsPost($web, $p, ['email' => 'u7@cliente.test'])->status);
    same(6, count(reviewsRows($app)));
});

<?php
/**
 * AIFEC — pruebas de la sección de valoraciones compacta (P096): resumen de una línea, formulario plegado, estrellas que se pulsan,
 * lista ligera de cinco en cinco con «Leer más» y una hoja de estilo propia y pequeña que solo se carga donde hace falta.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('rvcSection')) {
    /** La sección de valoraciones de una página (de su apertura a su cierre). */
    function rvcSection(string $body): string
    {
        $from = strpos($body, '<section class="af-section af-rv"');
        if ($from === false) {
            return '';
        }
        $to = strpos($body, '</section>', $from);

        return $to === false ? substr($body, $from) : substr($body, $from, $to - $from + 10);
    }

    function rvcUrl(\Aifec\Kernel\App $app, int $productId): string
    {
        return (string) $app->service('catalog')->product($productId)['url'];
    }

    /** La sección de la ficha de un producto, pedida por HTTP. */
    function rvcGet(\Aifec\Kernel\App $app, int $productId, string $query = ''): string
    {
        return rvcSection((new TestClient($app))->get(rvcUrl($app, $productId) . $query)->body);
    }

    /** Publica $n valoraciones de distintas personas. */
    function rvcPublish(\Aifec\Kernel\App $app, int $productId, int $n, array $extra = []): void
    {
        for ($i = 1; $i <= $n; ++$i) {
            $id = reviewsSubmit($app, $productId, $extra + ['email' => 'p' . $i . '@cliente.test', 'name' => 'Persona' . $i, 'body' => 'Opinión de la persona ' . $i . ' sobre este producto.'])['id'];
            $app->service('reviews')->publish($id);
            $app->clock()->advance(60);
        }
    }

    /** La ficha pintada directamente con errores o con lo escrito, como lo haría un envío fallido. */
    function rvcRender(\Aifec\Kernel\App $app, int $productId, array $values, array $errors, bool $open = false): string
    {
        (new TestClient($app))->get('/');
        $product = $app->service('catalog')->product($productId);

        return $app->view()->render('reviews::front/product', [
            'product' => $product, 'data' => $app->service('reviews')->forProduct($productId), 'notice' => '', 'open' => $open,
            'action' => $app->url()->route('reviews.submit', ['productId' => $productId]), 'values' => $values, 'errors' => $errors,
        ]);
    }

    function rvcCss(): string
    {
        return (string) file_get_contents(__DIR__ . '/../assets/reviews.css');
    }

    /** La hoja sin sus comentarios. */
    function rvcCssCode(): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', rvcCss());
    }
}

test('sin valoraciones: una sola línea y el botón, sin barras ni lista', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $s = rvcGet($app, $p);

    contains('Todavía no hay valoraciones', $s);
    contains('Escribe una valoración', $s);
    notContains('<progress', $s);
    notContains('af-rv-list', $s);
    notContains('af-rv-item', $s);
    notContains('Ver el desglose', $s);
    notContains('af-card', $s);
});

test('sin valoraciones el formulario está plegado: el HTML no lleva details open', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $s = rvcGet($app, $p);

    contains('<details class="af-rv-write"', $s);
    check(preg_match('/<details\b[^>]*\bopen\b/', $s) !== 1, 'ningún details sale abierto');
    contains('name="body"', $s, 'el formulario está dentro, aunque plegado');
});

test('el botón es el summary del details y apunta al formulario', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $s = rvcGet($app, $p);

    contains('<summary aria-controls="af-rv-form">Escribe una valoración</summary>', $s);
    contains('<form class="af-rv-form" id="af-rv-form" method="post"', $s);
    $details = (int) strpos($s, '<details class="af-rv-write"');
    check($details < (int) strpos($s, 'id="af-rv-form"'), 'el formulario va dentro del details');
    check((int) strpos($s, 'id="af-rv-form"') < (int) strpos($s, '</details>', $details), 'y se cierra con él');
});

test('con una valoración: resumen en una línea con estrellas, media y número, y el reparto en dos copias (columna y desplegable)', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    rvcPublish($app, $p, 1, ['rating' => '4']);
    $s = rvcGet($app, $p);

    contains('(1 valoración)', $s);
    contains('<strong>4,0</strong>', $s);
    contains('role="img" aria-label="4,0 de 5"', $s);
    same(10, substr_count($s, '<progress'), 'cinco barras en la columna y otras cinco en el desplegable');
    contains('<summary>Ver el desglose</summary>', $s);
    contains('af-rv-bars af-rv-bars-d', $s);
    notContains('af-split', $s);
});

test('la cabecera va antes que la lista: resumen, botón y después las opiniones', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    rvcPublish($app, $p, 2);
    $s = rvcGet($app, $p);

    $sum = (int) strpos($s, 'af-rv-sum');
    $button = (int) strpos($s, 'af-rv-write');
    $list = (int) strpos($s, 'af-rv-list');
    check($sum > 0 && $sum < $button && $button < $list, 'resumen < botón < lista');
});

test('la lista es ligera: una fila por valoración, sin tarjetas, con la línea de autor y fecha', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    rvcPublish($app, $p, 3);
    $s = rvcGet($app, $p);

    same(3, substr_count($s, 'class="af-rv-item"'));
    contains('<ol class="af-rv-list" data-af-reviews>', $s);
    notContains('af-card', $s);
    notContains('af-stack', $s);
    contains('class="af-rv-m"', $s);
    contains('Persona3', $s);
});

test('con doce valoraciones salen cinco y la paginación que ya había sigue', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    rvcPublish($app, $p, 12);
    $s = rvcGet($app, $p);

    same(5, substr_count($s, 'class="af-rv-item"'));
    contains('(12 valoraciones)', $s);
    contains('?valoraciones=2#valoraciones', $s);
    same(5, substr_count(rvcGet($app, $p, '?valoraciones=2'), 'class="af-rv-item"'));
    same(2, substr_count(rvcGet($app, $p, '?valoraciones=3'), 'class="af-rv-item"'));
    same(5, \Aifec\Modules\Reviews\Reviews::PER_PAGE);
});

test('con errores el details sale abierto y conserva lo escrito, escapado', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $html = rvcRender($app, $p, ['name' => 'Ana "la" <i>', 'email' => 'ana@cliente.test', 'rating' => '4', 'body' => 'corta <&'], ['body' => 'Escribe al menos 10 caracteres.']);

    check(preg_match('/<details class="af-rv-write"[^>]*\bopen\b/', $html) === 1, 'el formulario sale abierto');
    contains('value="Ana &quot;la&quot; &lt;i&gt;"', $html);
    contains('corta &lt;&amp;', $html);
    contains('Escribe al menos 10 caracteres.', $html);
});

test('lo escrito sin errores también deja el formulario abierto', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $html = rvcRender($app, $p, ['name' => 'Ana'], []);

    check(preg_match('/<details class="af-rv-write"[^>]*\bopen\b/', $html) === 1);
});

test('?valorar=1 abre el formulario y sin él sale plegado, también con valoraciones', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    rvcPublish($app, $p, 2);

    check(preg_match('/<details class="af-rv-write"[^>]*\bopen\b/', rvcGet($app, $p, '?valorar=1')) === 1, 'abierto con ?valorar=1');
    check(preg_match('/<details class="af-rv-write"[^>]*\bopen\b/', rvcGet($app, $p)) !== 1, 'plegado sin él');
    check(preg_match('/<details class="af-rv-write"[^>]*\bopen\b/', rvcGet($app, $p, '?valorar=0')) !== 1, 'y con otro valor');
});

test('la caché de página guarda aparte la ficha abierta con ?valorar=1: no se cuela en la normal', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $web = new TestClient($app);
    $url = rvcUrl($app, $p);

    $web->get($url . '?valorar=1');
    $plain = rvcSection($web->get($url)->body);
    check(preg_match('/<details\b[^>]*\bopen\b/', $plain) !== 1, 'la ficha normal sigue plegada');
});

test('las estrellas son cinco radios con su label, sin select, y cada una con su nombre accesible', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $s = rvcGet($app, $p);

    same(5, preg_match_all('/<input type="radio" id="af-rv-star-(\d)" name="rating" value="\1"/', $s, $m));
    same(['1', '2', '3', '4', '5'], $m[1]);
    for ($n = 1; $n <= 5; ++$n) {
        contains('<label for="af-rv-star-' . $n . '"', $s);
    }
    contains('aria-label="3 estrellas"', $s);
    contains('aria-label="1 estrella"', $s);
    notContains('<select', $s);
    notContains('name="rating" required>', $s);
});

test('al volver con error queda marcada la estrella elegida y solo esa', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $html = rvcRender($app, $p, ['rating' => '4', 'name' => 'Ana'], ['body' => 'Falta el texto.']);

    same(1, substr_count($html, ' checked'));
    check(preg_match('/id="af-rv-star-4" name="rating" value="4"[^>]* checked/', $html) === 1, 'la de 4 está marcada');
    $none = rvcRender($app, $p, ['name' => 'Ana'], []);
    same(0, substr_count($none, ' checked'), 'sin nota elegida no hay ninguna marcada');
});

test('el servidor rechaza una nota fuera de 1 a 5 y un envío sin nota, igual que antes', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $web = new TestClient($app);

    foreach (['0', '6', '9', '-1', 'cinco', ''] as $rating) {
        $r = reviewsPost($web, $p, ['rating' => $rating]);
        same(422, $r->status, 'nota «' . $rating . '»');
        contains('Elige una nota de 1 a 5 estrellas.', $r->body);
    }
    $web2 = new TestClient($app);
    $data = reviewsData();
    unset($data['rating']);
    same(422, $web2->post('/valoracion/' . $p, $data)->status, 'sin el campo');
    same(0, count(reviewsRows($app)));
    same(302, reviewsPost($web, $p, ['rating' => '3'])->status, 'una nota válida pasa');
});

test('el campo trampa sigue en el formulario y un envío con él lleno no guarda nada', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    contains('name="website"', rvcGet($app, $p));
    $web = new TestClient($app);

    same(302, reviewsPost($web, $p, ['website' => 'http://spam.test'])->status);
    same(0, count(reviewsRows($app)));
});

test('un envío desde otra web sigue cortándose con 403', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $web = new TestClient($app);

    same(403, $web->post('/valoracion/' . $p, reviewsData(), ['sec-fetch-site' => 'cross-site'])->status);
    same(0, count(reviewsRows($app)));
});

test('nombre y correo van en una fila (dos columnas), el título es una línea sin pista y el texto tiene tres filas', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $s = rvcGet($app, $p);

    $pair = (int) strpos($s, 'class="af-rv-pair"');
    $name = (int) strpos($s, 'id="af-rv-name"');
    $email = (int) strpos($s, 'id="af-rv-email"');
    $title = (int) strpos($s, 'id="af-rv-title"');
    check($pair > 0 && $pair < $name && $name < $email && $email < $title, 'fila de nombre y correo antes del título');
    contains('rows="3"', $s);
    notContains('rows="5"', $s);
    same(1, substr_count($s, 'class="af-hint"'), 'la única pista es la del correo');
    contains('Título (opcional)', $s);
    check(strpos($s, 'af-rv-stars') < $pair, 'las estrellas van primero');
});

test('los nombres de los campos no han cambiado', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $s = rvcGet($app, $p);

    foreach (['name', 'email', 'rating', 'title', 'body', 'website'] as $field) {
        contains('name="' . $field . '"', $s);
    }
    contains('data-af-review-form', $s);
    contains('method="post"', $s);
});

test('un texto largo se recorta con «Leer más» y sigue entero en el HTML (sin JavaScript se ve completo)', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $long = str_repeat('Muy buena calidad y llegó rápido. ', 12) . 'FINAL-DEL-TEXTO';
    rvcPublish($app, $p, 1, ['body' => $long]);
    $s = rvcGet($app, $p);

    contains('af-rv-clamp', $s);
    contains('data-af-more', $s);
    contains('aria-expanded="false"', $s);
    contains('>Leer más</button>', $s);
    contains('data-less="Leer menos"', $s);
    contains('FINAL-DEL-TEXTO', $s);
    contains('reviews.js', $s);
    check(preg_match('/<button class="af-rv-more" type="button" data-af-more hidden /', $s) === 1, 'el botón nace oculto: sin JavaScript no sale');
    check(preg_match('/aria-controls="(af-rv-body-\d+)"/', $s, $m) === 1 && strpos($s, 'id="' . $m[1] . '"') !== false, 'el botón apunta al texto');
});

test('un texto con muchos saltos de línea también se recorta y uno corto no lleva botón ni guion', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    rvcPublish($app, $p, 1, ['body' => "Uno\nDos\nTres\nCuatro\nCinco\nSeis"]);
    contains('data-af-more', rvcGet($app, $p));

    $app2 = reviewsApp();
    $q = aifecProduct($app2);
    rvcPublish($app2, $q, 1, ['body' => 'Un texto corto pero suficiente.']);
    $short = rvcGet($app2, $q);
    notContains('data-af-more', $short);
    notContains('af-rv-clamp', $short);
    notContains('reviews.js', $short);
});

test('la respuesta de la tienda sale sangrada y la compra verificada se marca', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    reviewsPaidOrder($app, $p, 'ana@cliente.test');
    $id = reviewsSubmit($app, $p, ['body' => 'Compra comprobada y muy contenta.'])['id'];
    $app->service('reviews')->publish($id);
    $app->service('reviews')->reply($id, "Gracias\nVuelve pronto");
    $s = rvcGet($app, $p);

    contains('class="af-rv-reply"', $s);
    contains('Gracias<br />', $s);
    contains('<span class="af-rv-ok">Compra verificada</span>', $s);
    same(1, substr_count($s, 'Compra verificada'));
});

test('un nombre con script y con «&» sale escapado en la lista', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    rvcPublish($app, $p, 1, ['name' => 'Eva <script>alert(1)</script> & Co', 'title' => 'A & B <b>', 'body' => 'Texto con <img src=x onerror=alert(2)> y & más.']);
    $s = rvcGet($app, $p);

    contains('Eva &lt;script&gt;alert(1)&lt;/script&gt; &amp; Co', $s);
    contains('A &amp; B &lt;b&gt;', $s);
    contains('&lt;img src=x onerror=alert(2)&gt; y &amp; más.', $s);
    notContains('<script>alert', $s);
    notContains('<img src=x', $s);
});

test('la hoja pesa 3000 bytes o menos', function (): void {
    check(is_file(__DIR__ . '/../assets/reviews.css'), 'la hoja existe');
    check(filesize(__DIR__ . '/../assets/reviews.css') <= 3000, 'pesa ' . filesize(__DIR__ . '/../assets/reviews.css') . ' bytes');
});

test('la hoja no lleva colores escritos a mano, ni !important, ni fuentes ni direcciones de fuera', function (): void {
    $code = rvcCssCode();

    check(strpos($code, '#') === false, 'ningún # fuera de los comentarios');
    notContains('!important', $code);
    notContains('url(', $code);
    notContains('@import', $code);
    notContains('@font-face', $code);
    notContains('http', $code);
    check(preg_match('/\b(rgb|rgba|hsl|hsla)\(/i', $code) !== 1, 'sin rgb() ni hsl()');
    check(preg_match('/:\s*(white|black|red|green|blue|gray|grey|orange|yellow)\b/i', $code) !== 1, 'sin colores con nombre');
    contains('var(--star)', $code);
    contains('var(--line)', $code);
    contains('var(--accent)', $code);
});

test('la hoja usa solo variables que todos los temas definen', function (): void {
    preg_match_all('/var\((--[a-z0-9-]+)\)/', rvcCssCode(), $m);
    $known = ['--ink', '--muted', '--line', '--ok', '--radius', '--accent', '--accent-ink', '--star'];
    foreach (array_unique($m[1]) as $var) {
        check(in_array($var, $known, true), 'la variable ' . $var . ' no es de las del contrato de temas');
    }
    foreach (['base', 'aifec', 'atelier', 'despensa', 'gmartos', 'taller'] as $theme) {
        $css = (string) file_get_contents(__DIR__ . '/../../../themes/' . $theme . '/assets/css/theme.css');
        check(strpos($css, '--star:') !== false, 'el tema ' . $theme . ' define --star');
    }
});

test('la hoja cuida el móvil: áreas táctiles de 44 px, desborde controlado y el escritorio solo con media query', function (): void {
    $code = rvcCssCode();

    contains('min-height:44px', $code);
    contains('overflow-wrap:anywhere', $code);
    contains('@media (min-width:48em)', $code);
    contains('line-clamp:4', $code);
    contains(':focus-visible', $code);
});

test('la hoja se enlaza en la ficha y en la página de errores de escribir, y no en la portada ni en una categoría', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $c = aifecCategory($app, 'Camisetas');
    $web = new TestClient($app);

    $product = $web->get(rvcUrl($app, $p))->body;
    contains('reviews.css', $product);
    same(1, substr_count($product, 'reviews.css'));

    $write = reviewsPost($web, $p, ['body' => 'x']);
    same(422, $write->status);
    contains('reviews.css', $write->body);
    notContains('af-rv-write', $write->body, 'la página de escribir no lleva el desplegable');
    contains('af-rv-star', $write->body, 'pero sí las estrellas');
    notContains('<select', $write->body);

    notContains('reviews.css', $web->get('/')->body);
    $category = $web->get($app->url()->entity('category', $c, $app->lang()));
    same(200, $category->status);
    notContains('reviews.css', $category->body);
});

test('la hoja y el guion se sirven desde la dirección de recursos del módulo', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    rvcPublish($app, $p, 1, ['body' => str_repeat('Texto largo de prueba. ', 20)]);
    $s = rvcGet($app, $p);

    check(preg_match('~href="[^"]*/assets/m/reviews/[a-z0-9]+/reviews\.css"~', (new TestClient($app))->get(rvcUrl($app, $p))->body) === 1, 'la hoja');
    check(preg_match('~src="[^"]*/assets/m/reviews/[a-z0-9]+/reviews\.js" defer~', $s) === 1, 'el guion, diferido');
    check(is_file(__DIR__ . '/../assets/reviews.js'), 'el guion existe');
});

test('el guion solo mejora: no hace nada sin el botón y usa aria-expanded y delegación', function (): void {
    $js = (string) file_get_contents(__DIR__ . '/../assets/reviews.js');

    contains('data-af-more', $js);
    contains('aria-expanded', $js);
    contains("document.addEventListener('click'", $js);
    contains('hidden = false', $js);
    notContains('eval(', $js);
    notContains('innerHTML', $js);
});

test('los textos nuevos tienen su traducción castellana con tildes', function (): void {
    $lang = require __DIR__ . '/../lang/es.php';

    same('Ver el desglose', $lang['View the breakdown']);
    same('Leer más', $lang['Read more']);
    same('Leer menos', $lang['Read less']);
    same('Todavía no hay valoraciones', $lang['No reviews yet']);
    check(!isset($lang['Choose']), 'sobra «Choose»: el desplegable ya no existe');
});

test('los datos estructurados de la ficha no cambian: la valoración agregada sigue en el producto', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    rvcPublish($app, $p, 2, ['rating' => '4']);

    same(['average' => 4.0, 'count' => 2], $app->service('catalog')->product($p)['rating']);
});

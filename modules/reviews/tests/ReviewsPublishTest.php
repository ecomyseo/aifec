<?php
/**
 * AIFEC — pruebas de las valoraciones: publicar, retirar, la media del producto, el reparto, la lista y la respuesta de la tienda.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('publicar recalcula la media y la cuenta del producto y la ficha del catálogo las enseña; retirar las quita', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta']);
    $reviews = $app->service('reviews');
    $a = reviewsSubmit($app, $p, ['rating' => '5', 'email' => 'a@cliente.test'])['id'];
    $b = reviewsSubmit($app, $p, ['rating' => '4', 'email' => 'b@cliente.test'])['id'];
    $c = reviewsSubmit($app, $p, ['rating' => '2', 'email' => 'c@cliente.test'])['id'];
    same(null, $app->service('catalog')->product($p)['rating'], 'ninguna publicada todavía');

    same(true, $reviews->publish($a));
    same(['average' => 5.0, 'count' => 1], $app->service('catalog')->product($p)['rating']);
    same(true, $reviews->publish($b));
    same(['average' => 4.5, 'count' => 2], $app->service('catalog')->product($p)['rating']);
    same(true, $reviews->publish($c));
    same(['average' => 3.7, 'count' => 3], $app->service('catalog')->product($p)['rating']);
    same(11, (int) $app->db()->value('SELECT rating_sum FROM {products} WHERE id = ?', [$p]));

    same(true, $reviews->reject($a));
    same(['average' => 3.0, 'count' => 2], $app->service('catalog')->product($p)['rating']);
    same(true, $reviews->reject($b));
    same(true, $reviews->reject($c));
    same(null, $app->service('catalog')->product($p)['rating'], 'sin ninguna publicada la ficha no enseña nota');
    same(0, (int) $app->db()->value('SELECT rating_count FROM {products} WHERE id = ?', [$p]));
});

test('la media sale siempre de una suma sobre las publicadas: un valor estropeado se corrige solo al publicar', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app, ['rating_sum' => 999, 'rating_count' => 77]);
    $id = reviewsSubmit($app, $p, ['rating' => '3'])['id'];

    $app->service('reviews')->publish($id);

    same(3, (int) $app->db()->value('SELECT rating_sum FROM {products} WHERE id = ?', [$p]));
    same(1, (int) $app->db()->value('SELECT rating_count FROM {products} WHERE id = ?', [$p]));
});

test('publicar dos veces solo cuenta la primera y la acción review.published salta una vez con la opinión', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $seen = [];
    $app->hooks()->on('review.published', static function (array $review) use (&$seen): void {
        $seen[] = $review;
    });
    $id = reviewsSubmit($app, $p, ['rating' => '4'])['id'];

    same(true, $app->service('reviews')->publish($id));
    same(false, $app->service('reviews')->publish($id), 'ya estaba publicada');
    same(false, $app->service('reviews')->publish(99999), 'no existe');

    same(1, count($seen));
    same($id, $seen[0]['id']);
    same($p, $seen[0]['product_id']);
    same('published', $seen[0]['status']);
    same(1, (int) $app->db()->value('SELECT rating_count FROM {products} WHERE id = ?', [$p]), 'no se cuenta dos veces');
    same(false, $app->service('reviews')->reject(99999));
    same(true, $app->service('reviews')->reject($id));
    same(false, $app->service('reviews')->reject($id), 'ya estaba rechazada');
});

test('una opinión rechazada se puede volver a publicar', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $id = reviewsSubmit($app, $p, ['rating' => '1'])['id'];
    $app->service('reviews')->reject($id);
    same(0, $app->service('reviews')->forProduct($p)['count']);

    same(true, $app->service('reviews')->publish($id));
    same(1, $app->service('reviews')->forProduct($p)['count']);
    same(['average' => 1.0, 'count' => 1], $app->service('catalog')->product($p)['rating']);
});

test('forProduct da el reparto por estrellas, la media y solo las publicadas, con las verificadas primero y paginadas de diez en diez', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'stock' => 50]);
    $reviews = $app->service('reviews');
    for ($i = 1; $i <= 12; ++$i) {
        $id = reviewsSubmit($app, $p, ['rating' => (string) (($i % 5) + 1), 'email' => 'u' . $i . '@cliente.test', 'name' => 'Cliente ' . $i])['id'];
        $reviews->publish($id);
        $app->clock()->advance(60);
    }
    // La última publicada es de compra verificada: sale la primera aunque no sea la más nueva de las verificadas.
    reviewsPaidOrder($app, $p, 'verificada@cliente.test');
    $verified = reviewsSubmit($app, $p, ['rating' => '5', 'email' => 'verificada@cliente.test', 'name' => 'Verificada'])['id'];
    $app->clock()->advance(60);
    $reviews->publish($verified);
    reviewsSubmit($app, $p, ['email' => 'pendiente@cliente.test']);

    $first = $reviews->forProduct($p);
    same(13, $first['total']);
    same(13, $first['count']);
    same(3, $first['pages']);
    same(5, count($first['items']));
    same('Verificada', $first['items'][0]['name'], 'las verificadas, primero');
    same(true, $first['items'][0]['verified']);
    same(false, $first['items'][1]['verified']);
    same('Cliente 12', $first['items'][1]['name'], 'después, de más nueva a más vieja');
    $second = $reviews->forProduct($p, 2);
    same(5, count($second['items']));
    same(2, $second['page']);
    same(3, count($reviews->forProduct($p, 99)['items']), 'una página que no existe se queda en la última');
    same(5, count($reviews->forProduct($p, -4)['items']), 'una página negativa se queda en la primera');

    $sum = 0;
    $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    for ($i = 1; $i <= 12; ++$i) {
        $r = ($i % 5) + 1;
        $sum += $r;
        ++$dist[$r];
    }
    $sum += 5;
    ++$dist[5];
    same($dist, $first['distribution']);
    same(round($sum / 13, 1), $first['average']);
});

test('la respuesta de la tienda se guarda como texto plano, se puede quitar y no pasa de 1.000 caracteres', function (): void {
    $app = reviewsApp();
    $p = aifecProduct($app);
    $id = reviewsSubmit($app, $p)['id'];
    $app->service('reviews')->publish($id);

    same(true, $app->service('reviews')->reply($id, "  Gracias <b>Ana</b> & hasta pronto\r\nUn saludo  "));
    $row = reviewsRows($app)[0];
    same("Gracias <b>Ana</b> & hasta pronto\nUn saludo", $row['reply']);
    same($app->clock()->sql(), $row['replied_at']);
    same(reviewsReplyShown($app, $p), "Gracias <b>Ana</b> & hasta pronto\nUn saludo", 'forProduct devuelve la respuesta');

    same(true, $app->service('reviews')->reply($id, ''));
    same(null, reviewsRows($app)[0]['reply']);
    same(null, reviewsRows($app)[0]['replied_at']);

    throws(function () use ($app, $id): void {
        $app->service('reviews')->reply($id, str_repeat('r', 1001));
    }, \InvalidArgumentException::class);
    same(false, $app->service('reviews')->reply(99999, 'Hola'));
});

/** La respuesta de la primera opinión de la lista pública. */
function reviewsReplyShown(\Aifec\Kernel\App $app, int $productId): string
{
    $items = $app->service('reviews')->forProduct($productId)['items'];

    return (string) $items[0]['reply'];
}

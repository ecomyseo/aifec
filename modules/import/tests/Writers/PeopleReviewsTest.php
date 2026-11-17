<?php
/**
 * AIFEC — escritor de opiniones: crea con la fecha del origen, recalcula la media del producto como el módulo, repite sin cambios,
 * actualiza, choca con lo editado aquí, no entra sin producto ni con una nota fuera de rango ni con un segundo del mismo correo,
 * limpia el texto, no pide ninguna valoración por correo y no cambia nada en un simulacro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Writers\OrdersWriter;
use Aifec\Modules\Import\Writers\ReviewsWriter;

require_once __DIR__ . '/WritersKit2.php';

/** Una opinión neutra: la de ejemplo con cambios. */
function pwReview(array $over = []): array
{
    return wr('reviews', $over);
}

/** La suma y la cuenta de opiniones publicadas que guarda el producto. */
function pwRating(App $app, int $productId): array
{
    $row = $app->db()->row('SELECT rating_sum, rating_count FROM {products} WHERE id = ?', [$productId]);

    return [(int) $row['rating_sum'], (int) $row['rating_count']];
}

pwTest('la opinión de ejemplo se escribe con la fecha del origen y el producto recalcula su media', function (): void {
    [$app, $transport, $ctx, $product, $customer] = pwOrderSite();
    $out = (new ReviewsWriter($app))->write(pwReview(), $ctx);
    same(TypeWriter::CREATED, $out['result']);
    $row = $app->db()->find('reviews', ['id' => $out['id']]);

    same([$product, $customer, 'Ana R.', 'ana@cliente.test', 5], [(int) $row['product_id'], (int) $row['customer_id'], $row['name'], $row['email'], (int) $row['rating']]);
    same(['Muy bien acabada', 'Llegó en dos días y sin un golpe.', 'es', 'published', 1], [$row['title'], $row['body'], $row['lang'], $row['status'], (int) $row['verified']]);
    same(['2026-10-10 18:00:00', '2026-10-10 18:00:00', null], [$row['created_at'], $row['published_at'], $row['reply']], 'con la fecha de la opinión, no la de hoy');
    same([5, 1], pwRating($app, $product), 'la suma y la cuenta del producto');
    same(['average' => 5.0, 'count' => 1], aifecCatalog($app)->product($product, 'es')['rating'], 'y la ficha del catálogo ya lo enseña');
    same(0, wrCount($app, 'review_requests'), 'no se pide ninguna valoración');
    pwSilent($app, $transport, 'tras escribir una opinión');
});

pwTest('una opinión de un comprador verificado queda unida a su pedido', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $order = (int) (new OrdersWriter($app))->write(wr('orders'), $ctx)['id'];
    $out = (new ReviewsWriter($app))->write(pwReview(), $ctx);
    same($order, (int) wrValue($app, 'SELECT order_id FROM {reviews} WHERE id = ?', [$out['id']]), 'con el pedido pagado de ese correo y ese producto');
    $other = (new ReviewsWriter($app))->write(pwReview(['id' => '16', 'customer_id' => null, 'verified' => false, 'author' => 'Otro', 'extra' => ['email' => 'otro@cliente.test']]), $ctx);
    same(null, wrValue($app, 'SELECT order_id FROM {reviews} WHERE id = ?', [$other['id']]), 'una sin verificar no lleva pedido');
});

pwTest('repetir la opinión no cambia ninguna tabla; cambiarla en el origen la actualiza y recalcula', function (): void {
    [$app, , $ctx, $product] = pwOrderSite();
    $writer = new ReviewsWriter($app);
    $record = pwReview();
    $id = (int) $writer->write($record, $ctx)['id'];
    $counts = wrCounts($app);

    same(TypeWriter::UNCHANGED, $writer->write($record, $ctx)['result']);
    same($counts, wrCounts($app));
    same(TypeWriter::UPDATED, $writer->write(pwReview(['rating' => 3, 'body' => 'Al final, regular.']), $ctx)['result']);
    same($counts, wrCounts($app), 'se actualiza en su sitio');
    same(['Al final, regular.', 3], [wrValue($app, 'SELECT body FROM {reviews} WHERE id = ?', [$id]), (int) wrValue($app, 'SELECT rating FROM {reviews} WHERE id = ?', [$id])]);
    same([3, 1], pwRating($app, $product), 'la media se recalcula, no se suma encima');
});

pwTest('una opinión editada aquí después de traerla choca; overwrite_local la pisa; el simulacro no escribe nada', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $writer = new ReviewsWriter($app);
    $id = (int) $writer->write(pwReview(), $ctx)['id'];
    $app->clock()->advance(300);
    $app->db()->update('reviews', ['reply' => 'Gracias.', 'updated_at' => $app->clock()->sql()], ['id' => $id]);
    $app->clock()->advance(300);

    $out = $writer->write(pwReview(['rating' => 2]), $ctx);
    same(TypeWriter::CONFLICT, $out['result']);
    same(5, (int) wrValue($app, 'SELECT rating FROM {reviews} WHERE id = ?', [$id]), 'lo de aquí queda intacto');

    $dry = writersContext($app)->dry(true);
    $counts = wrCounts($app);
    same(TypeWriter::CREATED, $writer->write(pwReview(['id' => '99', 'extra' => ['email' => 'nuevo@cliente.test'], 'customer_id' => null]), $dry)['result']);
    same(TypeWriter::CONFLICT, $writer->write(pwReview(['rating' => 2]), $dry)['result'], 'el simulacro decide lo mismo');
    same($counts, wrCounts($app));

    $over = writersContext($app, ['overwrite_local' => true]);
    same(TypeWriter::UPDATED, $writer->write(pwReview(['rating' => 2]), $over)['result']);
    same(2, (int) wrValue($app, 'SELECT rating FROM {reviews} WHERE id = ?', [$id]));
});

pwTest('sin producto traído, con una nota fuera de rango o con un segundo del mismo correo para el mismo producto, la opinión no entra', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $writer = new ReviewsWriter($app);

    $none = $writer->write(pwReview(['product_id' => '999']), $ctx);
    same(TypeWriter::SKIPPED, $none['result']);
    contains('product', $none['reason']);
    $bad = $writer->write(pwRaw('reviews', ['rating' => 9]), $ctx);
    same(TypeWriter::SKIPPED, $bad['result']);
    contains('rating', $bad['reason']);
    same(0, wrCount($app, 'reviews'), 'y no queda nada a medias');

    same(TypeWriter::CREATED, $writer->write(pwReview(), $ctx)['result']);
    $second = $writer->write(pwReview(['id' => '16']), $ctx);
    same(TypeWriter::SKIPPED, $second['result']);
    contains('already a review', $second['reason']);
    same(1, wrCount($app, 'reviews'));
});

pwTest('una opinión anónima lleva un correo inventado que no recibe nada y su texto sale sin etiquetas', function (): void {
    [$app, $transport, $ctx, $product] = pwOrderSite();
    $record = pwReview(['id' => '16', 'customer_id' => null, 'author' => 'Visitante', 'status' => 'pending', 'verified' => false,
        'title' => 'Bien <b>y</b> barato', 'body' => '<p>Muy <b>bueno</b> &amp; barato</p><script>alert(1)</script>']);
    $out = (new ReviewsWriter($app))->write($record, $ctx);
    $row = $app->db()->find('reviews', ['id' => $out['id']]);
    same('review-16@import.invalid', $row['email']);
    same(['Visitante', 'pending', null, 0], [$row['name'], $row['status'], $row['published_at'], (int) $row['verified']]);
    notContains('<', (string) $row['body'] . (string) $row['title'], 'sin etiquetas');
    notContains('alert', (string) $row['body']);
    contains('Muy bueno & barato', (string) $row['body']);
    same([0, 0], pwRating($app, $product), 'una pendiente no cuenta en la media');
    pwSilent($app, $transport, 'tras una opinión anónima');
});

pwTest('solo cuentan en la media las publicadas, y la respuesta de la tienda llega con su fecha', function (): void {
    [$app, , $ctx, $product] = pwOrderSite();
    $writer = new ReviewsWriter($app);
    $writer->write(pwReview(['rating' => 5]), $ctx);
    $writer->write(pwReview(['id' => '16', 'customer_id' => null, 'extra' => ['email' => 'dos@cliente.test'], 'rating' => 3, 'status' => 'approved', 'reply' => 'Gracias por avisar.']), $ctx);
    $writer->write(pwReview(['id' => '17', 'customer_id' => null, 'extra' => ['email' => 'tres@cliente.test'], 'rating' => 1, 'status' => 'rejected']), $ctx);
    $writer->write(pwReview(['id' => '18', 'customer_id' => null, 'extra' => ['email' => 'cuatro@cliente.test'], 'rating' => 4, 'status' => 'pending']), $ctx);
    same([8, 2], pwRating($app, $product), '5 + 3 publicadas; la rechazada y la pendiente no cuentan');
    $reply = $app->db()->row('SELECT reply, replied_at FROM {reviews} WHERE rating = 3');
    same(['Gracias por avisar.', '2026-10-10 18:00:00'], [$reply['reply'], $reply['replied_at']]);
    same(['reviews_count' => 4], $writer->checksums($ctx));
});

<?php
/**
 * AIFEC — pruebas de la marca de la tienda: «Parte de este texto se redactó con ayuda de IA y fue revisado por una persona» solo
 * sale con el ajuste ai.show_notice encendido y con textos aprobados de ese producto, y aparece sola al aprobar (la caché de página
 * del producto se invalida).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * La dirección pública de un producto.
 */
function aiProductUrl(\Aifec\Kernel\App $app, int $id): string
{
    return $app->url()->entity('product', $id);
}

test('sin marcas la ficha no lleva la frase aunque el ajuste esté encendido, y con marcas no sale si el ajuste está apagado', function (): void {
    $app = aiApp(['ai.show_notice' => true]);
    $p = aiProduct($app, ['name' => 'Silla de roble', 'description' => '<p>Vieja</p>']);
    $web = new TestClient($app);
    $page = $web->get(aiProductUrl($app, $p));
    same(200, $page->status);
    notContains('ai-notice', $page->body, 'ningún texto aprobado todavía');
    notContains('redactó con ayuda de IA', $page->body);

    $off = aiApp(['ai.show_notice' => false]);
    $q = aiProduct($off, ['name' => 'Silla de roble', 'description' => '<p>Vieja</p>']);
    $ai = $off->service('ai');
    $ai->proposals()->approve($ai->propose('product', $q, 'description', 'es', '<p>Nueva</p>'));
    same(1, count(aiRows($off, 'ai_marks')));
    $page = (new TestClient($off))->get(aiProductUrl($off, $q));
    same(200, $page->status);
    notContains('redactó con ayuda de IA', $page->body, 'el ajuste está apagado');
});

test('con el ajuste encendido y un texto aprobado la ficha lleva la frase, solo la de ese producto, y aparece al aprobar aunque la página estuviera en caché', function (): void {
    $app = aiApp(['ai.show_notice' => true]);
    $p = aiProduct($app, ['name' => 'Silla de roble', 'description' => '<p>Vieja</p>']);
    $other = aiProduct($app, ['name' => 'Mesa de pino', 'description' => '<p>Otra</p>']);
    $web = new TestClient($app);
    notContains('redactó con ayuda de IA', $web->get(aiProductUrl($app, $p))->body);
    notContains('redactó con ayuda de IA', $web->get(aiProductUrl($app, $p))->body, 'ya guardada en la caché de página');

    $ai = $app->service('ai');
    $ai->proposals()->approve($ai->propose('product', $p, 'description', 'es', '<p>Descripción nueva</p>'));

    $page = $web->get(aiProductUrl($app, $p));
    contains('<p class="ai-notice">Parte de este texto se redactó con ayuda de IA y fue revisado por una persona.</p>', $page->body);
    contains('Descripción nueva', $page->body);
    notContains('redactó con ayuda de IA', $web->get(aiProductUrl($app, $other))->body, 'otro producto sin marcas');

    // Una propuesta rechazada no deja marca.
    $ai->proposals()->reject($ai->propose('product', $other, 'description', 'es', '<p>Rechazada</p>'));
    notContains('redactó con ayuda de IA', $web->get(aiProductUrl($app, $other))->body);
});

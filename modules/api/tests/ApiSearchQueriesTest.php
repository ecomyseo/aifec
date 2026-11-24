<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/*
 * Auditoría externa del 24/11/2026 sobre /api/v1/search en gmartos.es:
 *   - «aviso stock» no encontraba «Aviso de usuarios que esperan stock»;
 *   - «horas soporte» devolvía otros productos que mencionan el soporte y no los packs de horas (que «pack horas soporte» sí encontraba);
 *   - «transportista» decía total=6 y devolvía 5 elementos, sin explicar por qué.
 * Productos inventados con la misma forma.
 */

/** @return array<string,int> */
function apiSearchShop(\Aifec\Kernel\App $app): array
{
    $ids = [];
    $ids['aviso'] = aifecProduct($app, ['name' => 'Aviso de usuarios que esperan stock', 'price' => 6000, 'images' => 1, 'stock' => 99,
        'summary' => 'Módulo que avisa por correo a quien espera un producto agotado.']);
    // Como en gmartos.es: los packs hablan de «asistencia técnica», no de «soporte».
    $ids['pack10'] = aifecProduct($app, ['name' => '10 Horas - Asistencia técnica a PrestaShop', 'price' => 50000, 'images' => 1, 'stock' => 99,
        'summary' => 'Los paquetes de asistencia técnica de 10 horas cubren la gama completa de requisitos.']);
    $ids['pack5'] = aifecProduct($app, ['name' => '5 Horas - Asistencia técnica por packs', 'price' => 30000, 'images' => 1, 'stock' => 99,
        'summary' => 'Los paquetes de asistencia técnica de 5 horas cubren la gama completa de requisitos.']);
    $ids['desist'] = aifecProduct($app, ['name' => 'Desistimiento', 'price' => 7000, 'images' => 1, 'stock' => 99,
        'summary' => 'Botón de desistimiento. Incluye soporte durante unas horas tras la instalación.']);
    $ids['abandcarts'] = aifecProduct($app, ['name' => 'Carritos abandonados', 'price' => 8000, 'images' => 1, 'stock' => 99,
        'description' => 'Recupera ventas. Soporte por correo en horas laborables.']);
    for ($i = 1; $i <= 7; ++$i) {
        $ids['tr' . $i] = aifecProduct($app, ['name' => 'Transportista ' . $i, 'price' => 1000 + $i, 'images' => 1, 'stock' => 99,
            'summary' => 'Conexión con el transportista ' . $i . '.']);
    }
    searchService($app)->reindexNow();

    return $ids;
}

/** @return int[] */
function apiSearchIds(\Aifec\Kernel\App $app, string $q, string $extra = ''): array
{
    $json = apiJson(apiCall($app, 'GET', '/api/v1/search?q=' . rawurlencode($q) . $extra));

    return array_map('intval', array_column($json['data'], 'id'));
}

test('«aviso stock» encuentra «Aviso de usuarios que esperan stock»', function (): void {
    $app = apiApp();
    $ids = apiSearchShop($app);
    check(in_array($ids['aviso'], apiSearchIds($app, 'aviso stock'), true), 'el producto sale');
    same($ids['aviso'], apiSearchIds($app, 'aviso stock')[0] ?? 0, 'y el primero');
});

test('«horas soporte» devuelve los packs de horas antes que los productos que solo mencionan el soporte', function (): void {
    $app = apiApp();
    $ids = apiSearchShop($app);
    $found = apiSearchIds($app, 'horas soporte');
    $top = array_slice($found, 0, 2);
    sort($top);
    $packs = [$ids['pack10'], $ids['pack5']];
    sort($packs);
    same($packs, $top, 'los dos packs van primero: ' . implode(',', $found));
});

test('una coincidencia solo por la descripción larga con varias palabras no se da por exacta, y las notas salen en castellano', function (): void {
    // Verificación del 24/11/2026: «pasarela de pago» devolvía «exact» con una ficha que lo dice a mitad de su descripción; y las notas
    // salían en inglés.
    $app = apiApp();
    aifecProduct($app, ['name' => 'Diagnóstico de tienda', 'price' => 5000, 'images' => 1, 'stock' => 9,
        'summary' => 'Revisión completa de tu tienda.', 'description' => 'Incluye una compra de prueba hasta la pasarela de pago, sin pagar.']);
    aifecProduct($app, ['name' => 'Pasarela de pago con tarjeta', 'price' => 9000, 'images' => 1, 'stock' => 9, 'summary' => 'Cobra con tarjeta.']);
    searchService($app)->reindexNow();
    $names = array_column(apiJson(apiCall($app, 'GET', '/api/v1/search?q=' . rawurlencode('pasarela de pago')))['data'], 'name');
    same('Pasarela de pago con tarjeta', $names[0] ?? '', 'lo que lo lleva en el nombre sigue siendo la respuesta');
    $only = apiApp();
    aifecProduct($only, ['name' => 'Diagnóstico de tienda', 'price' => 5000, 'images' => 1, 'stock' => 9,
        'summary' => 'Revisión completa de tu tienda.', 'description' => 'Incluye una compra de prueba hasta la pasarela de pago, sin pagar.']);
    searchService($only)->reindexNow();
    $meta = apiJson(apiCall($only, 'GET', '/api/v1/search?q=' . rawurlencode('pasarela de pago')))['meta'];
    check($meta['answer_kind'] !== 'exact', 'solo por la descripción no es exacta: ' . $meta['answer_kind']);
    $none = apiJson(apiCall($only, 'GET', '/api/v1/search?q=zzzqqq'))['meta'];
    same('Nada del catálogo coincide con esta búsqueda.', $none['note'] ?? '', 'la nota, en castellano');
    notContains('Nothing in the catalogue', (string) json_encode($none));
});

test('el total y los datos cuadran, o la respuesta dice que están limitados y a cuánto', function (): void {
    $app = apiApp();
    apiSearchShop($app);
    $json = apiJson(apiCall($app, 'GET', '/api/v1/search?q=transportista'));
    check($json['meta']['total'] >= 7, 'hay más resultados que el tope: ' . $json['meta']['total']);
    same(5, count($json['data']), 'por defecto cinco');
    same(5, $json['meta']['limit'], 'y la respuesta dice el límite');
    same(5, $json['meta']['returned'], 'y cuántos devuelve');
    same(8, $json['meta']['limit_max'], 'y el tope que se puede pedir');
    same(true, $json['meta']['has_more'], 'y que hay más');
    contains('límite', $json['meta']['note'], 'con una frase que lo explica');
    $all = apiJson(apiCall($app, 'GET', '/api/v1/search?q=transportista&limit=8'));
    same(7, count($all['data']), 'con limit=8 caben los siete');
    same(7, $all['meta']['total']);
    same(false, $all['meta']['has_more'], 'y ya no hay más');
    $few = apiJson(apiCall($app, 'GET', '/api/v1/search?q=' . rawurlencode('aviso stock')));
    same($few['meta']['total'], count($few['data']), 'cuando caben todos, cuadran');
    same(false, $few['meta']['has_more']);
});

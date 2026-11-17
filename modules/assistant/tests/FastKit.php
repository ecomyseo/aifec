<?php
/**
 * AIFEC — ayudas de las pruebas de P085 (asistente rápido y certero). No es una prueba (no acaba en «Test.php»): se carga con
 * require_once.
 *
 *   fastShop(array $ajustes = []): array
 *       [App, ids, AssistantFakeAi, TestClient, jamones]: la tienda pequeña de assistantShop() más cinco jamones ibéricos, ya
 *       indexada, con una IA de mentira y un navegador nuevo.
 *
 *   fastCardIds(array $respuesta): int[]
 *       Los identificadores de las tarjetas de una respuesta.
 *
 *   fastChatShop(): array
 *       [App, AssistantFakeAi, TestClient, ids]: la tienda de la compra en el chat (chatApp) con IA de mentira y la silla roja ya
 *       en el carrito de un navegador nuevo.
 *
 *   fastBlocks(string $html): array
 *       Los bloques de una página del módulo de contenidos con ese HTML en un párrafo.
 *
 *   fastShoe(App $app): int
 *       Una zapatilla con tres colores: negro y rojo con existencias y azul agotado.
 *
 *   fastShirt(App $app): int
 *       Una camiseta con dos opciones (tamaño y tono): negro M, negro L y rojo M.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/OrderChatKit.php';
require_once __DIR__ . '/../../cms/tests/helpers.php';

if (!function_exists('fastShop')) {
    /**
     * @param array<string,mixed> $ajustes
     *
     * @return array{0:App,1:array<string,int>,2:AssistantFakeAi,3:TestClient,4:int[]}
     */
    function fastShop(array $ajustes = []): array
    {
        $app = assistantApp($ajustes);
        $ids = assistantShop($app);
        $jamones = [];
        foreach (['Jamón ibérico de bellota Reserva', 'Jamón ibérico de bellota Gran Reserva', 'Jamón ibérico de bellota paleta', 'Jamón ibérico de bellota cortado a mano', 'Jamón ibérico de bellota en lonchas'] as $i => $name) {
            $jamones[] = aifecProduct($app, ['name' => $name, 'summary' => 'Curado más de tres años, con denominación de origen.', 'price' => 9900 + $i * 1000, 'stock' => 5]);
        }
        searchService($app)->reindexNow();
        $ai = assistantAi($app);

        return [$app, $ids, $ai, new TestClient($app), $jamones];
    }

    /**
     * @param array<string,mixed> $r
     *
     * @return int[]
     */
    function fastCardIds(array $r): array
    {
        return array_map('intval', array_column($r['cards'], 'id'));
    }

    /**
     * @param array<string,mixed> $ajustes
     *
     * @return array{0:App,1:AssistantFakeAi,2:TestClient,3:array<string,int>}
     */
    function fastChatShop(array $ajustes = []): array
    {
        $app = chatApp($ajustes);
        $ids = assistantShop($app);
        $ai = assistantAi($app);
        $web = new TestClient($app);
        chatCart($app, $web, $ids['silla_roja']);

        return [$app, $ai, $web, $ids];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    function fastBlocks(string $html): array
    {
        return [['type' => 'paragraph', 'attrs' => ['html' => $html]]];
    }

    function fastShoe(App $app): int
    {
        aifecAttribute($app, 'color', 'Color', ['negro' => 'Negro', 'rojo' => 'Rojo', 'azul' => 'Azul'], ['variant' => 1]);
        $id = aifecProduct($app, ['name' => 'Zapatilla Plana', 'price' => 4900, 'variants' => [
            ['sku' => 'ZP-N', 'label' => 'Negro', 'price' => 4900, 'stock' => 4, 'values' => ['color' => 'negro']],
            ['sku' => 'ZP-R', 'label' => 'Rojo', 'price' => 5200, 'stock' => 4, 'values' => ['color' => 'rojo']],
            ['sku' => 'ZP-A', 'label' => 'Azul', 'price' => 4900, 'stock' => 0, 'values' => ['color' => 'azul']],
        ]]);
        // Los valores nuevos de un atributo no pasan por el panel: se vacía la copia de los atributos que guarda el catálogo.
        $app->invalidate(['catalog']);
        searchService($app)->reindexNow();

        return $id;
    }

    function fastShirt(App $app): int
    {
        aifecAttribute($app, 'tono', 'Tono', ['negro' => 'Negro', 'rojo' => 'Rojo'], ['variant' => 1]);
        aifecAttribute($app, 'tam', 'Tamaño', ['m' => 'M', 'l' => 'L'], ['variant' => 1]);
        $id = aifecProduct($app, ['name' => 'Camiseta Básica', 'price' => 1900, 'variants' => [
            ['sku' => 'CB-NM', 'label' => 'Negro M', 'price' => 1900, 'stock' => 4, 'values' => ['tono' => 'negro', 'tam' => 'm']],
            ['sku' => 'CB-NL', 'label' => 'Negro L', 'price' => 1900, 'stock' => 4, 'values' => ['tono' => 'negro', 'tam' => 'l']],
            ['sku' => 'CB-RM', 'label' => 'Rojo M', 'price' => 1900, 'stock' => 4, 'values' => ['tono' => 'rojo', 'tam' => 'm']],
        ]]);
        // Los valores nuevos de un atributo no pasan por el panel: se vacía la copia de los atributos que guarda el catálogo.
        $app->invalidate(['catalog']);
        searchService($app)->reindexNow();

        return $id;
    }
}

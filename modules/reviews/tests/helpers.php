<?php
/**
 * AIFEC — ayudas de prueba de las valoraciones. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   reviewsApp(array $opciones = []): App
 *       Aplicación con los pedidos (y el catálogo, el correo, los medios y las tareas) y las valoraciones, con los ajustes que se
 *       pidan en $opciones['settings'].
 *
 *   reviewsData(array $cambios = []): array
 *       Los campos de una opinión válida tal como los manda el formulario de la ficha.
 *
 *   reviewsSubmit(App $app, int $productoId, array $cambios = []): array
 *       Llama al servicio «reviews» con una opinión válida de ana@cliente.test y devuelve lo que devuelve submit().
 *
 *   reviewsPaidOrder(App $app, int $productoId, string $correo = 'ana@cliente.test', int $unidades = 1): int
 *       Un pedido pagado de ese correo con ese producto; devuelve su id.
 *
 *   reviewsCompleted(App $app, int $productoId, string $correo = 'ana@cliente.test'): int
 *       Un pedido pagado que además pasa a «completado» por el servicio de pedidos (así salta la acción order.status).
 *
 *   reviewsRows(App $app, string $donde = '1 = 1', array $params = []): array
 *       Filas de la tabla reviews.
 *
 *   reviewsPost(TestClient $web, int $productoId, array $campos = []): Response
 *       El envío del formulario de la ficha, como lo manda un navegador.
 *
 *   reviewsCached(App $app, string $direccion): ?Response
 *       La copia de la caché de página de una dirección tal como la serviría el servidor antes de arrancar la aplicación, o null si no hay.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\PageCache;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

require_once __DIR__ . '/../../orders/tests/helpers.php';

if (!function_exists('reviewsApp')) {
    /**
     * @param array<string,mixed> $opciones
     */
    function reviewsApp(array $opciones = []): App
    {
        $opciones['modules'] = array_merge(['reviews'], isset($opciones['modules']) && is_array($opciones['modules']) ? $opciones['modules'] : []);

        return aifecOrderApp($opciones);
    }

    /**
     * @param array<string,mixed> $cambios
     *
     * @return array<string,mixed>
     */
    function reviewsData(array $cambios = []): array
    {
        return $cambios + [
            'name' => 'Ana', 'email' => 'ana@cliente.test', 'rating' => '5', 'title' => 'Muy buena', 'body' => 'La camiseta es justo lo que esperaba, muy buena calidad.',
        ];
    }

    /**
     * @param array<string,mixed> $cambios
     *
     * @return array<string,mixed>
     */
    function reviewsSubmit(App $app, int $productoId, array $cambios = []): array
    {
        return $app->service('reviews')->submit(['product_id' => $productoId] + reviewsData($cambios));
    }

    function reviewsPaidOrder(App $app, int $productoId, string $correo = 'ana@cliente.test', int $unidades = 1): int
    {
        return aifecOrderPaid($app, new TestClient($app), [[$productoId, 0, $unidades]], ['email' => $correo]);
    }

    function reviewsCompleted(App $app, int $productoId, string $correo = 'ana@cliente.test'): int
    {
        $id = reviewsPaidOrder($app, $productoId, $correo);
        check($app->service('orders')->setStatus($id, 'completed', '', null, false), 'el pedido pasa a completado');

        return $id;
    }

    /**
     * @param array<int,mixed> $params
     *
     * @return array<int,array<string,mixed>>
     */
    function reviewsRows(App $app, string $donde = '1 = 1', array $params = []): array
    {
        return $app->db()->select('SELECT * FROM {reviews} WHERE ' . $donde . ' ORDER BY id', $params);
    }

    /**
     * @param array<string,mixed> $campos
     */
    function reviewsPost(TestClient $web, int $productoId, array $campos = []): Response
    {
        return $web->post('/valoracion/' . $productoId, reviewsData($campos));
    }

    function reviewsCached(App $app, string $direccion): ?Response
    {
        $request = Request::create('GET', $direccion);
        $request->host = 'tienda.test';
        $request->secure = true;

        return PageCache::serve($app->site(), $request, $app->clock());
    }
}

<?php
/**
 * AIFEC — ayudas de prueba de las devoluciones. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   returnsApp(array $opciones = []): App
 *       Aplicación con los pedidos, el correo, los medios, las tareas, las facturas y las devoluciones, con la pasarela «gw» como
 *       cobro real. Acepta las opciones de aifecOrderApp() ('settings', 'modules'…).
 *
 *   returnsOrder(App $app, array $lineas, array $opciones = []): int
 *       Un pedido pagado y entregado y devuelve su id. $lineas como en aifecOrderPlace(): [[producto, variante, unidades], …].
 *       $opciones: days (hace cuántos días se entregó; 2), shipped_only (true = enviado pero sin fecha de entrega), state
 *       (processing: sin enviar; pending_payment: sin pagar), caja (datos de la caja).
 *
 *   returnsItemId(App $app, int $pedido, int $posicion = 0): int
 *       El id de la línea de producto que ocupa esa posición del pedido.
 *
 *   returnsPath(App $app, int $pedido): string
 *       La dirección de la página de devolución del pedido con su ficha: /devolucion/<número>/<ficha>.
 *
 *   returnsAsk(App $app, int $pedido, array $lineas, string $motivo = 'not_fit', string $comentario = ''): array
 *       Pide una devolución por el servicio, como lo haría el cliente. $lineas = [posición de la línea => unidades].
 *
 *   returnsUpload(App $app, TestClient $web, string $path, array $post, array $ficheros): Response
 *       Un POST con ficheros subidos, como lo manda un navegador (el cliente de pruebas no sabe de ficheros).
 *
 *   returnsImage(string $tipo = 'jpg', int $bytes = 2000): string
 *       La ruta de un fichero de pruebas que empieza con la firma de ese tipo de imagen (jpg, png, webp) o es un PHP disfrazado (php).
 *
 *   returnsFile(string $ruta, string $nombre = 'foto.jpg'): array
 *       El array de $_FILES de un fichero subido sin errores.
 *
 *   returnsAdmin(App $app, int $devolucion, string $papel = 'admin'): TestClient
 *       Un navegador de mentira con la sesión del panel y la ficha de la devolución abierta (para poder enviar acciones).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

require_once __DIR__ . '/../../orders/tests/FrontKit.php';

if (!function_exists('returnsApp')) {
    /**
     * @param array<string,mixed> $opciones
     */
    function returnsApp(array $opciones = []): App
    {
        $opciones['modules'] = array_merge(['returns', 'invoices'], isset($opciones['modules']) && is_array($opciones['modules']) ? $opciones['modules'] : []);
        $settings = isset($opciones['settings']) && is_array($opciones['settings']) ? $opciones['settings'] : [];
        // El módulo no viene encendido de serie (default: false): se enciende como lo haría el panel, con «modules.active».
        $active = array_merge($opciones['modules'], ['orders', 'catalog', 'customers', 'tax', 'shipping', 'coupons', 'mail', 'media', 'jobs']);
        $opciones['settings'] = $settings + [
            'modules.active' => array_values(array_unique($active)),
            'store.name' => 'Tienda de Pruebas', 'store.legal_name' => 'Tienda de Pruebas S.L.', 'store.vat_id' => 'B12345678',
            'store.address' => "Calle Mayor 1\n35001 Las Palmas de Gran Canaria", 'store.email' => 'tienda@ejemplo.test',
        ];
        $app = aifecOrderApp($opciones);
        aifecOrderPayment($app)->test = false;

        return $app;
    }

    /**
     * @param array<int,array<int,int>> $lineas
     * @param array<string,mixed>       $opciones
     */
    function returnsOrder(App $app, array $lineas, array $opciones = []): int
    {
        static $n = 0;
        ++$n;
        $caja = isset($opciones['caja']) && is_array($opciones['caja']) ? $opciones['caja'] : [];
        $placed = aifecOrderPlace($app, new TestClient($app), $lineas, $caja);
        check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);
        $id = (int) $placed['id'];
        $state = isset($opciones['state']) ? (string) $opciones['state'] : 'completed';
        if ($state === 'pending_payment') {
            return $id;
        }
        $order = $app->service('orders')->find($id);
        check($order !== null, 'el pedido existe');
        check($app->service('orders')->markPaid($id, 'gw', 'RET-' . $n, (int) $order['total']), 'el cobro se apunta');
        if ($state === 'processing') {
            return $id;
        }
        $days = isset($opciones['days']) ? (int) $opciones['days'] : 2;
        $now = $app->clock()->now();
        $set = ['status' => 'completed', 'fulfillment_status' => 'fulfilled', 'shipped_at' => $app->clock()->sql($now - ($days + 1) * 86400), 'completed_at' => $app->clock()->sql($now - $days * 86400)];
        if (!empty($opciones['shipped_only'])) {
            $set = ['status' => 'shipped', 'fulfillment_status' => 'fulfilled', 'shipped_at' => $app->clock()->sql($now - $days * 86400), 'completed_at' => null];
        }
        $app->db()->update('orders', $set, ['id' => $id]);

        return $id;
    }

    function returnsItemId(App $app, int $pedido, int $posicion = 0): int
    {
        $ids = $app->db()->column("SELECT id FROM {order_items} WHERE order_id = ? AND kind = 'product' ORDER BY position, id", [$pedido]);
        check(isset($ids[$posicion]), 'el pedido tiene una línea en la posición ' . $posicion);

        return (int) $ids[$posicion];
    }

    function returnsPath(App $app, int $pedido): string
    {
        $number = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$pedido]);
        $secret = $app->service('orders')->viewTokenOf($number);
        check($secret !== '', 'el pedido tiene ficha');

        return '/devolucion/' . $number . '/' . $secret;
    }

    /**
     * @param array<int,int> $lineas
     *
     * @return array<string,mixed>
     */
    function returnsAsk(App $app, int $pedido, array $lineas, string $motivo = 'not_fit', string $comentario = ''): array
    {
        $byId = [];
        foreach ($lineas as $pos => $qty) {
            $byId[returnsItemId($app, $pedido, (int) $pos)] = (int) $qty;
        }

        return $app->service('returns')->request($pedido, $byId, $motivo, $comentario);
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $ficheros
     */
    function returnsUpload(App $app, TestClient $web, string $path, array $post, array $ficheros): Response
    {
        $request = new Request('POST', $path, [], $post, $web->cookies, $web->headers, '', $ficheros, '127.0.0.1', 'tienda.test', true);
        $response = $app->handle($request);
        $web->last = $response;

        return $response;
    }

    function returnsImage(string $tipo = 'jpg', int $bytes = 2000): string
    {
        $heads = ['jpg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF", 'png' => "\x89PNG\r\n\x1A\n", 'webp' => 'RIFF' . "\x00\x00\x00\x00" . 'WEBPVP8 ', 'php' => '<?php echo "hola"; '];
        $path = tmpDir('aifec-ret') . '/foto-' . $tipo . '-' . $bytes . ($tipo === 'php' ? '.jpg' : '.' . $tipo);
        file_put_contents($path, $heads[$tipo] . str_repeat('x', max(0, $bytes - strlen($heads[$tipo]))));

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    function returnsFile(string $ruta, string $nombre = 'foto.jpg'): array
    {
        return ['name' => $nombre, 'type' => 'image/jpeg', 'tmp_name' => $ruta, 'error' => 0, 'size' => (int) filesize($ruta)];
    }

    function returnsAdmin(App $app, int $devolucion, string $papel = 'admin'): TestClient
    {
        $web = adminClient($app, $papel);
        $web->get('/admin/returns/' . $devolucion);

        return $web;
    }
}

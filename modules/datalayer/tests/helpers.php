<?php
/**
 * AIFEC — ayudas de prueba de la capa de datos. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   dlApp(array $opciones = []): App
 *       Aplicación con pedidos, catálogo, consentimiento, capa de datos y tareas. $opciones['settings'] = ajustes iniciales.
 *
 *   dlWeb(App $app, ?string $letras = null): TestClient
 *       Navegador de mentira; con $letras («s», «m», «sm»…), ya trae la cookie de la decisión de consentimiento.
 *
 *   dlOrder(App $app, TestClient $web, bool $pagar = true, array $caja = [], array $lineas = []): array
 *       Hace un pedido (por defecto, 2 unidades de un producto de 20,00 € y 1 de otro de 10,00 €) con la petición del navegador
 *       de mentira; con $pagar lo da por pagado. Devuelve ['id', 'number', 'secret', 'url' (la página del pedido)].
 *
 *   dlThanks(TestClient $web, array $pedido): Response
 *       Abre la página del pedido.
 *
 *   dlEvents(string $html): array
 *       Todos los eventos de los bloques «data-af-dl» de una página, en orden.
 *
 *   dlCents($numero): int
 *       Un importe de un evento (número decimal) en céntimos enteros.
 *
 *   dlHttp(App $app): FakeHttpClient
 *       El cliente HTTP de mentira de la aplicación.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\FakeHttpClient;
use Aifec\Kernel\App;
use Aifec\Kernel\Response;

require_once __DIR__ . '/../../orders/tests/helpers.php';

if (!function_exists('dlApp')) {
    /**
     * @param array<string,mixed> $opciones
     */
    function dlApp(array $opciones = []): App
    {
        $opciones['modules'] = array_merge(['datalayer', 'consent', 'jobs'], isset($opciones['modules']) && is_array($opciones['modules']) ? $opciones['modules'] : []);
        $opciones['http'] = new FakeHttpClient();
        $app = aifecOrderApp($opciones);
        $app->boot();

        return $app;
    }

    function dlWeb(App $app, ?string $letras = null): TestClient
    {
        $web = new TestClient($app);
        if ($letras !== null) {
            $web->cookies[$app->cookieName('consent')] = '1.' . $letras . '.' . $app->clock()->now() . '.9f2c4e1ab7d30586';
        }

        return $web;
    }

    /**
     * @param array<string,mixed>            $caja
     * @param array<int,array<int,int>>|null $lineas
     *
     * @return array{id:int,number:string,secret:string,url:string}
     */
    function dlOrder(App $app, TestClient $web, bool $pagar = true, array $caja = [], array $lineas = []): array
    {
        if ($lineas === []) {
            $a = aifecProduct($app, ['name' => 'Silla', 'price' => 2000, 'sku' => 'SIL-1', 'tax_class_id' => 'standard']);
            $b = aifecProduct($app, ['name' => 'Cojín & más', 'price' => 1000, 'sku' => 'COJ-1', 'tax_class_id' => 'reduced']);
            $lineas = [[$a, 0, 2], [$b, 0, 1]];
        }
        $placed = aifecOrderPlace($app, $web, $lineas, $caja);
        check(!empty($placed['ok']), 'el pedido se hace: ' . (isset($placed['error']) ? (string) $placed['error'] : ''));
        // La pasarela de pruebas marca sus pedidos como «de pruebas»: aquí se simula una pasarela real.
        $app->db()->update('orders', ['test' => 0], ['id' => (int) $placed['id']]);
        if ($pagar) {
            $order = $app->service('orders')->find((int) $placed['id']);
            check($app->service('orders')->markPaid((int) $placed['id'], 'gw', 'PAY-' . $placed['id'], (int) $order['total']), 'el cobro se apunta');
        }

        return [
            'id' => (int) $placed['id'], 'number' => (string) $placed['number'], 'secret' => (string) $placed['secret'],
            'url' => (string) parse_url($app->url()->route('order.thanks', ['number' => (string) $placed['number'], 'secret' => (string) $placed['secret']]), PHP_URL_PATH),
        ];
    }

    /**
     * @param array{id:int,number:string,secret:string,url:string} $pedido
     */
    function dlThanks(TestClient $web, array $pedido): Response
    {
        return $web->get($pedido['url']);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    function dlEvents(string $html): array
    {
        $out = [];
        if (preg_match_all('#<script type="application/json" data-af-dl>(.*?)</script>#s', $html, $found) > 0) {
            foreach ($found[1] as $json) {
                $data = json_decode($json, true);
                foreach (is_array($data) && isset($data['e']) && is_array($data['e']) ? $data['e'] : [] as $event) {
                    $out[] = $event;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $events
     *
     * @return array<string,mixed>|null
     */
    function dlEvent(array $events, string $name): ?array
    {
        foreach ($events as $event) {
            if (isset($event['event']) && $event['event'] === $name) {
                return $event;
            }
        }

        return null;
    }

    /** @param int|float|string $numero */
    function dlCents($numero): int
    {
        return (int) round(((float) $numero) * 100);
    }

    function dlHttp(App $app): FakeHttpClient
    {
        $http = $app->http();
        check($http instanceof FakeHttpClient, 'el cliente HTTP es el de pruebas');

        return $http;
    }
}

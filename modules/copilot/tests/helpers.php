<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Ayudas de prueba del copiloto. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   copilotApp(array $opciones = []): App
 *       Tienda de pruebas con el copiloto, pedidos, catálogo, clientes, cupones, envíos e impuestos (y correo). Trae el transportista
 *       «std» (Estándar) con tarifas en las cuatro zonas y la forma de pago «gw». Opciones: modules (más módulos), settings.
 *   copilotShop(App $app): array
 *       Catálogo de ejemplo: la categoría Sillas con «Silla roja» (SILLA-R, 59,90 €, 10 uds., marca Acme) y «Silla azul» (SILLA-A,
 *       69,90 €, 4 uds.), «Mesa de roble» (MESA-1, 120,00 €, 3 uds.) y «Sofá Nube» con dos variantes. Devuelve los identificadores:
 *       roja, azul, mesa, sofa, cat, brand.
 *   copilotOrder(App $app, int $unidades = 1): array
 *       Un pedido pagado de una camiseta de 20,00 €. Devuelve id, number y n (el número sin ceros ni año, como lo escribe una persona).
 *   copilotAdmin(App $app, string $papel = 'admin'): TestClient
 *       Persona del panel con sesión iniciada y una página abierta.
 *   copilotPost(TestClient $web, string $url, array $datos = [], bool $json = false): Response
 *       POST con la ficha del formulario (refresca la página antes). Con $json pide JSON, como el JavaScript del panel.
 *   copilotAsk(TestClient $web, string $texto): array
 *       Escribe una orden por el formulario sin JavaScript (POST → 303) y devuelve la propuesta creada, ya leída de la base.
 *   copilotRow(App $app, int $id): array
 *       La propuesta tal como está en la base de datos.
 *   copilotService(App $app): Copilot
 *   copilotMails(App $app): int
 *       Cuántos correos hay apuntados (para comprobar que un cambio no avisa a nadie).
 *   copilotAi(App $app): CopilotFakeAi
 *       Pone una IA de mentira (con llamadas a herramientas programadas) como servicio «ai».
 *   copilotTool(string $nombre, array $argumentos): AiResult  /  copilotText(string $texto): AiResult
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Ai\AiException;
use Aifec\Ai\AiResult;
use Aifec\Ai\AiToolCall;
use Aifec\Kernel\App;
use Aifec\Kernel\Response;
use Aifec\Modules\Copilot\Copilot;

require_once __DIR__ . '/../../orders/tests/AdminKit.php';

if (!function_exists('copilotApp')) {
    /**
     * @param array<string,mixed> $opciones
     */
    function copilotApp(array $opciones = []): App
    {
        $opciones['modules'] = array_merge(['copilot'], isset($opciones['modules']) && is_array($opciones['modules']) ? $opciones['modules'] : []);

        return adminOrdersApp($opciones);
    }

    /**
     * @return array<string,int>
     */
    function copilotShop(App $app): array
    {
        $cat = aifecCategory($app, 'Sillas');
        $brand = aifecBrand($app, 'Acme');
        $ids = ['cat' => $cat, 'brand' => $brand];
        $ids['roja'] = aifecProduct($app, ['name' => 'Silla roja', 'sku' => 'SILLA-R', 'price' => 5990, 'stock' => 10, 'categories' => [$cat], 'brand_id' => $brand]);
        $ids['azul'] = aifecProduct($app, ['name' => 'Silla azul', 'sku' => 'SILLA-A', 'price' => 6990, 'stock' => 4, 'categories' => [$cat]]);
        $ids['mesa'] = aifecProduct($app, ['name' => 'Mesa de roble', 'sku' => 'MESA-1', 'price' => 12000, 'stock' => 3]);
        $ids['sofa'] = aifecProduct($app, [
            'name' => 'Sofá Nube', 'price' => 49900, 'variants' => [
                ['sku' => 'NUBE-S', 'label' => 'Talla S', 'price' => 49900, 'stock' => 3, 'values' => ['talla' => 'S']],
                ['sku' => 'NUBE-L', 'label' => 'Talla L', 'price' => 59900, 'stock' => 3, 'values' => ['talla' => 'L']],
            ],
        ]);

        return $ids;
    }

    /**
     * @return array<string,mixed>
     */
    function copilotOrder(App $app, int $unidades = 1): array
    {
        static $n = 0;
        ++$n;
        $product = adminOrdersProduct($app, ['name' => 'Camiseta de pedido ' . $n]);
        $id = adminOrdersPaid($app, 'gw', [[$product, 0, $unidades]]);
        $number = adminOrdersNumber($app, $id);

        return ['id' => $id, 'number' => $number, 'n' => (string) (int) preg_replace('/^.*\D/', '', $number)];
    }

    function copilotAdmin(App $app, string $papel = 'admin'): TestClient
    {
        return adminClient($app, $papel);
    }

    /**
     * @param array<string,mixed> $datos
     */
    function copilotPost(TestClient $web, string $url, array $datos = [], bool $json = false): Response
    {
        $web->get('/admin/profile');
        $headers = $json ? ['accept' => 'application/json', 'x-requested-with' => 'fetch'] : [];

        return $web->post($url, ['_token' => aifecToken($web)] + $datos, $headers);
    }

    /**
     * @return array<string,mixed>
     */
    function copilotAsk(TestClient $web, string $texto): array
    {
        $r = copilotPost($web, '/admin/copilot/ask', ['text' => $texto]);
        same(303, $r->status, 'una orden escrita sin JavaScript contesta con una redirección 303');
        check(preg_match('#/admin/copilot/(\d+)$#', (string) $r->headers['Location'], $m) === 1, 'la redirección lleva a la propuesta: ' . (string) $r->headers['Location']);

        return copilotRow($web->app, (int) $m[1]);
    }

    /**
     * @return array<string,mixed>
     */
    function copilotRow(App $app, int $id): array
    {
        $row = copilotService($app)->proposals()->find($id);
        check($row !== null, 'la propuesta ' . $id . ' existe');

        return (array) $row;
    }

    function copilotService(App $app): Copilot
    {
        $service = $app->service('copilot');
        check($service instanceof Copilot, 'el servicio copilot es el del módulo');

        return $service;
    }

    function copilotMails(App $app): int
    {
        return (int) $app->db()->value('SELECT COUNT(*) FROM {mail_messages}');
    }

    function copilotAi(App $app): CopilotFakeAi
    {
        $fake = new CopilotFakeAi();
        $app->bind('ai', static function () use ($fake): CopilotFakeAi {
            return $fake;
        });

        return $fake;
    }

    /**
     * @param array<string,mixed> $argumentos
     */
    function copilotTool(string $nombre, array $argumentos): AiResult
    {
        static $n = 0;
        ++$n;

        return new AiResult('', [new AiToolCall('call-' . $n, $nombre, $argumentos)], null, 100, 10, 'tool_calls', 'fake-model');
    }

    function copilotText(string $texto): AiResult
    {
        return new AiResult($texto, [], null, 100, 20, 'stop', 'fake-model');
    }

    /** El valor de una columna de un producto. */
    function copilotProduct(App $app, int $id, string $columna)
    {
        $value = $app->db()->value('SELECT ' . $columna . ' FROM {products} WHERE id = ?', [$id]);

        // PHP 7.4 devuelve los enteros de la base de datos como texto: un número es un número.
        return is_string($value) && ctype_digit($value) ? (int) $value : $value;
    }

    /** Lo que se ve en la pantalla de una propuesta ya abierta (GET). */
    function copilotPage(TestClient $web, int $id): string
    {
        $r = $web->get('/admin/copilot/' . $id);
        same(200, $r->status, 'la propuesta se abre');

        return $r->body;
    }

    if (!class_exists('CopilotFakeAi')) {
        /** Servicio «ai» de mentira: respuestas programadas (incluidas las llamadas a herramientas) y registro de lo que se le pidió. */
        final class CopilotFakeAi
        {
            /** @var bool */
            public $on = true;

            /** @var bool true = ask() lanza, como un proveedor caído */
            public $fail = false;

            /** @var array<int,array<string,mixed>> */
            public $asked = [];

            /** @var AiResult[] */
            private $queue = [];

            public function script(AiResult ...$results): self
            {
                foreach ($results as $result) {
                    $this->queue[] = $result;
                }

                return $this;
            }

            public function enabled(): bool
            {
                return $this->on;
            }

            /**
             * @param array<string,mixed> $request
             */
            public function ask(string $task, array $request, string $tier = 'economy'): AiResult
            {
                $this->asked[] = ['task' => $task, 'request' => $request, 'tier' => $tier];
                if ($this->fail) {
                    throw new AiException('The provider is down', true);
                }
                if ($this->queue === []) {
                    return new AiResult('No tengo nada que elegir.', [], null, 10, 5, 'stop', 'fake-model');
                }

                return array_shift($this->queue);
            }
        }
    }
}

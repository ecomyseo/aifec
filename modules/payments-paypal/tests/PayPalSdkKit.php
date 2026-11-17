<?php
/**
 * AIFEC — apoyo de las pruebas de las formas de pago del guion de PayPal (no es una prueba: la cargan con require_once).
 *
 *   ppkApp(array $formas, array $ajustes = []): App                 tienda con PayPal y esas formas del guion encendidas
 *   ppkPlaced(string $forma, string $pais = 'ES', …): array          [app, PpFake, producto, place(), navegador]
 *   ppkOpen(TestClient $web, array $placed): array                   abre la página de pago: [respuesta, datos para pay.js]
 *   ppkPost(TestClient $web, string $url, array $campos): array      lo que manda pay.js: [estado, JSON]
 *   ppkPaid(string $forma, …): array                                  página, orden y captura de punta a punta
 *   ppkCsp(Response $r), ppkInlineScripts(string $html), ppkOffered(App, array), ppkScript(), ppkPayUrl(array)
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Response;
use Aifec\Modules\PaymentsPaypal\PayPalSettings;

require_once __DIR__ . '/PayPalKit.php';
require_once __DIR__ . '/../../orders/tests/FrontKit.php';

if (!defined('PPK_APPLE_FILE')) {
    // Un fichero de dominio inventado, con la forma de los de Apple (hexadecimal).
    define('PPK_APPLE_FILE', '7B227073704964223A2246364438333837364246433842463744464244393333384136354331463638363245364539353133303541453439413834363039423939424434313038343630227D');
}

if (!function_exists('ppkApp')) {
    /**
     * Una tienda con PayPal y esas formas del guion encendidas (con el fichero de Apple Pay si va Apple Pay).
     *
     * @param string[]            $formas
     * @param array<string,mixed> $ajustes
     */
    function ppkApp(array $formas, array $ajustes = []): App
    {
        if (in_array('apple_pay', $formas, true)) {
            $ajustes += [PayPalSettings::APPLE_PAY_FILE => PPK_APPLE_FILE];
        }

        return ppSourcesApp($formas, $ajustes);
    }

    /**
     * Un pedido pagado con una forma del guion por un comprador de ese país (producto digital). Devuelve
     * [app, PpFake, producto, place(), navegador].
     *
     * @param array<string,mixed> $ajustes
     *
     * @return array{0:App,1:PpFake,2:int,3:array<string,mixed>,4:TestClient}
     */
    function ppkPlaced(string $forma, string $pais = 'ES', array $ajustes = [], int $precio = 2000): array
    {
        $app = ppkApp([$forma], $ajustes);
        $fake = ppFake($app);
        ppThanksRoute($app);
        $p = aifecProduct($app, ['name' => 'Guía en PDF', 'price' => $precio, 'stock' => 10, 'kind' => 'digital']);
        $web = new TestClient($app);
        $placed = ppPlaceWith($app, $web, $p, 'paypal_' . $forma, ['billing' => ppBuyer($pais)]);
        check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);

        return [$app, $fake, $p, $placed, $web];
    }

    /** La dirección de la página de pago de un pedido. */
    function ppkPayUrl(array $placed): string
    {
        return '/pago/paypal/pagar/' . $placed['number'] . '/' . $placed['secret'];
    }

    /**
     * Los datos que la página de pago deja para pay.js.
     *
     * @return array<string,mixed>
     */
    function ppkConfig(string $html): array
    {
        check(preg_match('/data-config="([^"]*)"/', $html, $m) === 1, 'la página lleva data-config');
        $data = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Abre la página de pago (como el navegador tras «Hacer el pedido») y devuelve [respuesta, datos para pay.js].
     *
     * @return array{0:Response,1:array<string,mixed>}
     */
    function ppkOpen(TestClient $web, array $placed): array
    {
        $r = $web->get(ppkPayUrl($placed));
        same(200, $r->status, 'la página de pago abre');

        return [$r, ppkConfig($r->body)];
    }

    /**
     * Lo que manda pay.js: un POST con la ficha (y, al capturar, la orden de PayPal). Devuelve [estado, JSON].
     *
     * @param array<string,string> $campos
     *
     * @return array{0:int,1:array<string,mixed>}
     */
    function ppkPost(TestClient $web, string $url, array $campos): array
    {
        $r = $web->post($url, $campos);
        $data = json_decode($r->body, true);

        return [$r->status, is_array($data) ? $data : []];
    }

    /**
     * La página abierta, la orden creada y su captura:
     * [app, fake, placed, web, id de la orden de PayPal, respuesta de capturar, datos de la página].
     *
     * @param array<string,mixed> $ajustes
     *
     * @return array{0:App,1:PpFake,2:array<string,mixed>,3:TestClient,4:string,5:array{0:int,1:array<string,mixed>},6:array<string,mixed>}
     */
    function ppkPaid(string $forma, string $pais = 'ES', array $ajustes = [], int $precio = 2000): array
    {
        list($app, $fake, $p, $placed, $web) = ppkPlaced($forma, $pais, $ajustes, $precio);
        list(, $cfg) = ppkOpen($web, $placed);
        list($status, $created) = ppkPost($web, $cfg['create'], ['_token' => $cfg['token']]);
        same(200, $status, 'la orden se crea');
        $captured = ppkPost($web, $cfg['capture'], ['_token' => $cfg['token'], 'paypal_order' => (string) $created['id']]);

        return [$app, $fake, $placed, $web, (string) $created['id'], $captured, $cfg];
    }

    /**
     * La política de contenido de una respuesta, directiva => fuentes (como cspParse() de las pruebas del núcleo).
     *
     * @return array<string,string[]>
     */
    function ppkCsp(Response $r): array
    {
        $out = [];
        foreach (explode(';', isset($r->headers['Content-Security-Policy']) ? (string) $r->headers['Content-Security-Policy'] : '') as $part) {
            $words = preg_split('/\s+/', trim($part), -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($words) && $words !== []) {
                $out[array_shift($words)] = $words;
            }
        }

        return $out;
    }

    /**
     * Los guiones escritos dentro de la página que un navegador ejecutaría (como cspInlineScripts() de las pruebas del núcleo).
     *
     * @return string[]
     */
    function ppkInlineScripts(string $html): array
    {
        $found = [];
        if (preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $html, $all, PREG_SET_ORDER) > 0) {
            foreach ($all as $m) {
                if (stripos($m[1], 'src=') !== false || trim($m[2]) === '') {
                    continue;
                }
                if (preg_match('/type="([^"]+)"/i', $m[1], $type) === 1 && !in_array(strtolower($type[1]), ['text/javascript', 'module'], true)) {
                    continue;
                }
                $found[] = $m[2];
            }
        }

        return $found;
    }

    /**
     * Los códigos que la caja ofrece para un comprador.
     *
     * @param array<string,mixed> $contexto
     *
     * @return string[]
     */
    function ppkOffered(App $app, array $contexto): array
    {
        $contexto += ['total' => 2000, 'currency' => 'EUR', 'country' => 'ES', 'needs_shipping' => false, 'customer_id' => 0, 'lang' => 'es'];

        return array_map(static function ($m): string {
            return $m->code();
        }, $app->service('payments')->available($contexto));
    }

    /** El guion propio de la página de pago. */
    function ppkScript(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/assets/pay.js');
    }

    /**
     * Una orden de PayPal consultada con el resultado de 3-D Secure de la tarjeta.
     *
     * @return array<string,mixed>
     */
    function ppkThreeDs(string $id, string $shift, string $enrollment, string $authentication, string $source = 'card'): array
    {
        $result = ['liability_shift' => $shift, 'three_d_secure' => array_filter(['enrollment_status' => $enrollment, 'authentication_status' => $authentication])];
        $node = $source === 'google_pay' ? ['card' => ['authentication_result' => $result]] : ['last_digits' => '1111', 'brand' => 'VISA', 'authentication_result' => $result];

        return ['id' => $id, 'status' => 'APPROVED', 'payment_source' => [$source => $node], 'purchase_units' => [['amount' => ['currency_code' => 'EUR', 'value' => '20.00']]]];
    }
}

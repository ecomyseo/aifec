<?php
/**
 * AIFEC — ayudas de prueba del módulo de sitios. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   aifecSitesWorld(): array
 *       Una instalación de mentira, SOLO PARA LEER, que se monta una vez por pasada: carpeta de datos y pública temporales,
 *       el sitio maestro (con 'master' => true y el módulo «sites») y la demostración «demo-minimo» ya construida desde el paquete
 *       mínimo. Claves: root, data, public, master (App), demos (Demos), id, url.
 *
 *   aifecSitesFresh(?string $packages = null): array
 *       Lo mismo pero sin ninguna demostración y NUEVA en cada llamada (para las pruebas que cambian cosas). Con $packages, el
 *       ajuste sites.packages_dir apunta a esa carpeta.
 *
 *   aifecSitesDemoApp(array $world, string $id): App   la aplicación de un sitio de demostración, arrancada
 *   aifecSitesRelease(&$x): void                       suelta una aplicación o un cliente para poder borrar su base (Windows)
 *   aifecSitesPackages(): string                       copia temporal de demos/minimo como «minimo», «uno» y «dos»
 *   aifecSitesEditJson(string $file, callable $fn)     lee un JSON, lo cambia con $fn y lo vuelve a escribir
 *   aifecSitesPlaceOrder(App $demo): array             crea un pedido con el pago simulado en la demostración
 *   aifecSitesFront(array $world, string $path): Response   una petición al punto de entrada real (Front::run)
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Http\FakeHttpClient;
use Aifec\Install\SiteFactory;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Kernel\Front;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Kernel\SiteResolver;
use Aifec\Modules\Sites\Demos;

require_once Autoload::root() . '/tests/Install/support.php';
require_once Autoload::root() . '/modules/orders/tests/helpers.php';

if (!function_exists('aifecSitesWorld')) {
    /**
     * @return array<string,mixed>
     */
    function aifecSitesWorld(): array
    {
        static $world = null;
        if ($world === null) {
            $world = aifecSitesFresh();
            $created = $world['demos']->create(['package' => 'minimo', 'slug' => 'minimo']);
            check(!empty($created['ok']), 'la demostración de partida se crea: ' . (isset($created['error']) ? (string) $created['error'] : ''));
            $world['id'] = 'demo-minimo';
            $world['url'] = (string) $created['url'];
        }

        return $world;
    }

    /**
     * @return array<string,mixed>
     */
    function aifecSitesFresh(?string $packages = null): array
    {
        [$root, $data, $public] = aifecInstEmpty();
        SiteFactory::create($root, $data, aifecInstOptions(['public_dir' => $public, 'modules' => ['sites']]));
        $main = SiteResolver::load($data, 'main');
        SiteFactory::writeConfig($data . '/main', $main->config + ['master' => true]);
        SiteResolver::forget();
        $app = new App(SiteResolver::load($data, 'main'), $root, [
            'public_dir' => $public, 'clock' => new Clock(testNow()), 'http' => new FakeHttpClient(), 'log_files' => false,
        ]);
        $app->makeCurrent();
        $app->boot();
        if ($packages !== null) {
            $app->settings()->set('sites.packages_dir', $packages);
        }

        return ['root' => $root, 'data' => $data, 'public' => $public, 'master' => $app, 'demos' => new Demos($app), 'id' => '', 'url' => ''];
    }

    /**
     * @param array<string,mixed> $world
     */
    function aifecSitesDemoApp(array $world, string $id): App
    {
        SiteResolver::forget();
        $site = SiteResolver::load((string) $world['data'], $id);
        check($site !== null, 'el sitio ' . $id . ' existe');
        $app = new App($site, (string) $world['root'], [
            'public_dir' => (string) $world['public'], 'clock' => new Clock(testNow()), 'http' => new FakeHttpClient(), 'log_files' => false,
        ]);
        $app->makeCurrent();
        $app->boot();
        $world['master']->makeCurrent();

        return $app;
    }

    /**
     * @param mixed $x
     */
    function aifecSitesRelease(&$x): void
    {
        $x = null;
        gc_collect_cycles();
    }

    /** Copia temporal de demos/minimo con los nombres «minimo», «uno» y «dos». */
    function aifecSitesPackages(): string
    {
        $dir = tmpDir('aifec-sites-packages');
        foreach (['minimo', 'uno', 'dos'] as $name) {
            aifecSitesCopy(Autoload::root() . '/demos/minimo', $dir . '/' . $name);
        }

        return $dir;
    }

    function aifecSitesCopy(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (array_diff((array) scandir($from), ['.', '..']) as $item) {
            if (is_dir($from . '/' . $item)) {
                aifecSitesCopy($from . '/' . $item, $to . '/' . $item);
            } else {
                copy($from . '/' . $item, $to . '/' . $item);
            }
        }
    }

    function aifecSitesEditJson(string $file, callable $fn): void
    {
        $data = json_decode((string) file_get_contents($file), true);
        $data = $fn($data);
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string,mixed> $world
     */
    function aifecSitesFront(array $world, string $path, string $method = 'GET', array $post = [], array $cookies = []): Response
    {
        $request = Request::create($method, $path, $post, $cookies, ['user-agent' => 'AifecTest/1.0', 'sec-fetch-site' => 'same-origin']);
        $request->host = 'tienda.test';
        $request->secure = true;

        return Front::run((string) $world['root'], (string) $world['data'], (string) $world['public'], '', $request);
    }

    /**
     * Pone un pedido en la demostración con el pago simulado.
     *
     * @return array<string,mixed> lo que devuelve Orders::place()
     */
    function aifecSitesPlaceOrder(App $demo): array
    {
        $web = new TestClient($demo);
        $productId = (int) $demo->db()->value('SELECT id FROM {products} WHERE sku = ?', ['DEMO-MESA-02']);
        $carrierId = (int) $demo->db()->value('SELECT id FROM {carriers} WHERE code = ?', ['demo-estandar']);
        $result = aifecCartRun($web, static function ($cart, App $app) use ($productId, $carrierId): array {
            $added = $cart->add($productId, 0, 1);
            check(!empty($added['ok']), 'el producto se añade al carrito: ' . (isset($added['error']) ? (string) $added['error'] : ''));
            $cart->setCarrier($carrierId);
            $cart->setPaymentMethod('simulated');

            return $app->service('orders')->place(aifecOrderCheckout());
        });
        aifecSitesRelease($web);

        return $result;
    }
}

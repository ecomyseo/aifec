<?php
/**
 * AIFEC — apoyo de las pruebas del instalador: carpetas de datos y públicas de mentira, peticiones al punto de
 * entrada real (Front::run) y un recorrido completo del instalador web.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Db\Db;
use Aifec\Kernel\Front;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/** Raíz real del proyecto. */
function aifecInstRoot(): string
{
    return Autoload::root();
}

/**
 * Una instalación vacía: carpeta de datos y carpeta pública temporales.
 *
 * @return array{0:string,1:string,2:string} raíz, datos, pública
 */
function aifecInstEmpty(): array
{
    return [aifecInstRoot(), tmpDir('aifec-inst-data'), tmpDir('aifec-inst-public')];
}

/**
 * Opciones mínimas y válidas de SiteFactory::create (sin módulos opcionales: así cada sitio se crea rápido).
 *
 * @param array<string,mixed> $over
 *
 * @return array<string,mixed>
 */
function aifecInstOptions(array $over = []): array
{
    return array_replace_recursive([
        'id' => 'main', 'url' => 'https://tienda.test', 'locale' => 'es', 'shop_name' => 'Mi tienda & más',
        'admin' => ['name' => 'Ana Ruiz', 'email' => 'Ana@Tienda.Test', 'password' => 'clave-larga-1234'],
        'modules' => [],
    ], $over);
}

/**
 * @param array<string,mixed>  $post
 * @param array<string,string> $headers
 * @param array<string,string> $cookies
 */
function aifecInstRequest(array $i, string $method, string $uri, array $post = [], array $headers = [], array $cookies = []): Response
{
    $request = Request::create($method, $uri, $post, $cookies, $headers);
    $request->host = 'tienda.test';
    $request->secure = true;

    return Front::run($i[0], $i[1], $i[2], '', $request);
}

/**
 * @param array<string,string> $headers
 * @param array<string,string> $cookies
 */
function aifecInstGet(array $i, string $uri, array $headers = [], array $cookies = []): Response
{
    return aifecInstRequest($i, 'GET', $uri, [], $headers, $cookies);
}

/**
 * @param array<string,mixed>  $post
 * @param array<string,string> $headers
 * @param array<string,string> $cookies
 */
function aifecInstPost(array $i, string $uri, array $post, array $headers = [], array $cookies = []): Response
{
    return aifecInstRequest($i, 'POST', $uri, $post, $headers, $cookies);
}

/**
 * @return array<string,string> las cookies que pone una respuesta
 */
function aifecInstCookies(Response $r): array
{
    $out = [];
    foreach ($r->cookies as $name => $cookie) {
        $out[$name] = $cookie['value'];
    }

    return $out;
}

/** Base de datos SQLite de un sitio, abierta directamente para mirar lo que se ha guardado. */
function aifecInstDb(string $data, string $id): Db
{
    return Db::connect(['driver' => 'sqlite', 'path' => $data . '/' . $id . '/db.sqlite', 'prefix' => 'af_']);
}

/** Valor del campo oculto «state» de una pantalla del instalador. */
function aifecInstState(string $html): string
{
    if (preg_match('/name="state" value="([^"]+)"/', $html, $m) !== 1) {
        throw new \LogicException('No signed state in the page');
    }

    return html_entity_decode($m[1], ENT_QUOTES);
}

/**
 * Recorre las tres pantallas del instalador con datos buenos.
 *
 * @param array<string,string> $headers
 *
 * @return Response la pantalla final
 */
function aifecInstFlow(array $i, string $lang, array $headers = []): Response
{
    $one = aifecInstPost($i, '/', ['step' => '1', 'lang' => $lang], $headers);
    $two = aifecInstPost($i, '/', ['step' => '2', 'state' => aifecInstState($one->body), 'db_driver' => 'sqlite', 'go' => 'next'], $headers);

    return aifecInstPost($i, '/', [
        'step' => '3', 'state' => aifecInstState($two->body), 'go' => 'next',
        'shop_name' => 'Tienda & Co', 'shop_email' => 'hola@tienda.test', 'admin_name' => 'Ana Ruiz',
        'admin_email' => 'Ana@Tienda.test', 'admin_password' => 'clave-larga-1234', 'admin_password2' => 'clave-larga-1234',
    ], $headers);
}

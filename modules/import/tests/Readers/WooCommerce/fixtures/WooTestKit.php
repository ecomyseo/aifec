<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Http\HttpResponse;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\Readers\WooCommerce\ApiReader;
use Aifec\Modules\Import\Readers\WooCommerce\DbReader;
use Aifec\Modules\Import\Readers\WooCommerce\PublicReader;
use Aifec\Modules\Import\SourceReader;

require_once __DIR__ . '/db/schema.php';
require_once __DIR__ . '/db/data.php';

/**
 * Apoyo de las pruebas de los lectores de WooCommerce: una tienda de mentira que contesta con los JSON de fixtures/api/ (con la
 * paginación y las cabeceras de WordPress), los lectores ya montados y un bucle que lee un tipo hasta agotarlo.
 */
final class WooTestKit
{
    /** @var array<string,mixed>|null */
    private static $routes;

    /**
     * Todas las respuestas de ejemplo: ruta => lo que contesta la API.
     *
     * @return array<string,mixed>
     */
    public static function routes(): array
    {
        if (self::$routes === null) {
            self::$routes = [];
            foreach (glob(__DIR__ . '/api/*.json') ?: [] as $file) {
                $data = json_decode((string) file_get_contents($file), true);
                if (!is_array($data)) {
                    throw new RuntimeException('JSON de ejemplo no válido: ' . basename($file));
                }
                self::$routes += $data;
            }
        }

        return self::$routes;
    }

    /**
     * Un cliente HTTP de mentira que se hace pasar por la tienda de https://tienda.test.
     *
     * @param array<string,mixed> $options mutate (callable ruta, datos → datos), fail (callable ruta, petición → respuesta o excepción o null),
     *                                     no_total_pages (no manda X-WP-TotalPages)
     */
    public static function http(array $options = []): FakeHttpClient
    {
        $routes = self::routes();
        $http = new FakeHttpClient();
        $http->when('*', 'tienda.test', static function (HttpRequest $request) use ($routes, $options) {
            $parts = parse_url($request->url);
            $path = (string) ($parts['path'] ?? '');
            parse_str((string) ($parts['query'] ?? ''), $query);
            if (isset($options['fail']) && is_callable($options['fail'])) {
                $failure = $options['fail']($path, $request);
                if ($failure instanceof HttpException) {
                    throw $failure;
                }
                if ($failure instanceof HttpResponse) {
                    return $failure;
                }
            }
            $key = isset($query['parent']) && isset($routes[$path . '?parent=' . $query['parent']]) ? $path . '?parent=' . $query['parent'] : $path;
            if (!array_key_exists($key, $routes)) {
                return new HttpResponse(404, [], '{"code":"rest_no_route"}');
            }
            $data = $routes[$key];
            if (isset($options['mutate']) && is_callable($options['mutate'])) {
                $data = $options['mutate']($path, $data);
            }
            $isList = is_array($data) && ($data === [] || array_keys($data) === range(0, count($data) - 1));
            $headers = [];
            if ($isList && $path === '/wp-json/wc/v3/products' && isset($query['type'])) {
                $data = array_values(array_filter($data, static function ($product) use ($query): bool {
                    return is_array($product) && ($product['type'] ?? '') === $query['type'];
                }));
            }
            if ($isList) {
                if (($query['order'] ?? '') === 'desc') {
                    $data = array_reverse($data);
                }
                $per = max(1, (int) ($query['per_page'] ?? 10));
                $page = max(1, (int) ($query['page'] ?? 1));
                $headers['X-WP-Total'] = (string) count($data);
                if (empty($options['no_total_pages'])) {
                    $headers['X-WP-TotalPages'] = (string) max(1, (int) ceil(count($data) / $per));
                }
                $data = array_slice($data, ($page - 1) * $per, $per);
            }

            return new HttpResponse(200, $headers, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        });

        return $http;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $options ver http()
     */
    public static function api(array $config = [], array $options = []): ApiReader
    {
        return new ApiReader(self::http($options), $config + [
            'url' => 'https://tienda.test', 'key' => 'ck_clave-de-mentira', 'secret' => 'cs_secreto-de-mentira', 'wp_user' => 'gestor', 'wp_password' => 'abcd efgh ijkl', 'requests_per_second' => 0,
        ]);
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $options ver http()
     */
    public static function shop(array $config = [], array $options = []): PublicReader
    {
        return new PublicReader(self::http($options), $config + ['url' => 'https://tienda.test', 'locale' => 'es_ES', 'prices_include_tax' => 'no', 'requests_per_second' => 0]);
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function db(bool $hpos = false, array $config = [], ?PDO $pdo = null): DbReader
    {
        return new DbReader($pdo ?? wooMemoryDb($hpos), $config + ['prefix' => 'wp_', 'base_url' => 'https://tienda.test']);
    }

    /**
     * Lee un tipo hasta agotarlo, tanda a tanda.
     *
     * @return array{records:array<int,array<string,mixed>>,skipped:array<int,array{id:string,reason:string}>,read:int,batches:int}
     */
    public static function drain(SourceReader $reader, string $type, int $limit = 50): array
    {
        $records = [];
        $skipped = [];
        $read = 0;
        $batches = 0;
        $cursor = '';
        do {
            $batch = $reader->read($type, $cursor, $limit);
            ++$batches;
            foreach ($batch['records'] as $record) {
                $records[] = $record;
            }
            foreach ($batch['skipped'] as $skip) {
                $skipped[] = $skip;
            }
            $read += $batch['read'];
            $cursor = $batch['next'];
            if ($batches > 200) {
                throw new RuntimeException('La lectura de ' . $type . ' no termina');
            }
        } while ($cursor !== null);

        return ['records' => $records, 'skipped' => $skipped, 'read' => $read, 'batches' => $batches];
    }

    /**
     * Un registro de un tipo por su identificador de origen.
     *
     * @param array<int,array<string,mixed>> $records
     *
     * @return array<string,mixed>
     */
    public static function byId(array $records, string $id): array
    {
        foreach ($records as $record) {
            if ((string) $record['id'] === $id) {
                return $record;
            }
        }
        throw new RuntimeException('No hay registro ' . $id);
    }

    /**
     * Lee todos los tipos de un lector y comprueba cada registro contra el esquema neutro.
     *
     * @param string[] $allowSkipped tipos en los que se espera que algo se descarte
     *
     * @return array{problems:string[],empty:string[],counts:array<string,int>}
     */
    public static function checkAll(SourceReader $reader, int $limit = 3, array $allowSkipped = []): array
    {
        $problems = [];
        $empty = [];
        $counts = [];
        foreach ($reader->types() as $type) {
            $drained = self::drain($reader, $type, $limit);
            $counts[$type] = count($drained['records']);
            if ($drained['records'] === []) {
                $empty[] = $type;
            }
            if ($drained['skipped'] !== [] && !in_array($type, $allowSkipped, true)) {
                $problems[] = $type . ': descartados ' . json_encode($drained['skipped'], JSON_UNESCAPED_UNICODE);
            }
            $seen = [];
            foreach ($drained['records'] as $record) {
                foreach (Neutral::check($type, $record) as $problem) {
                    $problems[] = $problem;
                }
                $id = (string) $record['id'];
                if (isset($seen[$id])) {
                    $problems[] = $type . ': identificador repetido ' . $id;
                }
                $seen[$id] = true;
            }
        }

        return ['problems' => $problems, 'empty' => $empty, 'counts' => $counts];
    }
}

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
use Aifec\Modules\Import\Readers\PrestaShop\ApiReader;
use Aifec\Modules\Import\Readers\PrestaShop\DbReader;
use Aifec\Modules\Import\SourceReader;

require_once __DIR__ . '/db/schema.php';
require_once __DIR__ . '/db/data.php';

/**
 * La tarifa de un transportista para un peso (en gramos) y una zona.
 *
 * @param array<string,mixed> $carrier
 */
function psRateFor(array $carrier, string $zone, int $grams): ?int
{
    foreach ($carrier['rates'] as $rate) {
        if ($rate['zone_id'] === $zone && $rate['basis'] === 'weight' && $grams >= $rate['min'] && ($rate['max'] === null || $grams < $rate['max'])) {
            return $rate['price'];
        }
    }

    return null;
}

/** Una conexión que apunta cada consulta que prepara: así se comprueba que el lector solo lee. */
class PsRecordingPdo extends PDO
{
    /** @var string[] */
    public $statements = [];

    /** @var bool */
    public $deny = false;

    /**
     * @param string $query
     * @param array  $options
     *
     * @return PDOStatement|false
     */
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        if ($this->deny) {
            $error = new PDOException('SELECT command denied to user secreto-de-mentira');
            $error->errorInfo = ['42000', 1142, 'SELECT command denied to user secreto-de-mentira'];
            throw $error;
        }
        $this->statements[] = (string) $query;

        return parent::prepare($query, $options);
    }
}

/**
 * Apoyo de las pruebas de los lectores de PrestaShop: la copia de la base de datos de mentira, la tienda de mentira que contesta
 * con los JSON de fixtures/api/ (con filtros, orden y límite como el servicio web), los lectores ya montados y un bucle que lee
 * un tipo hasta agotarlo.
 */
final class PsTestKit
{
    /** @var array<string,array<int,array<string,mixed>>>|null */
    private static $resources;

    /**
     * Una copia de la base de datos de mentira con datos.
     */
    public static function pdo(string $variant = '8.2', string $version = '8.2.0'): PDO
    {
        $pdo = psMemoryDb($variant);
        psSeed($pdo, ['version' => $version]);

        return $pdo;
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function db(array $config = [], ?PDO $pdo = null): DbReader
    {
        return new DbReader($pdo ?? self::pdo(), $config + ['prefix' => 'ps_', 'base_url' => 'https://tienda.test', 'now' => '2026-11-01T00:00:00Z']);
    }

    /**
     * Todas las respuestas de ejemplo: recurso => lista de elementos tal como los da el servicio web.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function resources(): array
    {
        if (self::$resources === null) {
            self::$resources = [];
            foreach (glob(__DIR__ . '/api/*.json') ?: [] as $file) {
                $data = json_decode((string) file_get_contents($file), true);
                if (!is_array($data)) {
                    throw new RuntimeException('JSON de ejemplo no válido: ' . basename($file));
                }
                self::$resources += $data;
            }
        }

        return self::$resources;
    }

    /**
     * Un cliente HTTP de mentira que se hace pasar por el servicio web de https://tienda.test.
     *
     * @param array<string,mixed> $options key (la clave que acepta), mutate (callable recurso, lista → lista), fail (callable recurso, petición → respuesta,
     *                                     excepción o null), resources (otro juego de datos)
     */
    public static function http(array $options = []): FakeHttpClient
    {
        $resources = isset($options['resources']) ? $options['resources'] : self::resources();
        $key = isset($options['key']) ? (string) $options['key'] : 'clave-ws-de-mentira';
        $http = new FakeHttpClient();
        $http->when('*', 'tienda.test', static function (HttpRequest $request) use ($resources, $options, $key) {
            $parts = parse_url($request->url);
            $path = (string) ($parts['path'] ?? '');
            parse_str((string) ($parts['query'] ?? ''), $query);
            $resource = preg_match('#^/api/([a-z_]+)$#', $path, $m) === 1 ? $m[1] : '';
            if (($request->headers['Authorization'] ?? '') !== 'Basic ' . base64_encode($key . ':')) {
                return new HttpResponse(401, [], '{"errors":[{"code":0,"message":"Authentication required"}]}');
            }
            if (isset($options['fail']) && is_callable($options['fail'])) {
                $failure = $options['fail']($resource, $request);
                if ($failure instanceof HttpException) {
                    throw $failure;
                }
                if ($failure instanceof HttpResponse) {
                    return $failure;
                }
            }
            if (!array_key_exists($resource, $resources)) {
                return new HttpResponse(404, [], '{"errors":[{"code":0,"message":"Resource does not exist"}]}');
            }
            $list = $resources[$resource];
            if (isset($options['mutate']) && is_callable($options['mutate'])) {
                $list = $options['mutate']($resource, $list);
            }
            $list = self::filter($list, $query);
            $list = self::sort($list, isset($query['sort']) ? (string) $query['sort'] : '[id_ASC]');
            if (isset($query['limit']) && preg_match('/^(\d+),(\d+)$/', (string) $query['limit'], $m) === 1) {
                $list = array_slice($list, (int) $m[1], (int) $m[2]);
            }
            $list = self::display($list, isset($query['display']) ? (string) $query['display'] : 'full');
            if ($list === []) {
                return new HttpResponse(200, [], '[]');
            }

            return new HttpResponse(200, [], (string) json_encode([$resource => array_values($list)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        });

        return $http;
    }

    /**
     * @param array<int,array<string,mixed>> $list
     * @param array<string,mixed>            $query
     *
     * @return array<int,array<string,mixed>>
     */
    private static function filter(array $list, array $query): array
    {
        $filters = isset($query['filter']) && is_array($query['filter']) ? $query['filter'] : [];
        foreach ($filters as $field => $expression) {
            $expression = (string) $expression;
            $list = array_values(array_filter($list, static function (array $item) use ($field, $expression): bool {
                $value = isset($item[$field]) && !is_array($item[$field]) ? (string) $item[$field] : '';
                if (preg_match('/^\[(-?[\d.]+),(-?[\d.]+)\]$/', $expression, $m) === 1) {
                    return $value !== '' && (float) $value >= (float) $m[1] && (float) $value <= (float) $m[2];
                }
                if (preg_match('/^\[(.*)\]$/', $expression, $m) === 1) {
                    return in_array($value, explode('|', $m[1]), true);
                }

                return $value === $expression;
            }));
        }

        return $list;
    }

    /**
     * @param array<int,array<string,mixed>> $list
     *
     * @return array<int,array<string,mixed>>
     */
    private static function sort(array $list, string $sort): array
    {
        if (preg_match('/^\[(\w+)_(ASC|DESC)\]$/', $sort, $m) !== 1) {
            return $list;
        }
        usort($list, static function (array $a, array $b) use ($m): int {
            $x = isset($a[$m[1]]) ? (float) $a[$m[1]] : 0.0;
            $y = isset($b[$m[1]]) ? (float) $b[$m[1]] : 0.0;

            return $m[2] === 'ASC' ? $x <=> $y : $y <=> $x;
        });

        return $list;
    }

    /**
     * @param array<int,array<string,mixed>> $list
     *
     * @return array<int,array<string,mixed>>
     */
    private static function display(array $list, string $display): array
    {
        if (preg_match('/^\[(.*)\]$/', $display, $m) !== 1) {
            return $list;
        }
        $fields = array_flip(explode(',', $m[1]));

        return array_map(static function (array $item) use ($fields): array {
            return array_intersect_key($item, $fields);
        }, $list);
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $options ver http()
     */
    public static function api(array $config = [], array $options = []): ApiReader
    {
        return new ApiReader(self::http($options), $config + ['url' => 'https://tienda.test', 'key' => 'clave-ws-de-mentira', 'requests_per_second' => 0, 'now' => '2026-11-01T00:00:00Z']);
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
            if ($batches > 300) {
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
     * Un registro de un tipo, leído entero.
     *
     * @return array<string,mixed>
     */
    public static function record(SourceReader $reader, string $type, string $id): array
    {
        return self::byId(self::drain($reader, $type)['records'], $id);
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

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Readers\PrestaShop;

use Aifec\Http\HttpClient;
use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Modules\Import\SourceDenied;
use Aifec\Modules\Import\SourceError;

/**
 * El cliente del servicio web de PrestaShop («…/api/<recurso>?output_format=JSON&display=full&limit=<desde>,<cuántos>&sort=[id_ASC]»).
 * La clave va como usuario de la autenticación básica y no sale en ningún mensaje. Un 401 es «sin permiso» para ese recurso.
 * Lo que sabe del servicio web de PrestaShop: una lista vacía llega como «[]» y no como objeto, la lista de un recurso viene
 * dentro de un objeto con el nombre del recurso, y los números llegan como cadenas.
 */
final class Webservice
{
    /** @var HttpClient */
    private $http;

    /** @var string */
    private $base;

    /** @var string */
    private $key;

    /** @var array<string,mixed> */
    private $options;

    /** @var float */
    private $lastAt = 0.0;

    /**
     * @param array<string,mixed> $options timeout (segundos), requests_per_second (0 = sin límite, para las pruebas), shop (tienda de una multitienda)
     */
    public function __construct(HttpClient $http, string $baseUrl, string $key, array $options = [])
    {
        $this->http = $http;
        $this->base = rtrim($baseUrl, '/');
        $this->key = $key;
        $this->options = $options;
    }

    /** La dirección de la tienda, sin barra final. */
    public function base(): string
    {
        return $this->base;
    }

    /**
     * Una petición de lista: «desde» y «cuántos» van en «limit». Devuelve los elementos ya sin el envoltorio.
     *
     * @param array<string,string|int> $query filtros y campos («display», «filter[id]», «sort»)
     *
     * @return array<int,array<string,mixed>>
     */
    public function items(string $resource, array $query = [], int $offset = 0, ?int $count = null): array
    {
        $query += ['output_format' => 'JSON', 'display' => 'full', 'sort' => '[id_ASC]'];
        if ($count !== null) {
            $query['limit'] = $offset . ',' . $count;
        }
        if (isset($this->options['shop']) && (string) $this->options['shop'] !== '') {
            $query['id_shop'] = (string) $this->options['shop'];
        }
        $response = $this->send('/api/' . $resource . '?' . http_build_query($query));
        if ($response === null) {
            return [];
        }
        $data = $response->json();
        if ($data === null) {
            if (trim($response->body) === '') {
                return [];
            }
            throw new SourceError('The web service answered something that is not JSON');
        }

        return self::unwrap($data);
    }

    /**
     * Todas las páginas de una lista.
     *
     * @param array<string,string|int> $query
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(string $resource, array $query = [], int $chunk = 100): array
    {
        $out = [];
        $offset = 0;
        do {
            $page = $this->items($resource, $query, $offset, $chunk);
            foreach ($page as $item) {
                $out[] = $item;
            }
            $offset += $chunk;
        } while (count($page) >= $chunk && $offset < 1000000);

        return $out;
    }

    /**
     * ¿Se puede leer este recurso con la clave? (una petición de un solo elemento). Un 401 es «no»; los demás fallos suben.
     */
    public function allowed(string $resource): bool
    {
        try {
            $this->items($resource, ['display' => '[id]'], 0, 1);
        } catch (SourceDenied $e) {
            return false;
        }

        return true;
    }

    /**
     * El cuerpo decodificado sin el envoltorio: «{"products":[…]}» da la lista, «[]» da vacío.
     *
     * @param mixed $data
     *
     * @return array<int,array<string,mixed>>
     */
    public static function unwrap($data): array
    {
        if (!is_array($data) || $data === []) {
            return [];
        }
        if (array_keys($data) === range(0, count($data) - 1)) {
            return array_values(array_filter($data, 'is_array'));
        }
        $inner = reset($data);
        if (is_array($inner)) {
            if ($inner === []) {
                return [];
            }
            if (array_keys($inner) === range(0, count($inner) - 1)) {
                return array_values(array_filter($inner, 'is_array'));
            }

            return [$inner];
        }

        return [];
    }

    /**
     * Un campo con un texto por idioma: «[{"id":"1","value":"…"}]» pasa a [1 => «…»].
     *
     * @param mixed $value
     *
     * @return array<int,string>
     */
    public static function lang($value): array
    {
        $out = [];
        if (!is_array($value)) {
            return $out;
        }
        foreach ($value as $entry) {
            if (is_array($entry) && isset($entry['id']) && array_key_exists('value', $entry)) {
                $out[(int) $entry['id']] = $entry['value'] === null ? '' : (string) $entry['value'];
            }
        }

        return $out;
    }

    /**
     * Una asociación («associations.categories») como lista de elementos.
     *
     * @param array<string,mixed> $item
     *
     * @return array<int,array<string,mixed>>
     */
    public static function association(array $item, string $name): array
    {
        if (!isset($item['associations']) || !is_array($item['associations']) || !isset($item['associations'][$name]) || !is_array($item['associations'][$name])) {
            return [];
        }

        return array_values(array_filter($item['associations'][$name], 'is_array'));
    }

    /**
     * Los identificadores de una asociación.
     *
     * @param array<string,mixed> $item
     *
     * @return array<int,int>
     */
    public static function ids(array $item, string $name): array
    {
        return array_map(static function (array $entry): int {
            return (int) (isset($entry['id']) ? $entry['id'] : 0);
        }, self::association($item, $name));
    }

    // ------------------------------------------------------------------ red

    /**
     * GET con límite de ritmo y los fallos traducidos. Un 404 da null (un recurso opcional que no está); el resto de 4xx y los 5xx lanzan.
     */
    private function send(string $path): ?\Aifec\Http\HttpResponse
    {
        $this->throttle();
        $headers = ['Accept' => 'application/json', 'Authorization' => 'Basic ' . base64_encode($this->key . ':')];
        try {
            $response = $this->http->send(HttpRequest::get($this->base . $path, $headers, (float) (isset($this->options['timeout']) ? $this->options['timeout'] : 20)));
        } catch (HttpException $e) {
            throw new SourceError('The request to the shop failed: ' . $this->scrub($e->getMessage()));
        }
        $status = $response->status;
        if ($status === 401 || $status === 403) {
            throw new SourceDenied('The shop refused access (HTTP ' . $status . ')');
        }
        if ($status === 404) {
            return null;
        }
        if ($status === 429 || $status === 503) {
            $after = trim((string) $response->header('Retry-After'));

            throw new SourceError('The shop asks to slow down (HTTP ' . $status . ')' . ($after !== '' ? '; retry after ' . $this->scrub($after) . ' seconds' : ''));
        }
        if ($status >= 500) {
            throw new SourceError('The shop answered with an error (HTTP ' . $status . ')');
        }
        if ($status >= 400) {
            throw new SourceError('The shop rejected the request (HTTP ' . $status . ')');
        }

        return $response;
    }

    /** No más de «requests_per_second» (4 por defecto) peticiones por segundo al origen; 0 lo desactiva (pruebas). */
    private function throttle(): void
    {
        $perSecond = (float) (isset($this->options['requests_per_second']) ? $this->options['requests_per_second'] : 4);
        if ($perSecond <= 0) {
            return;
        }
        $wait = $this->lastAt + 1.0 / $perSecond - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }
        $this->lastAt = microtime(true);
    }

    /** Quita la clave de un texto antes de que salga en un mensaje. */
    private function scrub(string $text): string
    {
        if ($this->key !== '') {
            $text = str_replace([$this->key, base64_encode($this->key . ':')], '***', $text);
        }

        return mb_substr($text, 0, 200);
    }
}

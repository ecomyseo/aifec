<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Readers\WooCommerce;

use Aifec\Http\HttpClient;
use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Http\HttpResponse;
use Aifec\Modules\Import\SourceDenied;
use Aifec\Modules\Import\SourceError;
use Aifec\Modules\Import\SourceReader;

/**
 * Lo que comparten los dos lectores por red (el público y el de la API con clave): hablar con el sitio con tiempo límite, sin
 * pasar de 4 peticiones por segundo, traducir los fallos de red y de HTTP a SourceError / SourceDenied (sin una clave en el
 * mensaje), paginar con las cabeceras X-WP-Total y X-WP-TotalPages y leer los contenidos de WordPress (wp/v2).
 */
abstract class WpRestReader implements SourceReader
{
    /** @var HttpClient */
    protected $http;

    /** @var array<string,mixed> */
    protected $config;

    /** @var string dirección del sitio sin barra final */
    protected $base;

    /** @var float */
    private $lastAt = 0.0;

    /** @var string[] lo que no puede salir en un mensaje */
    private $hidden = [];

    /** @var ?Mapper */
    private $mapper;

    /**
     * @param mixed               $connection un Aifec\Http\HttpClient
     * @param array<string,mixed> $config
     */
    public function __construct($connection, array $config)
    {
        if (!$connection instanceof HttpClient) {
            throw new \InvalidArgumentException('An HttpClient is required');
        }
        $url = trim((string) ($config['url'] ?? ''));
        if ($url === '' || preg_match('#^https?://[^/\s]+#i', $url) !== 1) {
            throw new \InvalidArgumentException('The shop address is required');
        }
        $this->http = $connection;
        $this->config = $config;
        $this->base = rtrim($url, '/');
        foreach (['key', 'secret', 'wp_user', 'wp_password'] as $field) {
            if (isset($config[$field]) && is_string($config[$field]) && $config[$field] !== '') {
                $this->hidden[] = $config[$field];
            }
        }
        $userInfo = (string) parse_url($url, PHP_URL_USER) . ':' . (string) parse_url($url, PHP_URL_PASS);
        if ($userInfo !== ':') {
            $this->hidden[] = $userInfo;
        }
    }

    public function source(): string
    {
        return 'woocommerce';
    }

    public function key(): string
    {
        $host = (string) parse_url($this->base, PHP_URL_HOST);
        $path = (string) parse_url($this->base, PHP_URL_PATH);

        return substr($host . rtrim($path, '/'), 0, 60);
    }

    /** El valor de la cabecera Authorization que lleva esa ruta, o null si va sin clave. */
    abstract protected function authorization(string $path): ?string;

    /** Los datos del sitio con los que se arma el traductor (zona horaria, unidades, impuestos incluidos…). */
    abstract protected function buildMapper(): Mapper;

    /** Parámetros que se añaden a las páginas y entradas de wp/v2 (con clave de WordPress, también borradores). @return array<string,string> */
    protected function contentQuery(): array
    {
        return [];
    }

    protected function mapper(): Mapper
    {
        if ($this->mapper === null) {
            $this->mapper = $this->buildMapper();
        }

        return $this->mapper;
    }

    protected function basic(string $user, string $password): string
    {
        return 'Basic ' . base64_encode($user . ':' . $password);
    }

    // ------------------------------------------------------------------ red

    /**
     * GET con límite de ritmo y los fallos traducidos. Un 404 se devuelve (hay rutas opcionales); el resto de 4xx y los 5xx lanzan.
     *
     * @param array<string,string|int> $query
     */
    protected function request(string $path, array $query = []): HttpResponse
    {
        $headers = ['Accept' => 'application/json'];
        $auth = $this->authorization($path);
        if ($auth !== null) {
            $headers['Authorization'] = $auth;
        }
        $url = $this->base . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        $this->throttle();
        try {
            $response = $this->http->send(HttpRequest::get($url, $headers, (float) ($this->config['timeout'] ?? 20)));
        } catch (HttpException $e) {
            throw new SourceError('The request to the shop failed: ' . $this->scrub($e->getMessage()));
        }
        $status = $response->status;
        if ($status === 401 || $status === 403) {
            throw new SourceDenied('The shop refused access (HTTP ' . $status . ')');
        }
        if ($status === 429) {
            $after = trim((string) $response->header('Retry-After'));
            throw new SourceError('The shop asks to slow down (HTTP 429)' . ($after !== '' ? '; retry after ' . $this->scrub($after) . ' seconds' : ''));
        }
        if ($status >= 500) {
            throw new SourceError('The shop answered with an error (HTTP ' . $status . ')');
        }
        if ($status >= 400 && $status !== 404) {
            throw new SourceError('The shop rejected the request (HTTP ' . $status . ')');
        }

        return $response;
    }

    /** No más de «requests_per_second» (4 por defecto) peticiones por segundo al origen; 0 lo desactiva (pruebas). */
    private function throttle(): void
    {
        $perSecond = (float) ($this->config['requests_per_second'] ?? 4);
        if ($perSecond <= 0) {
            return;
        }
        $wait = $this->lastAt + 1.0 / $perSecond - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }
        $this->lastAt = microtime(true);
    }

    protected function scrub(string $text): string
    {
        foreach ($this->hidden as $secret) {
            $text = str_replace($secret, '***', $text);
        }

        return mb_substr($text, 0, 200);
    }

    /**
     * El cuerpo como lista de elementos.
     *
     * @return array<int,mixed>
     */
    protected function listOf(HttpResponse $response): array
    {
        if ($response->status === 404) {
            return [];
        }
        $data = $response->json();
        if (!is_array($data) || ($data !== [] && array_keys($data) !== range(0, count($data) - 1))) {
            throw new SourceError('The shop did not answer with a JSON list');
        }

        return $data;
    }

    /**
     * El cuerpo como objeto.
     *
     * @return array<string,mixed>
     */
    protected function objectOf(HttpResponse $response): array
    {
        $data = $response->json();
        if (!is_array($data)) {
            throw new SourceError('The shop did not answer with JSON');
        }

        return $data;
    }

    /**
     * Una página de una lista paginada. El cursor es «página.tamaño»; el final lo dice X-WP-TotalPages.
     *
     * @param array<string,string|int> $query
     *
     * @return array{0:array<int,mixed>,1:?string}
     */
    protected function page(string $path, array $query, string $cursor, int $limit): array
    {
        $page = 1;
        $per = max(1, min(100, $limit));
        if ($cursor !== '') {
            if (preg_match('/^(\d+)\.(\d+)$/', $cursor, $m) !== 1 || (int) $m[1] < 1) {
                throw new SourceError('Invalid cursor');
            }
            $page = (int) $m[1];
            $per = max(1, min(100, (int) $m[2]));
        }
        $response = $this->request($path, $query + ['page' => $page, 'per_page' => $per]);
        $items = $this->listOf($response);
        $pages = $response->header('X-WP-TotalPages');
        if ($pages !== null && is_numeric($pages)) {
            $more = (int) $pages > $page;
        } else {
            $more = count($items) >= $per; // sin la cabecera (un proxy que la quita): solo así se sabe que quizá hay otra página
        }

        return [$items, $more && $items !== [] ? ($page + 1) . '.' . $per : null];
    }

    /**
     * Todas las páginas de una lista (para lo que es pequeño: variaciones, términos).
     *
     * @param array<string,string|int> $query
     *
     * @return array<int,mixed>
     */
    protected function all(string $path, array $query = []): array
    {
        $all = [];
        $cursor = '';
        for ($i = 0; $i < 200; ++$i) {
            list($items, $next) = $this->page($path, $query, $cursor, 100);
            foreach ($items as $item) {
                $all[] = $item;
            }
            if ($next === null) {
                break;
            }
            $cursor = $next;
        }

        return $all;
    }

    /**
     * Cuántos hay, según X-WP-Total de una petición de un solo elemento. Null si el sitio no lo dice o no existe la ruta.
     *
     * @param array<string,string|int> $query
     */
    protected function total(string $path, array $query = []): ?int
    {
        $response = $this->request($path, $query + ['per_page' => 1]);
        if ($response->status === 404) {
            return null;
        }
        $total = $response->header('X-WP-Total');

        return $total !== null && is_numeric($total) ? (int) $total : null;
    }

    // ------------------------------------------------------------------ contenidos de WordPress (wp/v2)

    /**
     * Medios, páginas, categorías de entradas y entradas.
     *
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    protected function readContent(string $type, string $cursor, int $limit): array
    {
        $mapper = $this->mapper();
        $ordered = ['orderby' => 'id', 'order' => 'asc'];
        switch ($type) {
            case 'media':
                list($items, $next) = $this->page('/wp-json/wp/v2/media', $ordered, $cursor, $limit);
                $convert = static function (array $m) use ($mapper): array {
                    return $mapper->media($m);
                };
                break;
            case 'pages':
                list($items, $next) = $this->page('/wp-json/wp/v2/pages', $ordered + $this->contentQuery(), $cursor, $limit);
                $convert = static function (array $m) use ($mapper): array {
                    return $mapper->page($m);
                };
                break;
            case 'post_categories':
                list($items, $next) = $this->page('/wp-json/wp/v2/categories', $ordered, $cursor, $limit);
                $convert = static function (array $m) use ($mapper): array {
                    return $mapper->postCategory($m);
                };
                break;
            case 'posts':
                list($items, $next) = $this->page('/wp-json/wp/v2/posts', $ordered + $this->contentQuery() + ['_embed' => '1'], $cursor, $limit);
                $convert = static function (array $m) use ($mapper): array {
                    return $mapper->post($m);
                };
                break;
            default:
                throw new \InvalidArgumentException('This reader cannot read ' . $type);
        }
        list($records, $skipped) = Batch::map($items, $convert);

        return Batch::result($records, $next, count($items), $skipped);
    }
}

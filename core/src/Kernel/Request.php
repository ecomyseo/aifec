<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Kernel;

/**
 * Petición entrante. Es el único sitio donde se leen las variables globales de PHP (fromGlobals);
 * el resto del sistema recibe este objeto, y las pruebas lo fabrican con create().
 */
final class Request
{
    /** @var string GET, POST… */
    public $method;

    /** @var string ruta dentro del sitio, sin el prefijo del sitio y sin idioma: «/producto/silla» */
    public $path;

    /** @var string ruta dentro del sitio tal como llegó (con el prefijo de idioma si lo traía) */
    public $rawPath;

    /** @var array<string,mixed> */
    public $query;

    /** @var array<string,mixed> */
    public $post;

    /** @var array<string,string> */
    public $cookies;

    /** @var array<string,mixed> */
    public $files;

    /** @var array<string,string> cabeceras con el nombre en minúsculas */
    public $headers;

    /** @var string cuerpo sin procesar */
    public $body;

    /** @var string dirección del visitante: la de la conexión, o la que dice un proxy de confianza (ver trustProxies) */
    public $ip;

    /** @var string dirección de la conexión tal como llegó (REMOTE_ADDR): la de quien nos habla, sea o no un proxy */
    public $remoteAddr;

    /** @var string dominio (con puerto si no es el habitual) */
    public $host;

    /** @var bool */
    public $secure;

    /** @var string idioma resuelto para esta petición */
    public $lang = '';

    /** @var array<string,mixed> datos que van colgando los intermediarios (parámetros de ruta…) */
    private $attributes = [];

    /** @var array<string,mixed>|null */
    private $jsonCache;

    /**
     * @param array<string,mixed>  $query
     * @param array<string,mixed>  $post
     * @param array<string,string> $cookies
     * @param array<string,string> $headers
     * @param array<string,mixed>  $files
     */
    public function __construct(
        string $method,
        string $path,
        array $query = [],
        array $post = [],
        array $cookies = [],
        array $headers = [],
        string $body = '',
        array $files = [],
        string $ip = '127.0.0.1',
        string $host = 'localhost',
        bool $secure = false
    ) {
        $this->method = strtoupper($method);
        $path = '/' . ltrim($path, '/');
        $this->path = $path;
        $this->rawPath = $path;
        $this->query = $query;
        $this->post = $post;
        $this->cookies = $cookies;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->body = $body;
        $this->files = $files;
        $this->ip = $ip;
        $this->remoteAddr = $ip;
        $this->host = $host;
        $this->secure = $secure;
    }

    /**
     * Calcula la dirección del visitante cuando hay un proxy o una CDN delante. Hasta que se llama, `ip` es la de la conexión.
     * Solo se lee una cabecera de proxy si la conexión viene de uno de los proxies de confianza; de cualquier otro se ignora.
     *
     * @param string[] $trusted direcciones o rangos CIDR (IPv4 e IPv6) de los proxies de confianza
     * @param string   $header  `x-forwarded-for` (de serie) o `cf-connecting-ip`
     */
    public function trustProxies(array $trusted, string $header = 'x-forwarded-for'): void
    {
        $this->ip = self::clientIp($this->remoteAddr, $this->headers, $trusted, $header);
    }

    /**
     * La dirección del visitante.
     *
     * `x-forwarded-for` se lee de derecha a izquierda saltando los proxies de confianza: la primera dirección que no lo es, es el visitante
     * (lo que está más a la izquierda lo escribe el propio visitante y no vale). `cf-connecting-ip` lleva una sola dirección. Con cualquier
     * cosa rara en la cabecera se vuelve a la dirección de la conexión.
     *
     * @param array<string,string> $headers cabeceras con el nombre en minúsculas
     * @param string[]             $trusted
     */
    public static function clientIp(string $remote, array $headers, array $trusted, string $header = 'x-forwarded-for'): string
    {
        $trusted = array_values(array_filter($trusted, static function ($range): bool {
            return is_string($range) && self::validRange($range);
        }));
        if ($trusted === [] || self::bin($remote) === null || !self::inRanges($remote, $trusted)) {
            return $remote;
        }
        $header = strtolower($header) === 'cf-connecting-ip' ? 'cf-connecting-ip' : 'x-forwarded-for';
        $value = isset($headers[$header]) && is_string($headers[$header]) ? trim($headers[$header]) : '';
        if ($value === '') {
            return $remote;
        }
        if ($header === 'cf-connecting-ip') {
            $ip = self::cleanAddress($value);

            return $ip !== null ? $ip : $remote;
        }
        $parts = explode(',', $value);
        for ($i = count($parts) - 1; $i >= 0; --$i) {
            $ip = self::cleanAddress($parts[$i]);
            if ($ip === null) {
                return $remote; // basura en la cabecera: no se cree nada de ella
            }
            if (!self::inRanges($ip, $trusted)) {
                return $ip;
            }
        }

        return $remote;
    }

    /**
     * Una lista escrita por una persona (una por línea, o separadas por comas o espacios) → direcciones y rangos válidos, sin repetir.
     *
     * @return string[]
     */
    public static function parseProxyList(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
            if (self::validRange($item)) {
                $out[$item] = $item;
            }
        }

        return array_values($out);
    }

    /** ¿Es una dirección IP (IPv4 o IPv6) o un rango CIDR bien escrito? */
    public static function validRange(string $range): bool
    {
        $pos = strpos($range, '/');
        if ($pos === false) {
            return self::bin($range) !== null;
        }
        $bin = self::bin(substr($range, 0, $pos));
        $bits = substr($range, $pos + 1);
        if ($bin === null || $bits === '' || !ctype_digit($bits)) {
            return false;
        }

        return (int) $bits <= strlen($bin) * 8;
    }

    /**
     * @param string[] $ranges
     */
    private static function inRanges(string $ip, array $ranges): bool
    {
        $bin = self::bin($ip);
        if ($bin === null) {
            return false;
        }
        foreach ($ranges as $range) {
            $pos = strpos($range, '/');
            $base = self::bin($pos === false ? $range : substr($range, 0, $pos));
            if ($base === null || strlen($base) !== strlen($bin)) {
                continue;
            }
            $bits = $pos === false ? strlen($base) * 8 : (int) substr($range, $pos + 1);
            // IPv4 dentro de IPv6 (::ffff:a.b.c.d) ya viene reducida a IPv4 en bin(); aquí solo se comparan bits.
            $full = intdiv($bits, 8);
            if ($full > 0 && substr($bin, 0, $full) !== substr($base, 0, $full)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0 || (ord($bin[$full]) & (0xFF << (8 - $rest)) & 0xFF) === (ord($base[$full]) & (0xFF << (8 - $rest)) & 0xFF)) {
                return true;
            }
        }

        return false;
    }

    /** La dirección en binario (4 o 16 bytes); una IPv6 «mapeada» de IPv4 se reduce a sus 4 bytes. null si no es una IP. */
    private static function bin(string $ip): ?string
    {
        if ($ip === '' || strlen($ip) > 45 || strpos($ip, '%') !== false) {
            return null;
        }
        $bin = @inet_pton($ip);
        if (!is_string($bin)) {
            return null;
        }
        if (strlen($bin) === 16 && substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            return substr($bin, 12);
        }

        return $bin;
    }

    /** Una dirección de una cabecera, sin puerto ni corchetes y en su forma canónica; null si no es una IP. */
    private static function cleanAddress(string $text): ?string
    {
        $text = trim($text);
        if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::\d{1,5})?$/', $text, $m) === 1) {
            $text = $m[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d{1,5}$/', $text, $m) === 1) {
            $text = $m[1];
        }
        $bin = self::bin($text);
        if ($bin === null) {
            return null;
        }
        $out = @inet_ntop($bin);

        return is_string($out) ? $out : null;
    }

    /**
     * Fabrica una petición para las pruebas: create('GET', '/buscar?q=silla').
     *
     * @param array<string,mixed>  $post
     * @param array<string,string> $cookies
     * @param array<string,string> $headers
     */
    public static function create(string $method, string $uri, array $post = [], array $cookies = [], array $headers = [], string $body = ''): self
    {
        $query = [];
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            parse_str(substr($uri, $pos + 1), $query);
            $uri = substr($uri, 0, $pos);
        }

        return new self($method, rawurldecode($uri), $query, $post, $cookies, $headers, $body);
    }

    /** Petición real a partir de las variables globales. $basePath es el prefijo del sitio («/demos/moda»). */
    public static function fromGlobals(string $basePath = ''): self
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $pos = strpos($uri, '?');
        $path = rawurldecode($pos === false ? $uri : substr($uri, 0, $pos));
        if ($basePath !== '' && ($path === $basePath || strpos($path, $basePath . '/') === 0)) {
            $path = (string) substr($path, strlen($basePath));
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos((string) $key, 'HTTP_') === 0 && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $headers[$name] = $_SERVER[$key];
            }
        }

        $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            || (isset($headers['x-forwarded-proto']) && strtolower($headers['x-forwarded-proto']) === 'https');

        $cookies = [];
        foreach ($_COOKIE as $name => $value) {
            if (is_string($value)) {
                $cookies[(string) $name] = $value;
            }
        }

        $body = '';
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            $raw = file_get_contents('php://input');
            $body = $raw === false ? '' : $raw;
        }

        return new self(
            $method,
            $path === '' ? '/' : $path,
            $_GET,
            $_POST,
            $cookies,
            $headers,
            $body,
            $_FILES,
            isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0',
            isset($headers['host']) ? $headers['host'] : (isset($_SERVER['SERVER_NAME']) ? (string) $_SERVER['SERVER_NAME'] : 'localhost'),
            $https
        );
    }

    /**
     * Parámetro de la URL.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }

    /**
     * Campo del formulario o del cuerpo JSON; si no está, parámetro de la URL.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        $json = $this->json();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }

        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }

    /** Texto recortado de un campo; «» si no viene o no es un texto. */
    public function str(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /** Verdadero para «1», «true», «on», «yes». Un interruptor apagado manda «0». */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, null);
        if ($value === null) {
            return $default;
        }

        return in_array(is_string($value) ? strtolower($value) : $value, [1, true, '1', 'true', 'on', 'yes'], true);
    }

    /**
     * @return array<mixed>
     */
    public function arr(string $key): array
    {
        $value = $this->input($key, []);

        return is_array($value) ? $value : [];
    }

    /**
     * Cuerpo JSON como array; vacío si no es JSON o no es un objeto.
     *
     * @return array<string,mixed>
     */
    public function json(): array
    {
        if ($this->jsonCache === null) {
            $this->jsonCache = [];
            if ($this->body !== '' && strpos((string) $this->header('content-type', ''), 'json') !== false) {
                $data = json_decode($this->body, true);
                if (is_array($data)) {
                    $this->jsonCache = $data;
                }
            }
        }

        return $this->jsonCache;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $name = strtolower($name);

        return isset($this->headers[$name]) ? $this->headers[$name] : $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return isset($this->cookies[$name]) ? $this->cookies[$name] : $default;
    }

    public function userAgent(): string
    {
        return (string) $this->header('user-agent', '');
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** La respuesta esperada es JSON (petición de JavaScript propio o de un programa). */
    public function wantsJson(): bool
    {
        $accept = (string) $this->header('accept', '');
        if (strpos($accept, 'application/json') !== false || $this->header('x-requested-with') === 'fetch') {
            return true;
        }

        // Quien manda JSON es un programa aunque no diga qué acepta: sus errores también van en JSON. (Una dirección acabada en
        // «.json» no basta: «descargar mis datos» es un enlace que abre un navegador y, sin sesión, tiene que llevar a entrar.)
        return stripos((string) $this->header('content-type', ''), 'application/json') === 0;
    }

    /** Un programa que pide la versión en texto limpio de la página. */
    public function wantsMarkdown(): bool
    {
        return strpos((string) $this->header('accept', ''), 'text/markdown') !== false;
    }

    /**
     * ¿Viene la petición de nuestra propia web? Sirve para las acciones anónimas (añadir al carrito),
     * donde no hay sesión de la que colgar una ficha. Un navegador moderno manda Sec-Fetch-Site u Origin.
     */
    public function isSameOrigin(): bool
    {
        $site = $this->header('sec-fetch-site');
        if ($site !== null) {
            return in_array($site, ['same-origin', 'none'], true);
        }
        $origin = $this->header('origin');
        if ($origin === null) {
            $origin = $this->header('referer');
        }
        if ($origin === null) {
            // Ni Origin ni Referer: no es un formulario enviado desde otra web por un navegador actual.
            return true;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        $mine = strtolower($this->host);

        return is_string($host) && (strtolower($host) === $mine || strtolower($host . ($port ? ':' . $port : '')) === $mine);
    }

    public function scheme(): string
    {
        return $this->secure ? 'https' : 'http';
    }

    /**
     * @param mixed $value
     */
    public function setAttr(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * @param mixed $default
     *
     * @return mixed
     */
    public function attr(string $key, $default = null)
    {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }
}

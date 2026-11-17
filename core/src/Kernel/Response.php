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
 * Respuesta. Los controladores la devuelven; solo el punto de entrada (public/index.php) llama a send().
 * Ningún controlador, módulo ni plugin escribe con echo, manda cabeceras ni termina el proceso.
 */
final class Response
{
    /** @var int */
    public $status;

    /** @var array<string,string> */
    public $headers = [];

    /** @var string */
    public $body;

    /** @var array<string,array{value:string,options:array<string,mixed>}> */
    public $cookies = [];

    /** @var int segundos que puede guardarse en la caché de página (0 = no se guarda) */
    public $cacheTtl = 0;

    /** @var string[] etiquetas para invalidar la copia («product:12», «catalog») */
    public $cacheTags = [];

    /** @var string|null fichero que se envía tal cual (descargas); el cuerpo queda vacío */
    public $file;

    /** @var callable|null escribe el cuerpo por partes cuando las cabeceras ya han salido: function (callable $emit): void */
    public $writer;

    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        foreach ($headers as $name => $value) {
            $this->headers[(string) $name] = (string) $value;
        }
    }

    /**
     * Una respuesta que se escribe por partes: $writer recibe una función «emit(string)» y la llama con cada trozo según lo va
     * teniendo. Las cabeceras y las cookies encoladas HASTA que el controlador devuelve esta respuesta salen antes del primer
     * trozo; **una cookie que se encole mientras se escribe ya no sale** (las cabeceras se fueron). Por eso quien devuelve esto
     * deja fijado antes lo que vaya a necesitar cookie: el carrito, con Cart::ensure().
     *
     * @param callable             $writer  function (callable $emit): void
     * @param array<string,string> $headers
     */
    public static function stream(callable $writer, array $headers = [], int $status = 200): self
    {
        $response = new self('', $status, $headers);
        $response->writer = $writer;

        return $response;
    }

    /**
     * Ejecuta el escritor de una respuesta por partes y deja lo escrito en el cuerpo. Es lo que hace el cliente de las pruebas (y
     * cualquiera que necesite la respuesta entera en vez de emitirla): las cookies que se encolen aquí dentro tampoco viajan, igual
     * que en una petición de verdad.
     */
    public function drain(): void
    {
        if ($this->writer === null) {
            return;
        }
        $writer = $this->writer;
        $this->writer = null;
        $out = '';
        $writer(static function (string $chunk) use (&$out): void {
            $out .= $chunk;
        });
        $this->body = $out;
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function text(string $body, int $status = 200, string $type = 'text/plain'): self
    {
        return new self($body, $status, ['Content-Type' => $type . '; charset=UTF-8']);
    }

    /**
     * JSON con las tres cabeceras anti-caché: lo que devuelve estado nunca se guarda en ningún intermediario.
     *
     * @param mixed $data
     */
    public static function json($data, int $status = 200): self
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return new self($json === false ? 'null' : $json, $status, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url, 'Cache-Control' => 'no-store']);
    }

    /** Envía un fichero del disco (descarga privada, factura). El nombre se limpia de saltos y comillas. */
    public static function file(string $path, string $downloadName, string $mime = 'application/octet-stream', bool $inline = false): self
    {
        $name = str_replace(['"', "\r", "\n", '/', '\\'], '', $downloadName);
        $response = new self('', 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->file = $path;

        return $response;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * @param array<string,mixed> $options expires (marca de tiempo, 0 = sesión), path, domain, secure, httponly, samesite
     */
    public function cookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[$name] = ['value' => $value, 'options' => $options + [
            'expires' => 0, 'path' => '/', 'domain' => '', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax',
        ]];

        return $this;
    }

    public function clearCookie(string $name, string $path = '/'): self
    {
        return $this->cookie($name, '', ['expires' => 1, 'path' => $path]);
    }

    /**
     * Marca la respuesta como apta para la caché de página.
     *
     * @param string[] $tags
     */
    public function cacheable(int $ttl, array $tags = []): self
    {
        $this->cacheTtl = max(0, $ttl);
        $this->cacheTags = $tags;

        return $this;
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400 && isset($this->headers['Location']);
    }

    public function contentType(): string
    {
        return isset($this->headers['Content-Type']) ? $this->headers['Content-Type'] : '';
    }

    /** Emite la respuesta. Solo lo llama el punto de entrada. */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . str_replace(["\r", "\n"], '', $value), true);
            }
            foreach ($this->cookies as $name => $cookie) {
                $o = $cookie['options'];
                setcookie($name, $cookie['value'], [
                    'expires' => (int) $o['expires'],
                    'path' => (string) $o['path'],
                    'domain' => (string) $o['domain'],
                    'secure' => (bool) $o['secure'],
                    'httponly' => (bool) $o['httponly'],
                    'samesite' => (string) $o['samesite'],
                ]);
            }
        }
        if ($this->file !== null) {
            if (is_file($this->file)) {
                if (!headers_sent()) {
                    header('Content-Length: ' . (string) filesize($this->file));
                }
                readfile($this->file);
            }

            return;
        }
        if ($this->writer !== null) {
            // Por partes: sin compresión (un trozo comprimido no sale hasta llenar el búfer) y vaciando tras cada trozo.
            @ini_set('zlib.output_compression', '0');
            $writer = $this->writer;
            $this->writer = null;
            $writer(static function (string $chunk): void {
                echo $chunk;
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            });

            return;
        }
        echo $this->body;
    }
}

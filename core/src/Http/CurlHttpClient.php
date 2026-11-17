<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Http;

/** Cliente HTTP real, sobre cURL. No sigue redirecciones salvo que se pida. */
final class CurlHttpClient implements HttpClient
{
    /** @var string */
    private $userAgent;

    /** @var bool */
    private $followRedirects;

    /** @var string|null */
    private $caBundle;

    /** @var string servidor de DNS sobre HTTPS para el segundo intento cuando el DNS del servidor falla; vacío = sin él */
    private $dohUrl;

    /** @var callable|null quien ejecuta la petición (para las pruebas): function ($ch): array{0:string|false,1:int,2:string} */
    private $exec;

    /** Código de cURL «no se pudo resolver el nombre» (CURLE_COULDNT_RESOLVE_HOST). */
    private const DNS_FAILED = 6;

    public function __construct(string $userAgent = 'AIFEC/1.0', bool $followRedirects = false, ?string $caBundle = null, string $dohUrl = 'https://1.1.1.1/dns-query', ?callable $exec = null)
    {
        $this->userAgent = $userAgent;
        $this->followRedirects = $followRedirects;
        $this->caBundle = $caBundle;
        $this->dohUrl = $dohUrl;
        $this->exec = $exec;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new HttpException('cURL is not available');
        }
        $ch = curl_init();
        if ($ch === false) {
            throw new HttpException('cURL could not be initialised');
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $this->userAgent,
            // Conectar tarda décimas; si en 4 s no se ha conectado, no va a conectar: mejor saberlo pronto y repetir (abajo).
            CURLOPT_CONNECTTIMEOUT_MS => (int) max(1000, min(self::CONNECT_MS, round($request->timeout * 1000))),
            CURLOPT_TIMEOUT_MS => (int) max(1000, round($request->timeout * 1000)),
            CURLOPT_FOLLOWLOCATION => $this->followRedirects,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }

                return strlen($line);
            },
        ];
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        if ($this->caBundle !== null && is_file($this->caBundle)) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }
        curl_setopt_array($ch, $options);
        if ($this->prefersV4()) {
            $this->forceV4($ch);
        }

        [$body, $errno, $message] = $this->run($ch);
        if ($body === false && self::neverConnected($errno, $message)) {
            // No se llegó a conectar (13/11/2026, en el servidor de demostración y a ratos: «Failed to connect to
            // generativelanguage.googleapis.com port 443 after 10002 ms»; el asistente esperaba diez segundos y contestaba por
            // reglas). No ha salido nada hacia el otro lado, así que repetir es seguro también en un POST: un segundo intento
            // por IPv4 (lo habitual es una ruta IPv6 que no responde), y durante diez minutos las demás peticiones van por IPv4.
            // Medido ese día desde el servidor: su DNS devolvía a ratos una dirección que no es la del servicio (46.38.…, de
            // su propio alojamiento) y ahí nadie contesta en el 443. La petición se repite en un manejador NUEVO: el mismo
            // guarda un minuto la dirección mala que acaba de resolver, y repetir con él vuelve a llamar al mismo sitio.
            $responseHeaders = [];
            $fresh = $this->exec === null ? @curl_copy_handle($ch) : false;
            if ($fresh !== false && $fresh !== null) {
                if (PHP_VERSION_ID < 80000) {
                    curl_close($ch);
                }
                $ch = $fresh;
            }
            $this->forceV4($ch);
            [$body, $errno, $message] = $this->run($ch);
            if ($body !== false) {
                $this->rememberV4();
            }
        }
        if ($body === false && $errno === self::DNS_FAILED) {
            // El DNS del propio servidor falla a ratos (13/11/2026: «Could not resolve host» y el asistente contestaba por reglas
            // sin que nadie lo viera). No ha salido nada hacia el otro lado, así que repetir es seguro también en un POST: un
            // segundo intento, y si esta versión de cURL sabe, resolviendo el nombre por DNS sobre HTTPS con un servidor público.
            $responseHeaders = [];
            if ($this->dohUrl !== '' && defined('CURLOPT_DOH_URL')) {
                @curl_setopt($ch, (int) constant('CURLOPT_DOH_URL'), $this->dohUrl);
            }
            usleep(150000);
            [$body, $errno, $message] = $this->run($ch);
        }
        $error = $body === false ? $message . ' (' . $errno . ')' : '';
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        if ($body === false) {
            throw new HttpException('HTTP request failed: ' . $error);
        }

        return new HttpResponse($status, $responseHeaders, (string) $body);
    }

    /** Milisegundos como mucho para conectar (no para recibir la respuesta: eso es el tiempo de la petición). */
    public const CONNECT_MS = 4000;

    /** Segundos que se prefiere IPv4 después de que una conexión solo saliera por IPv4. */
    public const V4_SECONDS = 600;

    /**
     * ¿El fallo fue de conexión, sin llegar a mandar nada? 7 es «no se pudo conectar»; 28 es cualquier tiempo agotado, y solo
     * cuenta si el texto de cURL dice que fue al conectar (si se agotó esperando la respuesta, la petición sí llegó).
     */
    public static function neverConnected(int $errno, string $message): bool
    {
        // 7: no se pudo conectar. 51 y 60: el certificado no es el de ese nombre (se ha llegado a otra máquina): tampoco ha
        // salido la petición.
        if ($errno === 7 || $errno === 51 || $errno === 60) {
            return true;
        }

        return $errno === 28 && preg_match('/^(Failed to connect|Connection timed out|Connection timeout|connect to .+ timed out)/i', trim($message)) === 1;
    }

    /**
     * El camino seguro para el segundo intento: por IPv4 y con el nombre resuelto por DNS sobre HTTPS en un servidor público.
     * Lo segundo es lo que arregla el caso real: cuando el DNS del propio servidor falla a ratos, el sistema prueba el nombre
     * con su dominio de búsqueda, un comodín lo manda a otra máquina y ahí nadie contesta en el 443 («Failed to connect»).
     *
     * @param resource|\CurlHandle $ch
     */
    private function forceV4($ch): void
    {
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            @curl_setopt($ch, (int) constant('CURLOPT_IPRESOLVE'), (int) constant('CURL_IPRESOLVE_V4'));
        }
        if ($this->dohUrl !== '' && defined('CURLOPT_DOH_URL')) {
            @curl_setopt($ch, (int) constant('CURLOPT_DOH_URL'), $this->dohUrl);
        }
    }

    /** La marca de «por IPv4» vive en la carpeta temporal del sistema: vale para todas las peticiones de PHP de esta máquina. */
    private function v4Flag(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . '/aifec-http-ipv4';
    }

    private function prefersV4(): bool
    {
        if ($this->exec !== null) {
            return false; // en las pruebas no se mira ni se deja nada en el disco
        }
        $at = @filemtime($this->v4Flag());

        return is_int($at) && $at > time() - self::V4_SECONDS;
    }

    private function rememberV4(): void
    {
        if ($this->exec === null) {
            @touch($this->v4Flag());
        }
    }

    /**
     * Ejecuta la petición ya preparada: [cuerpo o false, código de error de cURL, su texto].
     *
     * @param resource|\CurlHandle $ch
     *
     * @return array{0:string|false,1:int,2:string}
     */
    private function run($ch): array
    {
        if ($this->exec !== null) {
            $result = ($this->exec)($ch);

            return [is_string($result[0]) ? $result[0] : false, (int) $result[1], (string) $result[2]];
        }
        $body = curl_exec($ch);

        return [is_string($body) ? $body : false, $body === false ? curl_errno($ch) : 0, $body === false ? curl_error($ch) : ''];
    }
}

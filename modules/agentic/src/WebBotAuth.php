<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Agentic;

use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;

/**
 * Web Bot Auth: la firma con la que un agente de IA dice quién es (RFC 9421, firmas de mensajes HTTP, con Ed25519).
 *
 * El agente de ChatGPT (y el monedero de ChatGPT, que compra con él) firma cada petición con las cabeceras Signature-Input,
 * Signature y Signature-Agent. La clave pública se publica en «<origen del agente>/.well-known/http-message-signatures-directory».
 * Aquí se comprueba la firma de verdad: solo se cree a los agentes de la lista de confianza (nunca se descarga nada de otro
 * origen), la firma tiene que cubrir el dominio de esta tienda (@authority) y la cabecera Signature-Agent, y tiene que estar
 * en su ventana de validez. Una firma que no se puede comprobar no bloquea nada: la petición sigue como una visita normal.
 */
final class WebBotAuth
{
    /** Ventana de una firma sin «expires»: cinco minutos desde «created». */
    private const MAX_AGE = 300;

    /** Una firma no puede valer más de un día. */
    private const MAX_WINDOW = 86400;

    /** Diferencia de reloj que se tolera. */
    private const SKEW = 60;

    /** Cuánto se guarda el directorio de claves de un agente (y cuánto se espera para reintentar si falló). */
    private const DIRECTORY_TTL = 3600;
    private const DIRECTORY_RETRY = 300;

    /** Nombres de los agentes conocidos, por su origen. */
    private const NAMES = ['https://chatgpt.com' => 'ChatGPT'];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Comprueba la firma de la petición.
     *
     * @return array<string,mixed>|null el agente verificado (agent, name, keyid, forwarded) o null si no hay firma válida
     */
    public function verify(Request $request): ?array
    {
        $input = (string) $request->header('signature-input', '');
        $signatures = (string) $request->header('signature', '');
        if ($input === '' || $signatures === '' || strlen($input) > 4096 || strlen($signatures) > 4096 || !function_exists('sodium_crypto_sign_verify_detached')) {
            return null;
        }
        $parsedInput = self::parseInput($input);
        $parsedSignatures = self::parseSignatures($signatures);
        $agentHeader = (string) $request->header('signature-agent', '');
        $now = $this->app->clock()->now();
        foreach ($parsedInput as $label => $entry) {
            if (!isset($parsedSignatures[$label]) || (isset($entry['params']['tag']) ? $entry['params']['tag'] : '') !== 'web-bot-auth') {
                continue;
            }
            $agent = self::agentOf($agentHeader, $label);
            if ($agent === '' || !in_array($agent, $this->trusted(), true)) {
                continue;
            }
            if (!self::fresh($entry['params'], $now) || !in_array('"@authority"', $entry['components'], true) || !self::coversAgent($entry['components'])) {
                continue;
            }
            $keyid = isset($entry['params']['keyid']) && is_string($entry['params']['keyid']) ? $entry['params']['keyid'] : '';
            $key = $keyid !== '' ? $this->key($agent, $keyid, $now) : null;
            if ($key === null) {
                continue;
            }
            $base = self::signatureBase($request, $entry, $this->basePath());
            if ($base === null || !self::verifyEd25519($base, $parsedSignatures[$label], $key)) {
                continue;
            }

            return [
                'agent' => $agent,
                'name' => isset(self::NAMES[$agent]) ? self::NAMES[$agent] : (string) parse_url($agent, PHP_URL_HOST),
                'keyid' => $keyid,
                'forwarded' => self::forwarded((string) $request->header('forwarded', '')),
            ];
        }

        return null;
    }

    /**
     * Los orígenes de confianza (ajuste agentic.trusted_agents, uno por línea).
     *
     * @return string[]
     */
    public function trusted(): array
    {
        $list = [];
        foreach (preg_split('/[\s,]+/', $this->app->settings()->str('agentic.trusted_agents', 'https://chatgpt.com')) ?: [] as $line) {
            $origin = self::origin($line);
            if ($origin !== '') {
                $list[] = $origin;
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * Lee Signature-Input: etiqueta => ['components' => identificadores tal como vienen ('"@authority"'), 'params' =>
     * parámetros, 'raw' => la lista con sus parámetros tal como llegó, que es la línea @signature-params].
     *
     * @return array<string,array{components:string[],params:array<string,mixed>,raw:string}>
     */
    public static function parseInput(string $header): array
    {
        $out = [];
        $len = strlen($header);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && ($header[$i] === ' ' || $header[$i] === ',' || $header[$i] === "\t")) {
                ++$i;
            }
            if (preg_match('/\G([a-z*][a-z0-9_\-.*]*)=\(/', $header, $m, 0, $i) !== 1) {
                break;
            }
            $label = $m[1];
            $start = $i + strlen($label) + 1;
            $i = $start + 1;
            $components = [];
            while ($i < $len && $header[$i] !== ')') {
                if ($header[$i] === ' ') {
                    ++$i;
                    continue;
                }
                $item = self::readString($header, $i);
                if ($item === null) {
                    return [];
                }
                $itemStart = $i;
                $i = $item[1];
                // Parámetros del componente (;key="sig1", ;sf…): forman parte del identificador.
                while ($i < $len && $header[$i] === ';') {
                    $param = self::readParam($header, $i);
                    if ($param === null) {
                        return [];
                    }
                    $i = $param[2];
                }
                $components[] = substr($header, $itemStart, $i - $itemStart);
            }
            if ($i >= $len) {
                return [];
            }
            ++$i;
            $params = [];
            while ($i < $len && $header[$i] === ';') {
                $param = self::readParam($header, $i);
                if ($param === null) {
                    return [];
                }
                $params[$param[0]] = $param[1];
                $i = $param[2];
            }
            $out[$label] = ['components' => $components, 'params' => $params, 'raw' => substr($header, $start, $i - $start)];
        }

        return $out;
    }

    /**
     * Lee Signature: etiqueta => la firma en bytes.
     *
     * @return array<string,string>
     */
    public static function parseSignatures(string $header): array
    {
        $out = [];
        if (preg_match_all('/([a-z*][a-z0-9_\-.*]*)=:([A-Za-z0-9+\/=]+):/', $header, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $bytes = base64_decode($match[2], true);
                if ($bytes !== false) {
                    $out[$match[1]] = $bytes;
                }
            }
        }

        return $out;
    }

    /**
     * La base de la firma (RFC 9421, apartado 2.5): una línea por componente y al final @signature-params. null si un
     * componente no se puede reconstruir (una cabecera que no ha llegado, un componente derivado que aquí no se usa).
     *
     * @param array{components:string[],params:array<string,mixed>,raw:string} $entry
     */
    public static function signatureBase(Request $request, array $entry, string $basePath = ''): ?string
    {
        $lines = [];
        foreach ($entry['components'] as $identifier) {
            if (preg_match('/^"([^"]+)"(.*)$/D', $identifier, $m) !== 1) {
                return null;
            }
            $name = strtolower($m[1]);
            $member = preg_match('/;key="([^"]*)"/', $m[2], $k) === 1 ? $k[1] : null;
            $value = self::componentValue($request, $name, $member, $basePath);
            if ($value === null) {
                return null;
            }
            $lines[] = $identifier . ': ' . $value;
        }
        $lines[] = '"@signature-params": ' . $entry['raw'];

        return implode("\n", $lines);
    }

    public static function verifyEd25519(string $base, string $signature, string $publicKey): bool
    {
        if (strlen($signature) !== 64 || strlen($publicKey) !== 32 || !function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($signature, $base, $publicKey);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** La huella de una clave Ed25519 en JWK (RFC 7638): el identificador que usan los directorios de claves. */
    public static function thumbprint(string $x): string
    {
        return self::b64url(hash('sha256', '{"crv":"Ed25519","kty":"OKP","x":"' . $x . '"}', true));
    }

    /**
     * Los datos de la persona que manda el agente en Forwarded (país, idioma, zona horaria). La dirección «for» solo se
     * acepta si es una IP.
     *
     * @return array<string,string>
     */
    public static function forwarded(string $header): array
    {
        $out = [];
        if ($header === '' || strlen($header) > 2048) {
            return $out;
        }
        if (preg_match_all('/([a-z][a-z0-9\-]*)=("(?:[^"\\\\]|\\\\.)*"|[^;,\s]*)/i', $header, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $name = strtolower($match[1]);
                $value = $match[2];
                if ($value !== '' && $value[0] === '"') {
                    $value = stripcslashes(substr($value, 1, -1));
                }
                if (!isset($out[$name]) && strlen($value) <= 200) {
                    $out[$name] = $value;
                }
            }
        }
        if (isset($out['for'])) {
            $ip = trim($out['for'], '[]');
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                unset($out['for']);
            } else {
                $out['for'] = $ip;
            }
        }

        return $out;
    }

    /** scheme://host[:puerto] en minúsculas, o '' si no es una dirección https (o http a localhost, para pruebas). */
    public static function origin(string $url): string
    {
        $url = trim($url, " \t\"'");
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true))) {
            return '';
        }

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    // ------------------------------------------------------------------ piezas

    /** El origen del agente: Signature-Agent como cadena («"https://chatgpt.com"») o como diccionario por etiqueta. */
    private static function agentOf(string $header, string $label): string
    {
        $header = trim($header);
        if ($header === '') {
            return '';
        }
        if ($header[0] === '"') {
            return self::origin(substr($header, 1, (int) strpos($header, '"', 1) - 1));
        }
        if (preg_match('/(?:^|[\s,])' . preg_quote($label, '/') . '="([^"]*)"/', $header, $m) === 1) {
            return self::origin($m[1]);
        }

        return '';
    }

    /** @param string[] $components */
    private static function coversAgent(array $components): bool
    {
        foreach ($components as $component) {
            if (strpos($component, '"signature-agent"') === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $params */
    private static function fresh(array $params, int $now): bool
    {
        $created = isset($params['created']) && is_int($params['created']) ? $params['created'] : null;
        $expires = isset($params['expires']) && is_int($params['expires']) ? $params['expires'] : null;
        if ($created === null || $created > $now + self::SKEW) {
            return false;
        }
        if (isset($params['alg']) && $params['alg'] !== 'ed25519') {
            return false;
        }
        if ($expires !== null) {
            return $expires >= $now - self::SKEW && $expires > $created && $expires - $created <= self::MAX_WINDOW;
        }

        return $now - $created <= self::MAX_AGE;
    }

    /** La clave pública (32 bytes) con ese identificador en el directorio del agente, o null. */
    private function key(string $agent, string $keyid, int $now): ?string
    {
        foreach ($this->directory($agent) as $jwk) {
            if (!is_array($jwk) || (isset($jwk['kty']) ? $jwk['kty'] : '') !== 'OKP' || (isset($jwk['crv']) ? $jwk['crv'] : '') !== 'Ed25519' || !isset($jwk['x']) || !is_string($jwk['x'])) {
                continue;
            }
            $kid = isset($jwk['kid']) && is_string($jwk['kid']) ? $jwk['kid'] : '';
            if ($kid !== $keyid && self::thumbprint($jwk['x']) !== $keyid) {
                continue;
            }
            if ((isset($jwk['nbf']) && is_int($jwk['nbf']) && $jwk['nbf'] > $now + self::SKEW) || (isset($jwk['exp']) && is_int($jwk['exp']) && $jwk['exp'] < $now)) {
                continue;
            }
            $raw = base64_decode(strtr($jwk['x'], '-_', '+/'), true);
            if ($raw !== false && strlen($raw) === 32) {
                return $raw;
            }
        }

        return null;
    }

    /**
     * Las claves publicadas por el agente (guardadas una hora; si el directorio no responde, se reintenta a los cinco minutos).
     *
     * @return array<int,mixed>
     */
    private function directory(string $agent): array
    {
        $cacheKey = 'agentic.wba.' . sha1($agent);
        $cached = $this->app->cache()->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        $keys = [];
        try {
            $response = $this->app->http()->send(HttpRequest::get($agent . '/.well-known/http-message-signatures-directory', ['Accept' => 'application/http-message-signatures-directory+json, application/json'], 4.0));
            $data = $response->ok() ? $response->json() : null;
            if (is_array($data) && isset($data['keys']) && is_array($data['keys'])) {
                $keys = array_values(array_slice($data['keys'], 0, 20));
            }
        } catch (HttpException $e) {
            $keys = [];
        }
        $this->app->cache()->set($cacheKey, $keys, $keys === [] ? self::DIRECTORY_RETRY : self::DIRECTORY_TTL);

        return $keys;
    }

    private function basePath(): string
    {
        try {
            return $this->app->site()->basePath;
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function componentValue(Request $request, string $name, ?string $member, string $basePath): ?string
    {
        switch ($name) {
            case '@method':
                return strtoupper($request->method);
            case '@authority':
                return strtolower($request->host);
            case '@scheme':
                return $request->secure ? 'https' : 'http';
            case '@path':
                return self::encodedPath($basePath . $request->rawPath);
            case '@query':
                return '?' . http_build_query($request->query, '', '&', PHP_QUERY_RFC3986);
            case '@target-uri':
                $query = $request->query !== [] ? '?' . http_build_query($request->query, '', '&', PHP_QUERY_RFC3986) : '';

                return ($request->secure ? 'https' : 'http') . '://' . strtolower($request->host) . self::encodedPath($basePath . $request->rawPath) . $query;
        }
        if ($name === '' || $name[0] === '@') {
            return null;
        }
        $value = $request->header($name);
        if ($value === null) {
            return null;
        }
        $value = trim(preg_replace('/\s*\r?\n\s*/', ' ', $value) ?? '');
        if ($member === null) {
            return $value;
        }
        // ;key="x": el valor de ese miembro del diccionario.
        if (preg_match('/(?:^|[\s,])' . preg_quote($member, '/') . '=("[^"]*"|[^,\s]*)/', $value, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private static function encodedPath(string $path): string
    {
        $segments = explode('/', $path === '' ? '/' : $path);

        return implode('/', array_map(static function (string $s): string {
            return str_replace('%7E', '~', rawurlencode($s));
        }, $segments));
    }

    /** @return array{0:string,1:int}|null la cadena y la posición siguiente */
    private static function readString(string $s, int $i): ?array
    {
        if (!isset($s[$i]) || $s[$i] !== '"') {
            return null;
        }
        $out = '';
        $len = strlen($s);
        for ($j = $i + 1; $j < $len; ++$j) {
            if ($s[$j] === '\\' && $j + 1 < $len) {
                $out .= $s[++$j];
                continue;
            }
            if ($s[$j] === '"') {
                return [$out, $j + 1];
            }
            $out .= $s[$j];
        }

        return null;
    }

    /** @return array{0:string,1:mixed,2:int}|null nombre, valor y posición siguiente de «;nombre=valor» */
    private static function readParam(string $s, int $i): ?array
    {
        if (preg_match('/\G;\s*([a-z*][a-z0-9_\-.*]*)/', $s, $m, 0, $i) !== 1) {
            return null;
        }
        $name = $m[1];
        $i += strlen($m[0]);
        if (!isset($s[$i]) || $s[$i] !== '=') {
            return [$name, true, $i];
        }
        ++$i;
        if (isset($s[$i]) && $s[$i] === '"') {
            $str = self::readString($s, $i);

            return $str === null ? null : [$name, $str[0], $str[1]];
        }
        if (preg_match('/\G(-?\d{1,15})(?![.\d])/', $s, $n, 0, $i) === 1) {
            return [$name, (int) $n[1], $i + strlen($n[1])];
        }
        if (preg_match('/\G([A-Za-z*][A-Za-z0-9_\-.:\/%*]*|\?[01])/', $s, $t, 0, $i) === 1) {
            return [$name, $t[1], $i + strlen($t[1])];
        }

        return null;
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

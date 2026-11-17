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
 * Caché de página completa para visitantes sin cuenta abierta (también con carrito o con sesión de cliente).
 *
 * Se consulta ANTES de arrancar la aplicación (sin base de datos, sin módulos): una página guardada
 * se sirve en muy pocos milisegundos. Solo se guarda lo que el controlador marcó como cacheable
 * (`$response->cacheable($ttl, $etiquetas)`) y solo si la respuesta no pone cookies.
 *
 * Lo personal de una página (el número de artículos del carrito) no va en el HTML: lo pinta el
 * navegador leyendo una cookie, así que la misma copia vale para todos.
 */
final class PageCache
{
    /** Parámetros de seguimiento que no cambian la página y no deben multiplicar las copias. */
    private const TRACKING = '/^(utm_[a-z]+|gclid|gbraid|wbraid|fbclid|msclkid|mc_[a-z]+|_ga|_gl|ref|srsltid)$/';

    /**
     * Clave de la copia para esta petición, o null si la petición no puede salir de la caché.
     */
    public static function key(Site $site, Request $request): ?string
    {
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return null;
        }
        // Sin copia solo para: (a) quien tiene abierto el panel, (b) quien tiene abierta su cuenta de cliente (la marca
        // legible «u»), (d) quien tiene pendiente algo que la página siguiente pinta en el servidor (la marca «nc»: un aviso
        // tras una acción sin JavaScript, los errores de un formulario) y quien se identifica con una cabecera. La cookie de
        // sesión de cliente sola (un carrito, una preferencia) ya no cuenta: lo de cada visitante lo pinta el JavaScript del
        // tema con las marcas «ck» y «u», y la copia es la misma para todos.
        $suffix = $site->cookieSuffix();
        if (isset($request->cookies['af_a_' . $suffix]) || isset($request->cookies['af_u_' . $suffix])
            || isset($request->cookies['af_nc_' . $suffix]) || $request->header('authorization') !== null) {
            return null;
        }
        // Una persona que llega desde un asistente de IA (página de origen o utm_source) no sale de la caché: hay que
        // apuntar de dónde viene, y eso lo hace la aplicación («request.start»), que con una copia guardada ni arranca.
        // Los parámetros utm_* no cuentan para la clave, así que sin esto su visita sería un acierto como cualquier otro.
        // Un robot sí sale de la caché: no compra, y su visita la apunta el registro de visitas.
        if (VisitLog::aiSource($request) !== null && VisitLog::bot($request->userAgent()) === null) {
            return null;
        }
        $query = [];
        foreach ($request->query as $name => $value) {
            if (preg_match(self::TRACKING, (string) $name) === 1) {
                continue;
            }
            if (!is_scalar($value)) {
                return null;
            }
            $query[(string) $name] = (string) $value;
        }
        if (count($query) > 4) {
            return null;
        }
        ksort($query);
        $qs = http_build_query($query);
        if (strlen($qs) > 160) {
            return null;
        }
        $variant = $request->wantsMarkdown() ? 'md' : ($request->wantsJson() ? 'json' : 'html');

        return 'page:' . md5(strtolower($request->host) . '|' . $request->rawPath . '|' . $qs . '|' . $variant . '|' . ($request->secure ? 's' : 'p'));
    }

    /** Sirve la copia guardada, si la hay y sigue valiendo. */
    public static function serve(Site $site, Request $request, Clock $clock): ?Response
    {
        $key = self::key($site, $request);
        if ($key === null || !is_dir($site->path('cache'))) {
            return null;
        }
        $cache = new Cache($site->path('cache'), $clock);
        $entry = $cache->get($key);
        if (!is_array($entry) || !isset($entry['b'], $entry['h'])) {
            return null;
        }
        $response = new Response($request->method === 'HEAD' ? '' : (string) $entry['b'], 200, (array) $entry['h']);
        $response->headers['X-Cache'] = 'HIT';

        return $response;
    }

    /** Guarda la respuesta si cumple todas las condiciones. Devuelve true si la guardó. */
    public static function store(App $app, Request $request, Response $response, bool $sessionStarted): bool
    {
        if ($response->cacheTtl <= 0 || $response->status !== 200 || $request->method !== 'GET'
            || $response->cookies !== [] || $sessionStarted || $response->file !== null
            || !$app->settings()->bool('cache.pages', true)) {
            return false;
        }
        $key = self::key($app->site(), $request);
        if ($key === null) {
            return false;
        }
        $headers = [];
        foreach ($response->headers as $name => $value) {
            if (!in_array(strtolower($name), ['set-cookie', 'server-timing', 'x-cache'], true)) {
                $headers[$name] = $value;
            }
        }
        $app->cache()->set($key, ['b' => $response->body, 'h' => $headers], $response->cacheTtl, array_merge(['pages'], $response->cacheTags));

        return true;
    }
}

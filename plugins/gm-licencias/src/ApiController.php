<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Licencias;

use Aifec\Kernel\App;
use Aifec\Kernel\RateLimiter;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * La API que consultan los módulos instalados en las tiendas de los clientes.
 *
 *   POST /api/gm/licencia/verificar    {key, domain}  → {valid, reason, updates_until, updates_active, domains_used, domains_max, activated}
 *   POST /api/gm/licencia/desactivar   {key, domain}  → {ok, deactivated}
 *
 * Reglas: las respuestas tienen siempre las mismas claves, exista la clave o no (una clave inventada y una revocada son
 * idénticas); hay un límite de uso por dirección y otro por clave, que se aplica ANTES de mirar si la clave existe; y lo que se
 * apunta de cada comprobación no lleva dirección, dominio ni correo. Se acepta formulario o JSON. Sin sesión ni ficha: la clave es
 * el secreto, y quien llama es otro programa.
 */
final class ApiController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params
     */
    public function verify(Request $request, array $params): Response
    {
        $licenses = $this->licenses();
        $blocked = $this->limited($request);
        if ($blocked !== null) {
            return $blocked;
        }
        $key = $request->str('key');
        if ($key === '' || strlen($key) > 80) {
            return Response::json($licenses->refusal('bad_request'), 400);
        }

        return Response::json($licenses->verify($key, $request->str('domain')));
    }

    /**
     * @param array<string,string> $params
     */
    public function deactivate(Request $request, array $params): Response
    {
        $blocked = $this->limited($request);
        if ($blocked !== null) {
            return $blocked;
        }
        $key = $request->str('key');
        if ($key === '' || strlen($key) > 80) {
            return Response::json(['ok' => false, 'deactivated' => false], 400);
        }

        return Response::json($this->licenses()->deactivate($key, $request->str('domain')));
    }

    /** El límite de uso por dirección y por clave (por minuto). Devuelve la respuesta 429, o null si puede seguir. */
    private function limited(Request $request): ?Response
    {
        $settings = $this->app->settings();
        $limiter = new RateLimiter($this->app->db(), $this->app->clock());
        $byAddress = $limiter->hit('gm_lic:ip:' . $request->ip, max(1, $settings->int('gm_licencias.rate_per_address', 30)), 60);
        $byKey = $limiter->hit('gm_lic:key:' . sha1(KeyFormat::normalize($request->str('key'))), max(1, $settings->int('gm_licencias.rate_per_key', 10)), 60);
        if ($byAddress && $byKey) {
            return null;
        }
        $this->licenses()->log(null, $request->path === '' ? 'verify' : 'rate', 'rate');
        $response = Response::json($this->licenses()->refusal('rate_limited'), 429);
        $response->headers['Retry-After'] = '60';

        return $response;
    }

    private function licenses(): Licenses
    {
        $service = $this->app->service('gm_licenses');
        if (!$service instanceof Licenses) {
            throw new \LogicException('The licences service is not the one of the plugin');
        }

        return $service;
    }
}

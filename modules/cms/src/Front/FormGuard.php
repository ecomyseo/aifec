<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Cms\Front;

use Aifec\Kernel\App;
use Aifec\Kernel\RateLimiter;
use Aifec\Kernel\Request;
use Aifec\Modules\Cms\Cms;
use Aifec\Security\Signer;

/**
 * Las defensas de los formularios públicos contra los robots, pensadas para no molestar a las personas:
 *
 *  - campo trampa: un campo oculto que una persona no ve y un robot rellena;
 *  - marca de tiempo firmada con la clave del sitio: dice cuándo se enfocó el formulario. Menos de 3 segundos o más de 24 horas
 *    es un envío de robot (o una página abandonada) y se descarta en silencio;
 *  - límite por dirección: 5 envíos por hora;
 *  - mensajes con más de 3 enlaces.
 *
 * Nada de esto guarda estado en el servidor salvo el contador del límite.
 */
final class FormGuard
{
    public const MIN_SECONDS = 3;

    public const MAX_SECONDS = 86400;

    public const HOURLY_LIMIT = 5;

    public const MAX_LINKS = 3;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** ¿Ha rellenado el campo trampa? Cualquier cosa que no sea una cadena vacía cuenta. */
    public function trapFilled(Request $request): bool
    {
        $value = $request->input(Cms::FORM_TRAP);
        if ($value === null) {
            return false;
        }
        if (is_scalar($value)) {
            return trim((string) $value) !== '';
        }

        return true;
    }

    /** La marca de tiempo que rellena el JavaScript al enfocar el primer campo de un formulario. */
    public function issue(string $code): string
    {
        $now = $this->app->clock()->now();

        return $this->signer()->sign(['f' => $code, 't' => $now], $now + self::MAX_SECONDS);
    }

    /**
     * Lo que llega en el campo de la marca. Sin marca (la persona no usa JavaScript) no hay nada que comprobar y se acepta;
     * con marca, tiene que estar bien firmada, ser de este formulario y ser de hace entre 3 segundos y 24 horas.
     */
    public function stampAcceptable(string $token, string $code): bool
    {
        $token = trim($token);
        if ($token === '') {
            return true;
        }
        $now = $this->app->clock()->now();
        $payload = $this->signer()->verify($token, $now);
        if ($payload === null || !isset($payload['f'], $payload['t']) || $payload['f'] !== $code || !is_int($payload['t'])) {
            return false;
        }

        return $now - $payload['t'] >= self::MIN_SECONDS;
    }

    /**
     * Apunta un envío de esta dirección. False si ya se pasó del tope de la hora (y no lo apunta).
     */
    public function allow(Request $request): bool
    {
        $limiter = new RateLimiter($this->app->db(), $this->app->clock());

        return $limiter->hit('cms.form:' . $request->ip, self::HOURLY_LIMIT, 3600);
    }

    /** Cuántos enlaces lleva un texto: con protocolo, con «www.» o como etiqueta. */
    public static function countLinks(string $text): int
    {
        $count = preg_match_all('#(?:https?://|ftp://|www\.)[^\s<>"\']+#i', $text);
        $tags = preg_match_all('#<a\s#i', $text);

        return max((int) $count, (int) $tags);
    }

    private function signer(): Signer
    {
        return new Signer(hash_hmac('sha256', 'aifec.cms.form-stamp', $this->app->site()->secret()));
    }
}

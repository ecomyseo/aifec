<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Themes;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;

/**
 * Vista previa del personalizador: los valores sin publicar viven en la caché del servidor y solo los ve quien lleva la
 * cookie de vista previa (que solo da el panel a quien tiene permiso) Y pide la página con «?af_preview=1».
 *
 * La cookie de sesión del panel solo viaja por «/admin», así que la tienda no puede leer de ahí: por eso hay una cookie
 * propia, al azar, de sesión del navegador y que apunta a un borrador guardado en el servidor. Ningún visitante normal la
 * tiene. Los valores se aplican únicamente en memoria durante esa petición (ver Memory) y la respuesta nunca se guarda
 * en la caché de página.
 */
final class Preview
{
    public const PARAM = 'af_preview';
    private const TTL = 3600;

    /** ¿Trae la petición el parámetro de vista previa? (Sea o no válida: en cualquier caso no se indexa ni se guarda.) */
    public static function requested(Request $request): bool
    {
        return array_key_exists(self::PARAM, $request->query);
    }

    private static function key(string $token): string
    {
        return 'themes.preview.' . $token;
    }

    private static function token(App $app, Request $request): string
    {
        $token = $request->cookie($app->cookieName('pv'), '');

        return is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? $token : '';
    }

    /**
     * Guarda el borrador de quien está en el panel y le da la cookie. Sustituye al que tuviera.
     *
     * @param array<string,mixed> $values opciones ya validadas del tema $slug
     */
    public static function start(App $app, string $slug, array $values): string
    {
        $old = self::token($app, $app->request());
        if ($old !== '') {
            $app->cache()->delete(self::key($old));
        }
        $token = bin2hex(random_bytes(16));
        $app->cache()->set(self::key($token), ['slug' => $slug, 'values' => $values, 'admin' => $app->auth()->adminId()], self::TTL, ['themes.preview']);
        $app->setCookie($app->cookieName('pv'), $token, 0);

        return $token;
    }

    /**
     * El borrador de quien hace la petición, si lo tiene y es del tema que se pide.
     *
     * @return array<string,mixed>|null
     */
    public static function draft(App $app, Request $request, ?string $slug = null): ?array
    {
        $token = self::token($app, $request);
        if ($token === '') {
            return null;
        }
        $entry = $app->cache()->get(self::key($token));
        if (!is_array($entry) || !isset($entry['slug'], $entry['values']) || !is_array($entry['values'])) {
            return null;
        }
        if ($slug !== null && $entry['slug'] !== $slug) {
            return null;
        }

        return $entry;
    }

    /** Borra el borrador de quien hace la petición (al publicar o restablecer). */
    public static function forget(App $app): void
    {
        $token = self::token($app, $app->request());
        if ($token !== '') {
            $app->cache()->delete(self::key($token));
            $app->forgetCookie($app->cookieName('pv'));
        }
    }

    /**
     * Al empezar una petición con «?af_preview=1»: si trae un borrador válido del tema activo, lo pone en memoria. Devuelve
     * lo que hay que restaurar al acabar, o null si no se aplicó nada.
     *
     * @return array<string,array{0:bool,1:mixed}>|null
     */
    public static function begin(App $app, Request $request): ?array
    {
        $active = $app->settings()->str('theme.active', 'base');
        $entry = self::draft($app, $request, $active);
        if ($entry === null) {
            return null;
        }
        $all = $app->settings()->arr('theme.options');
        $own = isset($all[$active]) && is_array($all[$active]) ? $all[$active] : [];
        $all[$active] = array_merge($own, $entry['values']);

        return Memory::apply($app, ['theme.options' => $all]);
    }

    /**
     * Quita la marca «?af_preview» de una dirección (la canónica no puede llevarla).
     */
    public static function stripParam(string $url): string
    {
        $url = (string) preg_replace('/([?&])' . self::PARAM . '=[^&#]*(&|$)/', '$1', $url);
        $url = (string) preg_replace('/&$/', '', $url);

        return rtrim($url, '?');
    }
}

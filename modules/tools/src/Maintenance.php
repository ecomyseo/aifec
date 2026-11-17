<?php
/**
 * AIFEC — modo mantenimiento: la tienda contesta 503 a las visitas y deja pasar al panel, a quien tiene sesión del panel,
 * a los avisos de las pasarelas de pago y a la dirección web de cron.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Security\Signer;

/**
 * La cookie de sesión del panel solo viaja a /admin, así que una persona del equipo que entra después a la tienda no la
 * lleva. Por eso, mientras el mantenimiento está puesto, cada visita al panel con sesión pone una segunda cookie, firmada
 * y con caducidad, en la ruta de la tienda: es la que deja pasar. No lleva datos de nadie.
 *
 * La página que ve quien pasa por esa vía lleva una banda de aviso y no se guarda en la caché de página: si se guardara,
 * la vería todo el mundo, banda incluida.
 */
final class Maintenance
{
    /** Segundos que vale el pase de quien tiene sesión del panel (lo mismo que la propia sesión). */
    private const PASS_TTL = 43200;

    public static function isOn(App $app): bool
    {
        return $app->settings()->bool('tools.maintenance', false);
    }

    /**
     * Filtro «request.start»: contesta 503 a quien no esté exento.
     *
     * @param mixed $early
     *
     * @return mixed
     */
    public static function early($early, Request $request, App $app)
    {
        if ($early instanceof Response || !self::isOn($app)) {
            return $early;
        }
        $path = $request->path;
        if (self::isAdminPath($path)) {
            // Quien trabaja en el panel con el mantenimiento puesto recibe el pase para ver también la tienda.
            if ($app->auth()->admin() !== null && !self::validPass($request, $app)) {
                $expires = $app->clock()->now() + self::PASS_TTL;
                $app->setCookie($app->cookieName('m'), self::signer($app)->sign(['m' => 1], $expires), 1);
            }

            return $early;
        }
        if (self::isOpenPath($path, $app)) {
            return $early;
        }
        if ($app->auth()->admin() !== null || self::validPass($request, $app)) {
            $request->setAttr('tools.bypass', true);

            return $early;
        }

        return self::answer($request, $app);
    }

    /**
     * Filtro «response»: pone la banda de aviso en la tienda de quien ha pasado por el pase y evita que su página se guarde.
     *
     * @param mixed $response
     *
     * @return mixed
     */
    public static function banner($response, Request $request, App $app)
    {
        if (!$response instanceof Response || $request->attr('tools.bypass', false) !== true) {
            return $response;
        }
        $response->cacheable(0);
        if (strpos($response->contentType(), 'text/html') !== 0) {
            return $response;
        }
        $bar = '<div role="status" style="position:sticky;top:0;z-index:99999;margin:0;padding:8px 16px;background:#7a4b00;color:#ffffff;'
            . 'font:14px/1.4 system-ui,sans-serif;text-align:center">'
            . e(t('Maintenance mode is on. Visitors cannot see the shop; you can because you are signed in to the panel.'))
            . ' <a style="color:#ffffff;text-decoration:underline" href="' . e($app->url()->plain('/admin/tools/cache')) . '">' . e(t('Turn it off')) . '</a></div>';
        $body = preg_replace_callback('/<body\b[^>]*>/i', static function (array $m) use ($bar): string {
            return $m[0] . $bar;
        }, $response->body, 1);
        if (is_string($body)) {
            $response->body = $body;
        }

        return $response;
    }

    /**
     * Filtro «admin.status»: aviso bien visible en el inicio del panel.
     *
     * @param mixed $status
     *
     * @return array<int,array<string,string>>
     */
    public static function status($status, App $app): array
    {
        $status = is_array($status) ? $status : [];
        if (self::isOn($app) && $app->auth()->can('tools.view')) {
            $status[] = [
                'kind' => 'warn',
                'text' => t('Maintenance mode is on: visitors see a maintenance page'),
                'url' => $app->url()->plain('/admin/tools/cache'),
                'label' => t('Turn it off'),
            ];
        }

        return $status;
    }

    /** Hueco «admin.foot»: una banda fija abajo en todas las pantallas del panel mientras el mantenimiento está puesto. */
    public static function adminBar(App $app): string
    {
        if (!self::isOn($app) || $app->auth()->admin() === null) {
            return '';
        }

        return '<div role="status" style="position:fixed;left:0;right:0;bottom:0;z-index:60;padding:var(--s1) var(--s4);text-align:center;'
            . 'background:var(--ink);color:var(--surface);font-size:var(--f1)">'
            . e(t('Maintenance mode is on: visitors see a maintenance page.')) . ' '
            . '<a style="color:var(--surface)" href="' . e($app->url()->plain('/admin/tools/cache')) . '">' . e(t('Turn it off')) . '</a></div>';
    }

    /**
     * Acción «settings.saved»: al encender o apagar el mantenimiento se tira lo guardado en la caché de página, que se sirve
     * sin arrancar la aplicación y se saltaría el mantenimiento.
     *
     * @param string[] $keys
     */
    public static function settingsSaved(string $module, array $keys, App $app): void
    {
        if (in_array('tools.maintenance', $keys, true)) {
            $app->invalidate(['pages']);
        }
    }

    /** Enciende o apaga el mantenimiento desde el panel o la consola. */
    public static function set(App $app, bool $on): void
    {
        $app->settings()->set('tools.maintenance', $on);
        $app->invalidate(['pages']);
    }

    /** El mensaje para las visitas en un idioma; si no hay, el del idioma principal; si tampoco, el de serie. */
    public static function message(App $app, string $lang): string
    {
        $settings = $app->settings();
        $lang = (string) preg_replace('/[^a-z0-9_-]/i', '', $lang);
        $text = trim($settings->str('tools.maintenance_text_' . $lang));
        if ($text === '') {
            $text = trim($settings->str('tools.maintenance_text_' . $app->defaultLang()));
        }

        return $text !== '' ? $text : t('We are doing some maintenance. We will be back very soon.');
    }

    // ------------------------------------------------------------------ interior

    private static function isAdminPath(string $path): bool
    {
        return $path === '/admin' || strpos($path, '/admin/') === 0;
    }

    /** Lo que sigue funcionando: los avisos de las pasarelas, la dirección web de cron y los recursos estáticos. */
    private static function isOpenPath(string $path, App $app): bool
    {
        $payment = '/' . trim($app->settings()->str('urls.payment', 'pago'), '/');
        if ($path === $payment || strpos($path, $payment . '/') === 0) {
            return true;
        }

        return strpos($path, '/cron/') === 0 || strpos($path, '/assets/') === 0;
    }

    private static function isMachinePath(Request $request): bool
    {
        $path = $request->path;

        return $path === '/api' || strpos($path, '/api/') === 0 || $path === '/mcp' || strpos($path, '/mcp/') === 0 || $request->wantsJson();
    }

    private static function validPass(Request $request, App $app): bool
    {
        $token = $request->cookie($app->cookieName('m'));
        if ($token === null || $token === '') {
            return false;
        }
        $payload = self::signer($app)->verify($token, $app->clock()->now());

        return is_array($payload) && isset($payload['m']) && $payload['m'] === 1;
    }

    private static function signer(App $app): Signer
    {
        return new Signer(hash('sha256', 'tools.maintenance|' . $app->site()->secret() . '|' . $app->site()->id));
    }

    private static function answer(Request $request, App $app): Response
    {
        $message = self::message($app, $app->lang());
        if (self::isMachinePath($request)) {
            $response = Response::json(['error' => ['status' => 503, 'message' => $message]], 503);
        } else {
            $data = ['title' => t('Back soon'), 'message' => $message, 'status' => 503, 'lang' => $app->lang()];
            $html = '';
            foreach (['maintenance', 'tools::front/maintenance'] as $template) {
                try {
                    if ($app->view()->exists($template)) {
                        $html = $app->view()->render($template, $data);
                        break;
                    }
                } catch (\Throwable $e) {
                    // La plantilla del tema no puede tumbar el aviso: se prueba con la propia.
                    $app->log()->exception($e, 'tools.maintenance');
                }
            }
            if ($html === '') {
                $html = '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><title>503</title><p>'
                    . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            }
            $response = Response::html($html, 503);
        }

        return $response
            ->header('Retry-After', '3600')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store');
    }
}

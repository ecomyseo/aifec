<?php
/**
 * AIFEC — comprobaciones de la tienda: datos de la tienda, depuración, HTTPS, cabeceras de seguridad y caché de la portada.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools\Checks;

use Aifec\Kernel\App;
use Aifec\Modules\Tools\Doctor;
use Aifec\Modules\Tools\Maintenance;

final class ShopChecks
{
    /** Cabeceras que el sistema pone a todas las páginas HTML. */
    private const HEADERS = ['X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy'];

    /** @return array<int,array<string,mixed>> */
    public static function all(App $app): array
    {
        $probe = new HomeProbe();

        return [
            ['id' => 'shop.identity', 'group' => 'shop', 'label' => t('Shop name and contact email'), 'run' => [self::class, 'identity']],
            ['id' => 'shop.debug', 'group' => 'shop', 'label' => t('Debug mode is off'), 'run' => [self::class, 'debug']],
            ['id' => 'shop.https', 'group' => 'shop', 'label' => t('The shop address uses HTTPS'), 'run' => [self::class, 'https']],
            ['id' => 'shop.headers', 'group' => 'shop', 'label' => t('Security headers on the home page'), 'run' => static function (App $app) use ($probe): array {
                return self::headers($app, $probe);
            }],
            ['id' => 'shop.cacheable', 'group' => 'shop', 'label' => t('The home page can be cached'), 'run' => static function (App $app) use ($probe): array {
                return self::cacheable($app, $probe);
            }],
        ];
    }

    /** ¿Es un sitio de pruebas (local o de demostración)? Mira la dirección, no el entorno. */
    public static function isLocal(App $app): bool
    {
        if ($app->site()->isDemo()) {
            return true;
        }
        $host = strtolower((string) parse_url($app->site()->url(), PHP_URL_HOST));
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]', ''], true)) {
            return true;
        }
        foreach (['.test', '.local', '.localhost', '.example'] as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,string> */
    public static function identity(App $app): array
    {
        $missing = [];
        if (trim($app->settings()->str('store.name')) === '') {
            $missing[] = t('the shop name');
        }
        if (trim($app->settings()->str('store.email')) === '') {
            $missing[] = t('the contact email');
        }
        if ($missing !== []) {
            return Doctor::result('warn', t('Missing: {list}', ['list' => implode(', ', $missing)]), $app->url()->plain('/admin/settings'));
        }

        return Doctor::result('ok', $app->settings()->str('store.name'));
    }

    /** @return array<string,string> */
    public static function debug(App $app): array
    {
        if (!$app->site()->debug()) {
            return Doctor::result('ok', t('Off'));
        }
        if (self::isLocal($app)) {
            return Doctor::result('ok', t('On, in a local or demo site'));
        }

        return Doctor::result('bad', t('Debug mode shows errors to visitors: turn it off in the site configuration'));
    }

    /** @return array<string,string> */
    public static function https(App $app): array
    {
        $url = $app->site()->url();
        if (strpos($url, 'https://') === 0) {
            return Doctor::result('ok', $url);
        }
        if (self::isLocal($app)) {
            return Doctor::result('ok', t('Local site: HTTPS is not needed'));
        }

        return Doctor::result('bad', t('Payments and sessions need HTTPS: {url}', ['url' => $url]));
    }

    /** @return array<string,string> */
    public static function headers(App $app, HomeProbe $probe): array
    {
        if (Maintenance::isOn($app)) {
            return Doctor::result('warn', t('Not checked while the maintenance mode is on'));
        }
        $response = $probe->response($app);
        if ($response === null) {
            return Doctor::result('warn', t('The home page could not be requested: {error}', ['error' => $probe->error()]));
        }
        $present = array_change_key_case($response->headers, CASE_LOWER);
        $wanted = self::HEADERS;
        if (strpos($app->site()->url(), 'https://') === 0) {
            $wanted[] = 'Strict-Transport-Security';
        }
        $missing = [];
        foreach ($wanted as $name) {
            if (!isset($present[strtolower($name)])) {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            return Doctor::result('warn', t('Missing: {list}', ['list' => implode(', ', $missing)]));
        }

        return Doctor::result('ok', t('{n} headers present', ['n' => count($wanted)]));
    }

    /** @return array<string,string> */
    public static function cacheable(App $app, HomeProbe $probe): array
    {
        if (Maintenance::isOn($app)) {
            return Doctor::result('warn', t('Not checked while the maintenance mode is on'));
        }
        $response = $probe->response($app);
        if ($response === null) {
            return Doctor::result('warn', t('The home page could not be requested: {error}', ['error' => $probe->error()]));
        }
        if ($response->status !== 200) {
            return Doctor::result('warn', t('The home page answered {status}', ['status' => $response->status]));
        }
        if ($response->cacheTtl <= 0) {
            return Doctor::result('warn', t('The home page is not saved in the page cache'), $app->url()->plain('/admin/tools/cache'));
        }

        return Doctor::result('ok', t('Saved for {n} seconds', ['n' => $response->cacheTtl]));
    }
}

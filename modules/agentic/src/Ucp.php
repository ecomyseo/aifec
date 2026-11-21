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

use Aifec\Kernel\App;
use Aifec\Kernel\Request;

/**
 * UCP (Universal Commerce Protocol): el perfil de la tienda y las piezas comunes de la API de compra.
 *
 * La versión es la que usa Google en el Modo IA y en Gemini (2026-04-08). Las formas de pago que se anuncian las ponen los
 * módulos de pago con el filtro «ucp.payment_handlers» (Google Pay por Redsys lo hace si está listo).
 */
final class Ucp
{
    public const VERSION = '2026-08-25';
    public const VERSIONS = ['2026-08-25', '2026-04-08'];

    public const CHECKOUT = 'dev.ucp.shopping.checkout';
    public const FULFILLMENT = 'dev.ucp.shopping.fulfillment';
    public const DISCOUNT = 'dev.ucp.shopping.discount';
    public const CART = 'dev.ucp.shopping.cart';

    /** Prefijo de las claves de la API de AIFEC (las da de alta el módulo API). */
    private const KEY_PREFIX = 'aifec_api_';

    /** Ámbitos de una clave que puede cerrar compras por UCP. */
    public const SCOPES = ['ucp', 'acp', 'orders:create'];

    public static function version(App $app): string
    {
        $version = $app->request()->attr('ucp_version', self::VERSION);

        return is_string($version) && in_array($version, self::VERSIONS, true) ? $version : self::VERSION;
    }

    public static function enabled(App $app): bool
    {
        return $app->settings()->bool('agentic.ucp_enabled', false);
    }

    /** La dirección base de la API de UCP de este sitio. */
    public static function endpoint(App $app): string
    {
        return $app->url()->origin() . $app->url()->to('/ucp/v1', [], '');
    }

    /**
     * El perfil de /.well-known/ucp.
     *
     * @return array<string,mixed>
     */
    public static function profile(App $app): array
    {
        $v = self::version($app);

        $keys = UcpSignatures::keys($app);
        $bindings = [[
                'version' => $v,
                'spec' => 'https://ucp.dev/' . $v . '/specification/overview',
                'transport' => 'rest',
                'schema' => 'https://ucp.dev/' . $v . '/services/shopping/rest.openapi.json',
                'endpoint' => self::endpoint($app),
        ]];
        if ($v === self::VERSION && $app->modules()->isLoaded('mcp')) {
            $bindings[] = [
                'version' => $v,
                'spec' => 'https://ucp.dev/' . $v . '/specification/overview',
                'transport' => 'mcp',
                'schema' => 'https://ucp.dev/' . $v . '/services/shopping/mcp.openrpc.json',
                'endpoint' => $app->url()->abs('/ucp/mcp', [], ''),
            ];
        }

        $profile = ['keys' => $keys, 'ucp' => [
            'version' => $v,
            'services' => ['dev.ucp.shopping' => $bindings],
            'capabilities' => [
                self::CHECKOUT => [[
                    'version' => $v,
                    'spec' => 'https://ucp.dev/' . $v . '/specification/shopping/checkout',
                    'schema' => 'https://ucp.dev/' . $v . '/schemas/shopping/checkout.json',
                ]],
                self::FULFILLMENT => [[
                    'version' => $v,
                    'spec' => 'https://ucp.dev/' . $v . '/specification/' . ($v === '2026-08-25' ? 'shopping/extensions/fulfillment' : 'fulfillment'),
                    'schema' => 'https://ucp.dev/' . $v . '/schemas/shopping/fulfillment.json',
                    'extends' => self::CHECKOUT,
                ]],
                self::DISCOUNT => [[
                    'version' => $v,
                    'spec' => 'https://ucp.dev/' . $v . '/specification/' . ($v === '2026-08-25' ? 'shopping/extensions/discount' : 'discount'),
                    'schema' => 'https://ucp.dev/' . $v . '/schemas/shopping/discount.json',
                    'extends' => [self::CHECKOUT, self::CART],
                ]],
                self::CART => [[
                    'version' => $v,
                    'spec' => 'https://ucp.dev/' . $v . '/specification/' . ($v === '2026-08-25' ? 'shopping/cart' : 'cart'),
                    'schema' => 'https://ucp.dev/' . $v . '/schemas/shopping/cart.json',
                ]],
                'dev.ucp.shopping.catalog.search' => [[
                    'version' => $v, 'spec' => 'https://ucp.dev/' . $v . '/specification/shopping/catalog/search',
                    'schema' => 'https://ucp.dev/' . $v . '/schemas/shopping/catalog_search.json',
                ]],
                'dev.ucp.shopping.catalog.lookup' => [[
                    'version' => $v, 'spec' => 'https://ucp.dev/' . $v . '/specification/shopping/catalog/lookup',
                    'schema' => 'https://ucp.dev/' . $v . '/schemas/shopping/catalog_lookup.json',
                ]],
            ],
            'payment_handlers' => self::handlers($app),
        ]];
        if ($v !== self::VERSION) {
            $profile['signing_keys'] = $keys;
            unset($profile['ucp']['capabilities']['dev.ucp.shopping.catalog.search'], $profile['ucp']['capabilities']['dev.ucp.shopping.catalog.lookup']);
        } elseif ($app->request()->attr('route') === 'ucp.profile' || $app->request()->path === '/ucp/v1') {
            $profile['ucp']['supported_versions'] = ['2026-04-08' => $app->url()->abs('/.well-known/ucp/2026-04-08', [], '')];
        }
        return $profile;
    }

    /**
     * La cabecera «ucp» de cada respuesta de compra.
     *
     * @return array<string,mixed>
     */
    public static function envelope(App $app): array
    {
        return [
            'version' => self::version($app),
            'status' => 'success',
            'capabilities' => [self::CHECKOUT => [['version' => self::version($app)]], self::FULFILLMENT => [['version' => self::version($app)]], self::DISCOUNT => [['version' => self::version($app)]]],
            'payment_handlers' => self::handlers($app),
        ];
    }

    /**
     * Los manejadores de pago que anuncian los módulos de pago, por nombre («com.google.pay» => [declaración, …]). Vacío se
     * escribe como objeto en JSON.
     *
     * @return array<string,mixed>|\stdClass
     */
    public static function handlers(App $app)
    {
        return new \stdClass();
    }

    /**
     * La clave de la API que manda la plataforma (Authorization: Bearer o X-API-Key), si vale para cerrar compras.
     *
     * @return array<string,mixed>|null id, name y scopes, o null
     */
    public static function key(App $app, Request $request): ?array
    {
        $raw = '';
        $auth = (string) $request->header('authorization', '');
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m) === 1) {
            $raw = $m[1];
        } elseif ($request->header('x-api-key') !== null) {
            $raw = trim((string) $request->header('x-api-key'));
        }
        if ($raw === '' || strlen($raw) > 200 || strpos($raw, self::KEY_PREFIX) !== 0) {
            return null;
        }
        $row = $app->db()->row('SELECT id, name, scopes, active FROM {api_keys} WHERE key_hash = ?', [hash('sha256', $raw)]);
        if ($row === null || (int) $row['active'] !== 1) {
            return null;
        }
        $scopes = is_string($row['scopes']) && $row['scopes'] !== '' ? json_decode($row['scopes'], true) : null;
        $scopes = is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];
        if (array_intersect(self::SCOPES, $scopes) === []) {
            return null;
        }

        return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'scopes' => $scopes];
    }

    /** La plataforma que llama, de la cabecera UCP-Agent (profile="https://…"): solo su dominio, para la atribución. */
    public static function platform(Request $request): string
    {
        $header = (string) $request->header('ucp-agent', '');
        if (preg_match('/profile="([^"]{1,500})"/', $header, $m) !== 1) {
            return '';
        }
        $host = parse_url($m[1], PHP_URL_HOST);

        return is_string($host) ? substr(strtolower($host), 0, 120) : '';
    }

    /**
     * Los enlaces legales de la compra: condiciones y privacidad (UCP los pide).
     *
     * @return array<int,array{type:string,url:string}>
     */
    public static function links(App $app): array
    {
        $links = [];
        foreach (['terms_of_service' => $app->settings()->int('orders.terms_page_id', 0), 'privacy_policy' => $app->settings()->int('agentic.privacy_page_id', 0)] as $type => $id) {
            $url = self::pageUrl($app, $id);
            if ($url !== '') {
                $links[] = ['type' => $type, 'url' => $url];
            }
        }

        return $links;
    }

    public static function pageUrl(App $app, int $id): string
    {
        if ($id < 1) {
            return '';
        }
        try {
            $slugs = $app->slugs();
            if ($slugs->path('page', $id, $app->lang()) === null && $slugs->path('page', $id, $app->defaultLang()) === null) {
                return '';
            }

            return self::absolute($app, $app->url()->entity('page', $id));
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function absolute(App $app, string $url): string
    {
        if ($url !== '' && $url[0] === '/' && substr($url, 0, 2) !== '//') {
            return $app->url()->origin() . $url;
        }

        return $url;
    }
}

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\PaymentsPaypal;

use Aifec\Kernel\App;
use Aifec\Payments\PayPal\PayPalClient;
use Aifec\Payments\PayPal\PayPalConfig;
use Aifec\Payments\PayPal\PayPalFundingSources;

/**
 * Lo que el comercio ha guardado en los ajustes de PayPal, leído en un solo sitio: el entorno, las credenciales de cada
 * entorno (el secreto se guarda cifrado) y el aviso. Todos los ajustes son por sitio.
 *
 * Aquí también se monta el cliente de la API, con el cliente HTTP de la aplicación y la caché del sitio para la ficha de
 * acceso: la ficha dura horas, y guardarla evita pedir una nueva en cada petición web. Nunca va a la base de datos ni al
 * registro.
 */
final class PayPalSettings
{
    public const ENVIRONMENT = 'payments.paypal.environment';
    public const CLIENT_ID_SANDBOX = 'payments.paypal.client_id_sandbox';
    public const SECRET_SANDBOX = 'payments.paypal.secret_sandbox';
    public const CLIENT_ID_LIVE = 'payments.paypal.client_id_live';
    public const SECRET_LIVE = 'payments.paypal.secret_live';
    public const WEBHOOK_ID_SANDBOX = 'payments.paypal.webhook_id_sandbox';
    public const WEBHOOK_ID_LIVE = 'payments.paypal.webhook_id_live';
    public const TITLE = 'payments.paypal.title';

    /** Prefijo de la casilla «encendida» de cada forma de pago: payments.paypal.source_ideal. */
    public const SOURCE_PREFIX = 'payments.paypal.source_';

    /** Prefijo del orden de cada forma de pago en la caja: payments.paypal.position_ideal. */
    public const POSITION_PREFIX = 'payments.paypal.position_';

    /** El fichero que acredita el dominio para Apple Pay (lo que se sirve en /.well-known/apple-developer-merchantid-domain-association). */
    public const APPLE_PAY_FILE = 'payments.paypal.applepay_domain_file';

    /** El mensaje de financiación de Paga más tarde en la página de pago. */
    public const PAYLATER_MESSAGE = 'payments.paypal.paylater_message';

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** La clave de la casilla «encendida» de una forma de pago. */
    public static function enabledKey(string $source): string
    {
        return self::SOURCE_PREFIX . $source;
    }

    /** La clave del orden de una forma de pago. */
    public static function positionKey(string $source): string
    {
        return self::POSITION_PREFIX . $source;
    }

    /**
     * ¿Está encendida esa forma de pago? De serie, las que el catálogo marca (la cartera y la tarjeta); las locales,
     * apagadas hasta que el comercio las marque.
     */
    public function enabled(string $source): bool
    {
        $known = PayPalFundingSources::get($source);
        if ($known === null || !PayPalFundingSources::isOfferable($source)) {
            return false;
        }
        $value = $this->app->settings()->get(self::enabledKey($source), !empty($known['default']));

        return in_array($value, [true, 1, '1', 'on', 'true', 'yes'], true);
    }

    /** El orden de una forma de pago en la caja (1 = la primera). Sin número, el del catálogo. */
    public function position(string $source): int
    {
        $index = array_search($source, array_keys(PayPalFundingSources::offerable()), true);
        $default = $index === false ? 99 : (int) $index + 1;
        $value = $this->app->settings()->get(self::positionKey($source));

        return is_int($value) || (is_string($value) && preg_match('/^\d{1,3}$/', $value) === 1) ? (int) $value : $default;
    }

    /** El fichero que acredita el dominio para Apple Pay, tal como lo pegó el comerciante ('' si no hay). */
    public function applePayFile(): string
    {
        return trim($this->app->settings()->str(self::APPLE_PAY_FILE));
    }

    /** ¿Se pinta el mensaje de financiación de Paga más tarde junto al importe de la página de pago? (Apagado de serie.) */
    public function payLaterMessage(): bool
    {
        return in_array($this->app->settings()->get(self::PAYLATER_MESSAGE, false), [true, 1, '1', 'on', 'true', 'yes'], true);
    }

    /**
     * Las formas de pago que se ofrecen (redirección y guion), en el orden que ha puesto el comercio (a igual número, el del
     * catálogo).
     *
     * @return string[]
     */
    public function ordered(): array
    {
        $sources = array_keys(PayPalFundingSources::offerable());
        $rank = [];
        foreach ($sources as $i => $source) {
            $rank[(string) $source] = [$this->position((string) $source), $i];
        }
        usort($sources, static function ($a, $b) use ($rank): int {
            return $rank[(string) $a] <=> $rank[(string) $b];
        });

        return array_map('strval', $sources);
    }

    /** «sandbox» o «live»: cualquier otra cosa guardada cuenta como «sandbox», que es el lado seguro. */
    public function environment(): string
    {
        return $this->app->settings()->str(self::ENVIRONMENT, PayPalConfig::ENV_SANDBOX) === PayPalConfig::ENV_LIVE
            ? PayPalConfig::ENV_LIVE
            : PayPalConfig::ENV_SANDBOX;
    }

    public function isTest(): bool
    {
        return $this->environment() === PayPalConfig::ENV_SANDBOX;
    }

    public function clientId(string $environment): string
    {
        return trim($this->app->settings()->str($environment === PayPalConfig::ENV_LIVE ? self::CLIENT_ID_LIVE : self::CLIENT_ID_SANDBOX));
    }

    /** El secreto del entorno, descifrado; vacío si no está puesto. */
    public function secret(string $environment): string
    {
        return trim($this->app->secret($environment === PayPalConfig::ENV_LIVE ? self::SECRET_LIVE : self::SECRET_SANDBOX));
    }

    public function webhookId(string $environment): string
    {
        return trim($this->app->settings()->str($environment === PayPalConfig::ENV_LIVE ? self::WEBHOOK_ID_LIVE : self::WEBHOOK_ID_SANDBOX));
    }

    /** ¿Hay identificador y secreto para ese entorno (por defecto, el elegido)? */
    public function complete(?string $environment = null): bool
    {
        $environment = $environment !== null ? $environment : $this->environment();

        return $this->clientId($environment) !== '' && $this->secret($environment) !== '';
    }

    /**
     * Los datos de la cuenta para un entorno (por defecto, el elegido).
     *
     * @throws \RuntimeException si falta el identificador o el secreto
     */
    public function config(?string $environment = null): PayPalConfig
    {
        $environment = $environment !== null ? $environment : $this->environment();
        if (!$this->complete($environment)) {
            throw new \RuntimeException('The PayPal credentials of the ' . $environment . ' environment are not set');
        }

        return new PayPalConfig(
            $this->clientId($environment),
            $this->secret($environment),
            $environment,
            $this->webhookId($environment),
            $this->app->settings()->str('store.name')
        );
    }

    /**
     * El cliente de la API de un entorno (por defecto, el elegido).
     *
     * @throws \RuntimeException si falta el identificador o el secreto
     */
    public function client(?string $environment = null): PayPalClient
    {
        $config = $this->config($environment);

        return new PayPalClient($config, $this->app->http(), $this->tokenCache($config));
    }

    /** La dirección absoluta a la que PayPal manda sus avisos. Sin prefijo de idioma. */
    public function notifyUrl(): string
    {
        $url = $this->app->url();

        return $url->origin() . $url->route('payment.paypal.notify', [], '');
    }

    /**
     * La ficha de acceso, en la caché del sitio hasta que caduque. Una entrada por entorno y cuenta.
     */
    private function tokenCache(PayPalConfig $config): callable
    {
        $app = $this->app;
        $key = 'paypal.token.' . $config->environment() . '.' . substr(hash('sha256', $config->clientId()), 0, 16);

        return static function (?array $set = null) use ($app, $key): ?array {
            if ($set !== null) {
                $ttl = (int) $set['expires'] - $app->clock()->now();
                if ($ttl > 0) {
                    $app->cache()->set($key, $set, $ttl);
                }

                return $set;
            }
            $stored = $app->cache()->get($key);

            return is_array($stored) ? $stored : null;
        };
    }
}

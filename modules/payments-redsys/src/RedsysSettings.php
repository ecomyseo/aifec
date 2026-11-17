<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\PaymentsRedsys;

use Aifec\Kernel\App;
use Aifec\Payments\Redsys\RedsysConfig;
use Aifec\Payments\Redsys\RedsysException;

/**
 * Lo que el comercio ha guardado en los ajustes de Redsys, leído en un solo sitio: el entorno, los datos del comercio y la
 * clave de cada entorno (que se guarda cifrada). Todos los ajustes son por sitio.
 */
final class RedsysSettings
{
    public const ENVIRONMENT = 'payments.redsys.environment';
    public const MERCHANT_CODE = 'payments.redsys.merchant_code';
    public const TERMINAL = 'payments.redsys.terminal';
    public const SECRET_TEST = 'payments.redsys.secret_test';
    public const SECRET_LIVE = 'payments.redsys.secret_live';
    public const BIZUM = 'payments.redsys.bizum';
    public const TITLE = 'payments.redsys.title';
    public const BIZUM_TITLE = 'payments.redsys.bizum_title';

    /** Moneda de Redsys para el euro (ISO 4217). */
    public const CURRENCY = '978';

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** «test» o «live»: cualquier otra cosa guardada cuenta como «test», que es el lado seguro. */
    public function environment(): string
    {
        return $this->app->settings()->str(self::ENVIRONMENT, RedsysConfig::ENV_TEST) === RedsysConfig::ENV_LIVE
            ? RedsysConfig::ENV_LIVE
            : RedsysConfig::ENV_TEST;
    }

    public function isTest(): bool
    {
        return $this->environment() === RedsysConfig::ENV_TEST;
    }

    public function bizumEnabled(): bool
    {
        return $this->app->settings()->bool(self::BIZUM, false);
    }

    public function merchantCode(): string
    {
        return trim($this->app->settings()->str(self::MERCHANT_CODE));
    }

    public function terminal(): string
    {
        return trim($this->app->settings()->str(self::TERMINAL, '1'));
    }

    /** La clave secreta del entorno, descifrada; vacía si no está puesta. */
    public function secret(string $environment): string
    {
        return trim($this->app->secret($environment === RedsysConfig::ENV_LIVE ? self::SECRET_LIVE : self::SECRET_TEST));
    }

    /** ¿Hay número de comercio, terminal y clave para ese entorno (por defecto, el elegido)? */
    public function complete(?string $environment = null): bool
    {
        $environment = $environment !== null ? $environment : $this->environment();

        return $this->merchantCode() !== '' && $this->terminal() !== '' && $this->secret($environment) !== '';
    }

    /**
     * Los datos del comercio para un entorno (por defecto, el elegido).
     *
     * @throws RedsysException si falta el número de comercio, el terminal o la clave
     */
    public function config(?string $environment = null): RedsysConfig
    {
        $environment = $environment !== null ? $environment : $this->environment();
        $secret = $this->secret($environment);
        if ($secret === '') {
            throw new RedsysException('The Redsys secret key of the ' . $environment . ' environment is not set');
        }

        return new RedsysConfig($this->merchantCode(), $this->terminal(), $secret, $environment, self::CURRENCY, $this->app->settings()->str('store.name'));
    }

    /** La dirección absoluta a la que el banco manda el aviso de cada pago. Sin prefijo de idioma. */
    public function notifyUrl(): string
    {
        $url = $this->app->url();

        return $url->origin() . $url->route('payment.redsys.notify', [], '');
    }

    // ------------------------------------------------------------------ Google Pay (cobro con el token, por el REST)

    public const GOOGLEPAY = 'payments.redsys.googlepay';
    public const GOOGLEPAY_MERCHANT_ID = 'payments.redsys.googlepay_merchant_id';
    public const GOOGLEPAY_NETWORKS = 'payments.redsys.googlepay_networks';
    public const GOOGLEPAY_PAN_ONLY = 'payments.redsys.googlepay_pan_only';

    /** Redes de tarjeta que Google Pay sabe nombrar, en el orden en que se declaran. */
    public const GOOGLEPAY_ALL_NETWORKS = ['VISA', 'MASTERCARD', 'AMEX', 'DISCOVER', 'JCB'];

    /** Identificador del manejador de Google Pay en UCP: lo pone la tienda, y es el mismo siempre para que la plataforma lo reconozca. */
    public const GOOGLEPAY_HANDLER_ID = 'aifec-redsys-googlepay';

    /** ¿Está encendido Google Pay por Redsys y están los datos del comercio del entorno elegido? */
    public function googlePayReady(): bool
    {
        if (!$this->app->settings()->bool(self::GOOGLEPAY, false) || !$this->complete()) {
            return false;
        }

        // En producción Google exige su identificador de comercio; en pruebas no.
        return $this->isTest() || $this->googlePayMerchantId() !== '';
    }

    public function googlePayMerchantId(): string
    {
        return trim($this->app->settings()->str(self::GOOGLEPAY_MERCHANT_ID));
    }

    /** El entorno de Google Pay va con el de Redsys: un token de pruebas solo se puede cobrar en el banco de pruebas. */
    public function googlePayEnvironment(): string
    {
        return $this->isTest() ? 'TEST' : 'PRODUCTION';
    }

    /**
     * Las redes aceptadas, de las que Google Pay conoce. Sin ninguna válida guardada, Visa y Mastercard.
     *
     * @return string[]
     */
    public function googlePayNetworks(): array
    {
        $saved = strtoupper($this->app->settings()->str(self::GOOGLEPAY_NETWORKS, 'VISA,MASTERCARD'));
        $chosen = [];
        foreach (self::GOOGLEPAY_ALL_NETWORKS as $network) {
            if (preg_match('/(^|[^A-Z])' . $network . '([^A-Z]|$)/', $saved) === 1) {
                $chosen[] = $network;
            }
        }

        return $chosen !== [] ? $chosen : ['VISA', 'MASTERCARD'];
    }

    /**
     * Métodos de autenticación de la tarjeta. CRYPTOGRAM_3DS siempre: es la tarjeta del móvil, ya autenticada (la PSD2 lo
     * da por bueno). PAN_ONLY (la tarjeta guardada en la cuenta de Google) solo si se pide: puede necesitar 3D Secure, y
     * entonces el pedido se termina en la caja de la tienda.
     *
     * @return string[]
     */
    public function googlePayAuthMethods(): array
    {
        return $this->app->settings()->bool(self::GOOGLEPAY_PAN_ONLY, false) ? ['PAN_ONLY', 'CRYPTOGRAM_3DS'] : ['CRYPTOGRAM_3DS'];
    }

    /**
     * La declaración del manejador «com.google.pay» de UCP (versión 2026-01-23), o null si Google Pay no está listo. La
     * pasarela es «redsys» y su identificador, el código de comercio: así cifra Google el token para el banco.
     *
     * @return array<string,mixed>|null
     */
    public function googlePayHandler(): ?array
    {
        if (!$this->googlePayReady()) {
            return null;
        }
        $url = $this->app->url();
        $host = (string) parse_url($url->origin(), PHP_URL_HOST);
        $merchantInfo = ['merchant_name' => mb_substr($this->app->settings()->str('store.name', 'AIFEC'), 0, 60, 'UTF-8'), 'merchant_origin' => $host];
        if ($this->googlePayMerchantId() !== '') {
            $merchantInfo['merchant_id'] = $this->googlePayMerchantId();
        }

        return [
            'id' => self::GOOGLEPAY_HANDLER_ID,
            'version' => '2026-01-23',
            'spec' => 'https://pay.google.com/gp/p/ucp/2026-01-23/',
            'schema' => 'https://pay.google.com/gp/p/ucp/2026-01-23/schemas/config.json',
            'config' => [
                'api_version' => 2,
                'api_version_minor' => 0,
                'environment' => $this->googlePayEnvironment(),
                'merchant_info' => $merchantInfo,
                'allowed_payment_methods' => [[
                    'type' => 'CARD',
                    'parameters' => [
                        'allowed_auth_methods' => $this->googlePayAuthMethods(),
                        'allowed_card_networks' => $this->googlePayNetworks(),
                        'billing_address_required' => true,
                        'billing_address_parameters' => ['format' => 'FULL'],
                    ],
                    'tokenization_specification' => [
                        'type' => 'PAYMENT_GATEWAY',
                        'parameters' => ['gateway' => 'redsys', 'gatewayMerchantId' => $this->merchantCode()],
                    ],
                ]],
            ],
        ];
    }
}

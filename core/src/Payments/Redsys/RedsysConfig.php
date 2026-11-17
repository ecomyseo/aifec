<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Payments\Redsys;

/** Datos del comercio en Redsys. Inmutable: se crea una vez y se pasa a quien firma o verifica. */
final class RedsysConfig
{
    public const ENV_TEST = 'test';

    public const ENV_LIVE = 'live';

    public const ENDPOINT_TEST = 'https://sis-t.redsys.es:25443/sis/realizarPago';

    public const ENDPOINT_LIVE = 'https://sis.redsys.es/sis/realizarPago';

    /** @var string */
    private $merchantCode;

    /** @var string */
    private $terminal;

    /** @var string */
    private $secretKey;

    /** @var string */
    private $environment;

    /** @var string */
    private $currency;

    /** @var string */
    private $merchantName;

    /**
     * @param string $merchantCode código de comercio (FUC)
     * @param string $terminal     número de terminal
     * @param string $secretKey    clave secreta tal como la da Redsys, en base64
     * @param string $environment  «test» o «live»
     * @param string $currency     código numérico ISO 4217 (978 = euro)
     * @param string $merchantName nombre que enseña la página del banco
     */
    public function __construct(
        string $merchantCode,
        string $terminal,
        string $secretKey,
        string $environment = self::ENV_TEST,
        string $currency = '978',
        string $merchantName = ''
    ) {
        if ($merchantCode === '') {
            throw new RedsysException('The merchant code is empty');
        }
        if ($terminal === '') {
            throw new RedsysException('The terminal is empty');
        }
        if ($environment !== self::ENV_TEST && $environment !== self::ENV_LIVE) {
            throw new RedsysException('The environment must be "test" or "live"');
        }
        if (preg_match('/^[0-9]{3}$/', $currency) !== 1) {
            throw new RedsysException('The currency must be a three-digit ISO 4217 code');
        }
        $this->merchantCode = $merchantCode;
        $this->terminal = $terminal;
        $this->secretKey = $secretKey;
        $this->environment = $environment;
        $this->currency = $currency;
        $this->merchantName = $merchantName;
    }

    public function merchantCode(): string
    {
        return $this->merchantCode;
    }

    public function terminal(): string
    {
        return $this->terminal;
    }

    public function secretKey(): string
    {
        return $this->secretKey;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function merchantName(): string
    {
        return $this->merchantName;
    }

    public function isLive(): bool
    {
        return $this->environment === self::ENV_LIVE;
    }

    /** Dirección a la que el navegador del cliente envía el formulario de pago. */
    public function endpoint(): string
    {
        return $this->isLive() ? self::ENDPOINT_LIVE : self::ENDPOINT_TEST;
    }
}

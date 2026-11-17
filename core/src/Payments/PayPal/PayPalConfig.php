<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Payments\PayPal;

/** Datos de la cuenta de PayPal del comercio. Inmutable. */
final class PayPalConfig
{
    public const ENV_SANDBOX = 'sandbox';

    public const ENV_LIVE = 'live';

    public const BASE_SANDBOX = 'https://api-m.sandbox.paypal.com';

    public const BASE_LIVE = 'https://api-m.paypal.com';

    /** @var string */
    private $clientId;

    /** @var string */
    private $clientSecret;

    /** @var string */
    private $environment;

    /** @var string */
    private $webhookId;

    /** @var string */
    private $brandName;

    /** @var string */
    private $locale;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $environment = self::ENV_SANDBOX,
        string $webhookId = '',
        string $brandName = '',
        string $locale = ''
    ) {
        if ($environment !== self::ENV_SANDBOX && $environment !== self::ENV_LIVE) {
            throw new \InvalidArgumentException('PayPal environment must be "sandbox" or "live"');
        }
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->environment = $environment;
        $this->webhookId = $webhookId;
        $this->brandName = $brandName;
        $this->locale = $locale;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function clientSecret(): string
    {
        return $this->clientSecret;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function webhookId(): string
    {
        return $this->webhookId;
    }

    public function brandName(): string
    {
        return $this->brandName;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function isLive(): bool
    {
        return $this->environment === self::ENV_LIVE;
    }

    public function baseUrl(): string
    {
        return $this->environment === self::ENV_LIVE ? self::BASE_LIVE : self::BASE_SANDBOX;
    }
}

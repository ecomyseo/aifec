<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Datalayer;

use Aifec\Kernel\App;
use Aifec\Modules\Consent\ConsentService;

/**
 * Lo que dicen los ajustes de la capa de datos, ya comprobado. Un identificador que no tiene la forma de los de verdad se trata como
 * vacío: nada que se escriba en un ajuste llega al JavaScript de la tienda sin pasar por aquí.
 */
final class Config
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function enabled(): bool
    {
        return $this->app->settings()->bool('datalayer.enabled', true);
    }

    /** ¿Llevan los importes de los eventos los impuestos dentro? */
    public function withTax(): bool
    {
        return $this->app->settings()->bool('datalayer.value_with_tax', false);
    }

    /** El interruptor de los eventos del servidor: nace apagado. */
    public function serverEvents(): bool
    {
        return !$this->app->site()->isDemo() && $this->app->settings()->bool('datalayer.server_events', false);
    }

    public function excludeStaff(): bool
    {
        return $this->app->settings()->bool('datalayer.exclude_staff', true);
    }

    public function gtm(): string
    {
        return $this->id('datalayer.gtm_id', '/^GTM-[A-Z0-9]{4,12}$/');
    }

    public function ga4(): string
    {
        return $this->id('datalayer.ga4_id', '/^G-[A-Z0-9]{4,14}$/');
    }

    public function adsId(): string
    {
        return $this->id('datalayer.ads_id', '/^AW-[0-9]{6,14}$/');
    }

    public function adsLabel(): string
    {
        return $this->id('datalayer.ads_label', '/^[A-Za-z0-9_-]{4,40}$/');
    }

    public function adsEnhanced(): bool
    {
        return $this->app->settings()->bool('datalayer.ads_enhanced', false);
    }

    public function metaPixel(): string
    {
        return $this->id('datalayer.meta_pixel', '/^[0-9]{6,20}$/');
    }

    public function tiktok(): string
    {
        return $this->id('datalayer.tiktok_pixel', '/^[A-Za-z0-9]{6,30}$/');
    }

    public function uet(): string
    {
        return $this->id('datalayer.uet_id', '/^[0-9]{5,12}$/');
    }

    /** Versión de la API de conversiones de Meta («v21.0»): configurable, porque Meta la cambia. */
    public function metaVersion(): string
    {
        return $this->id('datalayer.meta_version', '/^v[0-9]{1,2}\.[0-9]$/') !== '' ? $this->id('datalayer.meta_version', '/^v[0-9]{1,2}\.[0-9]$/') : 'v21.0';
    }

    public function metaTestCode(): string
    {
        return $this->id('datalayer.meta_test_code', '/^[A-Za-z0-9_-]{1,40}$/');
    }

    /** Categoría de consentimiento de un destino (clave sin «datalayer.» ni «_category»). */
    public function category(string $destination): string
    {
        $defaults = ['gtm' => 'statistics', 'ga4' => 'statistics', 'ads' => 'marketing', 'meta' => 'marketing', 'tiktok' => 'marketing', 'uet' => 'marketing'];
        $value = $this->app->settings()->str('datalayer.' . $destination . '_category', isset($defaults[$destination]) ? $defaults[$destination] : 'marketing');

        return ConsentService::normalize($value !== '' ? $value : (isset($defaults[$destination]) ? $defaults[$destination] : 'marketing'));
    }

    /** Código propio de una categoría (el que el comerciante pega a mano). Solo se usa dentro de un guion que espera su consentimiento. */
    public function custom(string $category): string
    {
        return trim($this->app->settings()->str('datalayer.custom_' . $category, ''));
    }

    /**
     * Los destinos con identificador válido.
     *
     * @return string[]
     */
    public function destinations(): array
    {
        $out = [];
        foreach (['gtm' => $this->gtm(), 'ga4' => $this->ga4(), 'ads' => $this->adsId(), 'meta' => $this->metaPixel(), 'tiktok' => $this->tiktok(), 'uet' => $this->uet()] as $name => $id) {
            if ($id !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }

    private function id(string $key, string $pattern): string
    {
        $value = trim($this->app->settings()->str($key, ''));

        return $value !== '' && preg_match($pattern, $value) === 1 ? $value : '';
    }
}

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Front;

use Aifec\Kernel\App;

/**
 * La dirección de la tienda. Se escribe en cinco campos (calle, código postal, localidad, provincia y país: «store.address_*») y de ahí
 * salen los datos estructurados de la organización (JSON-LD), llms.txt, index.md y el texto libre «store.address» que leen las facturas,
 * los correos y los pies de página.
 *
 * Compatibilidad: si solo existe el texto libre (una tienda antigua), todo sigue como estaba y no se inventa ningún reparto.
 */
final class StoreAddress
{
    /** Los campos, con su clave de ajuste. */
    public const FIELDS = [
        'street' => 'store.address_street',
        'postcode' => 'store.address_postcode',
        'city' => 'store.address_city',
        'region' => 'store.address_region',
        'country' => 'store.address_country',
    ];

    /**
     * Lo escrito en los campos (vacío lo que no se haya escrito).
     *
     * @return array{street:string,postcode:string,city:string,region:string,country:string}
     */
    public static function parts(App $app): array
    {
        $settings = $app->settings();
        $out = ['street' => '', 'postcode' => '', 'city' => '', 'region' => '', 'country' => ''];
        foreach (self::FIELDS as $part => $key) {
            $out[$part] = trim((string) preg_replace('/\s+/u', ' ', $settings->str($key, '')));
        }
        $out['country'] = strtoupper($out['country']);

        return $out;
    }

    /** ¿Hay una dirección escrita en los campos (la calle o la localidad, al menos)? */
    public static function structured(App $app): bool
    {
        $parts = self::parts($app);

        return $parts['street'] !== '' || $parts['city'] !== '';
    }

    /** El país: el del campo, o el país de la tienda (impuestos) si no se ha escrito. */
    public static function country(App $app): string
    {
        $country = self::parts($app)['country'];
        if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
            return $country;
        }
        $base = strtoupper(trim($app->settings()->str('tax.base_country', 'ES')));

        return preg_match('/^[A-Z]{2}$/', $base) === 1 ? $base : 'ES';
    }

    /**
     * El texto libre que sale de los campos: la calle, el código postal con la localidad y la provincia, cada cosa en su línea.
     */
    public static function text(App $app): string
    {
        $parts = self::parts($app);
        $lines = [];
        if ($parts['street'] !== '') {
            $lines[] = $parts['street'];
        }
        $place = trim($parts['postcode'] . ' ' . $parts['city']);
        if ($place !== '') {
            $lines[] = $place;
        }
        if ($parts['region'] !== '') {
            $lines[] = $parts['region'];
        }

        return implode("\n", $lines);
    }

    /** En una sola línea, con comas: para los resúmenes de texto. Con solo el texto libre, ese texto en una línea. */
    public static function oneLine(App $app): string
    {
        if (!self::structured($app)) {
            return trim((string) preg_replace('/\s+/u', ' ', $app->settings()->str('store.address', '')));
        }

        return implode(', ', array_filter(explode("\n", self::text($app)), static function (string $line): bool {
            return $line !== '';
        }));
    }

    /**
     * El domicilio para Schema.org (PostalAddress), sin el «@type». Con campos, cada dato en el suyo; con solo texto libre, el texto
     * entero en «streetAddress» (como siempre) y el país; sin nada, vacío.
     *
     * @return array<string,string>
     */
    public static function postal(App $app): array
    {
        if (self::structured($app)) {
            $parts = self::parts($app);

            return array_filter([
                'streetAddress' => $parts['street'],
                'addressLocality' => $parts['city'],
                'postalCode' => $parts['postcode'],
                'addressRegion' => $parts['region'],
                'addressCountry' => self::country($app),
            ], static function (string $value): bool {
                return $value !== '';
            });
        }
        $free = trim((string) preg_replace('/\s+/u', ' ', $app->settings()->str('store.address', '')));

        return $free === '' ? [] : ['streetAddress' => $free, 'addressCountry' => self::country($app)];
    }

    /**
     * Pasa lo escrito en los campos al texto libre «store.address», que es el que leen las facturas, los correos y los pies. Solo si hay
     * campos: con los campos vacíos no se toca lo que hubiera.
     */
    public static function sync(App $app): void
    {
        if (!self::structured($app)) {
            return;
        }
        $text = self::text($app);
        if ($text !== trim($app->settings()->str('store.address', ''))) {
            $app->settings()->set('store.address', $text);
        }
    }
}

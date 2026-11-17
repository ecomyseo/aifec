<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Front;

use Aifec\Geo\Countries;
use Aifec\Geo\Spain;
use Aifec\Kernel\App;

/**
 * Las listas que pintan el carrito y la caja: países a los que se puede enviar y provincias de España.
 *
 * Los países salen de las zonas de envío activas (una localización «*» significa cualquier país). Es una lectura de solo
 * consulta de las tablas de envíos porque el servicio «shipping» todavía no ofrece una lista de países: cuando la
 * tenga (un método countries()), se usa esa y esta lectura desaparece.
 */
final class Destinations
{
    /**
     * Países que se ofrecen: código ISO => nombre en el idioma de la petición.
     *
     * @param bool $forShipping true = solo los que tienen zona de envío; false = todos (lo digital no se envía a ningún sitio)
     *
     * @return array<string,string>
     */
    public static function countries(App $app, bool $forShipping): array
    {
        $all = Countries::all($app->lang());
        if (!$forShipping || !$app->has('shipping')) {
            return $all;
        }
        $shipping = $app->service('shipping');
        if (method_exists($shipping, 'countries')) {
            $listed = $shipping->countries();
            $codes = is_array($listed) ? $listed : [];
        } else {
            $rows = $app->db()->select('SELECT DISTINCT l.country FROM {zone_locations} l JOIN {shipping_zones} z ON z.id = l.zone_id WHERE z.active = 1');
            $codes = [];
            foreach ($rows as $row) {
                $codes[] = strtoupper(trim((string) $row['country']));
            }
        }
        if (in_array('*', $codes, true)) {
            return $all;
        }
        $out = [];
        foreach ($all as $iso => $name) {
            if (in_array((string) $iso, $codes, true)) {
                $out[(string) $iso] = $name;
            }
        }
        if ($out === []) {
            $default = method_exists($shipping, 'defaultCountry') ? (string) $shipping->defaultCountry() : 'ES';
            $out[$default] = isset($all[$default]) ? $all[$default] : $default;
        }

        return $out;
    }

    /**
     * Las provincias de España: prefijo del código postal («35») => nombre.
     *
     * @return array<string,string>
     */
    public static function provinces(): array
    {
        $out = [];
        foreach (Spain::provinces() as $province) {
            $out[(string) $province['prefix']] = (string) $province['name'];
        }
        // Por orden alfabético sin mirar tildes: «Ávila» va con la A, no detrás de «Zaragoza» (14/11/2026, quien navega oyendo la lista).
        uasort($out, static function (string $a, string $b): int {
            return strcmp(\Aifec\Util\Str::slug($a), \Aifec\Util\Str::slug($b));
        });

        return $out;
    }

    /** La provincia de un código postal español («35»), o cadena vacía si el código no vale. */
    public static function provinceOf(string $postcode): string
    {
        $province = Spain::provinceByPostcode($postcode);

        return $province !== null ? (string) $province['prefix'] : '';
    }
}

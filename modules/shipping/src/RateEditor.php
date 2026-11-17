<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Shipping;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Validation\Validator;

/**
 * Las tarifas en una sola tabla: el precio se cambia en la propia fila y, al lado, se ve lo que pagaría un pedido de muestra
 * (1 kg a Madrid) con el cambio hecho. El cálculo es el de «Probar un envío»: el servicio de envíos de siempre. Para enseñar
 * «con este cambio» sin guardarlo, el precio nuevo se escribe dentro de una transacción que se deshace al terminar.
 */
final class RateEditor
{
    /** Pedido de muestra: gramos, país y código postal (Madrid). */
    public const SAMPLE_WEIGHT = 1000;
    public const SAMPLE_COUNTRY = 'ES';
    public const SAMPLE_POSTCODE = '28001';
    public const SAMPLE_REGION = '28';

    /** Mensaje de la excepción con la que se deshace la prueba. */
    private const ROLLBACK = 'af-rate-preview';

    /**
     * Todas las tarifas de todos los transportistas de cálculo por tabla, con el nombre de su transportista y de su zona.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function rows(App $app): array
    {
        $db = $app->db();
        $lo = $db->ident('min');
        $hi = $db->ident('max');
        $sql = 'SELECT r.id, r.carrier_id, c.name AS carrier, r.zone_id, z.name AS zone, r.basis, r.' . $lo . ' AS lo, r.' . $hi . ' AS hi, r.price '
            . 'FROM {carrier_rates} r JOIN {carriers} c ON c.id = r.carrier_id JOIN {shipping_zones} z ON z.id = r.zone_id '
            . 'ORDER BY c.position, c.id, z.position, z.id, r.basis, r.' . $lo . ', r.id';
        $out = [];
        foreach ($db->select($sql) as $row) {
            $out[] = [
                'id' => (int) $row['id'], 'carrier_id' => (int) $row['carrier_id'], 'carrier' => (string) $row['carrier'],
                'zone_id' => (int) $row['zone_id'], 'zone' => (string) $row['zone'],
                'basis' => (string) $row['basis'] === 'price' ? 'price' : 'weight',
                'min' => (int) $row['lo'], 'max' => (int) $row['hi'], 'price' => (int) $row['price'],
            ];
        }

        return $out;
    }

    /** Un importe tal como se escribe: «4,95». */
    public static function cents(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '');
    }

    /** Lo escrito en el campo del precio, en céntimos; null si no es un importe. */
    public static function parsePrice(string $text): ?int
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $cents = Validator::toCents($text);

        return $cents !== null && $cents >= 0 && $cents <= 99999999 ? $cents : null;
    }

    /** El tramo de una tarifa en palabras: «de 0 a 1000 g», «de 50,00 € en adelante». */
    public static function rangeText(App $app, array $rate): string
    {
        $fmt = static function (int $n) use ($rate): string {
            return $rate['basis'] === 'price' ? money($n) : $n . ' g';
        };
        if ((int) $rate['max'] === 0) {
            return t('From {min} up', ['min' => $fmt((int) $rate['min'])]);
        }

        return t('From {min} to {max}', ['min' => $fmt((int) $rate['min']), 'max' => $fmt((int) $rate['max'])]);
    }

    /**
     * Lo que cobrarían los transportistas por el pedido de muestra. Con $rateId y $price, como si esa tarifa valiera ya ese
     * precio (no se guarda nada).
     *
     * @return array<int,array<string,mixed>> opciones del servicio de envíos
     */
    public static function sample(App $app, ?int $rateId = null, ?int $price = null): array
    {
        $service = $app->service('shipping');
        if (!$service instanceof Shipping) {
            return [];
        }
        $package = ['weight' => self::SAMPLE_WEIGHT, 'items_total' => 0, 'qty' => 1, 'needs_shipping' => true];
        $destination = ['country' => self::SAMPLE_COUNTRY, 'region' => self::SAMPLE_REGION, 'postcode' => self::SAMPLE_POSTCODE, 'city' => ''];
        if ($rateId === null || $price === null) {
            return $service->options($package, $destination);
        }
        $db = $app->db();
        $options = [];
        try {
            $db->transaction(static function () use ($db, $rateId, $price, $service, $package, $destination, &$options): void {
                $db->update('carrier_rates', ['price' => $price], ['id' => $rateId]);
                $options = $service->options($package, $destination);
                throw new \RuntimeException(self::ROLLBACK);
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== self::ROLLBACK) {
                throw $e;
            }
        }

        return $options;
    }

    /**
     * La tarjeta «lo que paga un pedido de 1 kg a Madrid». $changed = true cuando son los precios con un cambio puesto.
     *
     * @param array<int,array<string,mixed>> $options
     */
    public static function previewCard(array $options, bool $changed): string
    {
        $head = $changed ? t('With this change, a 1 kg order to Madrid would pay') : t('A 1 kg order to Madrid pays');
        $html = '<div class="af-card"><div class="af-card-head"><h2>' . e($head) . '</h2></div><div class="af-card-body">';
        if ($options === []) {
            return $html . '<p class="af-muted">' . e(t('No carrier can send a 1 kg order to Madrid.')) . '</p></div></div>';
        }
        $html .= '<ul class="af-stack">';
        foreach ($options as $option) {
            $price = !empty($option['free']) ? t('Free') : money((int) $option['price']);
            $html .= '<li><strong>' . e((string) $option['name']) . '</strong> <span class="af-nowrap">' . e($price) . '</span></li>';
        }

        return $html . '</ul></div></div>';
    }

    /** Una frase con el precio de cada transportista, para el aviso de después de guardar. */
    public static function sentence(array $options): string
    {
        if ($options === []) {
            return t('No carrier can send a 1 kg order to Madrid.');
        }
        $parts = [];
        foreach ($options as $option) {
            $parts[] = (string) $option['name'] . ' ' . (!empty($option['free']) ? t('Free') : money((int) $option['price']));
        }

        return t('With this change, a 1 kg order to Madrid would pay: {prices}', ['prices' => implode(', ', $parts)]);
    }

    /**
     * Guarda el precio nuevo de una tarifa y lo apunta en el registro de actividad. Devuelve false si la tarifa no existe.
     */
    public static function save(App $app, int $rateId, int $price): bool
    {
        $db = $app->db();
        $row = $db->find('carrier_rates', ['id' => $rateId]);
        if ($row === null) {
            return false;
        }
        $db->update('carrier_rates', ['price' => $price], ['id' => $rateId]);
        $app->invalidate(['shipping']);
        Admin::audit($app, 'update', 'carrier_rates', (string) $rateId, t('Rate price'), ['price' => [(string) (int) $row['price'], (string) $price]]);

        return true;
    }
}

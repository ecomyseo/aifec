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

use Aifec\Commerce\ShippingHandler;
use Aifec\Kernel\App;

/**
 * Manejador «rates»: el precio sale de la tabla carrier_rates del transportista para la zona del destino.
 *
 * Cada fila vale para un tramo [min, max] de su base: «weight» (gramos del paquete) o «price» (céntimos de los artículos).
 * Vale la fila cuyo tramo contiene el valor (max 0 = sin tope); si varias, la de min más alto. Si una zona mezcla las dos
 * bases, manda la primera que tenga filas, por este orden: peso, importe. Sin fila que cuadre no se ofrece el transportista.
 */
final class RatesHandler implements ShippingHandler
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function code(): string
    {
        return 'rates';
    }

    public function needsAddress(): bool
    {
        return true;
    }

    /**
     * @param array<string,mixed> $carrier
     * @param array<string,mixed> $package
     * @param array<string,mixed> $destination
     *
     * @return array{price:int,min_days:?int,max_days:?int}|null
     */
    public function quote(array $carrier, array $package, array $destination): ?array
    {
        $zone = isset($destination['zone_id']) && is_numeric($destination['zone_id']) ? (int) $destination['zone_id'] : 0;
        $carrierId = isset($carrier['id']) && is_numeric($carrier['id']) ? (int) $carrier['id'] : 0;
        if ($zone <= 0 || $carrierId <= 0) {
            return null;
        }
        $db = $this->app->db();
        $lo = $db->ident('min');
        $hi = $db->ident('max');
        $rows = $db->select(
            'SELECT basis, ' . $lo . ' AS lo, ' . $hi . ' AS hi, price FROM {carrier_rates} WHERE carrier_id = ? AND zone_id = ? ORDER BY ' . $lo . ', id',
            [$carrierId, $zone]
        );
        $values = [
            'weight' => isset($package['weight']) && is_numeric($package['weight']) ? (int) $package['weight'] : 0,
            'price' => isset($package['items_total']) && is_numeric($package['items_total']) ? (int) $package['items_total'] : 0,
        ];

        foreach ($values as $basis => $value) {
            $best = null;
            $any = false;
            foreach ($rows as $row) {
                if ((string) $row['basis'] !== $basis) {
                    continue;
                }
                $any = true;
                $from = (int) $row['lo'];
                $to = (int) $row['hi'];
                if ($from <= $value && ($to === 0 || $value <= $to) && ($best === null || $from > (int) $best['lo'])) {
                    $best = $row;
                }
            }
            if (!$any) {
                continue;
            }

            return $best !== null ? [
                'price' => max(0, (int) $best['price']),
                'min_days' => isset($carrier['min_days']) && is_numeric($carrier['min_days']) ? (int) $carrier['min_days'] : null,
                'max_days' => isset($carrier['max_days']) && is_numeric($carrier['max_days']) ? (int) $carrier['max_days'] : null,
            ] : null;
        }

        return null;
    }
}

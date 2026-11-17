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

/**
 * Manejador «pickup»: recogida en tienda. No necesita dirección y sirve cualquier destino y cualquier paquete.
 * El precio sale de settings.price (céntimos; 0 si falta) y el texto del sitio de recogida va en settings.address.
 */
final class PickupHandler implements ShippingHandler
{
    public function code(): string
    {
        return 'pickup';
    }

    public function needsAddress(): bool
    {
        return false;
    }

    /**
     * @param array<string,mixed> $carrier
     * @param array<string,mixed> $package
     * @param array<string,mixed> $destination
     *
     * @return array{price:int,min_days:?int,max_days:?int}
     */
    public function quote(array $carrier, array $package, array $destination): ?array
    {
        $settings = isset($carrier['settings']) && is_array($carrier['settings']) ? $carrier['settings'] : [];

        return [
            'price' => isset($settings['price']) && is_numeric($settings['price']) ? max(0, (int) $settings['price']) : 0,
            'min_days' => isset($carrier['min_days']) && is_numeric($carrier['min_days']) ? (int) $carrier['min_days'] : null,
            'max_days' => isset($carrier['max_days']) && is_numeric($carrier['max_days']) ? (int) $carrier['max_days'] : null,
        ];
    }
}

<?php
/**
 * AIFEC — una forma de calcular el precio de un envío. El módulo de envíos trae «rates» (tabla de tarifas) y «pickup»
 * (recogida); un plugin de transporte registra la suya con $app->service('shipping')->registerHandler(new MiTransporte($app)).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Commerce;

interface ShippingHandler
{
    /** Código que se pone en carriers.handler. */
    public function code(): string;

    /** ¿Hace falta dirección de entrega? (La recogida en tienda no.) */
    public function needsAddress(): bool;

    /**
     * Precio de llevar un paquete a un destino con este transportista.
     *
     * @param array<string,mixed> $carrier     fila de carriers, con settings ya descodificado
     * @param array<string,mixed> $package     weight (gramos), items_total (céntimos, tal como se escriben), qty, lines
     * @param array<string,mixed> $destination country, region, postcode, city
     *
     * @return array{price:int,min_days:?int,max_days:?int}|null null = no sirve ese destino o ese paquete
     */
    public function quote(array $carrier, array $package, array $destination): ?array;
}

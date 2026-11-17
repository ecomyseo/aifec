<?php
/**
 * AIFEC — apoyo de las pruebas de envíos: aplicación con el módulo cargado y ayudas para preparar zonas, transportistas y tarifas.
 *
 * No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Shipping\Shipping;

/**
 * Aplicación con el módulo de envíos (y el de impuestos, que necesita), arrancada. Trae las cuatro zonas de serie y ningún transportista.
 *
 * @param array<string,mixed> $settings
 */
function shippingTestApp(array $settings = []): App
{
    $options = ['modules' => ['shipping']];
    if ($settings !== []) {
        $options['settings'] = $settings;
    }
    $app = testApp($options);
    $app->boot();

    return $app;
}

function shippingService(App $app): Shipping
{
    $service = $app->service('shipping');
    check($service instanceof Shipping, 'el servicio shipping es el del módulo');

    return $service;
}

/** El id de una zona de serie por su nombre en inglés («Peninsula», «Balearic Islands», «Canary Islands», «Ceuta and Melilla»). */
function shippingZoneId(App $app, string $name): int
{
    $id = $app->db()->value('SELECT id FROM {shipping_zones} WHERE name = ?', [$name]);
    check($id !== null, 'la zona ' . $name . ' existe');

    return (int) $id;
}

/**
 * Crea una zona con sus localizaciones y devuelve su id.
 *
 * @param array<int,array<string,string>> $locations country, region, postcodes
 */
function shippingAddZone(App $app, string $name, array $locations, int $position = 10, int $active = 1): int
{
    $id = $app->db()->insert('shipping_zones', ['name' => $name, 'active' => $active, 'position' => $position]);
    foreach ($locations as $location) {
        $app->db()->insert('zone_locations', ['zone_id' => $id] + $location + ['region' => '', 'postcodes' => '']);
    }

    return $id;
}

/**
 * Crea un transportista y devuelve su id.
 *
 * @param array<string,mixed> $extra columnas que pisan las de serie
 */
function shippingAddCarrier(App $app, string $code, array $extra = []): int
{
    $now = $app->clock()->sql();

    return $app->db()->insert('carriers', $extra + [
        'code' => $code, 'name' => 'Transportista ' . $code, 'handler' => 'rates', 'max_weight' => 0, 'active' => 1, 'position' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
}

/** Añade una fila de tarifa (límites en gramos o céntimos según la base). */
function shippingAddRate(App $app, int $carrierId, int $zoneId, string $basis, int $min, int $max, int $price): int
{
    return $app->db()->insert('carrier_rates', ['carrier_id' => $carrierId, 'zone_id' => $zoneId, 'basis' => $basis, 'min' => $min, 'max' => $max, 'price' => $price]);
}

/**
 * Un paquete de prueba.
 *
 * @param array<string,mixed> $over
 *
 * @return array<string,mixed>
 */
function shippingPackage(array $over = []): array
{
    return $over + ['weight' => 500, 'items_total' => 2000, 'qty' => 1, 'needs_shipping' => true];
}

/**
 * Un destino de prueba.
 *
 * @return array<string,string>
 */
function shippingDest(string $country, string $postcode = '', string $region = ''): array
{
    return ['country' => $country, 'region' => $region, 'postcode' => $postcode, 'city' => ''];
}

function shippingCount(App $app, string $table, string $where = '1 = 1', array $params = []): int
{
    return (int) $app->db()->value('SELECT COUNT(*) FROM {' . $table . '} WHERE ' . $where, $params);
}

/**
 * Los precios de las opciones por código de transportista, para comparar en una línea.
 *
 * @param array<int,array<string,mixed>> $options
 *
 * @return array<string,int>
 */
function shippingPrices(array $options): array
{
    $out = [];
    foreach ($options as $option) {
        $out[(string) $option['code']] = (int) $option['price'];
    }

    return $out;
}

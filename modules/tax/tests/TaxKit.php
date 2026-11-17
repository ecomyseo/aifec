<?php
/**
 * AIFEC — apoyo de las pruebas de impuestos: aplicación con el módulo cargado y ayudas para preparar clases y tipos.
 *
 * No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Tax\TaxService;

/**
 * Aplicación con solo el módulo de impuestos, arrancada.
 *
 * @param array<string,mixed> $settings
 */
function taxTestApp(array $settings = []): App
{
    $options = ['modules' => ['tax']];
    if ($settings !== []) {
        $options['settings'] = $settings;
    }
    $app = testApp($options);
    $app->boot();

    return $app;
}

function taxService(App $app): TaxService
{
    $service = $app->service('tax');
    check($service instanceof TaxService, 'el servicio tax es el del módulo');

    return $service;
}

/** El id de una clase de serie por su código («standard», «reduced», «super-reduced», «zero»). */
function taxClassId(App $app, string $code): int
{
    $id = $app->db()->value('SELECT id FROM {tax_classes} WHERE code = ?', [$code]);
    check($id !== null, 'la clase ' . $code . ' existe');

    return (int) $id;
}

/**
 * Añade un tipo a una clase por su código y suelta lo que el servicio tenía leído.
 *
 * @param array<string,mixed> $extra region, postcodes, priority, active
 */
function taxAddRate(App $app, string $class, string $country, int $rate, string $name, array $extra = []): int
{
    $id = $app->db()->insert('tax_rates', $extra + [
        'tax_class_id' => taxClassId($app, $class), 'country' => $country, 'region' => '', 'postcodes' => '',
        'rate' => $rate, 'name' => $name, 'priority' => 0, 'active' => 1,
    ]);
    taxService($app)->flush();

    return $id;
}

function taxCount(App $app, string $table, string $where = '1 = 1', array $params = []): int
{
    return (int) $app->db()->value('SELECT COUNT(*) FROM {' . $table . '} WHERE ' . $where, $params);
}

/**
 * El tipo de una clase en el destino que se da por país, código postal y región, para comparar en una línea.
 *
 * @return array{rate:int,name:string}
 */
function taxRateAt(App $app, ?int $classId, string $country, string $postcode, string $region = ''): array
{
    return taxService($app)->rate($classId, ['country' => $country, 'region' => $region, 'postcode' => $postcode]);
}

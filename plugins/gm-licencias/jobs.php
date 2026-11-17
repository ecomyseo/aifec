<?php
/**
 * AIFEC — GM licences: tareas programadas.
 *
 * Solo trabajan con las tablas del plugin y con una ventana de 90 días: nunca recorren pedidos ni clientes.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Gm\Licencias\Tasks;

return ['cron' => [
    'gm_licencias.purge' => ['every' => 'daily@04:30', 'run' => [Tasks::class, 'purge'], 'description' => 'Deletes the licence checks older than 90 days'],
]];

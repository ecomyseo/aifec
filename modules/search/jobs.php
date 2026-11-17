<?php
/**
 * AIFEC — trabajos del buscador: indexar una entidad, reindexar por tandas, pedir a la IA formas de buscar un producto,
 * calcular el vector de una consulta y la limpieza diaria del registro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Search\SearchJobs;

return [
    'handlers' => [
        'search.index' => [SearchJobs::class, 'index'],
        'search.reindex' => [SearchJobs::class, 'reindex'],
        'search.expand' => [SearchJobs::class, 'expand'],
        'search.embed' => [SearchJobs::class, 'embed'],
    ],
    'cron' => [
        'search.purge' => [
            'every' => 'daily@04:10',
            'run' => [SearchJobs::class, 'purge'],
            'description' => 'Removes search log rows older than 90 days',
        ],
    ],
];

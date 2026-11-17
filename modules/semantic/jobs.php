<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Semantic\AuditJobs;
use Aifec\Modules\Semantic\BotsJobs;
use Aifec\Modules\Semantic\SemanticJobs;

/*
 * Trabajos del módulo: rehacer los ficheros de los feeds (se encola uno cuando cambia un precio o unas existencias y, además, la
 * tarea periódica los rehace cada hora), puntuar los productos para la auditoría AIFEC (por tandas de 100; una vez al día y, para
 * un producto solo, cada vez que se guarda) y sumar cada hora las visitas de robots y asistentes.
 */
return [
    'handlers' => [
        'semantic.feeds' => [SemanticJobs::class, 'feeds'],
        'semantic.audit' => [AuditJobs::class, 'run'],
    ],
    'cron' => [
        'semantic.feeds' => [
            'every' => 'hourly',
            'run' => [SemanticJobs::class, 'feedsTask'],
            'description' => 'Rebuilds the product feeds (google.xml, products.json and openai.json)',
        ],
        'semantic.audit' => [
            'every' => 'daily@03:30',
            'run' => [AuditJobs::class, 'dailyTask'],
            'description' => 'Measures every product for the AIFEC audit',
        ],
        'semantic.visits' => [
            'every' => 'hourly',
            'run' => [BotsJobs::class, 'visitsTask'],
            'description' => 'Adds up the visits of robots and of people arriving from AI assistants',
        ],
    ],
];

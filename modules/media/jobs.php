<?php
/**
 * AIFEC — tareas del módulo de medios: rehacer las variantes de una imagen en segundo plano.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Media\MediaJobs;

return [
    'handlers' => [
        'media.regenerate' => [MediaJobs::class, 'regenerate'],
    ],
    'cron' => [],
];

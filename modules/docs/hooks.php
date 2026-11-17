<?php
/**
 * AIFEC — documentación: el servicio «docs» y el gancho que sirve el texto de cada página antes que nadie.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Docs\Docs;
use Aifec\Modules\Docs\DocsController;

return static function (App $app): void {
    $app->bind('docs', static function (App $app): Docs {
        return new Docs($app);
    });
    // Prioridad 5: antes que el módulo de datos semánticos (10), que también contesta a «…/algo.md» y daría un 404 a las páginas
    // de la documentación, que no son entidades de la tienda.
    $app->hooks()->filter('request.start', [DocsController::class, 'early'], 5);
};

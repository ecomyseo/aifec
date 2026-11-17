<?php
/**
 * AIFEC — impuestos: publica el servicio «tax» y sella el filtro «tax.rate».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Tax\TaxService;

return static function (App $app): void {
    // El tipo de impuesto es dinero: solo un módulo con la capacidad «pricing» concedida puede cambiarlo.
    $app->hooks()->seal('tax.rate', 'pricing');

    $app->bind('tax', static function (App $app): TaxService {
        return new TaxService($app);
    });

    // Al guardar o borrar una clase o un tipo en el panel, el servicio suelta lo que tenía leído.
    $flush = static function (string $resource) use ($app): void {
        if ($resource !== 'tax_classes' && $resource !== 'tax_rates') {
            return;
        }
        $service = $app->service('tax');
        if ($service instanceof TaxService) {
            $service->flush();
        }
    };
    $app->hooks()->on('admin.resource.saved', $flush);
    $app->hooks()->on('admin.resource.deleted', $flush);
};

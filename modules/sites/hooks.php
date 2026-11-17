<?php
/**
 * AIFEC — sitios: el servicio «sites» del sitio maestro. En cualquier otro sitio el módulo no pinta nada.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Sites\Demos;

return static function (App $app): void {
    if (!Demos::isMaster($app)) {
        // Solo tiene efecto en el sitio cuyo config.php lleva 'master' => true: en los demás ni siquiera sale el menú.
        $app->hooks()->filter('admin.definitions', static function ($definitions) {
            if (is_array($definitions)) {
                unset($definitions['sites']);
            }

            return $definitions;
        });

        return;
    }

    $app->bind('sites', static function (App $app): Demos {
        return new Demos($app);
    });
};

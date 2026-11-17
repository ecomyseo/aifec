<?php
/**
 * AIFEC — herramientas: publica los servicios «doctor» y «backups» y pone el modo mantenimiento (la tienda contesta 503,
 * el panel avisa) y el aviso del inicio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Tools\Backups;
use Aifec\Modules\Tools\Doctor;
use Aifec\Modules\Tools\ToolsTasks;
use Aifec\Modules\Tools\Maintenance;

return static function (App $app): void {
    $app->bind('doctor', static function (App $app): Doctor {
        return new Doctor($app);
    });
    // El servicio solo enseña las copias comprobadas: es lo que mira el aviso «sin copia reciente» del inicio.
    // La pantalla de copias y las órdenes de consola usan `new Backups($app)`, que las enseña todas.
    $app->bind('backups', static function (App $app): Backups {
        return new Backups($app, true);
    });
    // Si la última copia que se comprobó salió mal, el inicio lo dice.
    $app->hooks()->filter('admin.status', [ToolsTasks::class, 'backupStatus']);

    // (La dirección del visitante detrás de un proxy de confianza la fija el núcleo, en App::handle, con o sin este módulo.)
    // El mantenimiento va el primero de lo demás: nadie más tiene que contestar a una visita a una tienda cerrada.
    $app->hooks()->filter('request.start', [Maintenance::class, 'early'], 0);
    $app->hooks()->filter('response', [Maintenance::class, 'banner'], 20);
    $app->hooks()->filter('admin.status', [Maintenance::class, 'status']);
    $app->hooks()->slot('admin.foot', static function () use ($app): string {
        return Maintenance::adminBar($app);
    });
    $app->hooks()->on('settings.saved', [Maintenance::class, 'settingsSaved']);
};

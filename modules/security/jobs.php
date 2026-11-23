<?php
/**
 * AIFEC — seguridad: tareas programadas (las ejecuta el módulo de tareas).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Security\SecurityAudit;
use Aifec\Modules\Security\UpdateCheck;

return ['cron' => [
    'security.update_check' => ['every' => 'daily@05:20', 'run' => static function (App $app): void {
        (new UpdateCheck($app))->refresh();
    }, 'description' => 'Asks GitHub for the latest version of AIFEC'],
    'security.audit' => ['every' => 'daily@05:40', 'run' => static function (App $app): void {
        $audit = new SecurityAudit($app);
        $audit->save($audit->run());
    }, 'description' => 'Runs the security audit and keeps the result for the dashboard'],
]];

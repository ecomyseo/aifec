<?php
/**
 * AIFEC — órdenes de consola del módulo de herramientas.
 *
 *   php aifec doctor       comprueba el sistema; sale con 1 si hay algo en rojo
 *   php aifec backup       crea una copia completa de este sitio (base de datos, medios y ficheros privados) y la comprueba
 *   php aifec backup:verify <fichero>                          comprueba una copia sin restaurarla
 *   php aifec backup:restore <fichero> [--yes] [--only=db|files] [--other-site]   restaura una copia
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Tools\BackupCli;
use Aifec\Modules\Tools\Doctor;

return [
    'doctor' => ['help' => 'Checks the system and exits with 1 if something is wrong', 'run' => static function (App $app, array $args, Console $io): int {
        $doctor = new Doctor($app);
        $results = $doctor->run();
        $marks = ['ok' => 'OK', 'warn' => 'WARN', 'bad' => 'BAD'];
        $rows = [];
        foreach ($results as $row) {
            $rows[] = [$marks[$row['status']], $row['group'], $row['label'], $row['detail']];
        }
        $io->table(['STATUS', 'GROUP', 'CHECK', 'DETAIL'], $rows);
        $sum = Doctor::summary($results);
        $io->line('doctor: ' . $sum['ok'] . ' ok, ' . $sum['warn'] . ' warnings, ' . $sum['bad'] . ' problems');

        return $sum['bad'] > 0 ? 1 : 0;
    }],
    'backup' => ['help' => 'Creates a backup of this site and checks it: backup [--mode=full|db]', 'run' => [BackupCli::class, 'backup']],
    'backup:verify' => ['help' => 'Checks a backup without restoring it: backup:verify <file>', 'run' => [BackupCli::class, 'verify']],
    'backup:restore' => ['help' => 'Restores a backup (after making a copy of what is there): backup:restore <file> [--yes] [--only=db|files] [--other-site]', 'run' => [BackupCli::class, 'restore']],
];

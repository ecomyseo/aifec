<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Consent;

use Aifec\Kernel\App;

/**
 * Los números del registro de decisiones: aceptación, rechazo y configuración por semana.
 *
 * Solo cuenta (sin datos personales: el registro no los tiene) y mira las últimas semanas. Las fechas se agrupan por día en la base
 * de datos (solo SQL corriente) y las semanas se arman aquí, con semanas ISO que empiezan en lunes.
 */
final class ConsentReport
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Las últimas semanas, de la más reciente a la más antigua. Cada una: week (2026-W46), start (lunes), all, none, custom, total
     * y los porcentajes enteros all_pct, none_pct y custom_pct (suman 100 si hay decisiones).
     *
     * @return array<int,array<string,mixed>>
     */
    public function weeks(int $weeks = 12): array
    {
        $now = $this->app->clock()->now();
        $weeks = max(1, min(52, $weeks));
        $monday = self::mondayOf($now);
        $from = $monday - ($weeks - 1) * 7 * 86400;
        $rows = $this->app->db()->select(
            'SELECT SUBSTR(created_at, 1, 10) AS d, action, COUNT(*) AS n FROM {consent_log} WHERE created_at >= ? GROUP BY SUBSTR(created_at, 1, 10), action',
            [$this->app->clock()->sql($from)]
        );
        $out = [];
        for ($i = 0; $i < $weeks; ++$i) {
            $start = $monday - $i * 7 * 86400;
            $out[$start] = ['week' => gmdate('o-\WW', $start), 'start' => gmdate('Y-m-d', $start), 'all' => 0, 'none' => 0, 'custom' => 0, 'total' => 0];
        }
        foreach ($rows as $row) {
            $day = strtotime((string) $row['d'] . ' 00:00:00 UTC');
            if ($day === false) {
                continue;
            }
            $key = self::mondayOf((int) $day);
            $action = in_array((string) $row['action'], ['all', 'none', 'custom'], true) ? (string) $row['action'] : 'custom';
            if (isset($out[$key])) {
                $out[$key][$action] += (int) $row['n'];
                $out[$key]['total'] += (int) $row['n'];
            }
        }
        foreach ($out as $key => $week) {
            $total = (int) $week['total'];
            $all = $total > 0 ? intdiv((int) $week['all'] * 100 + intdiv($total, 2), $total) : 0;
            $none = $total > 0 ? intdiv((int) $week['none'] * 100 + intdiv($total, 2), $total) : 0;
            $out[$key]['all_pct'] = $all;
            $out[$key]['none_pct'] = $none;
            $out[$key]['custom_pct'] = $total > 0 ? max(0, 100 - $all - $none) : 0;
        }

        return array_values($out);
    }

    /**
     * Todo el registro como filas para exportar (de la más reciente a la más antigua).
     *
     * @return array<int,array<int,string>>
     */
    public function exportRows(): array
    {
        $rows = $this->app->db()->select('SELECT created_at, cookie_id, policy_version, categories, action, gpc, lang FROM {consent_log} ORDER BY id DESC', []);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                (string) $row['created_at'], (string) $row['cookie_id'], (string) $row['policy_version'], (string) $row['categories'],
                (string) $row['action'], !empty($row['gpc']) ? '1' : '0', (string) $row['lang'],
            ];
        }

        return $out;
    }

    /** Cabecera del CSV de la exportación. */
    public static function exportHeader(): array
    {
        return ['date', 'cookie_id', 'policy_version', 'categories', 'action', 'gpc', 'language'];
    }

    /** Las decisiones de los últimos 30 días por tipo (para la pieza del inicio del panel). */
    public function lastDays(int $days = 30): array
    {
        $from = $this->app->clock()->sql($this->app->clock()->now() - $days * 86400);
        $out = ['all' => 0, 'none' => 0, 'custom' => 0, 'total' => 0];
        foreach ($this->app->db()->select('SELECT action, COUNT(*) AS n FROM {consent_log} WHERE created_at >= ? GROUP BY action', [$from]) as $row) {
            $action = in_array((string) $row['action'], ['all', 'none', 'custom'], true) ? (string) $row['action'] : 'custom';
            $out[$action] += (int) $row['n'];
            $out['total'] += (int) $row['n'];
        }

        return $out;
    }

    /** El lunes (00:00 UTC) de la semana de un instante. */
    private static function mondayOf(int $time): int
    {
        $day = intdiv($time, 86400) * 86400;
        // El 1 de enero de 1970 fue jueves: el día de la semana, con lunes = 0.
        $weekday = (intdiv($day, 86400) + 3) % 7;

        return $day - $weekday * 86400;
    }
}

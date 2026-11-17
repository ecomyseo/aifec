<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Jobs;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;

/**
 * Piezas del panel de las tareas: la ficha de un trabajo y el texto de «cada cuánto».
 */
final class JobsAdmin
{
    /**
     * Ficha de solo lectura de un trabajo (recurso «jobs»), con el botón «Retry» si está fallido.
     *
     * @param array<string,mixed> $row
     */
    public static function show(array $row, App $app): string
    {
        $id = (int) $row['id'];
        $status = (string) $row['status'];
        $html = '<div class="af-grid"><section class="af-col-4"><div class="af-card"><div class="af-card-body">'
            . '<dl class="af-kv">'
            . '<dt>' . e(t('Type')) . '</dt><dd class="af-mono">' . e((string) $row['type']) . '</dd>'
            . '<dt>' . e(t('Status')) . '</dt><dd>' . self::statusBadge($status) . '</dd>'
            . '<dt>' . e(t('Attempts')) . '</dt><dd>' . e((int) $row['attempts'] . ' / ' . (int) $row['max_attempts']) . '</dd>'
            . '<dt>' . e(t('Queue')) . '</dt><dd>' . e((string) $row['queue']) . '</dd>'
            . '<dt>' . e(t('Due')) . '</dt><dd>' . self::when(self::text($row, 'run_at'), $app) . '</dd>'
            . '<dt>' . e(t('Started')) . '</dt><dd>' . self::when(self::text($row, 'locked_at'), $app) . '</dd>'
            . '<dt>' . e(t('Finished')) . '</dt><dd>' . self::when(self::text($row, 'finished_at'), $app) . '</dd>'
            . '<dt>' . e(t('Created')) . '</dt><dd>' . self::when(self::text($row, 'created_at'), $app) . '</dd>'
            . '</dl>';
        if ($status === 'failed' && $app->auth()->can('jobs.edit')) {
            $html .= '<form method="post" action="' . e($app->url()->plain('/admin/jobs/retry/' . $id)) . '" class="af-inline">'
                . Admin::tokenField($app)
                . '<button type="submit" class="af-btn af-btn-primary">' . Admin::icon('refresh') . e(t('Retry')) . '</button></form>';
        }
        $html .= '<p><a class="af-btn af-btn-ghost" href="' . e(Admin::resourceUrl($app, 'jobs')) . '">' . e(t('Back to the list')) . '</a></p>'
            . '</div></div></section><section class="af-col-8">'
            . self::block(t('Payload'), self::pretty(self::text($row, 'payload')))
            . self::block(t('Result'), self::text($row, 'result'))
            . self::block(t('Error'), self::text($row, 'error'))
            . '</section></div>';

        return $html;
    }

    /** Insignia del estado de un trabajo. */
    public static function statusBadge(string $status): string
    {
        $map = [
            'done' => [t('Done'), 'ok'],
            'pending' => [t('Pending'), 'info'],
            'running' => [t('Running'), 'info'],
            'failed' => [t('Failed'), 'bad'],
        ];

        return isset($map[$status]) ? Admin::badge($map[$status][0], $map[$status][1]) : Admin::badge($status);
    }

    /** «Cada hora», «Cada 5 minutos», «Cada día a las 04:10». */
    public static function everyLabel(string $every): string
    {
        if (ctype_digit($every)) {
            $n = (int) $every;
            if ($n === 3600) {
                return t('Every hour');
            }
            if ($n === 60) {
                return t('Every minute');
            }
            if ($n % 3600 === 0) {
                return tn('Every {n} hour', 'Every {n} hours', intdiv($n, 3600));
            }
            if ($n % 60 === 0) {
                return tn('Every {n} minute', 'Every {n} minutes', intdiv($n, 60));
            }

            return tn('Every {n} second', 'Every {n} seconds', $n);
        }
        if ($every === 'hourly') {
            return t('Every hour');
        }
        if (strpos($every, 'daily@') === 0) {
            return t('Every day at {time}', ['time' => substr($every, 6)]);
        }

        return $every;
    }

    /** Fecha y hora del sitio, ya escapada; un guion si no hay. */
    public static function when(?string $sql, App $app): string
    {
        $time = $sql !== null && $sql !== '' ? Clock::parse($sql) : 0;
        if ($time <= 0) {
            return '<span class="af-muted">—</span>';
        }

        return '<span class="af-nowrap">' . e($app->i18n()->date($time, 'medium', $app->site()->timezone())) . '</span>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function text(array $row, string $key): string
    {
        return isset($row[$key]) && is_scalar($row[$key]) ? (string) $row[$key] : '';
    }

    private static function pretty(string $json): string
    {
        $data = json_decode($json, true);
        if (!is_array($data) || $data === []) {
            return $json === '[]' ? '' : $json;
        }
        $out = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($out) ? $out : $json;
    }

    private static function block(string $title, string $text): string
    {
        return '<div class="af-card"><div class="af-card-head"><h2>' . e($title) . '</h2></div><div class="af-card-body">'
            . ($text === '' ? '<span class="af-muted">—</span>' : '<pre class="af-mono" style="white-space:pre-wrap;word-break:break-word;margin:0">' . e($text) . '</pre>')
            . '</div></div>';
    }
}

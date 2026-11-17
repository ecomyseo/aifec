<?php
/**
 * AIFEC — diagnóstico del sistema (servicio «doctor»): interroga al sitio ya montado y dice qué está bien y qué no.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Kernel\App;
use Aifec\Modules\Tools\Checks\DatabaseChecks;
use Aifec\Modules\Tools\Checks\FileChecks;
use Aifec\Modules\Tools\Checks\MailChecks;
use Aifec\Modules\Tools\Checks\SecurityChecks;
use Aifec\Modules\Tools\Checks\ServerChecks;
use Aifec\Modules\Tools\Checks\ShopChecks;
use Aifec\Modules\Tools\Checks\TaskChecks;

/**
 * Cada comprobación es una definición `['id', 'group', 'label', 'run' => callable(App): array]` y devuelve
 * `['status' => 'ok'|'warn'|'bad', 'detail' => texto, 'fix_url' => dirección]` (o solo el estado). Si una lanza,
 * sale como «bad» con su error y las demás siguen.
 *
 * Los demás módulos añaden las suyas con el filtro «doctor.checks» `(array $checks, App $app)`: la lista de definiciones
 * (también vale una función suelta, que se toma como una comprobación sin nombre).
 */
final class Doctor
{
    private const GROUPS = ['server', 'files', 'database', 'tasks', 'mail', 'shop', 'security'];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** Un resultado de comprobación. */
    public static function result(string $status, string $detail = '', string $fixUrl = ''): array
    {
        return ['status' => $status, 'detail' => $detail, 'fix_url' => $fixUrl];
    }

    /**
     * Las definiciones de todas las comprobaciones (las de serie y las que añadan los demás módulos).
     *
     * @return array<int,mixed>
     */
    public function checks(): array
    {
        $checks = array_merge(
            ServerChecks::all($this->app),
            FileChecks::all($this->app),
            DatabaseChecks::all($this->app),
            TaskChecks::all($this->app),
            MailChecks::all($this->app),
            ShopChecks::all($this->app),
            SecurityChecks::all($this->app)
        );
        $filtered = $this->app->hooks()->apply('doctor.checks', $checks, $this->app);

        return is_array($filtered) ? array_values($filtered) : $checks;
    }

    /**
     * Pasa todas las comprobaciones.
     *
     * @return array<int,array{id:string,group:string,status:string,label:string,detail:string,fix_url:string}>
     */
    public function run(): array
    {
        $results = [];
        foreach ($this->checks() as $i => $check) {
            if (is_callable($check)) {
                $check = ['id' => 'custom.' . $i, 'group' => 'other', 'label' => t('Check'), 'run' => $check];
            }
            if (!is_array($check) || !isset($check['run']) || !is_callable($check['run'])) {
                continue;
            }
            $base = [
                'id' => isset($check['id']) ? (string) $check['id'] : 'custom.' . $i,
                'group' => isset($check['group']) ? (string) $check['group'] : 'other',
                'label' => isset($check['label']) ? (string) $check['label'] : '',
            ];
            try {
                $out = $check['run']($this->app);
                $results[] = $base + self::normalise($out);
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                $results[] = $base + ['status' => 'bad', 'detail' => get_class($e) . ($message !== '' ? ': ' . $message : ''), 'fix_url' => ''];
            }
        }
        $order = array_flip(self::GROUPS);
        usort($results, static function (array $a, array $b) use ($order): int {
            $ga = isset($order[$a['group']]) ? $order[$a['group']] : 99;
            $gb = isset($order[$b['group']]) ? $order[$b['group']] : 99;

            return $ga <=> $gb;
        });

        return $results;
    }

    /**
     * Cuántas comprobaciones hay en cada estado.
     *
     * @param array<int,array<string,mixed>> $results
     *
     * @return array{ok:int,warn:int,bad:int}
     */
    public static function summary(array $results): array
    {
        $sum = ['ok' => 0, 'warn' => 0, 'bad' => 0];
        foreach ($results as $row) {
            if (isset($row['status'], $sum[$row['status']])) {
                ++$sum[$row['status']];
            }
        }

        return $sum;
    }

    /**
     * @param mixed $out
     *
     * @return array{status:string,detail:string,fix_url:string}
     */
    private static function normalise($out): array
    {
        if (is_string($out)) {
            $out = ['status' => $out];
        }
        if (!is_array($out) || !isset($out['status']) || !in_array($out['status'], ['ok', 'warn', 'bad'], true)) {
            return ['status' => 'bad', 'detail' => 'The check returned something that is not a result', 'fix_url' => ''];
        }

        return [
            'status' => (string) $out['status'],
            'detail' => isset($out['detail']) && is_scalar($out['detail']) ? (string) $out['detail'] : '',
            'fix_url' => isset($out['fix_url']) && is_scalar($out['fix_url']) ? (string) $out['fix_url'] : '',
        ];
    }
}

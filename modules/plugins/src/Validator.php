<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Plugins;

/**
 * El validador del Contrato AIFEC: junta las comprobaciones estáticas (StaticChecks) y las dinámicas (DynamicChecks) en
 * un informe con cifras.
 *
 * Informe: ok (no hay ningún fallo de nivel «error»), score (0 a 100), checks (cada uno con rule, level, ok, message, file
 * y line; toda regla medida tiene al menos una entrada, en verde si no falló), measured (las cifras que se midieron) y
 * dynamic (si se ejecutó la parte dinámica).
 *
 * La parte dinámica ejecuta el plugin en un sitio de pruebas: no se hace si el código ya tiene un fallo grave de los que
 * no se deben ejecutar (código prohibido o manifiesto roto).
 */
final class Validator
{
    /** Reglas estáticas y dinámicas, en el orden en que se enseñan. */
    public const RULES = ['M1', 'P1', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'C8', 'C9', 'C10'];

    private const STATIC_RULES = ['M1', 'P1', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C8', 'C9'];

    /**
     * @param array<string,mixed> $manifest el plugin.json leído (vacío si no es válido)
     *
     * @return array<string,mixed>
     */
    public static function run(Plugins $plugins, string $root, string $id, array $manifest, bool $dynamic): array
    {
        $app = $plugins->app();
        $i18n = $app->i18n();
        $static = (new StaticChecks($i18n, $root, $id, $manifest, $plugins->protectedTables($id), $plugins->sealedHooks()))->run();
        $findings = $static['findings'];
        $measured = $static['measured'];
        $rules = self::STATIC_RULES;

        $blocked = false;
        foreach ($findings as $f) {
            if ($f['level'] === 'error' && in_array($f['rule'], ['M1', 'P1'], true)) {
                $blocked = true;
            }
        }
        $ranDynamic = false;
        if ($dynamic && !$blocked) {
            $result = (new DynamicChecks($plugins, $root, $id, $manifest))->run();
            foreach ($result['findings'] as $f) {
                $findings[] = $f;
            }
            $measured['dynamic'] = $result['measured'];
            $rules = array_merge($rules, $result['rules']);
            $ranDynamic = true;
        } elseif ($dynamic) {
            $measured['dynamic'] = 'skipped';
        }

        $checks = [];
        $credit = 0.0;
        $counted = 0;
        foreach (self::RULES as $rule) {
            if (!in_array($rule, $rules, true)) {
                continue;
            }
            $failed = array_values(array_filter($findings, static function (array $f) use ($rule): bool {
                return $f['rule'] === $rule;
            }));
            ++$counted;
            if ($failed === []) {
                $checks[] = ['rule' => $rule, 'level' => 'error', 'ok' => true, 'message' => $i18n->t('Passes'), 'file' => '', 'line' => 0];
                $credit += 1.0;
                continue;
            }
            $hasError = false;
            foreach ($failed as $f) {
                $hasError = $hasError || $f['level'] === 'error';
                $checks[] = ['rule' => $rule, 'level' => $f['level'], 'ok' => false, 'message' => $f['message'], 'file' => $f['file'], 'line' => (int) $f['line']];
            }
            $credit += $hasError ? 0.0 : 0.5;
        }
        $ok = true;
        foreach ($checks as $c) {
            if (!$c['ok'] && $c['level'] === 'error') {
                $ok = false;
            }
        }
        if (count($checks) > 150) {
            $checks = array_slice($checks, 0, 150);
        }

        return [
            'ok' => $ok,
            'score' => $counted > 0 ? (int) round(100 * $credit / $counted) : 0,
            'checks' => $checks,
            'measured' => $measured,
            'dynamic' => $ranDynamic,
        ];
    }

    /**
     * Nombre legible de cada regla (para el panel y la consola).
     *
     * @return array<string,string>
     */
    public static function titles(\Aifec\Kernel\I18n $i18n): array
    {
        return [
            'M1' => $i18n->t('Manifest and declaration'),
            'P1' => $i18n->t('Forbidden code'),
            'C1' => $i18n->t('Money only through the official channels'),
            'C2' => $i18n->t('Product data declared and typed'),
            'C3' => $i18n->t('Light and cacheable output'),
            'C4' => $i18n->t('Everything escaped and through templates'),
            'C5' => $i18n->t('External services and personal data declared'),
            'C6' => $i18n->t('AI, mail and tasks through the common channels'),
            'C7' => $i18n->t('Structured data intact'),
            'C8' => $i18n->t('Texts translated'),
            'C9' => $i18n->t('Uninstalls clean'),
            'C10' => $i18n->t('Does not bring the shop down'),
        ];
    }
}

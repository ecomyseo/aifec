<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot;

use Aifec\Kernel\App;

/**
 * Prepara una acción con sus datos: mira qué falta, pide su vista previa, le pone los límites del copiloto y calcula la huella
 * de lo que se va a enseñar. No cambia nada.
 *
 * La huella es un resumen de la acción, sus datos y TODOS los cambios de la vista previa (no solo las diez filas que se ven):
 * si entre que la persona mira y aprueba cambia cualquier dato, la huella es otra y no se aplica.
 */
final class Planner
{
    /** Filas de la vista previa que se guardan en la propuesta (la pantalla enseña estas y el total). */
    public const SHOWN = 10;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,mixed> $def  definición de la acción (Actions::define)
     * @param array<string,mixed> $args datos ya en su forma máquina
     *
     * @return array{preview:array<string,mixed>,status:string,fingerprint:string}
     *     status = incomplete (falta un dato o hay que elegir) o proposed; preview lleva las primeras filas y el total
     */
    public function plan(array $def, array $args): array
    {
        $missing = Params::missing($def['params'], $args, $def['one_of']);
        $base = [
            'summary' => (string) $def['label'], 'changes' => [], 'count' => 0, 'more' => 0, 'warnings' => [], 'errors' => [],
            'choices' => [], 'links' => [], 'missing' => $missing, 'readonly' => (bool) $def['readonly'],
        ];
        if ($missing !== []) {
            return ['preview' => $base, 'status' => 'incomplete', 'fingerprint' => ''];
        }

        try {
            $raw = call_user_func($def['preview'], $this->app, $args);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'copilot preview ' . $def['id']);
            $base['errors'][] = t('The copilot could not prepare this change.');

            return ['preview' => $base, 'status' => 'proposed', 'fingerprint' => ''];
        }
        $raw = is_array($raw) ? $raw : [];
        $changes = self::rows(isset($raw['changes']) ? $raw['changes'] : []);
        $count = isset($raw['count']) && is_numeric($raw['count']) ? max(0, (int) $raw['count']) : count($changes);
        $errors = self::texts(isset($raw['errors']) ? $raw['errors'] : []);
        $max = max(1, $this->app->settings()->int('copilot.max_rows', 500));
        if ($count > $max) {
            $errors[] = t('This change touches more than {max} records ({n}). Narrow it down.', ['max' => (string) $max, 'n' => (string) $count]);
        }
        $choices = self::choices(isset($raw['choices']) ? $raw['choices'] : [], $def);

        $preview = [
            'summary' => isset($raw['summary']) && is_string($raw['summary']) && $raw['summary'] !== '' ? mb_substr($raw['summary'], 0, 300, 'UTF-8') : (string) $def['label'],
            'changes' => array_slice($changes, 0, self::SHOWN),
            'count' => $count,
            'more' => max(0, $count - self::SHOWN),
            'warnings' => self::texts(isset($raw['warnings']) ? $raw['warnings'] : []),
            'errors' => array_slice($errors, 0, 6),
            'choices' => $choices,
            'links' => self::links(isset($raw['links']) ? $raw['links'] : []),
            'missing' => [],
            'readonly' => (bool) $def['readonly'],
        ];
        ksort($args);
        $fingerprint = sha1((string) json_encode([$def['id'], $args, $changes, $count], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));

        return ['preview' => $preview, 'status' => $choices !== [] ? 'incomplete' : 'proposed', 'fingerprint' => $fingerprint];
    }

    /**
     * @param mixed $rows
     *
     * @return array<int,array{label:string,from:string,to:string}>
     */
    private static function rows($rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['label'])) {
                $out[] = [
                    'label' => mb_substr((string) $row['label'], 0, 300, 'UTF-8'),
                    'from' => mb_substr(isset($row['from']) && is_scalar($row['from']) ? (string) $row['from'] : '', 0, 300, 'UTF-8'),
                    'to' => mb_substr(isset($row['to']) && is_scalar($row['to']) ? (string) $row['to'] : '', 0, 300, 'UTF-8'),
                ];
            }
        }

        return $out;
    }

    /**
     * @param mixed $list
     *
     * @return string[]
     */
    private static function texts($list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $text) {
            if (is_scalar($text) && trim((string) $text) !== '') {
                $out[] = mb_substr(trim((string) $text), 0, 400, 'UTF-8');
            }
        }

        return $out;
    }

    /**
     * Las opciones de una elección: solo para datos que existen en la acción.
     *
     * @param mixed               $choices
     * @param array<string,mixed> $def
     *
     * @return array<int,array{param:string,value:string,label:string}>
     */
    private static function choices($choices, array $def): array
    {
        $names = array_map(static function (array $p): string {
            return (string) $p['name'];
        }, $def['params']);
        $out = [];
        foreach (is_array($choices) ? $choices : [] as $choice) {
            if (is_array($choice) && isset($choice['param'], $choice['value'], $choice['label']) && in_array((string) $choice['param'], $names, true)) {
                $out[] = ['param' => (string) $choice['param'], 'value' => (string) $choice['value'], 'label' => mb_substr((string) $choice['label'], 0, 300, 'UTF-8')];
            }
        }

        return array_slice($out, 0, 12);
    }

    /**
     * Enlaces a pantallas del propio panel: solo direcciones que empiezan por «/» (nunca a otro sitio ni un javascript:).
     *
     * @param mixed $links
     *
     * @return array<int,array{label:string,url:string}>
     */
    private static function links($links): array
    {
        $out = [];
        foreach (is_array($links) ? $links : [] as $link) {
            if (is_array($link) && isset($link['label'], $link['url']) && is_string($link['url']) && preg_match('#^/(?!/)[^\s\\\\]*$#', $link['url']) === 1) {
                $out[] = ['label' => mb_substr((string) $link['label'], 0, 200, 'UTF-8'), 'url' => $link['url']];
            }
        }

        return array_slice($out, 0, 4);
    }
}

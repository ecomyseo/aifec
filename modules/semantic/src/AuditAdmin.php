<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Semantic;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;

/**
 * La auditoría AIFEC dentro del panel: la pieza del inicio y la columna de la ficha de producto.
 */
final class AuditAdmin
{
    /** Colores de una nota: 80 o más, bien; 50 o más, regular; menos, mal. */
    public static function kind(int $score): string
    {
        return $score >= 80 ? 'ok' : ($score >= 50 ? 'warn' : 'bad');
    }

    /** La pieza «AIFEC» del inicio: la nota del sitio y los tres fallos que más productos tienen. */
    public static function widget(App $app): string
    {
        /** @var Semantic $semantic */
        $semantic = $app->service('semantic');
        $site = Audit::site($semantic);
        $score = (int) $site['score'];
        $labels = Audit::labels();

        $list = '';
        foreach (AuditStore::topFails($app, 3) as $fail) {
            $list .= '<li>' . e(isset($labels[$fail['id']]) ? $labels[$fail['id']] : $fail['id'])
                . ' <span class="af-muted af-small">' . e(tn('{n} product', '{n} products', $fail['count'])) . '</span></li>';
        }
        if ($list === '') {
            $list = '<li class="af-muted">' . e(AuditStore::count($app) === 0 ? t('The products have not been measured yet') : t('Nothing to fix')) . '</li>';
        }

        return '<div class="af-card"><div class="af-stat"><div class="af-stat-label">' . e(t('AIFEC')) . ' · ' . e(t('Machine readiness')) . '</div>'
            . '<div class="af-stat-value af-tone-' . e(self::kind($score)) . '">' . e((string) $score) . '<span class="af-muted" style="font-size: var(--f1)"> / 100</span></div>'
            . '<div class="af-stat-note">' . e(t('Most repeated gaps in the products')) . '</div></div>'
            . '<div class="af-card-body"><ul>' . $list . '</ul>'
            . '<a class="af-btn af-btn-sm" href="' . e($app->url()->plain('/admin/semantic/audit')) . '">' . e(t('Open the audit')) . '</a></div></div>';
    }

    /**
     * La columna derecha de la ficha de producto: su nota y lo que le falta. En un producto nuevo no sale nada.
     *
     * @param array<string,mixed>|null $row
     */
    public static function side(?array $row, App $app): string
    {
        if ($row === null || !isset($row['id']) || (int) $row['id'] <= 0 || !$app->auth()->can('semantic.view')) {
            return '';
        }
        /** @var Semantic $semantic */
        $semantic = $app->service('semantic');
        $result = Audit::product($semantic, (int) $row['id']);
        if ($result['checks'] === []) {
            return '';
        }
        $items = '';
        foreach ($result['checks'] as $check) {
            if ($check['ok']) {
                continue;
            }
            $items .= '<li><a href="' . e((string) $check['fix_url']) . '">' . e((string) $check['label']) . '</a></li>';
        }
        $body = $items === ''
            ? '<p class="af-muted">' . e(t('Everything the machines need is filled in.')) . '</p>'
            : '<p class="af-small af-muted">' . e(t('What is missing')) . '</p><ul>' . $items . '</ul>';

        return '<div class="af-card"><div class="af-card-head"><h2>' . e(t('AIFEC score')) . '</h2>'
            . Admin::badge((string) (int) $result['score'] . ' / 100', self::kind((int) $result['score'])) . '</div>'
            . '<div class="af-card-body">' . $body . '</div></div>';
    }

    /**
     * El filtro «admin.definitions»: añade la columna derecha a la ficha de producto del catálogo, sin tocar su módulo. Si el
     * catálogo (u otro módulo) ya puso una, la nota va debajo.
     *
     * @param mixed $definitions
     *
     * @return mixed
     */
    public static function definitions($definitions, App $app)
    {
        if (!is_array($definitions) || !isset($definitions['catalog']['resources']['products']) || !is_array($definitions['catalog']['resources']['products'])) {
            return $definitions;
        }
        $previous = isset($definitions['catalog']['resources']['products']['side']) ? $definitions['catalog']['resources']['products']['side'] : null;
        $definitions['catalog']['resources']['products']['side'] = static function (?array $row, App $application) use ($previous): string {
            $html = is_callable($previous) ? (string) call_user_func($previous, $row, $application) : '';

            return $html . self::side($row, $application);
        };

        return $definitions;
    }
}

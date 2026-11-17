<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Seo;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;

/**
 * Lo que el módulo pone en el inicio del panel: el aviso de que el sitio está cerrado a los buscadores y la pieza «SEO».
 */
final class SeoAdmin
{
    /** ¿El sitio está cerrado a los buscadores (interruptor del panel o sitio de demostración)? */
    public static function isClosed(App $app): bool
    {
        return $app->site()->isDemo() || $app->settings()->bool('seo.noindex_site', false);
    }

    /**
     * Aviso del inicio: mientras el interruptor «no indexar» esté puesto se ve a todo el que entra, con el enlace para quitarlo
     * solo si puede.
     *
     * @param array<int,array<string,mixed>> $status
     *
     * @return array<int,array<string,mixed>>
     */
    public static function status(array $status, App $app): array
    {
        if (!$app->settings()->bool('seo.noindex_site', false)) {
            return $status;
        }
        $item = ['kind' => 'bad', 'text' => t('The whole site is hidden from search engines. Turn it off when the shop is ready.')];
        if ($app->auth()->can('seo.edit')) {
            $item['url'] = $app->url()->plain('/admin/settings/seo');
            $item['label'] = t('Change');
        }
        $status[] = $item;

        return $status;
    }

    /** La pieza del inicio: número de problemas de la auditoría y estado de indexación. */
    public static function widget(App $app): string
    {
        $result = Audit::run($app);
        $total = (int) $result['total'];
        $closed = self::isClosed($app);

        $state = $closed ? Admin::badge(t('Hidden from search engines'), 'bad') : Admin::badge(t('Open to search engines'), 'ok');

        return '<div class="af-card"><div class="af-stat"><div class="af-stat-label">' . e(t('SEO')) . ' · ' . e(t('Problems found')) . '</div>'
            . '<div class="af-stat-value">' . e((string) $total) . '</div><div class="af-stat-note">' . $state . '</div></div>'
            . '<div class="af-card-body"><a class="af-btn af-btn-sm" href="' . e($app->url()->plain('/admin/seo/audit')) . '">' . e(t('Open the audit')) . '</a></div></div>';
    }
}

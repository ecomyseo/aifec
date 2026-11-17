<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;

/**
 * La IA dentro del panel: la pieza del inicio (gasto de hoy frente al tope y propuestas pendientes), los avisos de estado y la tarjeta
 * «Proponer con IA» de la ficha de producto.
 */
final class AiAdmin
{
    /** Los textos de cada tarea en los botones y en la bandeja (en inglés; el panel los traduce). */
    public static function taskLabel(string $task): string
    {
        $labels = [
            'description' => 'Description',
            'summary' => 'Summary',
            'facts' => 'Key facts',
            'faqs' => 'Frequently asked questions',
            'meta_title' => 'Meta title',
            'meta_description' => 'Meta description',
            'alt' => 'Alternative text of the images',
            'translate' => 'Translate',
        ];

        return isset($labels[$task]) ? $labels[$task] : $task;
    }

    /** El nombre de un nivel (en inglés; se traduce al enseñarlo). */
    public static function tierLabel(string $tier): string
    {
        $labels = ['economy' => 'Economy', 'standard' => 'Standard', 'advanced' => 'Advanced'];

        return isset($labels[$tier]) ? $labels[$tier] : $tier;
    }

    /** Los textos de cada campo de producto o de imagen. */
    public static function fieldLabel(string $field): string
    {
        $labels = [
            'name' => 'Name',
            'summary' => 'Summary',
            'description' => 'Description',
            'facts' => 'Key facts',
            'faqs' => 'Frequently asked questions',
            'meta_title' => 'Meta title',
            'meta_description' => 'Meta description',
            'alt' => 'Alternative text',
        ];

        return isset($labels[$field]) ? $labels[$field] : $field;
    }

    /** La pieza «IA» del inicio. */
    public static function widget(App $app): string
    {
        $spent = Usage::spentMicro($app);
        $limit = Usage::limitMicro($app);
        /** @var Ai $ai */
        $ai = $app->service('ai');
        $over = $ai->limitReached();
        $pending = $ai->proposals()->pendingCount();
        $kind = !$ai->enabled() ? 'warn' : ($over ? 'bad' : 'ok');

        return '<div class="af-card"><div class="af-stat"><div class="af-stat-label">' . e(t('AI spending today')) . '</div>'
            . '<div class="af-stat-value af-tone-' . e($kind) . '">' . e(Usage::amount($app, $spent)) . '<span class="af-muted" style="font-size: var(--f1)"> '
            . e(t('of {limit}', ['limit' => Usage::amount($app, $limit)])) . '</span></div>'
            . '<div class="af-stat-note">' . e($ai->enabled() ? t('Estimated cost of the day against the daily cap') : t('The AI is off')) . '</div></div>'
            . '<div class="af-card-body"><p>' . e(tn('{n} proposal waiting for approval', '{n} proposals waiting for approval', $pending)) . '</p>'
            . ($app->auth()->can('ai.use') ? '<a class="af-btn af-btn-sm" href="' . e($app->url()->plain('/admin/ai/proposals', ['f' => ['status' => 'pending']])) . '">' . e(t('Open the proposals')) . '</a> ' : '')
            . '<a class="af-btn af-btn-sm af-btn-ghost" href="' . e($app->url()->plain('/admin/ai/usage')) . '">' . e(t('See the usage')) . '</a> '
            . ($app->auth()->can('ai.edit') ? '<a class="af-btn af-btn-sm af-btn-ghost" href="' . e($app->url()->plain('/admin/settings/ai')) . '">' . e(t('AI settings')) . '</a>' : '') . '</div></div>';
    }

    /**
     * ¿Algún nivel que se usa de verdad no tiene precio por millón de fichas? Con precio cero el coste sale a 0,00 € aunque la IA
     * trabaje, y el tope diario no cuenta nada. Los niveles de pruebas («fake») o servidos por otro módulo no se miran.
     */
    public static function pricesMissing(App $app): bool
    {
        if (!$app->settings()->bool('ai.enabled', false)) {
            return false;
        }
        foreach (Ai::TIERS as $tier) {
            if ($app->has('ai.provider.' . $tier) || $app->settings()->str('ai.' . $tier . '.provider', 'gemini') === 'fake') {
                continue;
            }
            if ($app->settings()->int('ai.' . $tier . '.price_in', 0) === 0 && $app->settings()->int('ai.' . $tier . '.price_out', 0) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filtro «admin.status»: avisa si la IA está encendida y un nivel no tiene clave, o si ningún precio está puesto (el tope no
     * podría contar nada).
     *
     * @param mixed $status
     *
     * @return array<int,array<string,string>>
     */
    public static function status($status, App $app): array
    {
        $status = is_array($status) ? $status : [];
        if (!$app->auth()->can('ai.edit')) {
            return $status;
        }
        $url = $app->url()->plain('/admin/settings/ai');
        if (!$app->settings()->bool('ai.enabled', false)) {
            // Con la IA apagada también se dice dónde se enciende y dónde va la clave (13/11/2026: «¿dónde demonios configuro la
            // API de la IA?»).
            $status[] = [
                'kind' => 'info',
                'text' => t('The AI is off. Turn it on and add the API key of your provider in the AI settings.'),
                'url' => $url . '#tab=aimodels',
                'label' => t('Set up the AI'),
            ];

            return $status;
        }
        $noKey = [];
        foreach (Ai::TIERS as $tier) {
            if ($app->has('ai.provider.' . $tier)) {
                continue;
            }
            $kind = $app->settings()->str('ai.' . $tier . '.provider', 'gemini');
            if ($kind === 'fake') {
                continue;
            }
            if ($app->secret('ai.' . $tier . '.key') === '') {
                $noKey[] = t(self::tierLabel($tier));
            }
        }
        $noPrice = self::pricesMissing($app);
        if ($noKey !== []) {
            $status[] = [
                'kind' => 'bad',
                'text' => t('The AI is on but there is no API key for: {tiers}', ['tiers' => implode(', ', $noKey)]),
                'url' => $url,
                'label' => t('Open the settings'),
            ];
        }
        if ($noPrice) {
            $status[] = [
                'kind' => 'warn',
                'text' => t('The AI is on but no price per million tokens is set, so the daily cap cannot count the spending'),
                'url' => $url,
                'label' => t('Open the settings'),
            ];
        }

        return $status;
    }

    /**
     * La tarjeta «Proponer con IA» de la columna derecha de la ficha de producto. En un producto nuevo no sale nada.
     *
     * @param array<string,mixed>|null $row
     */
    public static function side(?array $row, App $app): string
    {
        if ($row === null || !isset($row['id']) || (int) $row['id'] <= 0 || !$app->auth()->can('ai.use')) {
            return '';
        }
        $head = '<div class="af-card"><div class="af-card-head"><h2>' . e(t('Propose with AI')) . '</h2></div><div class="af-card-body af-stack">';
        /** @var Ai $ai */
        $ai = $app->service('ai');
        if (!$ai->enabled()) {
            $link = $app->auth()->can('ai.edit') ? ' <a href="' . e($app->url()->plain('/admin/settings/ai')) . '">' . e(t('Open the settings')) . '</a>' : '';

            return $head . '<p class="af-muted">' . e(t('The AI is off.')) . $link . '</p></div></div>';
        }

        $id = (int) $row['id'];
        $languages = $app->languages();
        $html = $head . '<form method="post" action="' . e($app->url()->plain('/admin/ai/propose')) . '" class="af-stack">' . Admin::tokenField($app)
            . '<input type="hidden" name="product_id" value="' . $id . '">';
        if (count($languages) > 1) {
            $html .= '<label class="af-small af-muted">' . e(t('Language of the text')) . '<select class="af-select" name="lang">';
            foreach ($languages as $lang) {
                $html .= '<option value="' . e($lang) . '">' . e(strtoupper($lang)) . '</option>';
            }
            $html .= '</select></label>';
        }
        $html .= '<p class="af-small af-muted">' . e(t('The AI writes a proposal; nothing is published until you approve it.')) . '</p><div class="af-stack">';
        foreach (['description', 'summary', 'facts', 'faqs', 'meta_title', 'meta_description', 'alt'] as $task) {
            $html .= '<button type="submit" class="af-btn af-btn-sm" name="task" value="' . e($task) . '">' . Admin::icon('sparkles') . e(t(self::taskLabel($task))) . '</button>';
        }
        $html .= '</div>';
        if (count($languages) > 1) {
            $html .= '<hr><label class="af-small af-muted">' . e(t('Translate this field')) . '<select class="af-select" name="field">';
            foreach (['name', 'summary', 'description', 'facts', 'faqs', 'meta_title', 'meta_description'] as $field) {
                $html .= '<option value="' . e($field) . '">' . e(t(self::fieldLabel($field))) . '</option>';
            }
            $html .= '</select></label><label class="af-small af-muted">' . e(t('Into')) . '<select class="af-select" name="to">';
            foreach ($languages as $lang) {
                if ($lang !== $app->defaultLang()) {
                    $html .= '<option value="' . e($lang) . '">' . e(strtoupper($lang)) . '</option>';
                }
            }
            $html .= '</select></label><button type="submit" class="af-btn af-btn-sm" name="task" value="translate">' . Admin::icon('globe') . e(t('Translate')) . '</button>';
        }

        return $html . '</form></div></div>';
    }

    /**
     * La tarjeta «Proponer con IA» de una página de contenido: su título y su descripción para buscadores (14/11/2026).
     *
     * @param array<string,mixed>|null $row
     */
    public static function pageSide(?array $row, App $app): string
    {
        if ($row === null || !isset($row['id']) || (int) $row['id'] <= 0 || !$app->auth()->can('ai.use')) {
            return '';
        }
        $head = '<div class="af-card" data-af-page-ai><div class="af-card-head"><h2>' . e(t('Propose with AI')) . '</h2></div><div class="af-card-body af-stack">';
        /** @var Ai $ai */
        $ai = $app->service('ai');
        if (!$ai->enabled()) {
            return $head . '<p class="af-muted">' . e(t('The AI is off.')) . '</p></div></div>';
        }
        $html = $head . '<form method="post" action="' . e($app->url()->plain('/admin/ai/propose')) . '" class="af-stack">' . Admin::tokenField($app)
            . '<input type="hidden" name="page_id" value="' . (int) $row['id'] . '">';
        $languages = $app->languages();
        if (count($languages) > 1) {
            $html .= '<label class="af-small af-muted">' . e(t('Language of the text')) . '<select class="af-select" name="lang">';
            foreach ($languages as $lang) {
                $html .= '<option value="' . e($lang) . '">' . e(strtoupper($lang)) . '</option>';
            }
            $html .= '</select></label>';
        }
        $html .= '<p class="af-small af-muted">' . e(t('The AI writes a proposal; nothing is published until you approve it.')) . '</p>'
            . '<button type="submit" class="af-btn af-btn-sm" name="task" value="page_meta_title">' . Admin::icon('sparkles') . e(t('Title for search engines')) . '</button>'
            . '<button type="submit" class="af-btn af-btn-sm" name="task" value="page_meta_description">' . Admin::icon('sparkles') . e(t('Description for search engines')) . '</button>';

        return $html . '</form></div></div>';
    }

    /**
     * El filtro «admin.definitions»: añade la tarjeta a la columna derecha de la ficha de producto del catálogo, sin tocar su módulo.
     * Si otro módulo ya puso una, la tarjeta va debajo.
     *
     * @param mixed $definitions
     *
     * @return mixed
     */
    public static function definitions($definitions, App $app)
    {
        if (is_array($definitions) && isset($definitions['cms']['resources']['pages']) && is_array($definitions['cms']['resources']['pages'])) {
            $previousPage = isset($definitions['cms']['resources']['pages']['side']) ? $definitions['cms']['resources']['pages']['side'] : null;
            $definitions['cms']['resources']['pages']['side'] = static function (?array $row, App $application) use ($previousPage): string {
                $html = is_callable($previousPage) ? (string) call_user_func($previousPage, $row, $application) : '';

                return $html . self::pageSide($row, $application);
            };
        }
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

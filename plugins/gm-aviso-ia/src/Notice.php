<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\AvisoIa;

use Aifec\Kernel\App;

/**
 * Decide si hay que enseñar el aviso de «textos redactados con IA» y lo pinta con su vista.
 *
 * Tres sitios: la ficha del producto (bajo el resumen, al final, o los dos), las páginas y entradas del CMS (encima del
 * pie) y un aviso corto en el pie de todas las páginas. Con el ajuste «solo lo marcado» y el módulo `ai` cargado, solo
 * sale donde una persona aprobó un texto escrito por la IA (tabla ai_marks del módulo ai: aquí no se duplica ningún dato).
 *
 * Nada de lo que se pinta depende del visitante: las páginas siguen siendo cacheables.
 */
final class Notice
{
    public const SETTING_PREFIX = 'gm_aviso_ia.';

    /**
     * El aviso de la ficha de un producto en un sitio concreto ('summary' o 'bottom'). Vacío si no toca.
     *
     * @param array<string,mixed> $product ProductData
     */
    public static function product(App $app, array $product, string $where): string
    {
        $settings = $app->settings();
        if (!$settings->bool('gm_aviso_ia.enabled', true)) {
            return '';
        }
        $placement = $settings->str('gm_aviso_ia.placement', 'summary');
        if ($placement !== 'both' && $placement !== $where) {
            return '';
        }
        $id = isset($product['id']) ? (int) $product['id'] : 0;
        if ($id < 1 || !self::inCategories($settings->str('gm_aviso_ia.categories', ''), $product)) {
            return '';
        }
        if (!self::allowed($app, 'product', $id)) {
            return '';
        }
        // Si el módulo de IA ya pone su propia marca al final de la ficha, aquí no se repite.
        if ($where === 'bottom' && self::aiPrintsItsOwn($app, $id)) {
            return '';
        }

        return self::render($app, 'product-' . $where, self::title($app), self::text($app));
    }

    /**
     * El aviso de una página o entrada del CMS, encima del pie. $type y $id salen de la página que se está pintando.
     */
    public static function page(App $app, string $type, int $id): string
    {
        $settings = $app->settings();
        if (!$settings->bool('gm_aviso_ia.enabled', true) || !$settings->bool('gm_aviso_ia.pages', true)) {
            return '';
        }
        if (($type !== 'page' && $type !== 'post') || $id < 1 || !self::allowed($app, $type, $id)) {
            return '';
        }

        return self::render($app, 'page', self::title($app), self::text($app));
    }

    /** El aviso corto del pie de todas las páginas (ajuste «aviso en el pie»). */
    public static function footer(App $app): string
    {
        $settings = $app->settings();
        if (!$settings->bool('gm_aviso_ia.enabled', true) || !$settings->bool('gm_aviso_ia.footer', false)) {
            return '';
        }
        $text = trim($settings->str('gm_aviso_ia.footer_text', ''));
        if ($text === '') {
            $text = t('The product descriptions of this shop were written with the help of AI and reviewed by a person.');
        }

        return $app->view()->render('gm-aviso-ia::front/footer', ['text' => $text, 'url' => self::infoUrl($app), 'label' => self::label($app)]);
    }

    /** La dirección de la página que explica cómo se hace: una página del CMS elegida, o la propia del plugin. */
    public static function infoUrl(App $app): string
    {
        $pageId = $app->settings()->int('gm_aviso_ia.page_id', 0);
        if ($pageId > 0) {
            return $app->url()->entity('page', $pageId);
        }

        return $app->router()->has('gm.aviso_ia') ? $app->url()->route('gm.aviso_ia') : '';
    }

    public static function title(App $app): string
    {
        $title = trim($app->settings()->str('gm_aviso_ia.title', ''));

        return $title !== '' ? $title : t('Texts written with the help of artificial intelligence');
    }

    public static function text(App $app): string
    {
        $text = trim($app->settings()->str('gm_aviso_ia.text', ''));

        return $text !== '' ? $text : t('The descriptions of this shop were written with the help of artificial intelligence. A person reviews them before they are published.');
    }

    public static function label(App $app): string
    {
        $label = trim($app->settings()->str('gm_aviso_ia.link_label', ''));

        return $label !== '' ? $label : t('How these texts are made');
    }

    /** ¿Se enseña en esta entidad? Con «solo lo marcado» y el módulo de IA, solo si tiene una marca aprobada. */
    private static function allowed(App $app, string $entity, int $id): bool
    {
        if ($app->settings()->str('gm_aviso_ia.scope', 'all') !== 'marked') {
            return true;
        }
        if (!self::aiMarks($app)) {
            // Sin módulo de IA no hay marcas que mirar: se enseña en todas, que es lo que el comerciante pidió al activar el aviso.
            return true;
        }

        return (int) $app->db()->value('SELECT COUNT(*) FROM {ai_marks} WHERE entity = ? AND entity_id = ?', [$entity, $id]) > 0;
    }

    private static function aiMarks(App $app): bool
    {
        return $app->modules()->isLoaded('ai') && $app->db()->tableExists('ai_marks');
    }

    private static function aiPrintsItsOwn(App $app, int $productId): bool
    {
        if (!self::aiMarks($app) || !$app->settings()->bool('ai.show_notice', false)) {
            return false;
        }

        return (int) $app->db()->value("SELECT COUNT(*) FROM {ai_marks} WHERE entity = 'product' AND entity_id = ?", [$productId]) > 0;
    }

    /**
     * ¿Está el producto en alguna de las categorías elegidas? Vacío = todas.
     *
     * @param array<string,mixed> $product
     */
    private static function inCategories(string $list, array $product): bool
    {
        $wanted = [];
        foreach (preg_split('/[\s,;]+/', $list) ?: [] as $part) {
            if ((int) $part > 0) {
                $wanted[] = (int) $part;
            }
        }
        if ($wanted === []) {
            return true;
        }
        foreach (isset($product['categories']) && is_array($product['categories']) ? $product['categories'] : [] as $category) {
            if (is_array($category) && isset($category['id']) && in_array((int) $category['id'], $wanted, true)) {
                return true;
            }
        }

        return false;
    }

    private static function render(App $app, string $where, string $title, string $text): string
    {
        return $app->view()->render('gm-aviso-ia::front/notice', [
            'where' => $where, 'title' => $title, 'text' => $text, 'url' => self::infoUrl($app), 'label' => self::label($app),
        ]);
    }
}

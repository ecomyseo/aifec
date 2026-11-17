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

use Aifec\Kernel\App;

/**
 * La marca de la tienda: «Parte de este texto se redactó con ayuda de IA y fue revisado por una persona», al final de la ficha del
 * producto (hueco «product.bottom»). Solo sale con el ajuste ai.show_notice encendido y si ese producto tiene textos aprobados
 * (marcas). La ficha va en la caché de página: al aprobar, el producto invalida su etiqueta y la marca aparece sola.
 */
final class Notice
{
    /**
     * @param array<string,mixed> $product ProductData
     */
    public static function render(App $app, array $product): string
    {
        if (!$app->settings()->bool('ai.show_notice', false)) {
            return '';
        }
        $id = isset($product['id']) ? (int) $product['id'] : 0;
        if ($id <= 0) {
            return '';
        }
        $marks = (int) $app->db()->value("SELECT COUNT(*) FROM {ai_marks} WHERE entity = 'product' AND entity_id = ?", [$id]);
        if ($marks === 0) {
            return '';
        }

        return '<p class="ai-notice">' . e(t('Part of this text was written with the help of AI and reviewed by a person.')) . '</p>';
    }
}

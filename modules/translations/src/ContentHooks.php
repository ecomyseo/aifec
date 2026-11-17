<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Translations;

use Aifec\Kernel\App;
use Aifec\Util\Str;

/**
 * Dos oyentes que hacen que el contenido traducido se vea en /<idioma>/…:
 *
 *  - «ai.approved»: al aprobar el nombre de un producto o el título de una página o de una entrada en un idioma que NO es el
 *    principal, si esa entidad aún no tiene dirección en ese idioma, se le da una sacada del nombre traducido, con el mismo prefijo
 *    que la del idioma principal («producto/silla-roble» → «producto/chaise-chene»). Si ya existía, no se toca.
 *  - «ai.apply» (puente): el módulo de IA pasa al filtro la fila de ai_proposals, con el texto en «proposed_text», y el aplicador
 *    de páginas y entradas del módulo de contenidos lo busca en «proposed». Sin este puente, una propuesta de página aprobada no la
 *    aplicaba nadie. Aquí se llama a ese mismo aplicador, por su método público, con el texto donde lo espera.
 */
final class ContentHooks
{
    /**
     * @param array<string,mixed> $proposal la propuesta aprobada (fila de ai_proposals)
     */
    public static function approved(App $app, array $proposal): void
    {
        $entity = isset($proposal['entity']) ? (string) $proposal['entity'] : '';
        $field = isset($proposal['field']) ? (string) $proposal['field'] : '';
        $id = isset($proposal['entity_id']) ? (int) $proposal['entity_id'] : 0;
        $lang = isset($proposal['lang']) ? (string) $proposal['lang'] : '';
        $text = isset($proposal['proposed_text']) && is_string($proposal['proposed_text']) ? $proposal['proposed_text'] : '';
        $wanted = ($entity === 'product' && $field === 'name') || (($entity === 'page' || $entity === 'post') && $field === 'title');
        if (!$wanted || $id <= 0 || $lang === '' || $lang === $app->defaultLang() || !in_array($lang, $app->languages(), true)) {
            return;
        }
        $slugs = $app->slugs();
        if ($slugs->path($entity, $id, $lang) !== null) {
            return;
        }
        $base = $slugs->path($entity, $id, $app->defaultLang());
        if ($base === null) {
            return;
        }
        $pos = strrpos($base, '/');
        $prefix = $pos === false ? '' : substr($base, 0, $pos + 1);
        $segment = Str::slug(strip_tags($text), 90);
        $slugs->set($entity, $id, $lang, $prefix . ($segment !== '' ? $segment : $entity . '-' . $id));
        $app->invalidate([$entity . ':' . $id, $entity === 'product' ? 'catalog' : 'cms']);
    }

    /**
     * El puente de «ai.apply» para las páginas y las entradas.
     *
     * @param mixed $done
     * @param mixed $proposal
     */
    public static function applyContent(App $app, $done, $proposal): bool
    {
        if ($done === true) {
            return true;
        }
        if (!is_array($proposal) || isset($proposal['proposed']) || !isset($proposal['proposed_text']) || !is_string($proposal['proposed_text'])) {
            return false;
        }
        $entity = isset($proposal['entity']) ? (string) $proposal['entity'] : '';
        if (($entity !== 'page' && $entity !== 'post') || !$app->has('cms') || !class_exists('Aifec\\Modules\\Cms\\AiApply')) {
            return false;
        }

        return \Aifec\Modules\Cms\AiApply::apply(false, $proposal + ['proposed' => $proposal['proposed_text']]);
    }
}

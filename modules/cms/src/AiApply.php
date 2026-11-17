<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Cms;

use Aifec\Kernel\App;

/**
 * El filtro `ai.apply` `(bool $done, array $proposal)` de las propuestas de la IA: aplica una propuesta APROBADA sobre un
 * campo de texto de una página o de una entrada, en un idioma (título, extracto, meta título o meta descripción), y nada más.
 *
 * La propuesta trae `entity` (`page` o `post`), `entity_id`, `field`, `lang` (vacío = idioma principal) y el texto aprobado en
 * `proposed`. Solo se toca ESE campo de ESE idioma, sin pasar por el formulario: los bloques, el estado y las demás columnas
 * no se tocan, y un contenido que no se ve sigue sin verse. Devuelve true si lo aplicó (o ya estaba aplicado) y, si no es
 * suyo o no se puede aplicar, deja el valor como venía.
 */
final class AiApply
{
    /** Campos que se pueden aplicar y su límite de largo. */
    private const FIELDS = ['title' => 191, 'excerpt' => 600, 'meta_title' => 191, 'meta_description' => 320];

    /**
     * @param mixed               $done
     * @param array<string,mixed> $proposal
     *
     * @return bool
     */
    public static function apply($done, $proposal): bool
    {
        if ($done === true || !is_array($proposal)) {
            return $done === true;
        }
        $entity = isset($proposal['entity']) ? (string) $proposal['entity'] : '';
        $field = isset($proposal['field']) ? (string) $proposal['field'] : '';
        if (($entity !== 'page' && $entity !== 'post') || !isset(self::FIELDS[$field])) {
            return false;
        }
        $id = isset($proposal['entity_id']) ? (int) $proposal['entity_id'] : 0;
        // La bandeja de la IA entrega la fila de la propuesta tal cual (su texto va en «proposed_text»); quien llama a mano puede
        // pasar «proposed». Leer solo «proposed» dejaba sin aplicar toda propuesta de página aprobada desde la bandeja.
        $raw = isset($proposal['proposed_text']) && is_string($proposal['proposed_text']) ? $proposal['proposed_text'] : (isset($proposal['proposed']) && is_string($proposal['proposed']) ? $proposal['proposed'] : '');
        $text = trim($raw);
        $app = App::current();
        $lang = isset($proposal['lang']) && is_string($proposal['lang']) && $proposal['lang'] !== '' ? $proposal['lang'] : $app->defaultLang();
        if ($id <= 0 || !in_array($lang, $app->languages(), true) || ($text === '' && $field === 'title')) {
            return false;
        }
        $text = mb_substr(strip_tags($text), 0, self::FIELDS[$field]);

        $db = $app->db();
        $isPage = $entity === 'page';
        if ($db->value($isPage ? 'SELECT id FROM {pages} WHERE id = ?' : 'SELECT id FROM {posts} WHERE id = ?', [$id]) === null) {
            return false;
        }
        $fk = $isPage ? 'page_id' : 'post_id';
        $table = $isPage ? 'page_i18n' : 'post_i18n';
        $exists = $db->value($isPage ? 'SELECT id FROM {page_i18n} WHERE page_id = ? AND lang = ?' : 'SELECT id FROM {post_i18n} WHERE post_id = ? AND lang = ?', [$id, $lang]) !== null;
        if (!$exists && $field !== 'title') {
            // Una traducción nueva empieza por su título: el texto de otro campo no se puede colgar de la nada.
            return false;
        }
        if ($exists) {
            $db->update($table, [$field => $text], [$fk => $id, 'lang' => $lang]);
        } else {
            $db->insert($table, [$fk => $id, 'lang' => $lang, 'title' => $text]);
        }
        $db->update($isPage ? 'pages' : 'posts', ['updated_at' => $app->clock()->sql()], ['id' => $id]);
        (new Revisions($app))->record($entity, $id);
        $app->invalidate(['cms', $entity . ':' . $id]);
        $app->hooks()->fire('content.saved', $entity, $id);

        return true;
    }
}

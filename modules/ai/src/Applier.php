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
 * Aplica una propuesta aprobada (filtro «ai.apply», que devuelve true cuando alguien la aplicó). Este módulo aplica los campos de
 * producto por idioma (ProductFields::FIELDS) y el texto alternativo de una imagen (entidad «media», campo «alt»); las páginas y
 * entradas las aplica el módulo «cms».
 *
 * Escribe con cuidado: solo ese campo, en ese idioma, y después invalida la caché del producto y avisa a quien reindexa.
 */
final class Applier
{
    /**
     * @param array<string,mixed> $proposal fila de ai_proposals
     */
    public static function apply(App $app, bool $done, array $proposal): bool
    {
        if ($done) {
            return true;
        }
        $entity = isset($proposal['entity']) ? (string) $proposal['entity'] : '';
        $id = isset($proposal['entity_id']) ? (int) $proposal['entity_id'] : 0;
        $field = isset($proposal['field']) ? (string) $proposal['field'] : '';
        $lang = isset($proposal['lang']) ? (string) $proposal['lang'] : '';
        $text = isset($proposal['proposed_text']) ? (string) $proposal['proposed_text'] : '';
        if ($id <= 0) {
            return false;
        }
        if ($entity === 'product' && ProductFields::isField($field)) {
            return self::product($app, $id, $lang, $field, $text);
        }
        if ($entity === 'media' && $field === 'alt') {
            return self::mediaAlt($app, $id, $text);
        }

        return false;
    }

    private static function product(App $app, int $id, string $lang, string $field, string $text): bool
    {
        if ($lang === '') {
            $lang = $app->defaultLang();
        }
        if (!$app->db()->tableExists('product_i18n') || !ProductFields::write($app, $id, $lang, $field, $text)) {
            return false;
        }
        $tags = ['product:' . $id];
        if ($field === 'name' || $field === 'summary') {
            $tags[] = 'catalog';
        }
        $app->invalidate($tags);
        // Lo mismo que avisa el panel al guardar un producto: el buscador lo reindexa, los feeds y la auditoría se rehacen.
        $app->hooks()->fire('admin.resource.saved', 'products', $id, [$field => $text], null);

        return true;
    }

    private static function mediaAlt(App $app, int $id, string $text): bool
    {
        $db = $app->db();
        if (!$db->tableExists('media') || $db->row('SELECT id FROM {media} WHERE id = ?', [$id]) === null) {
            return false;
        }
        $alt = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        $db->update('media', ['alt' => $alt === '' ? null : mb_substr($alt, 0, 300, 'UTF-8')], ['id' => $id]);
        $tags = ['media'];
        if ($db->tableExists('product_media')) {
            $products = $db->column('SELECT product_id FROM {product_media} WHERE media_id = ?', [$id]);
            $main = $db->column('SELECT id FROM {products} WHERE main_media_id = ?', [$id]);
            foreach (array_unique(array_merge($products, $main)) as $productId) {
                $tags[] = 'product:' . (int) $productId;
            }
        }
        $app->invalidate($tags);
        $app->hooks()->fire('admin.resource.saved', 'media', $id, ['alt' => $alt], null);

        return true;
    }
}

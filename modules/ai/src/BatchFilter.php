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
 * Qué productos recorre un lote. Tres conjuntos:
 *
 *   no_description   los que no tienen descripción en el idioma «lang» (el principal por defecto)
 *   category         los de una categoría (category_id)
 *   missing_lang     los que no tienen traducción al idioma «lang»; con un campo de texto («field»), también los que la tienen
 *                    pero con ese campo vacío
 *
 * El recorrido va por identificador creciente: «dame los siguientes N después de este». Todo con parámetros.
 */
final class BatchFilter
{
    public const TYPES = ['no_description', 'category', 'missing_lang'];

    /**
     * Deja el filtro como se guarda: solo las claves que importan y con su tipo.
     *
     * @param array<string,mixed> $filter
     *
     * @return array<string,mixed>
     *
     * @throws \InvalidArgumentException
     */
    public static function normalise(App $app, array $filter): array
    {
        $type = isset($filter['type']) && is_string($filter['type']) ? $filter['type'] : '';
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown batch filter: ' . $type);
        }
        $out = ['type' => $type];
        $lang = isset($filter['lang']) && is_string($filter['lang']) && $filter['lang'] !== '' ? strtolower($filter['lang']) : $app->defaultLang();
        if (!in_array($lang, $app->languages(), true)) {
            throw new \InvalidArgumentException('Unknown language: ' . $lang);
        }
        if ($type === 'category') {
            $out['category_id'] = isset($filter['category_id']) ? (int) $filter['category_id'] : 0;
            if ($out['category_id'] <= 0) {
                throw new \InvalidArgumentException('Choose a category');
            }

            return $out;
        }
        $out['lang'] = $lang;
        if ($type === 'missing_lang') {
            $field = isset($filter['field']) && is_string($filter['field']) ? $filter['field'] : '';
            if ($field !== '') {
                if (!in_array($field, ProductFields::TEXT_FIELDS, true)) {
                    throw new \InvalidArgumentException('Unknown field: ' . $field);
                }
                $out['field'] = $field;
            }
        }

        return $out;
    }

    /**
     * Los $limit identificadores siguientes a $afterId.
     *
     * @param array<string,mixed> $filter ya normalizado
     *
     * @return int[]
     */
    public static function nextIds(App $app, array $filter, int $afterId, int $limit): array
    {
        list($from, $where, $params) = self::parts($app, $filter);
        $params[] = $afterId;
        $params[] = max(1, $limit);
        $rows = $app->db()->column('SELECT p.id FROM ' . $from . ' WHERE ' . $where . ' AND p.id > ? ORDER BY p.id LIMIT ?', $params);

        return array_map('intval', $rows);
    }

    /**
     * Cuántos productos recorre en total (desde el principio).
     *
     * @param array<string,mixed> $filter ya normalizado
     */
    public static function count(App $app, array $filter): int
    {
        list($from, $where, $params) = self::parts($app, $filter);

        return (int) $app->db()->value('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where, $params);
    }

    /**
     * @param array<string,mixed> $filter
     *
     * @return array{0:string,1:string,2:array<int,mixed>} desde, condición y parámetros
     */
    private static function parts(App $app, array $filter): array
    {
        $db = $app->db();
        $type = isset($filter['type']) ? (string) $filter['type'] : '';
        if ($type === 'category') {
            return ['{products} p INNER JOIN {product_categories} pc ON pc.product_id = p.id', 'pc.category_id = ?', [(int) $filter['category_id']]];
        }
        $lang = isset($filter['lang']) ? (string) $filter['lang'] : $app->defaultLang();
        if ($type === 'no_description') {
            return [
                '{products} p LEFT JOIN {product_i18n} i ON i.product_id = p.id AND i.lang = ?',
                "(i.id IS NULL OR i.description IS NULL OR i.description = '')",
                [$lang],
            ];
        }
        if ($type === 'missing_lang') {
            $where = 't.id IS NULL';
            if (isset($filter['field']) && is_string($filter['field']) && in_array($filter['field'], ProductFields::TEXT_FIELDS, true)) {
                $column = 't.' . $db->ident($filter['field']);
                $where = '(t.id IS NULL OR ' . $column . ' IS NULL OR ' . $column . " = '')";
            }

            return [
                '{products} p INNER JOIN {product_i18n} s ON s.product_id = p.id AND s.lang = ? LEFT JOIN {product_i18n} t ON t.product_id = p.id AND t.lang = ?',
                $where,
                [$app->defaultLang(), $lang],
            ];
        }
        throw new \InvalidArgumentException('Unknown batch filter: ' . $type);
    }
}

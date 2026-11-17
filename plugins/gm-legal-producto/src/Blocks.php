<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\LegalProducto;

use Aifec\Kernel\App;

/**
 * Los bloques legales y a qué se aplican.
 *
 * Un bloque se asigna a productos concretos o a categorías (y vale para las subcategorías de esa categoría). Solo los activos
 * se enseñan, ordenados por su posición. Todo el texto es sin formato y se escapa al pintarlo.
 */
final class Blocks
{
    public const KINDS = ['guarantee' => 'Guarantee', 'withdrawal' => 'Withdrawal of digital content', 'compatibility' => 'Compatibility', 'support' => 'Support', 'other' => 'Other'];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Los bloques activos que se aplican a un producto, por asignación directa o por categoría.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forProduct(int $productId): array
    {
        return $this->forProducts([$productId]);
    }

    /**
     * Los bloques activos que se aplican a alguno de estos productos, sin repetir y por posición.
     *
     * @param int[] $productIds
     *
     * @return array<int,array<string,mixed>>
     */
    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static function (int $id): bool {
            return $id > 0;
        })));
        if ($productIds === []) {
            return [];
        }
        $db = $this->app->db();
        $categories = $this->categoryIds($productIds);
        $params = [];
        $sql = "SELECT * FROM {gm_legal_blocks} WHERE active = 1 AND id IN (SELECT block_id FROM {gm_legal_assignments} WHERE (target_type = 'product' AND target_id IN (" . $db->in($productIds, $params) . "))";
        if ($categories !== []) {
            $sql .= " OR (target_type = 'category' AND target_id IN (" . $db->in($categories, $params) . '))';
        }
        $sql .= ') ORDER BY position, id';

        return $db->select($sql, $params);
    }

    /**
     * Las categorías de estos productos y todas las de arriba (la ruta de cada una lleva los identificadores desde la raíz).
     *
     * @param int[] $productIds
     *
     * @return int[]
     */
    public function categoryIds(array $productIds): array
    {
        $db = $this->app->db();
        $params = [];
        $own = $db->column('SELECT DISTINCT category_id FROM {product_categories} WHERE product_id IN (' . $db->in($productIds, $params) . ')', $params);
        $all = [];
        foreach ($own as $categoryId) {
            $all[(int) $categoryId] = true;
            $path = $db->value('SELECT path FROM {categories} WHERE id = ?', [(int) $categoryId]);
            foreach (explode('/', is_string($path) ? $path : '') as $part) {
                if ((int) $part > 0) {
                    $all[(int) $part] = true;
                }
            }
        }

        return array_keys($all);
    }

    /**
     * @return int[]
     */
    public function assigned(int $blockId, string $type): array
    {
        return array_map('intval', $this->app->db()->column('SELECT target_id FROM {gm_legal_assignments} WHERE block_id = ? AND target_type = ? ORDER BY target_id', [$blockId, $type]));
    }

    /**
     * Deja asignado el bloque a estas categorías y a estos productos (lo anterior se sustituye).
     *
     * @param int[] $categoryIds
     * @param int[] $productIds
     */
    public function assign(int $blockId, array $categoryIds, array $productIds): void
    {
        $db = $this->app->db();
        $db->transaction(static function ($db) use ($blockId, $categoryIds, $productIds): void {
            $db->delete('gm_legal_assignments', ['block_id' => $blockId]);
            foreach (['category' => $categoryIds, 'product' => $productIds] as $type => $ids) {
                foreach (array_unique(array_filter(array_map('intval', $ids))) as $id) {
                    $db->insertIgnore('gm_legal_assignments', ['block_id' => $blockId, 'target_type' => $type, 'target_id' => $id]);
                }
            }
        });
        $this->app->invalidate(['catalog']);
    }

    /** Quita las asignaciones de un bloque (cuando se borra). */
    public function forget(int $blockId): void
    {
        $this->app->db()->delete('gm_legal_assignments', ['block_id' => $blockId]);
        $this->app->invalidate(['catalog']);
    }

    /**
     * El texto de un bloque en párrafos (una línea = un párrafo; las vacías se quitan).
     *
     * @return string[]
     */
    public static function paragraphs(string $body): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            if (trim($line) !== '') {
                $out[] = trim($line);
            }
        }

        return $out;
    }

    /**
     * Los bloques en la forma que usan las vistas y el correo: título, tipo y párrafos.
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array{id:int,title:string,kind:string,paragraphs:string[]}>
     */
    public static function view(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'kind' => (string) $row['kind'], 'paragraphs' => self::paragraphs((string) $row['body'])];
        }

        return $out;
    }
}

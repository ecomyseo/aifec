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

use Aifec\Kernel\App;

/**
 * Las notas ya calculadas (tabla audit_scores): una fila por producto con su nota y los códigos de lo que le falta.
 * La pantalla y la pieza del inicio leen de aquí; el cálculo corre en segundo plano (AuditJobs).
 */
final class AuditStore
{
    public const PRODUCT = 'product';

    /** Tramos de la distribución de notas: [desde, hasta, tipo de insignia]. */
    public const BANDS = [[0, 49, 'bad'], [50, 69, 'warn'], [70, 89, 'info'], [90, 100, 'ok']];

    /**
     * Guarda la nota de una entidad con lo que le falta (de más a menos peso).
     *
     * @param array{score:int,checks:array<int,array{id:string,ok:bool,label:string,fix_url:string,weight:int}>} $result
     */
    public static function save(App $app, string $entity, int $id, array $result): void
    {
        $failed = [];
        foreach ($result['checks'] as $index => $check) {
            if (!$check['ok']) {
                $failed[] = [(int) $check['weight'], $index, (string) $check['id']];
            }
        }
        // La ordenación de PHP 7 no es estable: el desempate por posición lo hace estable.
        usort($failed, static function (array $a, array $b): int {
            return $a[0] === $b[0] ? $a[1] <=> $b[1] : $b[0] <=> $a[0];
        });
        $ids = [];
        foreach ($failed as $row) {
            $ids[] = $row[2];
        }
        $app->db()->upsert('audit_scores', [
            'entity' => $entity,
            'entity_id' => $id,
            'score' => max(0, min(100, (int) $result['score'])),
            'fails' => json_encode($ids, JSON_UNESCAPED_UNICODE),
            'checked_at' => $app->clock()->sql(),
        ], ['entity', 'entity_id']);
    }

    public static function forget(App $app, string $entity, int $id): void
    {
        $app->db()->delete('audit_scores', ['entity' => $entity, 'entity_id' => $id]);
    }

    /** Borra las notas de los productos que ya no existen o no están publicados. */
    public static function prune(App $app): void
    {
        $app->db()->run(
            "DELETE FROM {audit_scores} WHERE entity = ? AND entity_id NOT IN (SELECT id FROM {products} WHERE status = 'published')",
            [self::PRODUCT]
        );
    }

    /** Cuántos productos tienen nota. */
    public static function count(App $app): int
    {
        return (int) $app->db()->value('SELECT COUNT(*) FROM {audit_scores} WHERE entity = ?', [self::PRODUCT]);
    }

    /** Cuándo se puntuó algo por última vez (fecha de la base de datos, UTC), o null si nunca. */
    public static function lastChecked(App $app): ?string
    {
        $value = $app->db()->value('SELECT MAX(checked_at) FROM {audit_scores} WHERE entity = ?', [self::PRODUCT]);

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /**
     * Cuántos productos hay en cada tramo de nota.
     *
     * @return array<int,array{from:int,to:int,kind:string,count:int}>
     */
    public static function distribution(App $app): array
    {
        $out = [];
        foreach (self::BANDS as $band) {
            $count = (int) $app->db()->value(
                'SELECT COUNT(*) FROM {audit_scores} WHERE entity = ? AND score >= ? AND score <= ?',
                [self::PRODUCT, $band[0], $band[1]]
            );
            $out[] = ['from' => $band[0], 'to' => $band[1], 'kind' => $band[2], 'count' => $count];
        }

        return $out;
    }

    /**
     * Los productos con peor nota, con lo que les falta. Con $missing (un código de comprobación de producto) solo salen los
     * que no la cumplen.
     *
     * @return array<int,array{id:int,score:int,fails:string[],checked_at:string}>
     */
    public static function worst(App $app, int $limit = 50, string $missing = ''): array
    {
        $sql = 'SELECT entity_id, score, fails, checked_at FROM {audit_scores} WHERE entity = ?';
        $params = [self::PRODUCT];
        if ($missing !== '' && isset(Audit::PRODUCT[$missing])) {
            $sql .= ' AND fails LIKE ?';
            $params[] = '%"' . $missing . '"%';
        }
        $sql .= ' ORDER BY score ASC, entity_id ASC LIMIT ' . max(1, $limit);
        $out = [];
        foreach ($app->db()->select($sql, $params) as $row) {
            $fails = json_decode((string) $row['fails'], true);
            $out[] = [
                'id' => (int) $row['entity_id'],
                'score' => (int) $row['score'],
                'fails' => is_array($fails) ? array_values(array_map('strval', $fails)) : [],
                'checked_at' => (string) $row['checked_at'],
            ];
        }

        return $out;
    }

    /**
     * Las comprobaciones que más productos incumplen, de más a menos.
     *
     * @return array<int,array{id:string,count:int}>
     */
    public static function topFails(App $app, int $limit = 3): array
    {
        $rows = [];
        $order = 0;
        foreach (array_keys(Audit::PRODUCT) as $id) {
            $count = (int) $app->db()->value(
                'SELECT COUNT(*) FROM {audit_scores} WHERE entity = ? AND fails LIKE ?',
                [self::PRODUCT, '%"' . $id . '"%']
            );
            if ($count > 0) {
                $rows[] = [$count, Audit::PRODUCT[$id], $order, $id];
            }
            ++$order;
        }
        // Primero los más repetidos; a igualdad, los que más pesan, y después el orden de la lista.
        usort($rows, static function (array $a, array $b): int {
            if ($a[0] !== $b[0]) {
                return $b[0] <=> $a[0];
            }

            return $a[1] !== $b[1] ? $b[1] <=> $a[1] : $a[2] <=> $b[2];
        });
        $out = [];
        foreach (array_slice($rows, 0, max(0, $limit)) as $row) {
            $out[] = ['id' => $row[3], 'count' => $row[0]];
        }

        return $out;
    }
}

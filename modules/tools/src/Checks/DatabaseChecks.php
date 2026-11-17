<?php
/**
 * AIFEC — comprobaciones de la base de datos: conexión, migraciones pendientes y filas huérfanas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools\Checks;

use Aifec\Db\Migrator;
use Aifec\Kernel\App;
use Aifec\Modules\Tools\Doctor;

final class DatabaseChecks
{
    /** Cuántos ficheros de medios se comprueban (los últimos). */
    private const MEDIA_SAMPLE = 200;

    /** @return array<int,array<string,mixed>> */
    public static function all(App $app): array
    {
        return [
            ['id' => 'db.connection', 'group' => 'database', 'label' => t('Database connection'), 'run' => [self::class, 'connection']],
            ['id' => 'db.migrations', 'group' => 'database', 'label' => t('Pending migrations'), 'run' => [self::class, 'migrations']],
            ['id' => 'db.orphan_variants', 'group' => 'database', 'label' => t('Variants without a product'), 'run' => [self::class, 'orphanVariants']],
            ['id' => 'db.orphan_texts', 'group' => 'database', 'label' => t('Texts without their product, category or attribute'), 'run' => [self::class, 'orphanTexts']],
            ['id' => 'db.orphan_order_items', 'group' => 'database', 'label' => t('Order lines without an order'), 'run' => [self::class, 'orphanOrderItems']],
            ['id' => 'db.orphan_categories', 'group' => 'database', 'label' => t('Products in categories that do not exist'), 'run' => [self::class, 'orphanCategories']],
            ['id' => 'db.orphan_slugs', 'group' => 'database', 'label' => t('Addresses of things that were deleted'), 'run' => [self::class, 'orphanSlugs']],
            ['id' => 'db.orphan_media', 'group' => 'database', 'label' => t('Media whose file is missing'), 'run' => [self::class, 'orphanMedia']],
        ];
    }

    /** @return array<string,string> */
    public static function connection(App $app): array
    {
        $app->db()->value('SELECT 1');

        return Doctor::result('ok', $app->db()->driver() === 'sqlite' ? 'SQLite' : 'MySQL / MariaDB');
    }

    /** @return array<string,string> */
    public static function migrations(App $app): array
    {
        $modules = $app->modules();
        $scopes = ['core' => $app->root() . '/core/migrations'];
        $ids = [];
        foreach ($modules->all() as $id => $manifest) {
            if ($manifest['kind'] === 'module' || $modules->isLoaded((string) $id)) {
                $ids[] = (string) $id;
            }
        }
        $scopes += $modules->migrationDirs($ids);
        $done = [];
        foreach ((new Migrator($app->db()))->applied() as $row) {
            $done[$row['scope'] . '/' . $row['name']] = true;
        }
        $pending = [];
        foreach ($scopes as $scope => $dir) {
            foreach (Migrator::files($dir) as $file) {
                $name = basename($file, '.php');
                if (!isset($done[$scope . '/' . $name])) {
                    $pending[] = $scope . '/' . $name;
                }
            }
        }
        if ($pending === []) {
            return Doctor::result('ok', t('Everything is applied'));
        }

        return Doctor::result('warn', implode(', ', array_slice($pending, 0, 5)) . (count($pending) > 5 ? '…' : '') . ' — php aifec migrate');
    }

    /** @return array<string,string> */
    public static function orphanVariants(App $app): array
    {
        $n = self::count($app, ['variants', 'products'], 'SELECT COUNT(*) FROM {variants} v LEFT JOIN {products} p ON p.id = v.product_id WHERE p.id IS NULL');

        return self::report($n);
    }

    /** @return array<string,string> */
    public static function orphanTexts(App $app): array
    {
        $queries = [
            [['product_i18n', 'products'], 'SELECT COUNT(*) FROM {product_i18n} i LEFT JOIN {products} p ON p.id = i.product_id WHERE p.id IS NULL'],
            [['category_i18n', 'categories'], 'SELECT COUNT(*) FROM {category_i18n} i LEFT JOIN {categories} c ON c.id = i.category_id WHERE c.id IS NULL'],
            [['attribute_i18n', 'attributes'], 'SELECT COUNT(*) FROM {attribute_i18n} i LEFT JOIN {attributes} a ON a.id = i.attribute_id WHERE a.id IS NULL'],
        ];
        $total = null;
        foreach ($queries as $query) {
            $n = self::count($app, $query[0], $query[1]);
            if ($n !== null) {
                $total = (int) $total + $n;
            }
        }

        return self::report($total);
    }

    /** @return array<string,string> */
    public static function orphanOrderItems(App $app): array
    {
        $n = self::count($app, ['order_items', 'orders'], 'SELECT COUNT(*) FROM {order_items} i LEFT JOIN {orders} o ON o.id = i.order_id WHERE o.id IS NULL');

        return self::report($n);
    }

    /** @return array<string,string> */
    public static function orphanCategories(App $app): array
    {
        $n = self::count($app, ['product_categories', 'categories'], 'SELECT COUNT(*) FROM {product_categories} pc LEFT JOIN {categories} c ON c.id = pc.category_id WHERE c.id IS NULL');

        return self::report($n);
    }

    /** @return array<string,string> */
    public static function orphanSlugs(App $app): array
    {
        $queries = [
            ['product', ['slugs', 'products'], 'SELECT COUNT(*) FROM {slugs} s LEFT JOIN {products} e ON e.id = s.entity_id WHERE s.type = ? AND e.id IS NULL'],
            ['category', ['slugs', 'categories'], 'SELECT COUNT(*) FROM {slugs} s LEFT JOIN {categories} e ON e.id = s.entity_id WHERE s.type = ? AND e.id IS NULL'],
            ['brand', ['slugs', 'brands'], 'SELECT COUNT(*) FROM {slugs} s LEFT JOIN {brands} e ON e.id = s.entity_id WHERE s.type = ? AND e.id IS NULL'],
        ];
        $total = null;
        foreach ($queries as $query) {
            $n = self::count($app, $query[1], $query[2], [$query[0]]);
            if ($n !== null) {
                $total = (int) $total + $n;
            }
        }

        return self::report($total);
    }

    /** @return array<string,string> */
    public static function orphanMedia(App $app): array
    {
        $db = $app->db();
        if (!$db->tableExists('media')) {
            return Doctor::result('ok', t('Nothing to check'));
        }
        $root = rtrim(str_replace('\\', '/', $app->site()->mediaDir($app->publicDir())), '/');
        $missing = 0;
        $rows = $db->select('SELECT id, path FROM {media} ORDER BY id DESC LIMIT ?', [self::MEDIA_SAMPLE]);
        foreach ($rows as $row) {
            if (!is_file($root . '/' . ltrim((string) $row['path'], '/'))) {
                ++$missing;
            }
        }
        if ($missing === 0) {
            return Doctor::result('ok', tn('{n} file checked', '{n} files checked', count($rows)));
        }

        return Doctor::result('warn', tn('{n} file is missing', '{n} files are missing', $missing) . ' · ' . t('{n} checked', ['n' => count($rows)]));
    }

    /**
     * Cuenta con una consulta, si existen todas las tablas que usa; null si falta alguna (el módulo no está).
     *
     * @param string[]         $tables
     * @param array<int,mixed> $params
     */
    private static function count(App $app, array $tables, string $sql, array $params = []): ?int
    {
        foreach ($tables as $table) {
            if (!$app->db()->tableExists($table)) {
                return null;
            }
        }

        return (int) $app->db()->value($sql, $params);
    }

    /** @return array<string,string> */
    private static function report(?int $n): array
    {
        if ($n === null) {
            return Doctor::result('ok', t('Nothing to check'));
        }
        if ($n === 0) {
            return Doctor::result('ok', t('None'));
        }

        return Doctor::result('warn', tn('{n} orphan row', '{n} orphan rows', $n));
    }
}

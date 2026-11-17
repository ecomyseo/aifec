<?php
/**
 * AIFEC — direcciones amigables de categorías, marcas y productos: cómo se componen y cómo se recalculan.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

use Aifec\Kernel\App;
use Aifec\Kernel\Slugs;
use Aifec\Util\Str;

/**
 * Una dirección es «prefijo/trozo propio»; la de una categoría lleva delante la de su madre («categoria/muebles/sillas»).
 * El prefijo sale de los ajustes urls.product, urls.category y urls.brand (por sitio) y se lee siempre en el momento:
 * así un cambio de ajuste se nota en la misma petición, sin esperar a que se vuelvan a leer las definiciones del panel.
 *
 * Cambiar una dirección deja la antigua apuntando a la nueva (lo hace Aifec\Kernel\Slugs::set()).
 */
final class TaxonomySlugs
{
    private const DEFAULTS = ['product' => 'producto', 'category' => 'categoria', 'brand' => 'marca'];

    /** Palabra que va delante de las direcciones de un tipo de entidad (product, category, brand). */
    public static function prefix(App $app, string $entity): string
    {
        $default = isset(self::DEFAULTS[$entity]) ? self::DEFAULTS[$entity] : $entity;
        $prefix = trim($app->settings()->str('urls.' . $entity, $default), '/');

        return $prefix !== '' ? $prefix : $default;
    }

    /** El último trozo de una dirección: «categoria/muebles/sillas» → «sillas». */
    public static function segment(string $path): string
    {
        $path = Slugs::clean($path);
        $pos = strrpos($path, '/');

        return $pos === false ? $path : substr($path, $pos + 1);
    }

    /** Trozo de dirección sacado de un texto; si no queda nada utilizable, el de reserva. */
    public static function make(string $text, string $fallback): string
    {
        $slug = Str::slug($text, 90);

        return $slug !== '' ? $slug : $fallback;
    }

    /**
     * Dirección completa de una categoría: la de su madre en ese idioma (o en el principal) y su trozo; sin madre, el prefijo.
     */
    public static function categoryPath(App $app, ?int $parentId, string $lang, string $segment): string
    {
        $base = '';
        if ($parentId !== null && $parentId > 0) {
            $slugs = $app->slugs();
            $found = $slugs->path('category', $parentId, $lang);
            if ($found === null) {
                $found = $slugs->path('category', $parentId, $app->defaultLang());
            }
            $base = $found !== null ? $found : '';
        }
        if ($base === '') {
            $base = self::prefix($app, 'category');
        }

        return $base . '/' . $segment;
    }

    /**
     * Los trozos propios de las direcciones de una entidad, por idioma, para rellenar el campo «dirección» al editar.
     *
     * @return array<string,string> idioma => trozo
     */
    public static function segments(App $app, string $entity, int $id): array
    {
        $out = [];
        foreach ($app->languages() as $lang) {
            $path = $app->slugs()->path($entity, $id, $lang);
            if ($path !== null) {
                $out[$lang] = self::segment($path);
            }
        }

        return $out;
    }

    /**
     * Vuelve a componer las direcciones de todas las entidades de un tipo con el prefijo vigente. Las antiguas quedan redirigiendo.
     * Devuelve cuántas direcciones han cambiado.
     */
    public static function rebuild(App $app, string $entity): int
    {
        if ($entity === 'category') {
            return self::rebuildCategories($app);
        }
        $slugs = $app->slugs();
        $prefix = self::prefix($app, $entity);
        $changed = 0;
        $rows = $app->db()->select('SELECT entity_id, lang, path FROM {slugs} WHERE type = ? AND canonical = 1 ORDER BY entity_id, lang', [$entity]);
        foreach ($rows as $row) {
            $segment = self::segment((string) $row['path']);
            if ($segment === '') {
                continue;
            }
            $new = $prefix . '/' . $segment;
            if ($new !== (string) $row['path']) {
                $slugs->set($entity, (int) $row['entity_id'], (string) $row['lang'], $new);
                ++$changed;
            }
        }

        return $changed;
    }

    /**
     * Las categorías se recorren de la raíz hacia abajo para que cada una encuentre ya puesta la dirección de su madre.
     */
    private static function rebuildCategories(App $app): int
    {
        $rows = $app->db()->select('SELECT id, parent_id FROM {categories} ORDER BY position, id');
        $known = [];
        foreach ($rows as $row) {
            $known[(int) $row['id']] = true;
        }
        $children = [];
        foreach ($rows as $row) {
            $parent = isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
            $children[isset($known[$parent]) ? $parent : 0][] = (int) $row['id'];
        }
        $slugs = $app->slugs();
        $changed = 0;
        $seen = [];
        $queue = [0];
        while ($queue !== []) {
            $parent = (int) array_shift($queue);
            foreach (isset($children[$parent]) ? $children[$parent] : [] as $id) {
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $queue[] = $id;
                foreach ($app->languages() as $lang) {
                    $current = $slugs->path('category', $id, $lang);
                    if ($current === null) {
                        continue;
                    }
                    $segment = self::segment($current);
                    if ($segment === '') {
                        continue;
                    }
                    $new = self::categoryPath($app, $parent > 0 ? $parent : null, $lang, $segment);
                    if ($new !== $current) {
                        $slugs->set('category', $id, $lang, $new);
                        ++$changed;
                    }
                }
            }
        }

        return $changed;
    }
}

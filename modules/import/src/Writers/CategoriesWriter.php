<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Writers;

use Aifec\Modules\Catalog\Admin\CategoryAdmin;
use Aifec\Modules\Catalog\Admin\TaxonomySlugs;
use Aifec\Modules\Catalog\Categories;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\WriteContext;

/**
 * El árbol de categorías del origen. En la primera pasada se escribe cada categoría; el padre se pone en la segunda (link()), cuando
 * ya están todas, y entonces se recalculan las rutas, los niveles, las direcciones amigables y los contadores como hace el panel.
 * Una categoría que ya existe aquí con el mismo nombre y el mismo padre no se duplica.
 */
final class CategoriesWriter extends WriterBase
{
    public function type(): string
    {
        return 'categories';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $parent = $this->parentId($record, $ctx);
        $found = $this->resolve('categories', $record, $ctx, function (int $localId) use ($db): ?array {
            return $db->find('categories', ['id' => $localId]);
        }, function () use ($record, $ctx, $id, $parent): ?array {
            return $this->existing($record, $ctx, $id, $parent);
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($action === self::CONFLICT) {
            return $this->conflict((int) $row['id']);
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }
        $now = $this->now();
        $data = [
            'position' => isset($record['position']) ? (int) $record['position'] : 0, 'active' => isset($record['active']) && $record['active'] === false ? 0 : 1,
            'in_menu' => isset($record['in_menu']) && $record['in_menu'] === false ? 0 : 1, 'updated_at' => $now,
        ];
        if ($row === null) {
            $data['parent_id'] = $parent;
        } elseif ($parent !== null) {
            $data['parent_id'] = $parent;
        }
        if (isset($record['image']) && is_array($record['image'])) {
            $media = $ctx->image($record['image'], 'categories', $id);
            if ($media !== null) {
                $data['media_id'] = $media;
            }
        }
        if ($row === null) {
            $localId = $db->insert('categories', $data + ['path' => '', 'depth' => 0, 'product_count' => 0, 'created_at' => $now]);
        } else {
            $localId = (int) $row['id'];
            $db->update('categories', $data, ['id' => $localId]);
        }
        $names = $this->texts(isset($record['name']) && is_array($record['name']) ? $record['name'] : [], $ctx);
        $this->saveTexts($localId, $record, $names, $ctx);
        $this->refreshPaths($localId);
        $this->address($localId, $record, $names, $ctx);
        $ctx->remember('categories', $id, $localId, Neutral::hash($record));
        CategoryAdmin::recount($this->app);
        $this->app->invalidate(['catalog']);

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    /**
     * Segunda pasada: el padre, cuando ya existen todas las categorías.
     */
    public function link(array $record, WriteContext $ctx): void
    {
        $localId = $ctx->localId('categories', (string) $record['id']);
        if ($localId === null || $ctx->dryRun()) {
            return;
        }
        $db = $this->db();
        $row = $db->find('categories', ['id' => $localId]);
        if ($row === null) {
            return;
        }
        $source = isset($record['parent_id']) && is_string($record['parent_id']) && $record['parent_id'] !== '' ? $record['parent_id'] : null;
        $parent = $source !== null ? $ctx->localId('categories', $source) : null;
        if ($source !== null && ($parent === null || $parent === $localId)) {
            $ctx->note('categories', (string) $record['id'], 'note', 'The parent category was not brought: it stays at the top');
            $parent = null;
        }
        if ($parent !== null && $this->isInside($parent, $localId)) {
            $ctx->note('categories', (string) $record['id'], 'note', 'The parent category is one of its own subcategories: it stays at the top');
            $parent = null;
        }
        $current = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        if ($parent === $current) {
            return;
        }
        $this->quiet = true;

        try {
            $db->transaction(function () use ($db, $localId, $parent, $record, $ctx): void {
                $db->update('categories', ['parent_id' => $parent, 'updated_at' => $this->now()], ['id' => $localId]);
                $this->refreshPaths($localId);
                $names = [];
                foreach ($db->select('SELECT lang, name FROM {category_i18n} WHERE category_id = ?', [$localId]) as $text) {
                    $names[(string) $text['lang']] = (string) $text['name'];
                }
                $this->address($localId, $record, $names, $ctx);
                $this->reslugDescendants($localId);
                CategoryAdmin::recount($this->app);
            });
        } finally {
            $this->quiet = false;
        }
        $this->app->invalidate(['catalog']);
    }

    public function checksums(WriteContext $ctx): array
    {
        return ['categories_count' => $this->mappedCount('categories', 'categories')];
    }

    // ------------------------------------------------------------------ piezas

    /**
     * El identificador de aquí del padre, si ya se escribió.
     *
     * @param array<string,mixed> $record
     */
    private function parentId(array $record, WriteContext $ctx): ?int
    {
        return isset($record['parent_id']) && is_string($record['parent_id']) && $record['parent_id'] !== '' ? $ctx->localId('categories', $record['parent_id']) : null;
    }

    /**
     * La categoría de aquí que ya es esta: mismo nombre (en el idioma principal) y mismo padre.
     *
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>|null
     */
    private function existing(array $record, WriteContext $ctx, string $id, ?int $parent): ?array
    {
        $hasParent = isset($record['parent_id']) && is_string($record['parent_id']) && $record['parent_id'] !== '';
        if ($hasParent && $parent === null) {
            return null; // el padre aún no se ha escrito: no se puede saber si es la misma
        }
        $name = trim($this->mainText(isset($record['name']) && is_array($record['name']) ? $record['name'] : [], $ctx));
        if ($name === '') {
            return null;
        }
        $db = $this->db();
        $params = [$this->app->defaultLang()];
        $sql = 'SELECT c.* FROM {categories} c JOIN {category_i18n} i ON i.category_id = c.id AND i.lang = ? WHERE ' . $this->sameText('i.name', $name, $params) . ' AND ';
        if ($parent === null) {
            $sql .= 'c.parent_id IS NULL ORDER BY c.id';
        } else {
            $sql .= 'c.parent_id = ? ORDER BY c.id';
            $params[] = $parent;
        }
        foreach ($db->select($sql, $params) as $row) {
            if (!$this->claimed('categories', (int) $row['id'], $id)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Los textos de cada idioma del sitio.
     *
     * @param array<string,mixed>  $record
     * @param array<string,string> $names
     */
    private function saveTexts(int $localId, array $record, array $names, WriteContext $ctx): void
    {
        $db = $this->db();
        $descriptions = $this->texts(isset($record['description']) && is_array($record['description']) ? $record['description'] : [], $ctx);
        $titles = $this->texts(isset($record['meta_title']) && is_array($record['meta_title']) ? $record['meta_title'] : [], $ctx);
        $metas = $this->texts(isset($record['meta_description']) && is_array($record['meta_description']) ? $record['meta_description'] : [], $ctx);
        foreach ($names as $lang => $name) {
            $text = [
                'name' => trim($name),
                'description' => isset($descriptions[$lang]) ? $this->html($descriptions[$lang]) : null,
                'meta_title' => isset($titles[$lang]) ? trim($titles[$lang]) : null,
                'meta_description' => isset($metas[$lang]) ? trim($metas[$lang]) : null,
            ];
            if ($db->find('category_i18n', ['category_id' => $localId, 'lang' => $lang]) === null) {
                $db->insert('category_i18n', $text + ['category_id' => $localId, 'lang' => $lang]);
            } else {
                $db->update('category_i18n', $text, ['category_id' => $localId, 'lang' => $lang]);
            }
        }
    }

    /**
     * Las direcciones amigables de cada idioma (con la de la madre delante) y las antiguas.
     *
     * @param array<string,mixed>  $record
     * @param array<string,string> $names
     */
    private function address(int $localId, array $record, array $names, WriteContext $ctx): void
    {
        $row = $this->db()->find('categories', ['id' => $localId]);
        if ($row === null) {
            return;
        }
        $parent = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        $slugs = $this->texts(isset($record['slug']) && is_array($record['slug']) ? $record['slug'] : [], $ctx);
        $main = $this->app->defaultLang();
        foreach ($names as $lang => $name) {
            $typed = isset($slugs[$lang]) ? $slugs[$lang] : '';
            $segment = TaxonomySlugs::make($typed !== '' ? $typed : $name, 'category-' . $localId);
            $this->app->slugs()->set('category', $localId, $lang, TaxonomySlugs::categoryPath($this->app, $parent, $lang, $segment));
        }
        $paths = [];
        foreach (isset($record['old_paths']) && is_array($record['old_paths']) ? $record['old_paths'] : [] as $path) {
            if (is_string($path)) {
                $paths[] = $path;
            }
        }
        $ctx->oldPaths('category', $localId, $paths, isset($names[$main]) ? $main : '');
    }

    /** Calcula path y depth de la categoría y de todas sus descendientes, de arriba abajo (como el panel). */
    private function refreshPaths(int $id): void
    {
        $db = $this->db();
        $row = $db->find('categories', ['id' => $id]);
        if ($row === null) {
            return;
        }
        $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
        $parent = $parentId > 0 ? $db->find('categories', ['id' => $parentId]) : null;
        $path = $parent !== null ? Categories::pathOf($parent) . '/' . $id : (string) $id;
        $depth = $parent !== null ? (int) $parent['depth'] + 1 : 0;
        $db->update('categories', ['path' => $path, 'depth' => $depth], ['id' => $id]);
        $seen = [$id => true];
        $queue = [[$id, $path, $depth]];
        while ($queue !== []) {
            $item = array_shift($queue);
            foreach ($db->column('SELECT id FROM {categories} WHERE parent_id = ? ORDER BY position, id', [$item[0]]) as $child) {
                $child = (int) $child;
                if (isset($seen[$child])) {
                    continue;
                }
                $seen[$child] = true;
                $childPath = $item[1] . '/' . $child;
                $db->update('categories', ['path' => $childPath, 'depth' => $item[2] + 1], ['id' => $child]);
                $queue[] = [$child, $childPath, $item[2] + 1];
            }
        }
    }

    /** Las descendientes conservan su trozo propio y toman la dirección nueva de su madre, de arriba abajo. */
    private function reslugDescendants(int $id): void
    {
        $db = $this->db();
        $slugs = $this->app->slugs();
        $seen = [$id => true];
        $queue = [$id];
        while ($queue !== []) {
            $current = (int) array_shift($queue);
            foreach ($db->column('SELECT id FROM {categories} WHERE parent_id = ? ORDER BY position, id', [$current]) as $child) {
                $child = (int) $child;
                if (isset($seen[$child])) {
                    continue;
                }
                $seen[$child] = true;
                $queue[] = $child;
                foreach ($this->app->languages() as $lang) {
                    $path = $slugs->path('category', $child, $lang);
                    if ($path === null) {
                        continue;
                    }
                    $new = TaxonomySlugs::categoryPath($this->app, $current, $lang, TaxonomySlugs::segment($path));
                    if ($new !== $path) {
                        $slugs->set('category', $child, $lang, $new);
                    }
                }
            }
        }
    }

    /** ¿Es $candidate una descendiente de $id? (Para no hacer un bucle.) */
    private function isInside(int $candidate, int $id): bool
    {
        $row = $this->db()->find('categories', ['id' => $candidate]);
        if ($row === null) {
            return false;
        }
        $ids = array_map('intval', explode('/', Categories::pathOf($row)));

        return in_array($id, $ids, true);
    }
}

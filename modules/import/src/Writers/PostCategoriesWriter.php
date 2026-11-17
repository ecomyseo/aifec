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

use Aifec\Modules\Cms\Cms;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\WriteContext;
use Aifec\Util\Str;

/**
 * Las categorías del blog (términos de tipo «category» del módulo de contenidos). La categoría madre se pone en la segunda pasada,
 * cuando ya están todas. Una categoría que ya existe aquí con el mismo nombre no se duplica.
 *
 * Los términos no llevan fecha de modificación, así que para saber si se editaron aquí se guarda, al escribirlos, una huella de lo que
 * dejó este escritor (nombres, descripciones y madre) en el ajuste «import.fp.post_categories.<id>»: si la huella de ahora no es la
 * guardada, alguien la tocó y choca.
 */
final class PostCategoriesWriter extends WriterBase
{
    public function type(): string
    {
        return 'post_categories';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $names = $this->texts(isset($record['name']) && is_array($record['name']) ? $record['name'] : [], $ctx);
        if ($names === []) {
            return $this->result(self::SKIPPED, null, 'The category has no name');
        }
        $main = $this->app->defaultLang();
        $found = $this->resolve('post_categories', $record, $ctx, static function (int $localId) use ($db): ?array {
            return $db->find('terms', ['id' => $localId, 'taxonomy' => 'category']);
        }, function () use ($db, $names, $main, $id): ?array {
            if (!isset($names[$main])) {
                return null;
            }
            $params = [$main];
            $row = $db->row(
                "SELECT t.* FROM {terms} t JOIN {term_i18n} x ON x.term_id = t.id AND x.lang = ? WHERE t.taxonomy = 'category' AND " . $this->sameText('x.name', $names[$main], $params),
                $params
            );

            return $row !== null && !$this->claimed('post_categories', (int) $row['id'], $id) ? $row : null;
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($action === self::CONFLICT || ($action === self::UPDATED && !$found['natural'] && $row !== null && $this->edited((int) $row['id']) && !(bool) $ctx->option('overwrite_local', false))) {
            return $this->conflict((int) $row['id']);
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }

        $descriptions = $this->texts(isset($record['description']) && is_array($record['description']) ? $record['description'] : [], $ctx);
        if ($row === null) {
            $localId = $db->insert('terms', ['taxonomy' => 'category', 'position' => 0]);
        } else {
            $localId = (int) $row['id'];
        }
        foreach ($names as $lang => $name) {
            $db->upsert('term_i18n', [
                'term_id' => $localId, 'lang' => $lang, 'name' => $this->cut($name, 191),
                'description' => isset($descriptions[$lang]) && trim($descriptions[$lang]) !== '' ? $this->html($descriptions[$lang]) : null,
            ], ['term_id', 'lang']);
        }
        $this->addresses($localId, $record, $names, $ctx);
        $this->app->invalidate(['cms']);
        $this->remember($localId);
        $ctx->remember('post_categories', $id, $localId, Neutral::hash($record));

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    public function link(array $record, WriteContext $ctx): void
    {
        if ($ctx->dryRun()) {
            return;
        }
        $db = $this->db();
        $localId = $ctx->localId('post_categories', (string) $record['id']);
        if ($localId === null || $db->find('terms', ['id' => $localId]) === null) {
            return;
        }
        $parent = isset($record['parent_id']) && is_string($record['parent_id']) && $record['parent_id'] !== '' ? $ctx->localId('post_categories', $record['parent_id']) : null;
        if ($parent !== null && ($parent === $localId || $db->find('terms', ['id' => $parent, 'taxonomy' => 'category']) === null || $this->inside($parent, $localId))) {
            $ctx->note('post_categories', (string) $record['id'], 'note', 'The parent category was not brought or would make a loop: the category was left at the top level');
            $parent = null;
        }
        $current = $db->value('SELECT parent_id FROM {terms} WHERE id = ?', [$localId]);
        if (($current !== null ? (int) $current : null) !== $parent) {
            $db->run('UPDATE {terms} SET parent_id = ? WHERE id = ?', [$parent, $localId]);
            $this->app->invalidate(['cms']);
        }
        $this->remember($localId);
    }

    public function checksums(WriteContext $ctx): array
    {
        return ['post_categories_count' => $this->mappedCount('post_categories', 'terms')];
    }

    /**
     * La dirección de cada idioma y las antiguas del origen.
     *
     * @param array<string,mixed>  $record
     * @param array<string,string> $names
     */
    private function addresses(int $localId, array $record, array $names, WriteContext $ctx): void
    {
        $slugs = $this->texts(isset($record['slug']) && is_array($record['slug']) ? $record['slug'] : [], $ctx);
        $prefix = (new Cms($this->app))->prefix('category');
        foreach ($names as $lang => $name) {
            $slug = Str::slug(isset($slugs[$lang]) ? $slugs[$lang] : $name, 90);
            $this->app->slugs()->set('term', $localId, $lang, $prefix . '/' . ($slug !== '' ? $slug : 'category-' . $localId));
        }
        $paths = [];
        foreach (isset($record['old_paths']) && is_array($record['old_paths']) ? $record['old_paths'] : [] as $path) {
            if (is_string($path)) {
                $paths[] = $path;
            }
        }
        $ctx->oldPaths('term', $localId, $paths);
    }

    // ------------------------------------------------------------------ huella

    /**
     * Lo que hay ahora en la categoría (madre, nombres y descripciones), resumido.
     */
    private function fingerprint(int $localId): string
    {
        $db = $this->db();
        $parent = $db->value('SELECT parent_id FROM {terms} WHERE id = ?', [$localId]);
        $texts = [];
        foreach ($db->select('SELECT lang, name, description FROM {term_i18n} WHERE term_id = ? ORDER BY lang', [$localId]) as $text) {
            $texts[] = [(string) $text['lang'], (string) $text['name'], (string) $text['description']];
        }

        return sha1((string) json_encode([$parent !== null ? (int) $parent : null, $texts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function remember(int $localId): void
    {
        $this->app->settings()->set('import.fp.post_categories.' . $localId, $this->fingerprint($localId));
    }

    /** ¿Se tocó aquí después de traerla? (su huella ya no es la que se guardó) */
    private function edited(int $localId): bool
    {
        $stored = $this->app->settings()->str('import.fp.post_categories.' . $localId, '');

        return $stored !== '' && $stored !== $this->fingerprint($localId);
    }

    /**
     * ¿Está la categoría $id dentro de la rama de $start (sube por las madres)?
     */
    private function inside(int $start, int $id): bool
    {
        $db = $this->db();
        $seen = [];
        $current = $start;
        while ($current > 0 && !isset($seen[$current])) {
            if ($current === $id) {
                return true;
            }
            $seen[$current] = true;
            $parent = $db->value('SELECT parent_id FROM {terms} WHERE id = ?', [$current]);
            $current = $parent !== null ? (int) $parent : 0;
        }

        return false;
    }
}

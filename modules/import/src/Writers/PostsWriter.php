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

use Aifec\Modules\Import\WriteContext;
use Aifec\Text\HtmlSanitizer;
use Aifec\Util\Str;

/**
 * Las entradas del blog. Lo común con las páginas (bloques, imágenes del contenido, estado, fecha, dirección, versión inicial) está en
 * PagesWriter; esta clase añade la imagen destacada, el extracto, las categorías (ya traídas, por su pareja) y las etiquetas (que se
 * crean aquí si no existen, en el idioma principal, como lo hace el panel). Las entradas no casan con las de aquí por lo natural: son
 * historia del blog, no se mezclan.
 *
 * El autor del origen es un texto y aquí el autor es un usuario: no se trae y se cuenta como «sin equivalente».
 */
final class PostsWriter extends PagesWriter
{
    public function type(): string
    {
        return 'posts';
    }

    protected function entity(): string
    {
        return 'post';
    }

    protected function table(): string
    {
        return 'posts';
    }

    protected function textTable(): string
    {
        return 'post_i18n';
    }

    protected function textKey(): string
    {
        return 'post_id';
    }

    protected function prefix(): string
    {
        return $this->cmsPrefix('post');
    }

    protected function natural(array $record, WriteContext $ctx, string $id): ?array
    {
        return null;
    }

    /** Las entradas no llevan plantilla, posición ni portada: solo «noindex», que ya sale a 0. */
    protected function defaults(int $localId): void
    {
    }

    protected function extraColumns(array $record, WriteContext $ctx, string $id): array
    {
        if (isset($record['image']) && is_array($record['image'])) {
            $media = $ctx->image($record['image'], 'posts', $id);
            if ($media !== null) {
                return ['media_id' => $media];
            }
        }

        return [];
    }

    protected function excerpt(array $record, string $lang, WriteContext $ctx): string
    {
        $texts = $this->texts(isset($record['excerpt']) && is_array($record['excerpt']) ? $record['excerpt'] : [], $ctx);

        return isset($texts[$lang]) ? mb_substr(trim(HtmlSanitizer::text($texts[$lang])), 0, 2000, 'UTF-8') : '';
    }

    protected function afterWrite(int $localId, array $record, WriteContext $ctx): void
    {
        $db = $this->db();
        $ids = [];
        foreach (isset($record['category_ids']) && is_array($record['category_ids']) ? $record['category_ids'] : [] as $source) {
            $term = is_string($source) ? $ctx->localId('post_categories', $source) : null;
            if ($term !== null && $db->value("SELECT id FROM {terms} WHERE id = ? AND taxonomy = 'category'", [$term]) !== null) {
                $ids[$term] = $term;
            } else {
                $this->noteOnce($ctx, 'category', 'A category of the post was not brought: the post was written without it');
            }
        }
        foreach ($this->tags($record, $ctx) as $tag) {
            $ids[$tag] = $tag;
        }
        $db->delete('post_terms', ['post_id' => $localId]);
        foreach (array_keys($ids) as $term) {
            $db->insert('post_terms', ['post_id' => $localId, 'term_id' => (int) $term]);
        }
        if (isset($record['author']) && is_string($record['author']) && trim($record['author']) !== '') {
            $ctx->unmapped('field', 'Post author', 1, 'The author of a post is a user here: the name of the source was not kept');
        }
        $this->app->invalidate(['cms']);
    }

    /**
     * Los identificadores de las etiquetas del origen (las que no existen se crean, en el idioma principal).
     *
     * @param array<string,mixed> $record
     *
     * @return int[]
     */
    private function tags(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $list = isset($record['tags']) && is_array($record['tags']) ? $record['tags'] : [];
        $main = $this->app->defaultLang();
        $names = isset($list[$main]) && is_array($list[$main]) ? $list[$main] : [];
        if ($names === []) {
            foreach ($list as $one) {
                if (is_array($one) && $one !== []) {
                    $names = $one;
                    break;
                }
            }
        }
        $known = [];
        foreach ($db->select("SELECT t.id AS id, x.name AS name FROM {terms} t JOIN {term_i18n} x ON x.term_id = t.id AND x.lang = ? WHERE t.taxonomy = 'tag'", [$main]) as $row) {
            $known[mb_strtolower((string) $row['name'], 'UTF-8')] = (int) $row['id'];
        }
        $out = [];
        foreach ($names as $name) {
            $name = is_string($name) ? $this->cut(trim(strip_tags($name)), 80) : '';
            if ($name === '' || count($out) >= 20) {
                continue;
            }
            $key = mb_strtolower($name, 'UTF-8');
            if (!isset($known[$key])) {
                $termId = $db->insert('terms', ['taxonomy' => 'tag', 'position' => 0]);
                $db->insert('term_i18n', ['term_id' => $termId, 'lang' => $main, 'name' => $name]);
                $slug = Str::slug($name, 90);
                $this->app->slugs()->set('term', $termId, $main, $this->cmsPrefix('tag') . '/' . ($slug !== '' ? $slug : 'tag-' . $termId));
                $known[$key] = $termId;
            }
            $out[$known[$key]] = $known[$key];
        }

        return array_values($out);
    }
}

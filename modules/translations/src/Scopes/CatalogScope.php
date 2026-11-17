<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Translations\Scopes;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Modules\Catalog\Admin\TaxonomySlugs;
use Aifec\Modules\Translations\Proposals;
use Aifec\Modules\Translations\Scope;

/**
 * Las categorías («categories») o los atributos y sus valores («attributes») del catálogo.
 *
 * La IA los traduce como el resto (14/11/2026: antes solo se contaban y la tienda en inglés salía con el menú y los filtros en
 * castellano): nombre, título y descripción para buscadores y descripción de cada categoría, con su dirección en ese idioma; nombre
 * de cada atributo y etiqueta de cada valor. Se escribe en sus tablas (`category_i18n`, `attribute_i18n`, `attribute_value_i18n`)
 * solo si el original sigue diciendo lo mismo que se tradujo; sin publicar, espera en translation_proposals.
 */
final class CatalogScope implements Scope
{
    /** Orden de los campos de una categoría: el nombre primero (una fila nueva en el idioma nace con su nombre). */
    private const FIELDS = ['1' => 'name', '2' => 'meta_title', '3' => 'meta_description', '4' => 'description'];

    private App $app;
    private string $kind;

    public function __construct(App $app, string $kind)
    {
        $this->app = $app;
        $this->kind = $kind === 'attributes' ? 'attributes' : 'categories';
    }

    public function code(): string
    {
        return $this->kind;
    }

    public function label(): string
    {
        return $this->kind === 'attributes' ? t('Attributes and values') : t('Categories');
    }

    public function describe(): string
    {
        return $this->kind === 'attributes'
            ? t('Names of the attributes (size, colour) and of their values')
            : t('Name, description and texts for search engines');
    }

    public function entities(): array
    {
        return $this->kind === 'attributes' ? ['attribute', 'attribute_value'] : ['category'];
    }

    public function sourceLang(): string
    {
        return $this->app->defaultLang();
    }

    public function brief(): string
    {
        return $this->kind === 'attributes' ? 'Names of product attributes and of their values.' : 'Names and descriptions of product categories.';
    }

    public function blocked(string $lang): string
    {
        return $lang === $this->app->defaultLang() ? t('This is the main language: the other languages are translated from it') : '';
    }

    public function coverage(string $lang): array
    {
        $out = ['total' => 0, 'translated' => 0, 'proposed' => 0, 'missing' => 0];
        if ($lang === $this->app->defaultLang()) {
            return $out;
        }
        $pending = (new Proposals($this->app))->pending($lang, $this->kind);
        foreach ($this->units($lang) as $key => $unit) {
            ++$out['total'];
            if ($unit['target'] !== '') {
                ++$out['translated'];
            } elseif (isset($pending[$key])) {
                ++$out['proposed'];
            } else {
                ++$out['missing'];
            }
        }

        return $out;
    }

    public function next(string $lang, string $after, int $maxUnits, int $maxChars): array
    {
        $pending = (new Proposals($this->app))->pending($lang, $this->kind);
        $units = [];
        $chars = 0;
        $cursor = $after;
        foreach ($this->units($lang) as $key => $unit) {
            if ($after !== '' && strcmp($key, $after) <= 0) {
                continue;
            }
            if ($unit['target'] !== '' || isset($pending[$key])) {
                $cursor = $key;
                continue;
            }
            if ($units !== [] && (count($units) >= $maxUnits || $chars + strlen($unit['source']) > $maxChars)) {
                return ['units' => $units, 'cursor' => $cursor, 'done' => false];
            }
            $units[] = ['key' => $key, 'text' => $unit['source'], 'format' => $unit['format']];
            $chars += strlen($unit['source']);
            $cursor = $key;
        }

        return ['units' => $units, 'cursor' => $cursor, 'done' => true];
    }

    public function save(string $lang, array $texts, array $units, bool $publish, int $jobId): array
    {
        if ($publish) {
            $sources = [];
            foreach ($texts as $key => $text) {
                $sources[$key] = isset($units[$key]['text']) ? (string) $units[$key]['text'] : '';
            }
            $applied = $this->apply($lang, $texts, $sources);

            return array_values(array_diff(array_map('strval', array_keys($texts)), $applied));
        }
        $proposals = new Proposals($this->app);
        foreach ($texts as $key => $text) {
            $proposals->put($lang, $this->kind, (string) $key, isset($units[$key]['text']) ? (string) $units[$key]['text'] : '', $text, $jobId);
        }

        return [];
    }

    public function approveAll(string $lang, int $max = 1000): int
    {
        $proposals = new Proposals($this->app);
        $texts = [];
        $sources = [];
        foreach ($proposals->pending($lang, $this->kind) as $key => $row) {
            if (count($texts) >= $max) {
                break;
            }
            $texts[(string) $key] = $row['text'];
            $sources[(string) $key] = $row['source'];
        }
        $applied = $texts === [] ? [] : $this->apply($lang, $texts, $sources);
        $proposals->remove($lang, $this->kind, array_keys($texts));

        return count($applied);
    }

    // ------------------------------------------------------------------ unidades y guardado

    /**
     * Lo que hay que traducir, por clave ordenada: «c:<categoría>:<n.º de campo>», «a:<atributo>» y «v:<atributo>:<valor>».
     *
     * @return array<string,array{source:string,target:string,format:string}>
     */
    private function units(string $lang): array
    {
        $db = $this->app->db();
        $default = $this->app->defaultLang();
        $out = [];
        if ($this->kind === 'categories') {
            $rows = $db->select('SELECT category_id AS id, lang, name, description, meta_title, meta_description FROM {category_i18n} WHERE lang = ? OR lang = ? ORDER BY category_id', [$default, $lang]);
            $byLang = self::byLang($rows, $default);
            foreach ($byLang['source'] as $id => $source) {
                $target = isset($byLang['target'][$id]) ? $byLang['target'][$id] : [];
                foreach (self::FIELDS as $n => $field) {
                    $text = isset($source[$field]) ? trim((string) $source[$field]) : '';
                    if ($text === '') {
                        continue;
                    }
                    $out[sprintf('c:%08d:%s', $id, $n)] = [
                        'source' => $text,
                        'target' => isset($target[$field]) ? trim((string) $target[$field]) : '',
                        'format' => $field === 'description' ? 'html' : 'text',
                    ];
                }
            }
            ksort($out, SORT_STRING);

            return $out;
        }
        $byLang = self::byLang($db->select('SELECT attribute_id AS id, lang, name FROM {attribute_i18n} WHERE lang = ? OR lang = ? ORDER BY attribute_id', [$default, $lang]), $default);
        foreach ($byLang['source'] as $id => $source) {
            $text = trim((string) $source['name']);
            if ($text !== '') {
                $out[sprintf('a:%08d', $id)] = ['source' => $text, 'target' => isset($byLang['target'][$id]) ? trim((string) $byLang['target'][$id]['name']) : '', 'format' => 'text'];
            }
        }
        $values = $db->select(
            'SELECT v.attribute_id AS attribute_id, t.value_id AS id, t.lang AS lang, t.label AS label FROM {attribute_value_i18n} t JOIN {attribute_values} v ON v.id = t.value_id WHERE t.lang = ? OR t.lang = ? ORDER BY t.value_id',
            [$default, $lang]
        );
        $pairs = [];
        foreach ($values as $row) {
            $pairs[sprintf('v:%08d:%08d', (int) $row['attribute_id'], (int) $row['id'])][(string) $row['lang'] === $default ? 'source' : 'target'] = trim((string) $row['label']);
        }
        foreach ($pairs as $key => $pair) {
            if (isset($pair['source']) && $pair['source'] !== '') {
                $out[$key] = ['source' => $pair['source'], 'target' => isset($pair['target']) ? $pair['target'] : '', 'format' => 'text'];
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * Escribe las traducciones cuyo original sigue diciendo lo mismo. Devuelve las claves escritas.
     *
     * @param array<string,string> $texts   clave => traducción
     * @param array<string,string> $sources clave => original que se tradujo
     *
     * @return string[]
     */
    private function apply(string $lang, array $texts, array $sources): array
    {
        $units = $this->units($lang);
        $db = $this->app->db();
        $applied = [];
        $named = [];
        foreach ($texts as $key => $text) {
            $key = (string) $key;
            $text = trim((string) $text);
            if ($text === '' || !isset($units[$key]) || $units[$key]['source'] !== trim(isset($sources[$key]) ? $sources[$key] : '')) {
                continue;
            }
            $parts = explode(':', $key);
            if ($parts[0] === 'c' && count($parts) === 3 && isset(self::FIELDS[$parts[2]])) {
                $id = (int) $parts[1];
                $field = self::FIELDS[$parts[2]];
                $text = $field === 'name' ? mb_substr($text, 0, 191) : ($field === 'meta_title' ? mb_substr($text, 0, 191) : ($field === 'meta_description' ? mb_substr($text, 0, 320) : $text));
                if ($db->value('SELECT id FROM {category_i18n} WHERE category_id = ? AND lang = ?', [$id, $lang]) !== null) {
                    $db->update('category_i18n', [$field => $text], ['category_id' => $id, 'lang' => $lang]);
                } else {
                    // La fila nace con el nombre: si llega otro campo antes, el nombre es el del idioma principal hasta que llegue el suyo.
                    $name = $field === 'name' ? $text : (string) $db->value('SELECT name FROM {category_i18n} WHERE category_id = ? AND lang = ?', [$id, $this->app->defaultLang()]);
                    $db->insert('category_i18n', ['category_id' => $id, 'lang' => $lang, 'name' => $name] + ($field !== 'name' ? [$field => $text] : []));
                }
                if ($field === 'name') {
                    $named[$id] = $text;
                }
                $applied[] = $key;
            } elseif ($parts[0] === 'a' && count($parts) === 2) {
                $id = (int) $parts[1];
                if ($db->value('SELECT id FROM {attribute_i18n} WHERE attribute_id = ? AND lang = ?', [$id, $lang]) !== null) {
                    $db->update('attribute_i18n', ['name' => mb_substr($text, 0, 120)], ['attribute_id' => $id, 'lang' => $lang]);
                } else {
                    $db->insert('attribute_i18n', ['attribute_id' => $id, 'lang' => $lang, 'name' => mb_substr($text, 0, 120)]);
                }
                $applied[] = $key;
            } elseif ($parts[0] === 'v' && count($parts) === 3) {
                $id = (int) $parts[2];
                if ($db->value('SELECT id FROM {attribute_value_i18n} WHERE value_id = ? AND lang = ?', [$id, $lang]) !== null) {
                    $db->update('attribute_value_i18n', ['label' => mb_substr($text, 0, 120)], ['value_id' => $id, 'lang' => $lang]);
                } else {
                    $db->insert('attribute_value_i18n', ['value_id' => $id, 'lang' => $lang, 'label' => mb_substr($text, 0, 120)]);
                }
                $applied[] = $key;
            }
        }
        if ($named !== []) {
            $this->categorySlugs($lang, $named);
        }
        if ($applied !== []) {
            $this->app->invalidate(['catalog', 'settings']);
        }

        return $applied;
    }

    /**
     * La dirección de cada categoría recién nombrada en ese idioma (si aún no tiene una), de la madre a las hijas.
     *
     * @param array<int,string> $named categoría => nombre
     */
    private function categorySlugs(string $lang, array $named): void
    {
        $slugs = $this->app->slugs();
        $rows = $this->app->db()->select('SELECT id, parent_id FROM {categories} ORDER BY id');
        $parents = [];
        foreach ($rows as $row) {
            $parents[(int) $row['id']] = isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
        }
        $depth = static function (int $id) use ($parents): int {
            $n = 0;
            while ($id > 0 && isset($parents[$id]) && $n < 30) {
                $id = $parents[$id];
                ++$n;
            }

            return $n;
        };
        $ids = array_keys($named);
        usort($ids, static function (int $a, int $b) use ($depth): int {
            return $depth($a) <=> $depth($b) ?: $a <=> $b;
        });
        foreach ($ids as $id) {
            if ($slugs->path('category', $id, $lang) !== null) {
                continue;
            }
            $segment = TaxonomySlugs::make($named[$id], 'category-' . $id);
            $path = TaxonomySlugs::categoryPath($this->app, isset($parents[$id]) && $parents[$id] > 0 ? $parents[$id] : null, $lang, $segment);
            try {
                $slugs->set('category', $id, $lang, $path);
            } catch (\Throwable $e) {
                // Otra cosa ya usa esa dirección en ese idioma: la categoría se queda con la del idioma principal (dentro de /<idioma>/).
                $this->app->log()->exception($e, 'translations.category_slug');
            }
        }
    }

    public function approvalNeedsAi(): bool
    {
        return false;
    }

    public function viewUrl(string $lang): string
    {
        return $this->app->url()->plain('/admin/translations/' . $lang . '/' . $this->kind);
    }

    public function rows(string $lang, int $page, int $perPage): ?array
    {
        $list = $this->texts($lang);
        $fields = $this->kind === 'attributes'
            ? ['name' => t('Name'), 'values' => t('Values')]
            : ['name' => t('Name'), 'description' => t('Description'), 'meta_title' => t('Search title'), 'meta_description' => t('Search description')];

        return ['fields' => $fields, 'rows' => array_slice($list, max(0, ($page - 1) * $perPage), $perPage), 'total' => count($list)];
    }

    public function tokensPerUnit(): array
    {
        return [30, 35];
    }

    /**
     * Cada categoría (o atributo) con el estado de cada campo en ese idioma. Las consultas van escritas enteras, una por caso. En
     * $counted deja un booleano por texto con original (true = traducido): los valores de un atributo cuentan uno a uno.
     *
     * @param bool[] $counted
     *
     * @return array<int,array{label:string,url:string,fields:array<string,string>}>
     */
    private function texts(string $lang, array &$counted = []): array
    {
        $db = $this->app->db();
        $default = $this->app->defaultLang();
        $out = [];
        if ($this->kind === 'categories') {
            $rows = $db->select('SELECT category_id AS id, lang, name, description, meta_title, meta_description FROM {category_i18n} WHERE lang = ? OR lang = ? ORDER BY category_id', [$default, $lang]);
            $byLang = self::byLang($rows, $default);
            foreach ($byLang['source'] as $id => $source) {
                $target = isset($byLang['target'][$id]) ? $byLang['target'][$id] : [];
                $fields = [];
                foreach (['name', 'description', 'meta_title', 'meta_description'] as $field) {
                    $fields[$field] = self::status($source, $target, $field);
                    if ($fields[$field] !== 'none') {
                        $counted[] = $fields[$field] === 'ok';
                    }
                }
                $out[] = ['label' => (string) $source['name'], 'url' => Admin::resourceUrl($this->app, 'categories', $id), 'fields' => $fields];
            }

            return $out;
        }
        $rows = $db->select('SELECT attribute_id AS id, lang, name FROM {attribute_i18n} WHERE lang = ? OR lang = ? ORDER BY attribute_id', [$default, $lang]);
        $byLang = self::byLang($rows, $default);
        $values = $db->select(
            'SELECT v.attribute_id AS attribute_id, t.value_id AS id, t.lang AS lang, t.label AS label FROM {attribute_value_i18n} t JOIN {attribute_values} v ON v.id = t.value_id WHERE t.lang = ? OR t.lang = ? ORDER BY t.value_id',
            [$default, $lang]
        );
        $valueStatus = [];
        $valueLabels = [];
        foreach ($values as $row) {
            $valueLabels[(int) $row['attribute_id']][(int) $row['id']][(string) $row['lang'] === $default ? 'source' : 'target'] = trim((string) $row['label']);
        }
        foreach ($valueLabels as $attributeId => $list) {
            $all = 'none';
            foreach ($list as $pair) {
                if (!isset($pair['source']) || $pair['source'] === '') {
                    continue;
                }
                $ok = isset($pair['target']) && $pair['target'] !== '';
                $counted[] = $ok;
                $all = $all === 'missing' || !$ok ? 'missing' : 'ok';
            }
            $valueStatus[$attributeId] = $all;
        }
        foreach ($byLang['source'] as $id => $source) {
            $target = isset($byLang['target'][$id]) ? $byLang['target'][$id] : [];
            if (self::status($source, $target, 'name') !== 'none') {
                $counted[] = self::status($source, $target, 'name') === 'ok';
            }
            $out[] = [
                'label' => (string) $source['name'],
                'url' => Admin::resourceUrl($this->app, 'attributes', $id),
                'fields' => ['name' => self::status($source, $target, 'name'), 'values' => isset($valueStatus[$id]) ? $valueStatus[$id] : 'none'],
            ];
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array{source:array<int,array<string,mixed>>,target:array<int,array<string,mixed>>}
     */
    private static function byLang(array $rows, string $default): array
    {
        $out = ['source' => [], 'target' => []];
        foreach ($rows as $row) {
            $out[(string) $row['lang'] === $default ? 'source' : 'target'][(int) $row['id']] = $row;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $target
     */
    private static function status(array $source, array $target, string $field): string
    {
        if (!isset($source[$field]) || trim((string) $source[$field]) === '') {
            return 'none';
        }

        return isset($target[$field]) && trim((string) $target[$field]) !== '' ? 'ok' : 'missing';
    }
}

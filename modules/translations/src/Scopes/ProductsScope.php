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
use Aifec\Modules\Translations\AiGate;
use Aifec\Modules\Translations\Scope;

/**
 * Los textos de los productos por idioma: nombre, resumen, descripción, datos clave, preguntas frecuentes y textos para buscadores.
 *
 * Se LEEN de las tablas del catálogo (solo lectura, para contar y para sacar el original) y se ESCRIBEN por el servicio público del
 * módulo de IA: cada traducción es una propuesta de su bandeja (`ai->propose('product', …)`) y aprobarla la aplica el filtro
 * «ai.apply» del dueño del campo. Los datos clave y las preguntas viajan elemento a elemento y se vuelven a montar en una lista.
 */
final class ProductsScope implements Scope
{
    /** Campos, en el orden en que se proponen y se aprueban (el nombre primero: crea la fila del idioma). */
    public const FIELDS = ['name', 'summary', 'description', 'facts', 'faqs', 'meta_title', 'meta_description'];

    /** Productos que se miran de una vez al buscar lo que falta. */
    private const PAGE = 50;

    /** Productos que se miran como mucho en una llamada a next() (el resto, en la siguiente). */
    private const SCAN = 1000;

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function code(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return t('Products');
    }

    public function describe(): string
    {
        return t('Name, descriptions, key facts, questions and texts for search engines');
    }

    public function entities(): array
    {
        return ['product'];
    }

    public function sourceLang(): string
    {
        return $this->app->defaultLang();
    }

    public function brief(): string
    {
        return 'Product texts of an online shop: names, summaries, descriptions, key facts, frequent questions and texts for search engines.';
    }

    public function blocked(string $lang): string
    {
        if ($lang === $this->app->defaultLang()) {
            return t('This is the main language: the other languages are translated from it');
        }

        return AiGate::installed($this->app) ? '' : t('It needs the AI module');
    }

    public function coverage(string $lang): array
    {
        $default = $this->app->defaultLang();
        $out = ['total' => 0, 'translated' => 0, 'proposed' => 0, 'missing' => 0];
        if ($lang === $default) {
            return $out;
        }
        $flags = $this->flags([$default, $lang], null);
        $pending = $this->pending($lang, null);
        foreach (isset($flags[$default]) ? $flags[$default] : [] as $id => $source) {
            foreach (self::FIELDS as $field) {
                if (empty($source[$field])) {
                    continue;
                }
                // Los datos clave y las preguntas cuentan por textos (etiqueta y valor, pregunta y respuesta), como viajan a la IA.
                $weight = isset($source['_w'][$field]) ? max(1, (int) $source['_w'][$field]) : 1;
                $out['total'] += $weight;
                if (!empty($flags[$lang][$id][$field])) {
                    $out['translated'] += $weight;
                } elseif (isset($pending[$id][$field])) {
                    $out['proposed'] += $weight;
                } else {
                    $out['missing'] += $weight;
                }
            }
        }

        return $out;
    }

    public function next(string $lang, string $after, int $maxUnits, int $maxChars): array
    {
        $db = $this->app->db();
        $default = $this->app->defaultLang();
        $cursor = (int) $after;
        $units = [];
        $chars = 0;
        $scanned = 0;
        while ($scanned < self::SCAN) {
            $ids = array_map('intval', $db->column('SELECT product_id FROM {product_i18n} WHERE lang = ? AND product_id > ? ORDER BY product_id LIMIT ?', [$default, $cursor, self::PAGE]));
            if ($ids === []) {
                return ['units' => $units, 'cursor' => (string) $cursor, 'done' => true];
            }
            $texts = $this->texts($ids, $default, $lang);
            $pending = $this->pending($lang, $ids);
            foreach ($ids as $id) {
                ++$scanned;
                $mine = $this->unitsOf($id, $texts, $default, $lang, isset($pending[$id]) ? $pending[$id] : []);
                $size = 0;
                foreach ($mine as $unit) {
                    $size += strlen($unit['text']);
                }
                if ($mine !== [] && $units !== [] && (count($units) + count($mine) > $maxUnits || $chars + $size > $maxChars)) {
                    // Este producto no cabe: se queda para la tanda siguiente (no se consume).
                    return ['units' => $units, 'cursor' => (string) $cursor, 'done' => false];
                }
                foreach ($mine as $unit) {
                    $units[] = $unit;
                }
                $chars += $size;
                $cursor = $id;
                if (count($units) >= $maxUnits || $chars >= $maxChars) {
                    return ['units' => $units, 'cursor' => (string) $cursor, 'done' => $this->lastId($default) <= $cursor];
                }
            }
        }

        return ['units' => $units, 'cursor' => (string) $cursor, 'done' => $this->lastId($default) <= $cursor];
    }

    public function save(string $lang, array $texts, array $units, bool $publish, int $jobId): array
    {
        $failed = [];
        if (!AiGate::installed($this->app)) {
            return array_keys($texts);
        }
        // clave «12:name» o «12:facts:0:label» → producto, campo y, en las listas, elemento y parte.
        $byProduct = [];
        foreach ($units as $key => $unit) {
            $parts = explode(':', (string) $key);
            $byProduct[(int) $parts[0]][$parts[1]][] = (string) $key;
        }
        $ai = $this->app->service('ai');
        foreach ($byProduct as $id => $fields) {
            foreach (self::FIELDS as $field) {
                if (!isset($fields[$field])) {
                    continue;
                }
                $keys = $fields[$field];
                $text = $this->assemble($field, $keys, $texts);
                if ($text === null) {
                    foreach ($keys as $key) {
                        $failed[] = $key;
                    }
                    continue;
                }
                try {
                    $proposalId = (int) $ai->propose('product', (int) $id, $field, $lang, $text, ['current' => '', 'task' => 'translations.products']);
                    if ($publish) {
                        $ai->proposals()->approve($proposalId);
                    }
                } catch (\Throwable $e) {
                    $this->app->log()->exception($e, 'translations:products');
                    foreach ($keys as $key) {
                        $failed[] = $key;
                    }
                }
            }
        }

        return $failed;
    }

    public function approveAll(string $lang, int $max = 1000): int
    {
        if (!AiGate::installed($this->app) || !$this->app->db()->tableExists('ai_proposals')) {
            return 0;
        }
        $rows = $this->app->db()->select(
            "SELECT id, field FROM {ai_proposals} WHERE status = 'pending' AND entity = 'product' AND lang = ? ORDER BY entity_id, id",
            [$lang]
        );
        // El nombre primero: crea la fila del idioma con el nombre traducido y no con el del idioma principal.
        usort($rows, static function (array $a, array $b): int {
            return ((string) $a['field'] === 'name' ? 0 : 1) <=> ((string) $b['field'] === 'name' ? 0 : 1) ?: (int) $a['id'] <=> (int) $b['id'];
        });
        $proposals = $this->app->service('ai')->proposals();
        $approved = 0;
        foreach ($rows as $row) {
            if ($approved >= $max) {
                break;
            }
            if (!in_array((string) $row['field'], self::FIELDS, true)) {
                continue;
            }
            if ($proposals->approve((int) $row['id']) === 'approved') {
                ++$approved;
            }
        }

        return $approved;
    }

    public function approvalNeedsAi(): bool
    {
        return true;
    }

    public function viewUrl(string $lang): string
    {
        return $this->app->url()->plain('/admin/translations/' . $lang . '/products');
    }

    public function rows(string $lang, int $page, int $perPage): ?array
    {
        $db = $this->app->db();
        $default = $this->app->defaultLang();
        $total = (int) $db->value('SELECT COUNT(*) FROM {product_i18n} WHERE lang = ?', [$default]);
        $list = $db->select('SELECT product_id, name FROM {product_i18n} WHERE lang = ? ORDER BY product_id LIMIT ? OFFSET ?', [$default, $perPage, max(0, ($page - 1) * $perPage)]);
        $ids = [];
        foreach ($list as $row) {
            $ids[] = (int) $row['product_id'];
        }
        $flags = $ids === [] ? [] : $this->flags([$default, $lang], $ids);
        $pending = $ids === [] ? [] : $this->pending($lang, $ids);
        $rows = [];
        foreach ($list as $row) {
            $id = (int) $row['product_id'];
            $fields = [];
            foreach (self::FIELDS as $field) {
                $fields[$field] = $this->status($flags, $pending, $id, $field, $default, $lang);
            }
            $rows[] = ['label' => (string) $row['name'], 'url' => Admin::resourceUrl($this->app, 'products', $id), 'fields' => $fields];
        }

        return ['fields' => self::fieldNames(), 'rows' => $rows, 'total' => $total];
    }

    public function tokensPerUnit(): array
    {
        // Un producto corriente son ~21 textos y ~670 fichas de original (la descripción se lleva la mitad): ~32 por texto, más
        // ~15 de la forma JSON de cada elemento; la salida, algo más larga que el original.
        return [50, 45];
    }

    /** @return array<string,string> */
    public static function fieldNames(): array
    {
        return [
            'name' => t('Name'),
            'summary' => t('Summary'),
            'description' => t('Description'),
            'facts' => t('Key facts'),
            'faqs' => t('Questions'),
            'meta_title' => t('Search title'),
            'meta_description' => t('Search description'),
        ];
    }

    // ------------------------------------------------------------------ interior

    /**
     * Qué campos tienen texto, por idioma y producto (sin traer los textos).
     *
     * @param string[]   $langs
     * @param int[]|null $ids   null = todos
     *
     * @return array<string,array<int,array<string,bool>>>
     */
    private function flags(array $langs, ?array $ids): array
    {
        $db = $this->app->db();
        $params = [$langs[0], $langs[1]];
        $in = '';
        if ($ids !== null) {
            $in = ' AND product_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
        $out = [];
        $rows = $db->select(
            "SELECT product_id, lang,
                CASE WHEN name IS NULL OR name = '' THEN 0 ELSE 1 END AS f_name,
                CASE WHEN summary IS NULL OR summary = '' THEN 0 ELSE 1 END AS f_summary,
                CASE WHEN description IS NULL OR description = '' THEN 0 ELSE 1 END AS f_description,
                CASE WHEN facts IS NULL OR facts = '' OR facts = '[]' THEN 0 ELSE 1 END AS f_facts,
                CASE WHEN meta_title IS NULL OR meta_title = '' THEN 0 ELSE 1 END AS f_meta_title,
                CASE WHEN meta_description IS NULL OR meta_description = '' THEN 0 ELSE 1 END AS f_meta_description,
                facts
             FROM {product_i18n} WHERE (lang = ? OR lang = ?)" . $in,
            $params
        );
        foreach ($rows as $row) {
            $flags = [];
            foreach (self::FIELDS as $field) {
                $flags[$field] = $field !== 'faqs' && (int) $row['f_' . $field] === 1;
            }
            $facts = count(self::factsOf($row['facts']));
            $flags['facts'] = $facts > 0;
            $flags['_w'] = ['facts' => 2 * $facts, 'faqs' => 0];
            $out[(string) $row['lang']][(int) $row['product_id']] = $flags;
        }
        $faqs = $db->select('SELECT product_id, lang, COUNT(*) AS n FROM {product_faqs} WHERE (lang = ? OR lang = ?)' . $in . ' GROUP BY product_id, lang', $params);
        foreach ($faqs as $row) {
            $lang = (string) $row['lang'];
            $id = (int) $row['product_id'];
            if (!isset($out[$lang][$id])) {
                $out[$lang][$id] = array_fill_keys(self::FIELDS, false) + ['_w' => ['facts' => 0, 'faqs' => 0]];
            }
            $out[$lang][$id]['faqs'] = (int) $row['n'] > 0;
            $out[$lang][$id]['_w']['faqs'] = 2 * (int) $row['n'];
        }

        return $out;
    }

    /**
     * Las propuestas pendientes de la bandeja de la IA en ese idioma: producto => campo => true.
     *
     * @param int[]|null $ids
     *
     * @return array<int,array<string,bool>>
     */
    private function pending(string $lang, ?array $ids): array
    {
        $db = $this->app->db();
        if (!$db->tableExists('ai_proposals')) {
            return [];
        }
        $params = [$lang];
        $in = '';
        if ($ids !== null) {
            $in = ' AND entity_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
        $out = [];
        foreach ($db->select("SELECT entity_id, field FROM {ai_proposals} WHERE status = 'pending' AND entity = 'product' AND lang = ?" . $in, $params) as $row) {
            $out[(int) $row['entity_id']][(string) $row['field']] = true;
        }

        return $out;
    }

    /**
     * Los textos de unos productos en el idioma principal y en el de destino.
     *
     * @param int[] $ids
     *
     * @return array<string,array<int,array<string,mixed>>> idioma => producto => fila (con 'faqs' => lista)
     */
    private function texts(array $ids, string $default, string $lang): array
    {
        $db = $this->app->db();
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $params = array_merge([$default, $lang], $ids);
        $out = [];
        $rows = $db->select('SELECT product_id, lang, name, summary, description, facts, meta_title, meta_description FROM {product_i18n} WHERE (lang = ? OR lang = ?) AND product_id IN (' . $in . ')', $params);
        foreach ($rows as $row) {
            $row['faqs'] = [];
            $out[(string) $row['lang']][(int) $row['product_id']] = $row;
        }
        $faqs = $db->select('SELECT product_id, lang, question, answer FROM {product_faqs} WHERE (lang = ? OR lang = ?) AND product_id IN (' . $in . ') ORDER BY position, id', $params);
        foreach ($faqs as $row) {
            $l = (string) $row['lang'];
            $id = (int) $row['product_id'];
            if (!isset($out[$l][$id])) {
                $out[$l][$id] = ['faqs' => []];
            }
            $out[$l][$id]['faqs'][] = ['question' => (string) $row['question'], 'answer' => (string) $row['answer']];
        }

        return $out;
    }

    /**
     * Las unidades que le faltan a un producto en ese idioma (sin traducción ni propuesta).
     *
     * @param array<string,array<int,array<string,mixed>>> $texts
     * @param array<string,bool>                           $pending
     *
     * @return array<int,array{key:string,text:string,format:string}>
     */
    private function unitsOf(int $id, array $texts, string $default, string $lang, array $pending): array
    {
        $source = isset($texts[$default][$id]) ? $texts[$default][$id] : [];
        $target = isset($texts[$lang][$id]) ? $texts[$lang][$id] : [];
        $units = [];
        foreach (self::FIELDS as $field) {
            if (isset($pending[$field])) {
                continue;
            }
            if ($field === 'facts' || $field === 'faqs') {
                $list = $field === 'facts' ? self::factsOf(isset($source['facts']) ? $source['facts'] : null) : (isset($source['faqs']) && is_array($source['faqs']) ? $source['faqs'] : []);
                $has = $field === 'facts' ? self::factsOf(isset($target['facts']) ? $target['facts'] : null) : (isset($target['faqs']) && is_array($target['faqs']) ? $target['faqs'] : []);
                if ($list === [] || $has !== []) {
                    continue;
                }
                $parts = $field === 'facts' ? ['label', 'value'] : ['question', 'answer'];
                foreach (array_values($list) as $i => $item) {
                    foreach ($parts as $part) {
                        $units[] = ['key' => $id . ':' . $field . ':' . $i . ':' . $part, 'text' => (string) $item[$part], 'format' => 'text'];
                    }
                }
                continue;
            }
            $text = isset($source[$field]) && is_string($source[$field]) ? $source[$field] : '';
            $have = isset($target[$field]) && is_string($target[$field]) ? $target[$field] : '';
            if (trim($text) === '' || trim($have) !== '') {
                continue;
            }
            $units[] = ['key' => $id . ':' . $field, 'text' => $text, 'format' => $field === 'summary' || $field === 'description' ? 'html' : 'text'];
        }

        return $units;
    }

    /**
     * Vuelve a montar el texto de un campo con sus traducciones: el texto tal cual, o la lista JSON de datos o de preguntas. null si
     * falta algún trozo (la lista entera se da por fallida).
     *
     * @param string[]             $keys
     * @param array<string,string> $texts
     */
    private function assemble(string $field, array $keys, array $texts): ?string
    {
        if ($field !== 'facts' && $field !== 'faqs') {
            $key = $keys[0];

            return isset($texts[$key]) ? $texts[$key] : null;
        }
        $items = [];
        foreach ($keys as $key) {
            if (!isset($texts[$key])) {
                return null;
            }
            $parts = explode(':', $key);
            $items[(int) $parts[2]][(string) $parts[3]] = $texts[$key];
        }
        ksort($items);
        $wanted = $field === 'facts' ? ['label', 'value'] : ['question', 'answer'];
        $list = [];
        foreach ($items as $item) {
            foreach ($wanted as $part) {
                if (!isset($item[$part])) {
                    return null;
                }
            }
            $list[] = [$wanted[0] => $item[$wanted[0]], $wanted[1] => $item[$wanted[1]]];
        }

        return (string) json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param mixed $json
     *
     * @return array<int,array{label:string,value:string}>
     */
    private static function factsOf($json): array
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        $out = [];
        foreach (is_array($data) ? $data : [] as $item) {
            if (is_array($item) && isset($item['label'], $item['value']) && is_scalar($item['label']) && is_scalar($item['value'])
                && trim((string) $item['label']) !== '' && trim((string) $item['value']) !== '') {
                $out[] = ['label' => (string) $item['label'], 'value' => (string) $item['value']];
            }
        }

        return $out;
    }

    /**
     * @param array<string,array<int,array<string,bool>>> $flags
     * @param array<int,array<string,bool>>               $pending
     */
    private function status(array $flags, array $pending, int $id, string $field, string $default, string $lang): string
    {
        if (empty($flags[$default][$id][$field])) {
            return 'none';
        }
        if (!empty($flags[$lang][$id][$field])) {
            return 'ok';
        }

        return isset($pending[$id][$field]) ? 'proposed' : 'missing';
    }

    private function lastId(string $default): int
    {
        return (int) $this->app->db()->value('SELECT MAX(product_id) FROM {product_i18n} WHERE lang = ?', [$default]);
    }
}

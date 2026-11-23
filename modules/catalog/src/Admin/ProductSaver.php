<?php
/**
 * AIFEC — guardado de un producto desde el panel: todo lo que no es una columna de «products», y duplicar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

use Aifec\Db\Db;
use Aifec\Kernel\App;
use Aifec\Media\MediaException;
use Aifec\Modules\Catalog\PublicFiles;

/**
 * Lo que el recurso «products» no guarda solo: textos sobrantes, categorías, etiquetas, imágenes, atributos, datos clave,
 * preguntas, relacionados, ficheros y direcciones. Todo ocurre en UNA transacción: si algo falla, no queda nada a medias
 * (los ficheros nuevos que ya estuvieran en disco se borran, y los que se quitan solo se borran cuando todo se ha guardado).
 *
 * Los valores son los del formulario (ProductForm). Un grupo que no viaja (porque la pestaña no existe: ficheros de un producto
 * que no es digital) no se toca.
 */
final class ProductSaver
{
    private App $app;

    private Db $db;

    private int $id;

    /** @var array<string,mixed> */
    private array $values;

    /** @var array<string,mixed>|null */
    private ?array $current;

    /** @var array<string,bool> idiomas con texto propio */
    private array $active = [];

    /** @var string[] ficheros privados nuevos (se borran si falla algo) */
    private array $stored = [];

    /** @var string[] ficheros privados quitados (se borran cuando todo se ha guardado) */
    private array $removed = [];

    /** @var string[] códigos de las direcciones públicas de las filas quitadas (se borran sus carpetas cuando todo se ha guardado) */
    private array $removedPublic = [];

    /** @var string[] avisos que no impiden guardar */
    private array $warnings = [];

    /**
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current fila de «products» antes de guardar; null en un alta
     */
    public function __construct(App $app, int $id, array $values, ?array $current)
    {
        $this->app = $app;
        $this->db = $app->db();
        $this->id = $id;
        $this->values = $values;
        $this->current = $current;
    }

    /**
     * Guarda todo o nada. Devuelve los avisos que no impiden guardar; lanza si algo falla (y entonces no se ha cambiado nada).
     *
     * @return string[]
     */
    public function run(): array
    {
        try {
            $this->db->transaction(function (): void {
                $this->texts();
                $this->categories();
                $this->tags();
                $this->images();
                $this->attributes();
                $this->facts();
                $this->faqs();
                $this->related();
                $this->files();
                $this->slugs();
                $this->db->run("UPDATE {products} SET published_at = ? WHERE id = ? AND status = 'published' AND published_at IS NULL", [$this->app->clock()->sql(), $this->id]);
            });
        } catch (\Throwable $e) {
            $this->dropFiles($this->stored);
            throw $e;
        }
        $this->dropFiles($this->removed);
        foreach ($this->removedPublic as $token) {
            PublicFiles::remove($this->app, $token);
        }
        $this->stock();

        return $this->warnings;
    }

    // ------------------------------------------------------------------ pasos

    /**
     * Una traducción sin nombre no es una traducción: se quita. Lo vacío de los textos de buscadores queda sin valor.
     */
    private function texts(): void
    {
        $default = $this->app->defaultLang();
        foreach ($this->app->languages() as $lang) {
            $name = $this->text('name', $lang);
            if ($lang !== $default && $name === '') {
                $this->db->delete('product_i18n', ['product_id' => $this->id, 'lang' => $lang]);
                $this->db->delete('product_faqs', ['product_id' => $this->id, 'lang' => $lang]);
                continue;
            }
            $this->active[$lang] = true;
        }
        foreach (['meta_title', 'meta_description'] as $column) {
            $this->db->run('UPDATE {product_i18n} SET ' . $column . " = NULL WHERE product_id = ? AND $column = ''", [$this->id]);
        }
    }

    private function categories(): void
    {
        if (!array_key_exists('categories', $this->values)) {
            return;
        }
        $wanted = $this->ints($this->values['categories']);
        $ids = [];
        if ($wanted !== []) {
            $params = [];
            $marks = $this->db->in($wanted, $params);
            $found = array_flip(array_map('intval', $this->db->column('SELECT id FROM {categories} WHERE id IN (' . $marks . ')', $params)));
            foreach ($wanted as $categoryId) {
                if (isset($found[$categoryId])) {
                    $ids[] = $categoryId;
                }
            }
        }
        $main = isset($this->values['main_category']) && is_numeric($this->values['main_category']) ? (int) $this->values['main_category'] : 0;
        if (!in_array($main, $ids, true)) {
            $main = $ids !== [] ? $ids[0] : 0;
        }
        $this->db->delete('product_categories', ['product_id' => $this->id]);
        foreach ($ids as $position => $categoryId) {
            $this->db->insert('product_categories', ['product_id' => $this->id, 'category_id' => $categoryId, 'is_main' => $categoryId === $main ? 1 : 0, 'position' => $position]);
        }
    }

    private function tags(): void
    {
        if (!array_key_exists('tags', $this->values)) {
            return;
        }
        $lang = $this->app->defaultLang();
        $names = [];
        foreach (is_array($this->values['tags']) ? $this->values['tags'] : [] as $tag) {
            $tag = is_scalar($tag) ? trim((string) $tag) : '';
            if ($tag !== '') {
                $names[mb_strtolower($tag, 'UTF-8')] = mb_substr($tag, 0, 80, 'UTF-8');
            }
        }
        $this->db->delete('product_tags', ['product_id' => $this->id]);
        foreach ($names as $name) {
            $tagId = $this->db->value('SELECT id FROM {tags} WHERE lang = ? AND name = ?', [$lang, $name]);
            if ($tagId === null) {
                $tagId = $this->db->insert('tags', ['lang' => $lang, 'name' => $name]);
            }
            $this->db->insertIgnore('product_tags', ['product_id' => $this->id, 'tag_id' => (int) $tagId]);
        }
    }

    private function images(): void
    {
        if (!array_key_exists('images', $this->values)) {
            return;
        }
        $ids = $this->ints($this->values['images']);
        if ($ids !== [] && $this->db->tableExists('media')) {
            $params = [];
            $marks = $this->db->in($ids, $params);
            $found = array_flip(array_map('intval', $this->db->column('SELECT id FROM {media} WHERE id IN (' . $marks . ')', $params)));
            $ids = array_values(array_filter($ids, static function (int $mediaId) use ($found): bool {
                return isset($found[$mediaId]);
            }));
        }
        $this->db->run('DELETE FROM {product_media} WHERE product_id = ? AND variant_id IS NULL', [$this->id]);
        foreach ($ids as $position => $mediaId) {
            $this->db->insertIgnore('product_media', ['product_id' => $this->id, 'media_id' => $mediaId, 'variant_id' => null, 'position' => $position]);
        }
        $this->db->update('products', ['main_media_id' => $ids !== [] ? $ids[0] : null], ['id' => $this->id]);

        // Una variante no puede quedarse con la foto de una imagen que el producto ya no tiene.
        $keep = $this->ints($this->db->column('SELECT media_id FROM {product_media} WHERE product_id = ?', [$this->id]));
        $params = [$this->id];
        $sql = 'UPDATE {variants} SET media_id = NULL WHERE product_id = ? AND media_id IS NOT NULL';
        if ($keep !== []) {
            $sql .= ' AND media_id NOT IN (' . $this->db->in($keep, $params) . ')';
        }
        $this->db->run($sql, $params);
    }

    /**
     * Los atributos del tipo de producto. Solo se escriben si la pantalla que se envió traía los del tipo que se guarda: al
     * cambiar de tipo, los campos nuevos aún no se habían visto, y los valores de atributos que el tipo nuevo no tiene se quitan.
     * Quitar el tipo del todo no borra ningún valor.
     */
    private function attributes(): void
    {
        $family = isset($this->values['family_id']) && is_numeric($this->values['family_id']) ? (int) $this->values['family_id'] : 0;
        $oldFamily = $this->current !== null && $this->current['family_id'] !== null ? (int) $this->current['family_id'] : 0;
        if ($oldFamily > 0 && $family > 0 && $oldFamily !== $family) {
            $keep = array_map('intval', $family > 0 ? $this->db->column('SELECT attribute_id FROM {family_attributes} WHERE family_id = ?', [$family]) : []);
            foreach ($this->db->column('SELECT attribute_id FROM {family_attributes} WHERE family_id = ?', [$oldFamily]) as $attributeId) {
                if (!in_array((int) $attributeId, $keep, true)) {
                    $this->db->delete('product_attributes', ['product_id' => $this->id, 'attribute_id' => (int) $attributeId]);
                }
            }
        }
        $sent = isset($this->values['attrs_family']) && is_numeric($this->values['attrs_family']) ? (int) $this->values['attrs_family'] : 0;
        if ($family <= 0 || $sent !== $family) {
            return;
        }
        $hasVariants = (int) $this->db->value('SELECT has_variants FROM {products} WHERE id = ?', [$this->id]) === 1;
        $default = $this->app->defaultLang();
        foreach (ProductForm::familyAttributes($this->app, $family, $hasVariants) as $attr) {
            $key = 'attr_' . $attr['id'];
            if (!array_key_exists($key, $this->values)) {
                continue;
            }
            $value = $this->values[$key];
            $attributeId = (int) $attr['id'];
            $this->db->delete('product_attributes', ['product_id' => $this->id, 'attribute_id' => $attributeId]);
            $base = ['product_id' => $this->id, 'attribute_id' => $attributeId];
            switch ($attr['type']) {
                case 'select':
                case 'color':
                    if (is_scalar($value) && (string) $value !== '' && isset($attr['values'][(int) $value])) {
                        $this->db->insert('product_attributes', $base + ['value_id' => (int) $value]);
                    }
                    break;
                case 'multiselect':
                    foreach ($this->ints($value) as $valueId) {
                        if (isset($attr['values'][$valueId])) {
                            $this->db->insert('product_attributes', $base + ['value_id' => $valueId]);
                        }
                    }
                    break;
                case 'number':
                    if ($value !== null && $value !== '' && is_numeric($value)) {
                        $this->db->insert('product_attributes', $base + ['value_number' => (string) $value]);
                    }
                    break;
                case 'bool':
                    if ($value === '1' || $value === '0' || $value === 1 || $value === 0) {
                        $this->db->insert('product_attributes', $base + ['value_number' => (int) $value]);
                    }
                    break;
                default:
                    foreach ($this->app->languages() as $lang) {
                        $text = is_array($value) && isset($value[$lang]) && is_string($value[$lang]) ? trim($value[$lang]) : '';
                        if ($text !== '' && ($lang === $default || isset($this->active[$lang]))) {
                            $this->db->insert('product_attributes', $base + ['lang' => $lang, 'value_text' => $text]);
                        }
                    }
                    break;
            }
        }
    }

    private function facts(): void
    {
        foreach (array_keys($this->active) as $lang) {
            $key = 'facts_' . $lang;
            if (!array_key_exists($key, $this->values)) {
                continue;
            }
            $rows = [];
            foreach (is_array($this->values[$key]) ? $this->values[$key] : [] as $fact) {
                $label = is_array($fact) && isset($fact['label']) ? trim((string) $fact['label']) : '';
                $value = is_array($fact) && isset($fact['value']) ? trim((string) $fact['value']) : '';
                if ($label !== '' && $value !== '') {
                    $rows[] = ['label' => $label, 'value' => $value];
                }
            }
            $this->db->update('product_i18n', ['facts' => $rows !== [] ? json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null], ['product_id' => $this->id, 'lang' => $lang]);
        }
    }

    private function faqs(): void
    {
        foreach (array_keys($this->active) as $lang) {
            $key = 'faqs_' . $lang;
            if (!array_key_exists($key, $this->values)) {
                continue;
            }
            $this->db->delete('product_faqs', ['product_id' => $this->id, 'lang' => $lang]);
            $position = 0;
            foreach (is_array($this->values[$key]) ? $this->values[$key] : [] as $faq) {
                $question = is_array($faq) && isset($faq['question']) ? trim((string) $faq['question']) : '';
                $answer = is_array($faq) && isset($faq['answer']) ? trim((string) $faq['answer']) : '';
                if ($question !== '' && $answer !== '') {
                    $this->db->insert('product_faqs', ['product_id' => $this->id, 'lang' => $lang, 'question' => $question, 'answer' => $answer, 'position' => $position++]);
                }
            }
        }
    }

    private function related(): void
    {
        foreach (array_keys(ProductForm::RELATIONS) as $kind) {
            $key = 'rel_' . $kind;
            if (!array_key_exists($key, $this->values)) {
                continue;
            }
            $wanted = [];
            foreach (is_array($this->values[$key]) ? $this->values[$key] : [] as $row) {
                $other = is_array($row) && isset($row['product']) && is_numeric($row['product']) ? (int) $row['product'] : 0;
                if ($other > 0 && $other !== $this->id) {
                    $wanted[$other] = $other;
                }
            }
            $ids = [];
            if ($wanted !== []) {
                $params = [];
                $found = array_flip(array_map('intval', $this->db->column('SELECT id FROM {products} WHERE id IN (' . $this->db->in(array_values($wanted), $params) . ')', $params)));
                foreach ($wanted as $other) {
                    if (isset($found[$other])) {
                        $ids[] = $other;
                    }
                }
            }
            $this->db->delete('product_relations', ['product_id' => $this->id, 'kind' => $kind]);
            foreach (array_slice($ids, 0, 50) as $position => $other) {
                $this->db->insert('product_relations', ['product_id' => $this->id, 'related_id' => $other, 'kind' => $kind, 'position' => $position]);
            }
        }
    }

    /**
     * Ficheros de un producto digital: los que se quedan (nombre, versión, orden), los que se quitan y uno nuevo.
     */
    private function files(): void
    {
        if (!array_key_exists('files', $this->values)) {
            return;
        }
        $existing = [];
        foreach ($this->db->select('SELECT id, name, path, public_token FROM {product_files} WHERE product_id = ?', [$this->id]) as $row) {
            $existing[(int) $row['id']] = $row;
        }
        $position = 0;
        $kept = [];
        foreach (is_array($this->values['files']) ? $this->values['files'] : [] as $row) {
            $fileId = is_array($row) && isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
            if ($fileId <= 0 || !isset($existing[$fileId]) || isset($kept[$fileId])) {
                continue;
            }
            $kept[$fileId] = true;
            $name = trim((string) (isset($row['name']) ? $row['name'] : ''));
            $version = trim((string) (isset($row['version']) ? $row['version'] : ''));
            $this->db->update('product_files', [
                'name' => $name !== '' ? $name : (string) $existing[$fileId]['name'],
                'version' => $version !== '' ? $version : null,
                'position' => $position++,
            ], ['id' => $fileId]);
            // La dirección pública: sin el campo en el envío (un formulario antiguo) se queda como estaba; con él, se activa o se quita.
            // La copia se renueva en cada guardado y el código se conserva mientras siga activa.
            $wasPublic = PublicFiles::validToken($existing[$fileId]['public_token'] !== null ? (string) $existing[$fileId]['public_token'] : null);
            $choice = is_array($row) && isset($row['public']) ? (string) $row['public'] : '';
            $public = $choice === '1' ? true : ($choice === '0' ? false : $wasPublic);
            if ($public || $wasPublic) {
                PublicFiles::sync($this->app, $existing[$fileId], $public);
            }
        }
        foreach ($existing as $fileId => $row) {
            if (!isset($kept[$fileId])) {
                $this->db->delete('product_files', ['id' => $fileId]);
                $this->removed[] = (string) $row['path'];
                $this->removedPublic[] = $row['public_token'] !== null ? (string) $row['public_token'] : '';
            }
        }

        $upload = isset($this->values['new_file']) && is_array($this->values['new_file']) ? $this->values['new_file'] : null;
        if ($upload === null) {
            return;
        }
        if (!$this->app->has('files')) {
            $this->warnings[] = t('The file could not be stored: the files service is not available.');

            return;
        }
        try {
            $stored = $this->app->service('files')->store((string) $upload['tmp_name'], (string) $upload['name'], 'products');
        } catch (MediaException $e) {
            $this->warnings[] = t('The new file was not stored: {reason}', ['reason' => t($e->getMessage())]);

            return;
        }
        $this->stored[] = (string) $stored['path'];
        $name = isset($this->values['new_file_name']) ? trim((string) $this->values['new_file_name']) : '';
        $version = isset($this->values['new_file_version']) ? trim((string) $this->values['new_file_version']) : '';
        $public = !empty($this->values['new_file_public']) && (string) $this->values['new_file_public'] !== '0';
        $replaces = isset($this->values['new_file_replaces']) ? (int) $this->values['new_file_replaces'] : 0;
        if ($replaces > 0 && isset($kept[$replaces])) {
            // Una versión nueva del mismo fichero: ocupa su fila (con su orden, su nombre y su dirección pública) y el fichero privado
            // de antes se borra cuando todo se ha guardado. La copia pública se renueva dentro de su carpeta, con el mismo código.
            $update = ['path' => (string) $stored['path'], 'bytes' => (int) $stored['bytes']];
            if ($name !== '') {
                $update['name'] = mb_substr($name, 0, 191, 'UTF-8');
            }
            if ($version !== '') {
                $update['version'] = $version;
            }
            $this->removed[] = (string) $existing[$replaces]['path'];
            $this->db->update('product_files', $update, ['id' => $replaces]);
            $row = $this->db->row('SELECT id, name, path, public_token FROM {product_files} WHERE id = ?', [$replaces]);
            if ($row !== null && (PublicFiles::validToken($row['public_token'] !== null ? (string) $row['public_token'] : null) || $public)) {
                PublicFiles::sync($this->app, $row, true);
            }

            return;
        }
        $fileId = $this->db->insert('product_files', [
            'product_id' => $this->id,
            'variant_id' => null,
            'name' => mb_substr($name !== '' ? $name : (string) $stored['name'], 0, 191, 'UTF-8'),
            'path' => (string) $stored['path'],
            'bytes' => (int) $stored['bytes'],
            'version' => $version !== '' ? $version : null,
            'position' => $position,
            'created_at' => $this->app->clock()->sql(),
        ]);
        if ($public) {
            PublicFiles::sync($this->app, ['id' => $fileId, 'path' => (string) $stored['path'], 'name' => (string) $stored['name'], 'public_token' => null], true);
        }
    }

    /**
     * La dirección de cada idioma con texto: la escrita o, si está vacía, la sacada del nombre. La antigua queda redirigiendo.
     */
    private function slugs(): void
    {
        $default = $this->app->defaultLang();
        $prefix = TaxonomySlugs::prefix($this->app, 'product');
        $defaultName = $this->text('name', $default);
        foreach (array_keys($this->active) as $lang) {
            $typed = isset($this->values['slug']) && is_array($this->values['slug']) && isset($this->values['slug'][$lang]) && is_string($this->values['slug'][$lang])
                ? trim($this->values['slug'][$lang]) : '';
            $name = $this->text('name', $lang);
            $segment = TaxonomySlugs::make($typed !== '' ? $typed : ($name !== '' ? $name : $defaultName), 'product-' . $this->id);
            $this->app->slugs()->set('product', $this->id, $lang, $prefix . '/' . $segment);
        }
    }

    /**
     * Si se cambió el número de existencias a mano, se apunta el movimiento (nunca un UPDATE directo).
     */
    private function stock(): void
    {
        if (!isset($this->values['stock']) || !is_numeric($this->values['stock'])) {
            return;
        }
        $row = $this->db->row('SELECT stock, manage_stock, has_variants FROM {products} WHERE id = ?', [$this->id]);
        if ($row === null || (int) $row['manage_stock'] !== 1 || (int) $row['has_variants'] === 1) {
            return;
        }
        $delta = (int) $this->values['stock'] - (int) $row['stock'];
        if ($delta === 0) {
            return;
        }
        $catalog = $this->app->service('catalog');
        if (!$catalog->adjustStock($this->id, 0, $delta, 'manual')) {
            $this->warnings[] = t('The stock could not be changed.');
        }
    }

    // ------------------------------------------------------------------ piezas

    private function text(string $field, string $lang): string
    {
        return isset($this->values[$field]) && is_array($this->values[$field]) && isset($this->values[$field][$lang]) && is_string($this->values[$field][$lang])
            ? trim($this->values[$field][$lang]) : '';
    }

    /**
     * @param mixed $list
     *
     * @return int[] enteros positivos, sin repetir y en su orden
     */
    private function ints($list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $item) {
            if (is_scalar($item) && is_numeric($item) && (int) $item > 0) {
                $out[(int) $item] = (int) $item;
            }
        }

        return array_values($out);
    }

    /**
     * @param string[] $paths
     */
    private function dropFiles(array $paths): void
    {
        if ($paths === [] || !$this->app->has('files')) {
            return;
        }
        $files = $this->app->service('files');
        foreach ($paths as $path) {
            $files->delete($path);
        }
    }

    // ------------------------------------------------------------------ borrar y duplicar

    /**
     * Borra todo lo que cuelga de un producto (menos la fila, sus textos y su dirección, que ya borra el recurso) y los
     * ficheros privados que se entregaban. No toca la biblioteca de imágenes.
     */
    public static function erase(App $app, int $id): void
    {
        $db = $app->db();
        $paths = array_map('strval', $db->column('SELECT path FROM {product_files} WHERE product_id = ?', [$id]));
        $tokens = array_map('strval', array_filter($db->column('SELECT public_token FROM {product_files} WHERE product_id = ?', [$id]), 'is_string'));
        $db->transaction(function (Db $db) use ($id): void {
            $variants = array_map('intval', $db->column('SELECT id FROM {variants} WHERE product_id = ?', [$id]));
            if ($variants !== []) {
                $params = [];
                $db->run('DELETE FROM {variant_values} WHERE variant_id IN (' . $db->in($variants, $params) . ')', $params);
            }
            foreach (['variants', 'product_attributes', 'product_categories', 'product_tags', 'product_media', 'product_relations', 'product_faqs', 'product_files', 'stock_moves'] as $table) {
                $db->delete($table, ['product_id' => $id]);
            }
            $db->delete('product_relations', ['related_id' => $id]);
            $db->delete('product_i18n', ['product_id' => $id]);
        });
        $app->slugs()->remove('product', $id);
        foreach ($tokens as $token) {
            PublicFiles::remove($app, $token);
        }
        if ($paths !== [] && $app->has('files')) {
            $files = $app->service('files');
            foreach ($paths as $path) {
                $files->delete($path);
            }
        }
    }

    /**
     * Copia un producto como borrador: sin referencia, sin código de barras, sin existencias y con «(copia)» en el nombre.
     * Se copian textos, categorías, etiquetas, imágenes, atributos, preguntas, relacionados y variantes (sin referencia ni
     * existencias). Los ficheros que se entregan no se copian.
     *
     * @return int|null el identificador de la copia, o null si el original no existe
     */
    public static function duplicate(App $app, int $id): ?int
    {
        $db = $app->db();
        $source = $db->find('products', ['id' => $id]);
        if ($source === null) {
            return null;
        }
        $now = $app->clock()->sql();
        $copy = (int) $db->transaction(function (Db $db) use ($app, $id, $source, $now): int {
            $row = $source;
            unset($row['id']);
            $row['sku'] = null;
            $row['gtin'] = null;
            $row['stock'] = 0;
            $row['status'] = 'draft';
            $row['published_at'] = null;
            $row['rating_sum'] = 0;
            $row['rating_count'] = 0;
            $row['sales_count'] = 0;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $newId = $db->insert('products', $row);

            $prefix = TaxonomySlugs::prefix($app, 'product');
            foreach ($db->select('SELECT * FROM {product_i18n} WHERE product_id = ?', [$id]) as $text) {
                $lang = (string) $text['lang'];
                unset($text['id']);
                $text['product_id'] = $newId;
                $text['name'] = mb_substr(trim((string) $text['name']) . ' ' . $app->i18n()->t('(copy)', [], $lang), 0, 191, 'UTF-8');
                $db->insert('product_i18n', $text);
                $app->slugs()->set('product', $newId, $lang, $prefix . '/' . TaxonomySlugs::make((string) $text['name'], 'product-' . $newId));
            }
            foreach (['product_categories', 'product_tags', 'product_attributes', 'product_faqs'] as $table) {
                foreach ($db->select('SELECT * FROM {' . $table . '} WHERE product_id = ?', [$id]) as $line) {
                    if (isset($line['id'])) {
                        unset($line['id']);
                    }
                    $line['product_id'] = $newId;
                    $db->insert($table, $line);
                }
            }
            foreach ($db->select('SELECT * FROM {product_relations} WHERE product_id = ?', [$id]) as $line) {
                $line['product_id'] = $newId;
                $db->insert('product_relations', $line);
            }

            $map = [];
            foreach ($db->select('SELECT * FROM {variants} WHERE product_id = ? ORDER BY position, id', [$id]) as $variant) {
                $oldId = (int) $variant['id'];
                unset($variant['id']);
                $variant['product_id'] = $newId;
                $variant['sku'] = null;
                $variant['gtin'] = null;
                $variant['stock'] = 0;
                $variant['created_at'] = $now;
                $variant['updated_at'] = $now;
                $map[$oldId] = $db->insert('variants', $variant);
                foreach ($db->select('SELECT attribute_id, value_id FROM {variant_values} WHERE variant_id = ?', [$oldId]) as $value) {
                    $db->insert('variant_values', ['variant_id' => $map[$oldId], 'attribute_id' => (int) $value['attribute_id'], 'value_id' => (int) $value['value_id']]);
                }
            }
            foreach ($db->select('SELECT * FROM {product_media} WHERE product_id = ?', [$id]) as $line) {
                $line['product_id'] = $newId;
                $line['variant_id'] = $line['variant_id'] !== null && isset($map[(int) $line['variant_id']]) ? $map[(int) $line['variant_id']] : null;
                $db->insertIgnore('product_media', $line);
            }

            return $newId;
        });
        $app->invalidate(['product:' . $copy, 'catalog']);

        return $copy;
    }
}

<?php
/**
 * AIFEC — ayudas de prueba del catálogo para los demás módulos. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   aifecProduct(App $app, array $datos = []): int
 *       Crea un producto con su traducción, su dirección amigable y lo que se pida; devuelve su id. Todo es opcional:
 *       sin datos sale un «Producto de prueba» publicado de 10,00 € con 10 unidades. Claves de $datos:
 *         name, summary, description, facts (lista [['label','value']]), meta_title, meta_description   textos en el idioma principal
 *         i18n        [idioma => ['name', 'summary', 'description', 'facts', 'meta_title', 'meta_description']]  otras traducciones
 *         slug        dirección sin prefijo (por defecto, el nombre en minúsculas y con guiones; sale «producto/<slug>»)
 *         price, compare_price, sale_price (céntimos), sale_from, sale_to («Y-m-d H:i:s» UTC)
 *         tax_class_id  identificador o código de la clase («standard», «reduced»…); sin él, la de por defecto
 *         stock (10), manage_stock (1), stock_alert, backorders (no|notify|yes), min_qty, max_qty, delivery_min_days, delivery_max_days
 *         kind (physical|digital|service), sku, gtin, mpn, weight, length, width, height, cost
 *         status (published), visibility (everywhere|catalog|search|hidden), featured, published_at, created_at, position,
 *         sales_count, rating_sum, rating_count, brand_id, extra (array [clave => ['label','value','type','public']])
 *         categories  [id, id…] (la primera es la principal)
 *         tags        ['nombre', …] en el idioma principal
 *         related     [id, id…] de productos relacionados
 *         faqs        [['question' => …, 'answer' => …], …] en el idioma principal
 *         images      número de imágenes que se crean (sin fichero, solo la fila de medios) o lista de identificadores ya existentes;
 *                     la primera es la principal. Sin el servicio «media» cargado no salen en la ficha.
 *         attributes  [código del atributo => valor]: texto = valor de lista (select), lista de textos = multiselect, número =
 *                     number, true/false = bool. El atributo y el valor se crean si no existen; si el atributo ya existe de tipo texto,
 *                     el valor se guarda como texto.
 *         variants    [['sku', 'gtin', 'price', 'compare_price', 'stock', 'weight', 'label', 'active', 'media_id',
 *                     'values' => [código del atributo => código del valor]], …]  Crea el producto con variantes.
 *
 *   aifecCategory(App $app, string $nombre, ?int $padre = null, array $datos = []): int
 *       Categoría activa con su traducción, su ruta (identificadores desde la raíz, con el propio al final) y su dirección
 *       «categoria/…». $datos: active, position, description, product_count, media_id, i18n [idioma => ['name', 'description']].
 *
 *   aifecBrand(App $app, string $nombre, array $datos = []): int
 *       Marca activa con su dirección «marca/<slug>». $datos: active, position, description, website, media_id.
 *
 *   aifecAttribute(App $app, string $codigo, string $nombre = '', array $valores = [], array $opciones = []): int
 *       Atributo (se reutiliza si ya existe) y sus valores. $valores: lista de códigos o [código => etiqueta]. $opciones:
 *       type (select), unit, filterable (1), visible (1), variant (0), position, i18n [idioma => nombre], colors [código => '#rrggbb'].
 *
 *   aifecMedia(App $app, string $alt = ''): int
 *       Fila de la biblioteca de medios con sus variantes «thumb», «card» y «large» (sin fichero en disco).
 *
 *   aifecCatalog(App $app): Catalog
 *       El servicio «catalog» ya comprobado.
 *
 *   catalogApp(array $settings = [], array $modules = ['catalog']): App
 *       Aplicación de prueba con el catálogo (y 'media' si se pide), arrancada.
 *
 *   catalogNames(array $resultadoDeListing): array
 *       Los nombres de las tarjetas, en su orden.
 *
 *   catalogNewRequest(App $app): void
 *       Simula que empieza otra petición: la caché olvida su memoria y lee del disco (con su caducidad).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Catalog\Catalog;
use Aifec\Util\Str;

if (!function_exists('aifecCatalog')) {
    /**
     * Aplicación de prueba con el catálogo (y los módulos que se pidan, por ejemplo 'media'), ya arrancada.
     *
     * @param array<string,mixed> $settings
     * @param string[]            $modules
     */
    function catalogApp(array $settings = [], array $modules = ['catalog']): App
    {
        $options = ['modules' => $modules];
        if ($settings !== []) {
            $options['settings'] = $settings;
        }
        $app = testApp($options);
        $app->boot();

        return $app;
    }

    /**
     * Simula que empieza otra petición: la caché olvida lo que tenía en memoria y vuelve a leer del disco, con su caducidad.
     * (Invalida una etiqueta que nadie usa: lo único que cambia es la memoria.)
     */
    function catalogNewRequest(App $app): void
    {
        $app->invalidate(['catalog-new-request']);
    }

    /**
     * Los nombres de las tarjetas de un resultado de listing(), en su orden.
     *
     * @param array<string,mixed> $result
     *
     * @return string[]
     */
    function catalogNames(array $result): array
    {
        return array_map(static function (array $card): string {
            return $card['name'];
        }, $result['items']);
    }

    function aifecCatalog(App $app): Catalog
    {
        $service = $app->service('catalog');
        check($service instanceof Catalog, 'el servicio catalog es el del módulo');

        return $service;
    }

    function aifecMedia(App $app, string $alt = ''): int
    {
        static $n = 0;
        ++$n;
        $variants = [
            'thumb' => ['path' => '2026/11/foto-' . $n . '-thumb.webp', 'width' => 150, 'height' => 150],
            'card' => ['path' => '2026/11/foto-' . $n . '-card.webp', 'width' => 400, 'height' => 300],
            'large' => ['path' => '2026/11/foto-' . $n . '-large.webp', 'width' => 1200, 'height' => 900],
        ];

        return $app->db()->insert('media', [
            'path' => '2026/11/foto-' . $n . '.jpg', 'filename' => 'foto-' . $n . '.jpg', 'mime' => 'image/jpeg', 'kind' => 'image',
            'bytes' => 1000, 'width' => 1600, 'height' => 1200, 'alt' => $alt, 'variants' => json_encode($variants),
            'created_at' => $app->clock()->sql(),
        ]);
    }

    /**
     * @param string[]|array<string,string> $valores
     * @param array<string,mixed>           $opciones
     */
    function aifecAttribute(App $app, string $codigo, string $nombre = '', array $valores = [], array $opciones = []): int
    {
        $app->boot();
        $db = $app->db();
        $lang = $app->defaultLang();
        $id = $db->value('SELECT id FROM {attributes} WHERE code = ?', [$codigo]);
        if ($id === null) {
            $id = $db->insert('attributes', [
                'code' => $codigo,
                'type' => isset($opciones['type']) ? (string) $opciones['type'] : 'select',
                'unit' => isset($opciones['unit']) ? (string) $opciones['unit'] : null,
                'filterable' => isset($opciones['filterable']) ? (int) $opciones['filterable'] : 1,
                'visible' => isset($opciones['visible']) ? (int) $opciones['visible'] : 1,
                'variant' => isset($opciones['variant']) ? (int) $opciones['variant'] : 0,
                'position' => isset($opciones['position']) ? (int) $opciones['position'] : 0,
            ]);
            $names = [$lang => $nombre !== '' ? $nombre : ucfirst($codigo)];
            foreach (isset($opciones['i18n']) && is_array($opciones['i18n']) ? $opciones['i18n'] : [] as $l => $text) {
                $names[(string) $l] = (string) $text;
            }
            foreach ($names as $l => $text) {
                $db->insert('attribute_i18n', ['attribute_id' => (int) $id, 'lang' => (string) $l, 'name' => $text]);
            }
        }
        $id = (int) $id;
        foreach ($valores as $key => $value) {
            $code = is_int($key) ? (string) $value : (string) $key;
            $label = is_int($key) ? ucfirst($code) : (string) $value;
            if ($db->value('SELECT id FROM {attribute_values} WHERE attribute_id = ? AND code = ?', [$id, $code]) !== null) {
                continue;
            }
            $count = (int) $db->value('SELECT COUNT(*) FROM {attribute_values} WHERE attribute_id = ?', [$id]);
            $valueId = $db->insert('attribute_values', [
                'attribute_id' => $id, 'code' => $code,
                'color' => isset($opciones['colors'][$code]) ? (string) $opciones['colors'][$code] : null,
                'position' => $count,
            ]);
            $db->insert('attribute_value_i18n', ['value_id' => $valueId, 'lang' => $lang, 'label' => $label]);
        }

        return $id;
    }

    /** El identificador del valor de un atributo (los crea si no existen). */
    function aifecValueId(App $app, string $atributo, string $valor, array $opciones = []): int
    {
        $attributeId = aifecAttribute($app, $atributo, '', [$valor], $opciones);

        return (int) $app->db()->value('SELECT id FROM {attribute_values} WHERE attribute_id = ? AND code = ?', [$attributeId, $valor]);
    }

    function aifecCategory(App $app, string $nombre, ?int $padre = null, array $datos = []): int
    {
        $app->boot();
        $db = $app->db();
        $lang = $app->defaultLang();
        $now = $app->clock()->sql();
        $parent = $padre !== null ? $db->row('SELECT id, path, depth FROM {categories} WHERE id = ?', [$padre]) : null;
        $id = $db->insert('categories', [
            'parent_id' => $padre, 'path' => '', 'depth' => $parent !== null ? (int) $parent['depth'] + 1 : 0,
            'position' => isset($datos['position']) ? (int) $datos['position'] : 0,
            'active' => isset($datos['active']) ? (int) $datos['active'] : 1, 'in_menu' => 1,
            'media_id' => isset($datos['media_id']) ? (int) $datos['media_id'] : null,
            'product_count' => isset($datos['product_count']) ? (int) $datos['product_count'] : 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $path = $parent !== null ? trim((string) $parent['path'], '/') . '/' . $id : (string) $id;
        $db->update('categories', ['path' => $path], ['id' => $id]);

        $texts = [$lang => ['name' => $nombre, 'description' => isset($datos['description']) ? (string) $datos['description'] : '']];
        foreach (isset($datos['i18n']) && is_array($datos['i18n']) ? $datos['i18n'] : [] as $l => $text) {
            $texts[(string) $l] = $text + ['name' => $nombre, 'description' => ''];
        }
        foreach ($texts as $l => $text) {
            $db->insert('category_i18n', [
                'category_id' => $id, 'lang' => (string) $l, 'name' => (string) $text['name'],
                'description' => (string) $text['description'], 'facts' => null, 'meta_title' => null, 'meta_description' => null,
            ]);
            $parentSlug = $padre !== null ? $app->slugs()->path('category', $padre, (string) $l) : null;
            $slug = $parentSlug !== null ? $parentSlug . '/' . Str::slug((string) $text['name']) : 'categoria/' . Str::slug((string) $text['name']);
            $app->slugs()->set('category', $id, (string) $l, $slug);
        }

        return $id;
    }

    /**
     * @param array<string,mixed> $datos
     */
    function aifecBrand(App $app, string $nombre, array $datos = []): int
    {
        $app->boot();
        $now = $app->clock()->sql();
        $id = $app->db()->insert('brands', [
            'name' => $nombre, 'description' => isset($datos['description']) ? (string) $datos['description'] : null,
            'website' => isset($datos['website']) ? (string) $datos['website'] : null,
            'media_id' => isset($datos['media_id']) ? (int) $datos['media_id'] : null,
            'active' => isset($datos['active']) ? (int) $datos['active'] : 1,
            'position' => isset($datos['position']) ? (int) $datos['position'] : 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $app->slugs()->set('brand', $id, $app->defaultLang(), 'marca/' . Str::slug($nombre));

        return $id;
    }

    /**
     * @param array<string,mixed> $datos ver la cabecera de este fichero
     */
    function aifecProduct(App $app, array $datos = []): int
    {
        $app->boot();
        $db = $app->db();
        $lang = $app->defaultLang();
        $now = $app->clock()->sql();
        $name = isset($datos['name']) ? (string) $datos['name'] : 'Producto de prueba';
        $variants = isset($datos['variants']) && is_array($datos['variants']) ? $datos['variants'] : [];

        $taxClass = null;
        if (isset($datos['tax_class_id'])) {
            $taxClass = is_int($datos['tax_class_id']) ? $datos['tax_class_id']
                : (int) $db->value('SELECT id FROM {tax_classes} WHERE code = ?', [(string) $datos['tax_class_id']]);
        }
        $extra = isset($datos['extra']) && is_array($datos['extra']) ? json_encode($datos['extra']) : null;

        $defaults = [
            'kind' => 'physical', 'sku' => null, 'gtin' => null, 'mpn' => null, 'brand_id' => null, 'family_id' => null,
            'price' => 1000, 'compare_price' => null, 'sale_price' => null, 'sale_from' => null, 'sale_to' => null, 'cost' => null,
            'manage_stock' => 1, 'stock' => 10, 'stock_alert' => 0, 'backorders' => 'no', 'weight' => 0, 'length' => 0, 'width' => 0,
            'height' => 0, 'min_qty' => 1, 'max_qty' => 0, 'delivery_min_days' => null, 'delivery_max_days' => null,
            'status' => 'published', 'visibility' => 'everywhere', 'featured' => 0, 'position' => 0, 'rating_sum' => 0,
            'rating_count' => 0, 'sales_count' => 0, 'published_at' => null, 'created_at' => $now,
        ];
        $row = [];
        foreach ($defaults as $column => $default) {
            $row[$column] = array_key_exists($column, $datos) ? $datos[$column] : $default;
        }
        $row['has_variants'] = $variants !== [] ? 1 : 0;
        $row['tax_class_id'] = $taxClass;
        $row['extra'] = $extra;
        $row['updated_at'] = $now;
        $id = $db->insert('products', $row);

        // Textos por idioma.
        $base = [
            'name' => $name,
            'summary' => isset($datos['summary']) ? (string) $datos['summary'] : '',
            'description' => isset($datos['description']) ? (string) $datos['description'] : '',
            'facts' => isset($datos['facts']) ? $datos['facts'] : [],
            'meta_title' => isset($datos['meta_title']) ? (string) $datos['meta_title'] : '',
            'meta_description' => isset($datos['meta_description']) ? (string) $datos['meta_description'] : '',
        ];
        $texts = [$lang => $base];
        foreach (isset($datos['i18n']) && is_array($datos['i18n']) ? $datos['i18n'] : [] as $l => $text) {
            $l = (string) $l;
            $texts[$l] = array_merge(isset($texts[$l]) ? $texts[$l] : ['name' => $name, 'summary' => '', 'description' => '', 'facts' => [], 'meta_title' => '', 'meta_description' => ''], $text);
        }
        foreach ($texts as $l => $text) {
            $facts = is_array($text['facts']) ? ($text['facts'] === [] ? null : json_encode($text['facts'], JSON_UNESCAPED_UNICODE)) : (string) $text['facts'];
            $db->insert('product_i18n', [
                'product_id' => $id, 'lang' => (string) $l, 'name' => (string) $text['name'], 'summary' => (string) $text['summary'],
                'description' => (string) $text['description'], 'facts' => $facts,
                'meta_title' => (string) $text['meta_title'] !== '' ? (string) $text['meta_title'] : null,
                'meta_description' => (string) $text['meta_description'] !== '' ? (string) $text['meta_description'] : null,
            ]);
            $slug = $l === $lang && isset($datos['slug']) ? (string) $datos['slug'] : Str::slug((string) $text['name']);
            $app->slugs()->set('product', $id, (string) $l, 'producto/' . $slug);
        }

        // Variantes.
        foreach (array_values($variants) as $i => $variant) {
            $variantId = $db->insert('variants', [
                'product_id' => $id,
                'sku' => isset($variant['sku']) ? (string) $variant['sku'] : null,
                'gtin' => isset($variant['gtin']) ? (string) $variant['gtin'] : null,
                'price' => isset($variant['price']) ? (int) $variant['price'] : null,
                'compare_price' => isset($variant['compare_price']) ? (int) $variant['compare_price'] : null,
                'cost' => null,
                'stock' => isset($variant['stock']) ? (int) $variant['stock'] : 0,
                'weight' => isset($variant['weight']) ? (int) $variant['weight'] : null,
                'media_id' => isset($variant['media_id']) ? (int) $variant['media_id'] : null,
                'label' => isset($variant['label']) ? (string) $variant['label'] : null,
                'position' => $i,
                'active' => array_key_exists('active', $variant) ? (int) (bool) $variant['active'] : 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach (isset($variant['values']) && is_array($variant['values']) ? $variant['values'] : [] as $attribute => $value) {
                $valueId = aifecValueId($app, (string) $attribute, (string) $value, ['variant' => 1]);
                $attributeId = (int) $db->value('SELECT id FROM {attributes} WHERE code = ?', [(string) $attribute]);
                $db->insert('variant_values', ['variant_id' => $variantId, 'attribute_id' => $attributeId, 'value_id' => $valueId]);
            }
        }

        // Categorías: la primera es la principal.
        foreach (isset($datos['categories']) && is_array($datos['categories']) ? array_values($datos['categories']) : [] as $i => $categoryId) {
            $db->insert('product_categories', ['product_id' => $id, 'category_id' => (int) $categoryId, 'is_main' => $i === 0 ? 1 : 0, 'position' => $i]);
        }

        // Imágenes: un número (se crean) o una lista de identificadores (ya existen).
        $mediaIds = [];
        if (isset($datos['images'])) {
            if (is_int($datos['images'])) {
                for ($i = 0; $i < $datos['images']; $i++) {
                    $mediaIds[] = aifecMedia($app, 'Foto ' . ($i + 1) . ' de ' . $name);
                }
            } elseif (is_array($datos['images'])) {
                foreach ($datos['images'] as $mediaId) {
                    $mediaIds[] = (int) $mediaId;
                }
            }
        }
        foreach ($mediaIds as $i => $mediaId) {
            $db->insert('product_media', ['product_id' => $id, 'media_id' => $mediaId, 'variant_id' => null, 'position' => $i]);
        }
        if ($mediaIds !== []) {
            $db->update('products', ['main_media_id' => $mediaIds[0]], ['id' => $id]);
        }

        foreach (isset($datos['tags']) && is_array($datos['tags']) ? $datos['tags'] : [] as $tag) {
            $db->insertIgnore('tags', ['lang' => $lang, 'name' => (string) $tag]);
            $tagId = (int) $db->value('SELECT id FROM {tags} WHERE lang = ? AND name = ?', [$lang, (string) $tag]);
            $db->insertIgnore('product_tags', ['product_id' => $id, 'tag_id' => $tagId]);
        }
        foreach (isset($datos['related']) && is_array($datos['related']) ? array_values($datos['related']) : [] as $i => $relatedId) {
            $db->insertIgnore('product_relations', ['product_id' => $id, 'related_id' => (int) $relatedId, 'kind' => 'related', 'position' => $i]);
        }
        foreach (isset($datos['faqs']) && is_array($datos['faqs']) ? array_values($datos['faqs']) : [] as $i => $faq) {
            $question = isset($faq['question']) ? $faq['question'] : $faq[0];
            $answer = isset($faq['answer']) ? $faq['answer'] : $faq[1];
            $db->insert('product_faqs', ['product_id' => $id, 'lang' => $lang, 'question' => (string) $question, 'answer' => (string) $answer, 'position' => $i]);
        }

        // Atributos de ficha.
        foreach (isset($datos['attributes']) && is_array($datos['attributes']) ? $datos['attributes'] : [] as $code => $value) {
            $code = (string) $code;
            $attribute = $db->row('SELECT id, type FROM {attributes} WHERE code = ?', [$code]);
            if ($attribute === null) {
                $type = is_array($value) ? 'multiselect' : (is_bool($value) ? 'bool' : (is_int($value) || is_float($value) ? 'number' : 'select'));
                aifecAttribute($app, $code, '', [], ['type' => $type]);
                $attribute = $db->row('SELECT id, type FROM {attributes} WHERE code = ?', [$code]);
            }
            $attributeId = (int) $attribute['id'];
            $type = (string) $attribute['type'];
            if ($type === 'select' || $type === 'color' || $type === 'multiselect') {
                foreach (is_array($value) ? $value : [$value] as $valueCode) {
                    $valueId = aifecValueId($app, $code, (string) $valueCode);
                    $db->insert('product_attributes', ['product_id' => $id, 'attribute_id' => $attributeId, 'value_id' => $valueId]);
                }
            } elseif ($type === 'number') {
                $db->insert('product_attributes', ['product_id' => $id, 'attribute_id' => $attributeId, 'value_number' => (string) $value]);
            } elseif ($type === 'bool') {
                $db->insert('product_attributes', ['product_id' => $id, 'attribute_id' => $attributeId, 'value_number' => $value ? 1 : 0]);
            } else {
                $db->insert('product_attributes', ['product_id' => $id, 'attribute_id' => $attributeId, 'value_text' => (string) $value]);
            }
        }

        return $id;
    }
}

<?php
/**
 * AIFEC — formulario de producto del panel: las pestañas, los campos y lo que ya tiene guardado cada uno.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Response;
use Aifec\Modules\Catalog\Attributes;
use Aifec\Modules\Catalog\Texts;

/**
 * Compone la definición del formulario del recurso «products» (admin.php). Todo lo que no es una columna de «products»
 * ni un texto de «product_i18n» (imágenes, categorías, etiquetas, atributos, datos clave, preguntas, ficheros y
 * relacionados) se declara con «store» => false y lo guarda ProductSaver; aquí solo se le da el valor que ya tiene.
 *
 * Dos cosas dependen de lo que se envía y no solo de lo guardado: el tipo de producto (sus atributos) y la clase (la pestaña
 * de ficheros solo existe en un producto digital). Al guardar se leen del envío, para que la validación vea los mismos campos
 * que la persona tiene delante.
 */
final class ProductForm
{
    public const KINDS = ['physical' => 'Physical', 'digital' => 'Digital', 'service' => 'Service'];
    public const STATUSES = ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'];
    public const VISIBILITIES = [
        'everywhere' => 'Everywhere',
        'catalog' => 'Catalog only (not in the search)',
        'search' => 'Search only (not in the catalog)',
        'hidden' => 'Hidden (only with the link)',
    ];
    public const BACKORDERS = ['no' => 'Do not allow', 'notify' => 'Allow, and let customers sign up for the restock', 'yes' => 'Allow, and sell without stock'];
    public const RELATIONS = ['related' => 'Related products', 'upsell' => 'Better alternatives', 'cross' => 'Bought together'];

    /**
     * @return array<string,mixed>
     */
    public static function definition(App $app, ?int $id): array
    {
        $db = $app->db();
        $langs = $app->languages();
        $multi = count($langs) > 1;
        $row = $id !== null ? $db->find('products', ['id' => $id]) : null;
        $posted = self::posted($app);

        $kind = $row !== null ? (string) $row['kind'] : 'physical';
        if (isset($posted['kind']) && is_string($posted['kind']) && isset(self::KINDS[$posted['kind']])) {
            $kind = $posted['kind'];
        }
        $savedFamily = $row !== null && $row['family_id'] !== null ? (int) $row['family_id'] : 0;
        $family = isset($posted['family_id']) && is_scalar($posted['family_id']) ? (int) $posted['family_id'] : $savedFamily;
        $hasVariants = $row !== null && (int) $row['has_variants'] === 1;

        $tabs = [
            'pdmain' => 'Main',
            'pdprice' => 'Price',
            'pdstock' => 'Stock and shipping',
            'pdmedia' => 'Images',
            'pdcats' => 'Categories and tags',
            'pdattrs' => 'Attributes',
            'pdseo' => 'SEO and AI',
        ];
        if ($kind === 'digital') {
            $tabs['pdfiles'] = 'Files';
        }
        $tabs['pdrelated'] = 'Related';

        $fields = self::mainFields($app, $savedFamily);
        $fields += self::priceFields($app);
        $fields += self::stockFields($app, $row, $hasVariants);
        $fields += self::mediaFields($app, $id);
        $fields += self::categoryFields($app, $id);
        $fields += self::attributeFields($app, $id, $family, $savedFamily, $hasVariants);
        $fields += self::seoFields($app, $id, $langs, $multi);
        if ($kind === 'digital') {
            $fields += self::fileFields($app, $id);
        }
        $fields += self::relatedFields($app, $id);
        self::fold($app, $row, $fields, $langs, $posted);

        return ['tabs' => $tabs, 'fields' => $fields];
    }

    /**
     * Deja preparado el «Más opciones» de cada pestaña: al terminar la petición, el HTML de la ficha pasa por MoreOptions::wrap().
     * El gancho solo vale para esta petición y se abre solo donde algún campo ya tiene valor (lo guardado, o lo enviado si es un
     * reenvío por errores). El guion que recuerda si se abrió se añade en la misma pasada.
     *
     * @param array<string,mixed>               $row    producto (null en un alta)
     * @param array<string,array<string,mixed>> $fields
     * @param string[]                          $langs
     * @param array<string,mixed>               $posted
     */
    private static function fold(App $app, ?array $row, array $fields, array $langs, array $posted): void
    {
        try {
            $request = $app->request();
        } catch (\Throwable $e) {
            return;
        }
        $groups = MoreOptions::groups($langs);
        $compare = $fields;
        if ($posted !== []) {
            $values = $posted;
        } elseif ($row !== null) {
            // Lo que no es una columna del producto (categorías, datos clave…) ya trae su valor guardado como «default».
            $values = $row;
            foreach ($fields as $name => $field) {
                if (!array_key_exists($name, $row) && array_key_exists('default', $field)) {
                    $values[$name] = $field['default'];
                    unset($compare[$name]['default']);
                }
            }
        } else {
            $values = [];
        }
        $open = MoreOptions::open($groups, $compare, $values);
        $script = '<script src="' . e($app->url()->asset('m', 'catalog', 'more.js')) . '" defer></script>';
        $app->hooks()->filter('response', static function ($response, $current = null) use ($request, $groups, $open, $script) {
            if (!$response instanceof Response || $current !== $request || !is_string($response->body) || strpos($response->body, 'data-af-panel="') === false) {
                return $response;
            }
            $wrapped = MoreOptions::wrap($response->body, $groups, $open);
            if ($wrapped !== $response->body) {
                $at = strrpos($wrapped, '</body>');
                $wrapped = $at === false ? $wrapped . $script : substr($wrapped, 0, $at) . $script . substr($wrapped, $at);
                $response->body = $wrapped;
            }

            return $response;
        }, 50);
    }

    /**
     * Lo que se envió en el formulario (solo en un POST); si no, nada.
     *
     * @return array<string,mixed>
     */
    public static function posted(App $app): array
    {
        try {
            $request = $app->request();
            if ($request->method !== 'POST') {
                return [];
            }

            return $request->arr('f');
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------------------ pestañas

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function mainFields(App $app, int $savedFamily): array
    {
        $include = true;
        if ($app->has('tax')) {
            $tax = $app->service('tax');
            $include = is_object($tax) && method_exists($tax, 'pricesIncludeTax') ? (bool) $tax->pricesIncludeTax() : true;
        }

        // Para dar de alta un producto basta el nombre y el precio: lo demás nace con valores razonables (ProductAdmin::beforeSave).
        // Lo que casi nadie toca (MoreOptions::groups) va plegado en «Más opciones» de su pestaña.
        return [
            'name' => ['label' => 'Name', 'i18n' => true, 'rules' => 'required|max:191', 'tab' => 'pdmain'],
            'price' => ['type' => 'money', 'label' => 'Price', 'rules' => 'min:0', 'default' => 0, 'tab' => 'pdmain',
                'hint' => $include ? 'Prices are written with the tax included.' : 'Prices are written without the tax.'],
            'summary' => ['type' => 'richtext', 'label' => 'Summary', 'i18n' => true, 'tab' => 'pdmain', 'hint' => 'Two or three lines: what it is and who it is for.'],
            'description' => ['type' => 'richtext', 'label' => 'Description', 'i18n' => true, 'tab' => 'pdmain'],
            'status' => ['type' => 'select', 'label' => 'Status', 'options' => self::STATUSES, 'default' => 'draft', 'tab' => 'pdmain'],
            'visibility' => ['type' => 'select', 'label' => 'Visibility', 'options' => self::VISIBILITIES, 'default' => 'everywhere', 'tab' => 'pdmain'],
            'featured' => ['type' => 'toggle', 'label' => 'Featured', 'default' => 0, 'tab' => 'pdmain'],
            'brand_id' => ['type' => 'select', 'label' => 'Brand', 'options_from' => ['table' => 'brands', 'value' => 'id', 'label' => 'name', 'order' => 'name'],
                'empty' => 'No brand', 'null_empty' => true, 'rules' => 'nullable|int', 'tab' => 'pdmain'],
            'family_id' => ['type' => 'select', 'label' => 'Product type', 'options_from' => ['table' => 'families', 'value' => 'id', 'label' => 'name', 'order' => 'position, name'],
                'empty' => 'No type', 'null_empty' => true, 'rules' => 'nullable|int', 'tab' => 'pdmain',
                'hint' => 'The type decides which attributes are asked for. Save to see them in the Attributes tab.'],
            'kind' => ['type' => 'select', 'label' => 'Kind', 'options' => self::KINDS, 'default' => 'physical', 'tab' => 'pdmain',
                'hint' => 'A digital product gets a Files tab once it is saved. A service is neither shipped nor downloaded.'],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function priceFields(App $app): array
    {
        return [
            'compare_price' => ['type' => 'money', 'label' => 'Previous price', 'rules' => 'min:0', 'tab' => 'pdprice',
                'hint' => 'Shown crossed out when it is higher than the price.'],
            'sale_price' => ['type' => 'money', 'label' => 'Sale price', 'rules' => 'min:0', 'tab' => 'pdprice', 'hint' => 'It has to be lower than the price.'],
            'sale_from' => ['type' => 'datetime', 'label' => 'Sale starts', 'tab' => 'pdprice'],
            'sale_to' => ['type' => 'datetime', 'label' => 'Sale ends', 'tab' => 'pdprice'],
            'cost' => ['type' => 'money', 'label' => 'Cost', 'rules' => 'min:0', 'tab' => 'pdprice', 'hint' => 'What it costs you. Customers never see it.'],
            'tax_class_id' => ['type' => 'select', 'label' => 'Tax class', 'options_from' => ['table' => 'tax_classes', 'value' => 'id', 'label' => 'name', 'order' => 'position, id'],
                'translate' => true, 'empty' => 'The default one', 'null_empty' => true, 'rules' => 'nullable|int', 'tab' => 'pdprice'],
        ];
    }

    /**
     * @param array<string,mixed>|null $row
     *
     * @return array<string,array<string,mixed>>
     */
    private static function stockFields(App $app, ?array $row, bool $hasVariants): array
    {
        if ($row === null) {
            $note = '<div class="af-alert af-alert-info">' . e(t('Save the product first: then you can add its variants.')) . '</div>';
        } else {
            $count = (int) $app->db()->value('SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [(int) $row['id']]);
            $link = '<a href="' . e(VariantAdmin::url($app, (int) $row['id'])) . '">' . e(t('Manage the variants')) . '</a>';
            $note = $hasVariants
                ? '<div class="af-alert af-alert-warn">' . e(tn('This product has {n} variant, and the stock is managed on it, not here.', 'This product has {n} variants, and the stock is managed on each of them, not here.', $count)) . ' ' . $link . '</div>'
                : '<div class="af-muted af-small">' . e(t('Does it come in sizes or colours?')) . ' ' . $link . '</div>';
        }

        return [
            'stock_note' => ['type' => 'html', 'label' => '', 'html' => $note, 'tab' => 'pdstock'],
            'sku' => ['label' => 'Reference (SKU)', 'rules' => 'nullable|max:64|unique:products,sku', 'width' => 'md', 'tab' => 'pdstock'],
            // Un producto nuevo nace sin controlar las existencias; quien las quiera contar enciende el interruptor.
            'manage_stock' => ['type' => 'toggle', 'label' => 'Control the stock', 'default' => $row === null ? 0 : 1, 'tab' => 'pdstock'],
            'stock' => ['type' => 'number', 'label' => 'Stock', 'default' => 0, 'rules' => 'min:0', 'store' => false, 'tab' => 'pdstock', 'show_if' => ['manage_stock' => '1'],
                'hint' => 'Changing it here is recorded as a manual movement.'],
            'weight' => ['type' => 'number', 'label' => 'Weight', 'default' => 0, 'rules' => 'min:0', 'suffix' => 'g', 'tab' => 'pdstock'],
            'gtin' => ['label' => 'Barcode (GTIN)', 'rules' => 'nullable|max:32', 'width' => 'md', 'tab' => 'pdstock'],
            'mpn' => ['label' => 'Manufacturer part number', 'rules' => 'nullable|max:64', 'width' => 'md', 'tab' => 'pdstock'],
            'stock_alert' => ['type' => 'number', 'label' => 'Warn when the stock is at or below', 'default' => 0, 'rules' => 'min:0', 'tab' => 'pdstock'],
            'backorders' => ['type' => 'select', 'label' => 'Orders without stock', 'options' => self::BACKORDERS, 'default' => 'no', 'tab' => 'pdstock'],
            'min_qty' => ['type' => 'number', 'label' => 'Minimum quantity', 'default' => 1, 'rules' => 'min:1', 'tab' => 'pdstock'],
            'max_qty' => ['type' => 'number', 'label' => 'Maximum quantity', 'default' => 0, 'rules' => 'min:0', 'tab' => 'pdstock', 'hint' => '0 means no maximum.'],
            'length' => ['type' => 'number', 'label' => 'Length', 'default' => 0, 'rules' => 'min:0', 'suffix' => 'mm', 'tab' => 'pdstock'],
            'width' => ['type' => 'number', 'label' => 'Width', 'default' => 0, 'rules' => 'min:0', 'suffix' => 'mm', 'tab' => 'pdstock'],
            'height' => ['type' => 'number', 'label' => 'Height', 'default' => 0, 'rules' => 'min:0', 'suffix' => 'mm', 'tab' => 'pdstock'],
            'delivery_min_days' => ['type' => 'number', 'label' => 'Delivery, minimum days', 'rules' => 'min:0', 'tab' => 'pdstock'],
            'delivery_max_days' => ['type' => 'number', 'label' => 'Delivery, maximum days', 'rules' => 'min:0', 'tab' => 'pdstock'],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function mediaFields(App $app, ?int $id): array
    {
        $ids = [];
        if ($id !== null) {
            $ids = array_map('intval', $app->db()->column('SELECT media_id FROM {product_media} WHERE product_id = ? ORDER BY position, media_id', [$id]));
        }

        return [
            'images' => ['type' => 'images', 'label' => 'Images', 'store' => false, 'default' => $ids, 'tab' => 'pdmedia',
                'hint' => 'The first one is the main image. Drag to change the order.'],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function categoryFields(App $app, ?int $id): array
    {
        $db = $app->db();
        $chosen = [];
        $main = '';
        $tags = [];
        if ($id !== null) {
            foreach ($db->select('SELECT category_id, is_main FROM {product_categories} WHERE product_id = ? ORDER BY position, category_id', [$id]) as $row) {
                $chosen[] = (string) $row['category_id'];
                if ((int) $row['is_main'] === 1) {
                    $main = (string) $row['category_id'];
                }
            }
            $tags = array_map('strval', $db->column(
                'SELECT g.name FROM {product_tags} pt JOIN {tags} g ON g.id = pt.tag_id WHERE pt.product_id = ? AND g.lang = ? ORDER BY g.name',
                [$id, $app->defaultLang()]
            ));
        }

        // Las categorías se eligen con un buscador, etiquetas y un árbol (CategoryPicker), no con una casilla por categoría. Siguen
        // siendo «categories» (lista) y «main_category»: lo que recibe el servidor no cambia. Todas las categorías valen al guardar
        // (un identificador que no existe se rechaza); el desplegable de la principal solo lleva las del producto.
        return [
            'categories' => ['type' => 'checks', 'label' => 'Categories', 'translate' => false, 'store' => false, 'default' => $chosen, 'tab' => 'pdcats',
                'options' => static function (App $app): array {
                    return CategoryPicker::allOptions($app);
                },
                'render' => static function (string $name, string $id, $value, App $app, array $all) use ($main): string {
                    return CategoryPicker::render($app, $name, $id, $value, $all, $main);
                },
                'hint' => 'Search by name or open the tree. The main one decides the breadcrumb and the address shown for the product.'],
            'main_category' => ['type' => 'select', 'label' => 'Main category', 'free' => true, 'translate' => false, 'empty' => 'The first one chosen', 'store' => false,
                'options' => static function (App $app) use ($chosen): array {
                    return CategoryPicker::chosenOptions($app, $chosen);
                },
                'default' => $main, 'rules' => 'nullable|int', 'tab' => 'pdcats'],
            'tags' => ['type' => 'tags', 'label' => 'Tags', 'store' => false, 'default' => $tags, 'tab' => 'pdcats', 'hint' => 'Separated by commas.'],
        ];
    }

    /**
     * Un campo por cada atributo del tipo de producto. Con variantes, los atributos que sirven para crearlas se eligen en su pantalla.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function attributeFields(App $app, ?int $id, int $family, int $savedFamily, bool $hasVariants): array
    {
        $fields = [
            'attrs_family' => ['type' => 'hidden', 'store' => false, 'default' => (string) $family, 'tab' => 'pdattrs'],
        ];
        $attrs = self::familyAttributes($app, $family, $hasVariants);
        if ($family === 0 || $attrs === []) {
            $fields['attrs_note'] = ['type' => 'heading', 'tab' => 'pdattrs',
                'label' => $family === 0 ? 'Choose a product type to see its attributes' : 'This product type has no attributes',
                'hint' => $family === 0 ? 'Pick one in the Main tab and save: its attributes appear here.' : 'Add attributes to the type in Catalog, Product types.'];

            return $fields;
        }
        $saved = $id !== null ? self::savedAttributes($app, $id) : [];
        $default = $app->defaultLang();
        foreach ($attrs as $attr) {
            $name = 'attr_' . $attr['id'];
            $label = $attr['name'];
            $rows = isset($saved[$attr['id']]) ? $saved[$attr['id']] : [];
            $base = ['label' => $label, 'tab' => 'pdattrs', 'store' => false, 'translate' => false];
            switch ($attr['type']) {
                case 'select':
                case 'color':
                    $options = [];
                    foreach ($attr['values'] as $valueId => $value) {
                        $options[(string) $valueId] = (string) $value['label'];
                    }
                    $current = '';
                    foreach ($rows as $row) {
                        if ($row['value_id'] !== null) {
                            $current = (string) $row['value_id'];
                            break;
                        }
                    }
                    $fields[$name] = $base + ['type' => 'select', 'options' => $options, 'empty' => 'Not set', 'default' => $current, 'rules' => 'nullable'];
                    break;
                case 'multiselect':
                    $options = [];
                    foreach ($attr['values'] as $valueId => $value) {
                        $options[(string) $valueId] = (string) $value['label'];
                    }
                    $current = [];
                    foreach ($rows as $row) {
                        if ($row['value_id'] !== null) {
                            $current[] = (string) $row['value_id'];
                        }
                    }
                    $fields[$name] = $base + ['type' => 'checks', 'options' => $options, 'default' => $current];
                    break;
                case 'number':
                    $current = '';
                    foreach ($rows as $row) {
                        if ($row['value_number'] !== null && $row['value_number'] !== '') {
                            $current = Texts::trimNumber((float) $row['value_number']);
                            break;
                        }
                    }
                    $fields[$name] = $base + ['type' => 'number', 'decimal' => true, 'rules' => 'nullable', 'default' => $current]
                        + ($attr['unit'] !== '' ? ['suffix' => $attr['unit']] : []);
                    break;
                case 'bool':
                    $current = '';
                    foreach ($rows as $row) {
                        if ($row['value_number'] !== null && $row['value_number'] !== '') {
                            $current = (float) $row['value_number'] !== 0.0 ? '1' : '0';
                            break;
                        }
                    }
                    $fields[$name] = $base + ['type' => 'select', 'options' => ['1' => 'Yes', '0' => 'No'], 'translate' => true, 'empty' => 'Not set', 'default' => $current, 'rules' => 'nullable'];
                    break;
                default:
                    $texts = [];
                    foreach ($rows as $row) {
                        $lang = $row['lang'] !== null && (string) $row['lang'] !== '' ? (string) $row['lang'] : $default;
                        if (!isset($texts[$lang])) {
                            $texts[$lang] = (string) $row['value_text'];
                        }
                    }
                    $fields[$name] = $base + ['type' => 'text', 'i18n' => true, 'rules' => 'nullable|max:500', 'default' => $texts];
                    break;
            }
        }

        return $fields;
    }

    /**
     * @return array<int,array<string,mixed>> atributos del tipo, en su orden
     */
    public static function familyAttributes(App $app, int $family, bool $hasVariants): array
    {
        if ($family <= 0) {
            return [];
        }
        $all = (new Attributes($app))->all($app->defaultLang());
        $out = [];
        $ids = $app->db()->column('SELECT attribute_id FROM {family_attributes} WHERE family_id = ? ORDER BY position, attribute_id', [$family]);
        foreach ($ids as $attributeId) {
            $attributeId = (int) $attributeId;
            if (!isset($all[$attributeId]) || ($hasVariants && $all[$attributeId]['variant'])) {
                continue;
            }
            $out[] = $all[$attributeId];
        }

        return $out;
    }

    /**
     * @return array<int,array<int,array<string,mixed>>> id del atributo => filas de product_attributes
     */
    private static function savedAttributes(App $app, int $id): array
    {
        $out = [];
        foreach ($app->db()->select('SELECT attribute_id, value_id, lang, value_text, value_number FROM {product_attributes} WHERE product_id = ? ORDER BY id', [$id]) as $row) {
            $out[(int) $row['attribute_id']][] = $row;
        }

        return $out;
    }

    /**
     * @param string[] $langs
     *
     * @return array<string,array<string,mixed>>
     */
    private static function seoFields(App $app, ?int $id, array $langs, bool $multi): array
    {
        $db = $app->db();
        $segments = [];
        $facts = [];
        $faqs = [];
        if ($id !== null) {
            $segments = TaxonomySlugs::segments($app, 'product', $id);
            foreach ($db->select('SELECT lang, facts FROM {product_i18n} WHERE product_id = ?', [$id]) as $row) {
                $facts[(string) $row['lang']] = Texts::facts($row['facts'] !== null ? (string) $row['facts'] : null);
            }
            foreach ($db->select('SELECT lang, question, answer FROM {product_faqs} WHERE product_id = ? ORDER BY position, id', [$id]) as $row) {
                $faqs[(string) $row['lang']][] = ['question' => (string) $row['question'], 'answer' => (string) $row['answer']];
            }
        }
        $fields = [
            'slug' => ['type' => 'slug', 'label' => 'Address', 'i18n' => true, 'store' => false, 'rules' => 'max:90', 'default' => $segments, 'tab' => 'pdseo',
                'hint' => 'Leave it empty to take it from the name. The old address keeps redirecting if you change it.'],
            'meta_title' => ['label' => 'Meta title', 'i18n' => true, 'rules' => 'max:191', 'tab' => 'pdseo', 'hint' => 'Around 60 characters.'],
            'meta_description' => ['type' => 'textarea', 'label' => 'Meta description', 'i18n' => true, 'rows' => 3, 'rules' => 'max:320', 'tab' => 'pdseo', 'hint' => 'Around 155 characters.'],
        ];
        foreach ($langs as $lang) {
            $fields['facts_' . $lang] = [
                'type' => 'repeater', 'label' => $multi ? t('Key facts') . ' (' . strtoupper($lang) . ')' : 'Key facts', 'store' => false, 'tab' => 'pdseo',
                'add' => 'Add a fact', 'hint' => 'Short facts that can be checked, like a size or a material. AI assistants quote them when they recommend or compare products.',
                'default' => isset($facts[$lang]) ? $facts[$lang] : [],
                'fields' => [
                    'label' => ['label' => 'Label', 'rules' => 'max:120'],
                    'value' => ['label' => 'Value', 'rules' => 'max:255'],
                ],
            ];
        }
        foreach ($langs as $lang) {
            $fields['faqs_' . $lang] = [
                'type' => 'repeater', 'label' => $multi ? t('Frequently asked questions') . ' (' . strtoupper($lang) . ')' : 'Frequently asked questions', 'store' => false, 'tab' => 'pdseo',
                'add' => 'Add a question', 'hint' => 'Real questions with a short, honest answer. Search engines and AI assistants quote them.',
                'default' => isset($faqs[$lang]) ? $faqs[$lang] : [],
                'fields' => [
                    'question' => ['label' => 'Question', 'rules' => 'max:255'],
                    'answer' => ['type' => 'textarea', 'label' => 'Answer', 'rows' => 3, 'rules' => 'max:2000'],
                ],
            ];
        }

        return $fields;
    }

    /**
     * Los ficheros que se entregan: los que hay (quitar una fila borra su fichero) y uno nuevo por cada guardado.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function fileFields(App $app, ?int $id): array
    {
        $rows = [];
        if ($id !== null) {
            foreach ($app->db()->select('SELECT id, name, version FROM {product_files} WHERE product_id = ? ORDER BY position, id', [$id]) as $row) {
                $rows[] = ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'version' => $row['version'] !== null ? (string) $row['version'] : ''];
            }
        }

        return [
            'files' => ['type' => 'repeater', 'label' => 'Files', 'store' => false, 'tab' => 'pdfiles', 'default' => $rows, 'add' => 'Add a file',
                'hint' => 'The files a customer can download after paying. Removing a row deletes the file. Drag the rows to change the order.',
                'fields' => [
                    'id' => ['type' => 'hidden', 'rules' => 'int'],
                    'name' => ['label' => 'Name', 'rules' => 'max:191'],
                    'version' => ['label' => 'Version', 'rules' => 'max:40'],
                ]],
            'new_file' => ['type' => 'file', 'label' => 'New file', 'store' => false, 'tab' => 'pdfiles', 'hint' => 'It is stored out of the public folder. One file each time you save.'],
            'new_file_name' => ['label' => 'Name of the new file', 'rules' => 'max:191', 'store' => false, 'tab' => 'pdfiles', 'hint' => 'Optional: the name of the file is used if it is empty.'],
            'new_file_version' => ['label' => 'Version of the new file', 'rules' => 'max:40', 'store' => false, 'width' => 'sm', 'tab' => 'pdfiles'],
        ];
    }

    /**
     * Tres listas de productos relacionados; cada fila lleva un selector con búsqueda.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function relatedFields(App $app, ?int $id): array
    {
        $db = $app->db();
        $saved = [];
        $ids = [];
        if ($id !== null) {
            foreach ($db->select('SELECT related_id, kind FROM {product_relations} WHERE product_id = ? ORDER BY position, related_id', [$id]) as $row) {
                $saved[(string) $row['kind']][] = ['product' => (string) $row['related_id']];
                $ids[] = (int) $row['related_id'];
            }
        }
        $labels = self::productLabels($app, $ids);
        $search = $app->url()->plain('/admin/r/products/search.json');
        $fields = [];
        foreach (self::RELATIONS as $kind => $label) {
            $fields['rel_' . $kind] = [
                'type' => 'repeater', 'label' => $label, 'store' => false, 'tab' => 'pdrelated', 'add' => 'Add a product',
                'default' => isset($saved[$kind]) ? $saved[$kind] : [],
                'fields' => [
                    'product' => ['type' => 'select', 'label' => 'Product', 'options' => $labels, 'free' => true, 'translate' => false, 'empty' => 'Search a product',
                        'rules' => 'int', 'attrs' => ['data-af-relation' => $search]],
                ],
            ];
        }

        return $fields;
    }

    /**
     * Nombre y referencia de unos productos, en el idioma principal.
     *
     * @param int[] $ids
     *
     * @return array<string,string> id => «Nombre (REF)»
     */
    public static function productLabels(App $app, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $db = $app->db();
        $params = [$app->defaultLang()];
        $marks = $db->in($ids, $params);
        $out = [];
        foreach ($db->select('SELECT p.id, p.sku, x.name FROM {products} p LEFT JOIN {product_i18n} x ON x.product_id = p.id AND x.lang = ? WHERE p.id IN (' . $marks . ')', $params) as $row) {
            $name = $row['name'] !== null && (string) $row['name'] !== '' ? (string) $row['name'] : '#' . $row['id'];
            $out[(string) $row['id']] = $name . ($row['sku'] !== null && (string) $row['sku'] !== '' ? ' (' . $row['sku'] . ')' : '');
        }

        return $out;
    }

    /**
     * Botones de la cabecera de la ficha de un producto: variantes, duplicar y ver.
     *
     * @param array<string,mixed>|null $row
     */
    public static function actions(?array $row, App $app): string
    {
        if ($row === null) {
            return '';
        }
        $id = (int) $row['id'];
        $html = '<a class="af-btn" href="' . e(VariantAdmin::url($app, $id)) . '">' . Admin::icon('layers') . e(t('Variants')) . '</a>';
        if ($app->auth()->can('catalog.edit')) {
            $html .= '<form method="post" action="' . e($app->url()->plain('/admin/catalog/products/' . $id . '/duplicate')) . '" class="af-inline">' . Admin::tokenField($app)
                . '<button type="submit" class="af-btn">' . Admin::icon('copy') . e(t('Duplicate')) . '</button></form>';
        }

        return $html . '<a class="af-btn" target="_blank" rel="noopener" href="' . e($app->url()->entity('product', $id)) . '">' . Admin::icon('external') . e(t('View')) . '</a>';
    }
}

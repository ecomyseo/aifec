<?php
/**
 * AIFEC — el formato neutro de migración: lo que un lector entrega y un escritor recibe, tipo a tipo.
 *
 * Es el contrato entre los lectores (WooCommerce, PrestaShop; por API o de una copia de la base de datos) y los
 * escritores (uno por tipo, comunes a todos los orígenes). Lo comprueba Neutral::check(); las pruebas de unos y de
 * otros pasan por ahí, así que este fichero es la única verdad. La explicación está en contratos/06-migracion.md.
 *
 * Cada campo se declara con una cadena: clase[:argumento][!][?]
 *   !  obligatorio          ?  admite null          (sin marca: puede faltar; si está, no puede ser null)
 *
 *   id            identificador de ORIGEN: cadena no vacía de 64 caracteres como mucho (opaca: «12», «f12», «wc-7»)
 *   ref:tipo      identificador de origen de otro registro de ese tipo       refs:tipo   lista de ellos
 *   str  text     texto plano (una línea / varias)        html   HTML sin sanear (lo sanea el escritor)
 *   strs          lista de textos
 *   i18n          [idioma => texto]        i18n_html   [idioma => HTML]        i18n_list   [idioma => lista de textos]
 *   int  int+     entero / entero ≥ 0      float       número
 *   cents         importe en céntimos (entero)          rate   porcentaje en centésimas de punto (2100 = 21 %)
 *   micros        el mismo importe en millonésimas (entero); campo hermano opcional «<campo>_micros» de un precio con más precisión que el céntimo
 *   bool          true o false de verdad (nunca 0, 1, «yes»)
 *   date          instante en UTC, «AAAA-MM-DDTHH:MM:SSZ»
 *   country       ISO 3166-1 de dos letras en mayúsculas        lang   código de idioma («es», «en», «pt-BR»)
 *   enum:a|b|c    uno de esos valores
 *   image         parte «image»            images      lista de partes «image»
 *   part:nombre   una parte                list:nombre lista de partes
 *   paths         lista de rutas del origen que empezaban por «/» (la canónica, la primera)
 *   map           datos libres [clave => valor]: lo que no tiene sitio (se conserva, no se pierde)
 *   secret        texto que NO puede salir en registros ni informes        secrets   [clave => valor] que puede llevarlos
 *
 * Medidas: peso en gramos, largo, ancho y alto en milímetros (el lector convierte desde las unidades del origen).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

final class NeutralSchema
{
    /** Campos comunes a todos los tipos. */
    private const COMMON = ['id' => 'id!', 'updated_at' => 'date?', 'extra' => 'map'];

    /**
     * @return array<string,array<string,string>> tipo => [campo => declaración]
     */
    public static function types(): array
    {
        $seo = ['meta_title' => 'i18n', 'meta_description' => 'i18n', 'old_paths' => 'paths'];
        $types = [
            'settings' => [
                'shop_name' => 'str', 'legal_name' => 'str', 'vat_id' => 'str', 'email' => 'str', 'phone' => 'str', 'address' => 'part:address',
                'currency' => 'str', 'languages' => 'strs', 'default_language' => 'lang', 'timezone' => 'str',
                'weight_unit' => 'enum:kg|g|lb|oz', 'dimension_unit' => 'enum:m|cm|mm|in',
                'prices_include_tax' => 'bool', 'display_prices_with_tax' => 'bool', 'tax_based_on' => 'enum:shipping|billing|shop',
                'guest_checkout' => 'bool', 'stock_management' => 'bool', 'logo' => 'image?', 'icon' => 'image?',
                'order_number_prefix' => 'str', 'order_number_next' => 'int+', 'invoice_number_prefix' => 'str', 'invoice_number_next' => 'int+',
                'mail' => 'part:mail', 'legal_pages' => 'map', 'home_title' => 'i18n', 'home_description' => 'i18n',
            ],
            'tax_classes' => ['code' => 'str!', 'name' => 'str!', 'is_default' => 'bool'],
            'tax_rates' => [
                'class_id' => 'ref:tax_classes!', 'country' => 'country!', 'region' => 'str', 'postcodes' => 'str', 'rate' => 'rate!', 'name' => 'str',
                'priority' => 'int', 'active' => 'bool', 'compound' => 'bool', 'applies_to_shipping' => 'bool',
            ],
            'shipping_zones' => ['name' => 'str!', 'locations' => 'list:location', 'position' => 'int+', 'active' => 'bool'],
            'carriers' => [
                'name' => 'str!', 'delay' => 'i18n', 'kind' => 'enum:rates|pickup|free!', 'tax_class_id' => 'ref:tax_classes?', 'price_includes_tax' => 'bool',
                'free_from' => 'cents?', 'max_weight' => 'int+?', 'min_days' => 'int+?', 'max_days' => 'int+?', 'tracking_url' => 'str', 'logo' => 'image?',
                'active' => 'bool', 'position' => 'int+', 'handling_fee' => 'cents', 'handling_fee_micros' => 'micros?', 'rates' => 'list:rate', 'zone_ids' => 'refs:shipping_zones',
            ],
            'payment_methods' => [
                'code' => 'str!', 'title' => 'i18n', 'enabled' => 'bool', 'sandbox' => 'bool?', 'settings' => 'secrets', 'min_amount' => 'cents?',
                'max_amount' => 'cents?', 'countries' => 'strs', 'position' => 'int+',
            ],
            'customer_groups' => ['name' => 'i18n!', 'discount' => 'rate', 'tax_exempt' => 'bool', 'is_default' => 'bool'],
            'brands' => ['name' => 'str!', 'description' => 'i18n_html', 'website' => 'str', 'logo' => 'image?', 'active' => 'bool', 'position' => 'int+', 'slug' => 'i18n'] + $seo,
            'categories' => [
                'parent_id' => 'ref:categories?', 'name' => 'i18n!', 'description' => 'i18n_html', 'slug' => 'i18n', 'position' => 'int+', 'active' => 'bool',
                'in_menu' => 'bool', 'image' => 'image?',
            ] + $seo,
            'attributes' => [
                'code' => 'str', 'name' => 'i18n!', 'type' => 'enum:select|text|number|bool|color!', 'unit' => 'str', 'variant' => 'bool', 'filterable' => 'bool',
                'visible' => 'bool', 'position' => 'int+', 'values' => 'list:attr_value',
            ],
            'products' => [
                'created_at' => 'date?', 'published_at' => 'date?', 'kind' => 'enum:physical|digital|service!', 'sku' => 'str', 'gtin' => 'str', 'mpn' => 'str',
                'brand_id' => 'ref:brands?', 'category_ids' => 'refs:categories', 'main_category_id' => 'ref:categories?', 'tax_class_id' => 'ref:tax_classes?',
                'name' => 'i18n!', 'summary' => 'i18n_html', 'description' => 'i18n_html', 'slug' => 'i18n',
                'price' => 'cents!', 'price_includes_tax' => 'bool!', 'compare_price' => 'cents?', 'sale_price' => 'cents?', 'sale_from' => 'date?', 'sale_to' => 'date?',
                'price_micros' => 'micros?', 'compare_price_micros' => 'micros?', 'sale_price_micros' => 'micros?', 'sale_reduction' => 'part:sale_reduction?',
                'cost' => 'cents?', 'manage_stock' => 'bool', 'stock' => 'int', 'backorders' => 'enum:no|notify|yes', 'min_qty' => 'int+', 'max_qty' => 'int+?',
                'weight' => 'int+', 'length' => 'int+', 'width' => 'int+', 'height' => 'int+',
                'status' => 'enum:published|draft|private!', 'visibility' => 'enum:visible|catalog|search|hidden', 'featured' => 'bool', 'reviews_allowed' => 'bool',
                'images' => 'images', 'attributes' => 'list:product_attr', 'tags' => 'i18n_list', 'files' => 'list:file', 'variants' => 'list:variant',
                'related_ids' => 'refs:products', 'upsell_ids' => 'refs:products', 'cross_sell_ids' => 'refs:products',
            ] + $seo,
            'customers' => [
                'created_at' => 'date?', 'email' => 'str!', 'first_name' => 'str', 'last_name' => 'str', 'company' => 'str', 'vat_id' => 'str', 'phone' => 'str',
                'group_id' => 'ref:customer_groups?', 'locale' => 'lang', 'active' => 'bool', 'guest' => 'bool', 'marketing' => 'bool', 'marketing_at' => 'date?',
                'password_hash' => 'secret?', 'password_scheme' => 'enum:wordpress|prestashop-md5|bcrypt|argon2|unknown', 'notes' => 'text',
                'last_login_at' => 'date?', 'addresses' => 'list:address', 'consents' => 'list:consent',
            ],
            'coupons' => [
                'code' => 'str!', 'kind' => 'enum:percent|amount|free_shipping!', 'percent' => 'rate', 'amount' => 'cents', 'amount_includes_tax' => 'bool',
                'min_subtotal' => 'cents?', 'starts_at' => 'date?', 'ends_at' => 'date?', 'max_uses' => 'int+?', 'max_uses_per_customer' => 'int+?', 'uses' => 'int+',
                'first_order_only' => 'bool', 'exclude_sale' => 'bool', 'combinable' => 'bool', 'customer_id' => 'ref:customers?',
                'product_ids' => 'refs:products', 'category_ids' => 'refs:categories', 'active' => 'bool', 'note' => 'str', 'unsupported' => 'strs',
            ],
            'orders' => [
                'number' => 'str!', 'customer_id' => 'ref:customers?', 'email' => 'str!', 'locale' => 'lang', 'currency' => 'str!', 'state' => 'str!',
                'state_meaning' => 'enum:pending|on_hold|paid|processing|shipped|delivered|cancelled|refunded|failed!',
                'payment_method' => 'str', 'payment_title' => 'str', 'carrier_id' => 'ref:carriers?', 'carrier_title' => 'str', 'tracking_code' => 'str',
                'prices_include_tax' => 'bool!', 'items_total' => 'cents!', 'discount_total' => 'cents!', 'shipping_total' => 'cents!', 'fee_total' => 'cents',
                'total_net' => 'cents!', 'total_tax' => 'cents!', 'total' => 'cents!', 'paid_total' => 'cents!', 'refunded_total' => 'cents',
                'taxes' => 'list:order_tax', 'billing' => 'part:address', 'shipping' => 'part:address', 'customer_note' => 'text',
                'placed_at' => 'date!', 'paid_at' => 'date?', 'shipped_at' => 'date?', 'completed_at' => 'date?', 'cancelled_at' => 'date?',
                'items' => 'list:order_item!', 'payments' => 'list:payment', 'refunds' => 'list:refund', 'history' => 'list:history',
                'invoices' => 'list:invoice', 'coupons' => 'list:order_coupon',
            ],
            'reviews' => [
                'product_id' => 'ref:products!', 'customer_id' => 'ref:customers?', 'author' => 'str', 'rating' => 'int!', 'title' => 'str', 'body' => 'text',
                'status' => 'enum:approved|pending|rejected!', 'verified' => 'bool', 'at' => 'date?', 'reply' => 'text', 'locale' => 'lang',
            ],
            'media' => ['url' => 'str', 'path' => 'str', 'alt' => 'i18n', 'title' => 'str', 'mime' => 'str', 'old_paths' => 'paths'],
            'pages' => [
                'title' => 'i18n!', 'slug' => 'i18n', 'content' => 'i18n_html', 'content_format' => 'enum:html|gutenberg', 'status' => 'enum:published|draft|private!',
                'parent_id' => 'ref:pages?', 'published_at' => 'date?', 'legal' => 'enum:terms|privacy|returns|cookies|legal|shipping',
            ] + $seo,
            'post_categories' => ['name' => 'i18n!', 'slug' => 'i18n', 'parent_id' => 'ref:post_categories?', 'description' => 'i18n_html', 'old_paths' => 'paths'],
            'posts' => [
                'title' => 'i18n!', 'slug' => 'i18n', 'excerpt' => 'i18n_html', 'content' => 'i18n_html', 'content_format' => 'enum:html|gutenberg',
                'status' => 'enum:published|draft|private!', 'category_ids' => 'refs:post_categories', 'tags' => 'i18n_list', 'image' => 'image?', 'author' => 'str',
                'published_at' => 'date?',
            ] + $seo,
            'menus' => ['location' => 'str!', 'name' => 'str', 'items' => 'list:menu_item'],
            'redirects' => ['source' => 'str!', 'target' => 'str!', 'code' => 'int', 'active' => 'bool'],
            'team' => [
                'email' => 'str!', 'name' => 'str!', 'role' => 'str', 'role_meaning' => 'enum:admin|manager|editor|support|other!', 'active' => 'bool',
                'locale' => 'lang', 'last_login_at' => 'date?',
            ],
            'subscribers' => ['email' => 'str!', 'name' => 'str', 'granted_at' => 'date?', 'source' => 'str', 'confirmed' => 'bool', 'locale' => 'lang'],
        ];
        foreach ($types as $type => $fields) {
            $types[$type] = self::COMMON + $fields;
        }

        return $types;
    }

    /**
     * Las partes: trozos que van dentro de un registro. No llevan los campos comunes.
     *
     * @return array<string,array<string,string>>
     */
    public static function parts(): array
    {
        return [
            // Una imagen: al menos «url» o «path» (fichero local en modo copia). «cover» = es la principal.
            'image' => ['url' => 'str', 'path' => 'str', 'alt' => 'i18n', 'position' => 'int+', 'cover' => 'bool'],
            'address' => [
                'id' => 'str', 'label' => 'str', 'first_name' => 'str', 'last_name' => 'str', 'company' => 'str', 'vat_id' => 'str', 'phone' => 'str',
                'line1' => 'str', 'line2' => 'str', 'postcode' => 'str', 'city' => 'str', 'region' => 'str', 'country' => 'country',
                'default_shipping' => 'bool', 'default_billing' => 'bool',
            ],
            'mail' => [
                'transport' => 'enum:smtp|php|other', 'host' => 'str', 'port' => 'int+', 'encryption' => 'enum:none|ssl|tls', 'username' => 'str',
                'password' => 'secret', 'from_email' => 'str', 'from_name' => 'str',
            ],
            // region: código ISO 3166-2 sin el país («B», «TF») o vacío; postcodes: como tax_rates.postcodes («35,38», «51000-51999»).
            'location' => ['country' => 'country!', 'region' => 'str', 'postcodes' => 'str'],
            // min y max: gramos si basis = weight, céntimos si basis = subtotal; max null = sin tope. price: lo que cuesta ese tramo.
            'rate' => ['zone_id' => 'ref:shipping_zones!', 'basis' => 'enum:weight|subtotal|flat!', 'min' => 'int+', 'max' => 'int+?', 'price' => 'cents!', 'price_micros' => 'micros?'],
            // Una rebaja por importe sobre el precio del producto cuyo tipo de impuesto el lector no conoce (PrestaShop: reduction_tax = 1): amount es el
            // redondeo de amount_micros; includes_tax dice si ese importe lleva impuestos. La resta la hace el escritor, que sí conoce el tipo.
            'sale_reduction' => ['amount' => 'cents!', 'amount_micros' => 'micros?', 'includes_tax' => 'bool!'],
            'attr_value' => ['id' => 'id!', 'label' => 'i18n!', 'color' => 'str?', 'position' => 'int+'],
            // price: precio FINAL de la variante (no la diferencia con el producto), con el mismo criterio de impuestos que su producto.
            'variant' => [
                'id' => 'id!', 'sku' => 'str', 'gtin' => 'str', 'price' => 'cents!', 'compare_price' => 'cents?', 'price_micros' => 'micros?', 'compare_price_micros' => 'micros?',
                'cost' => 'cents?', 'stock' => 'int', 'weight' => 'int+',
                'image_index' => 'int+?', 'active' => 'bool', 'position' => 'int+', 'values' => 'list:variant_value!',
            ],
            'variant_value' => ['attribute_id' => 'ref:attributes!', 'value_id' => 'str!'],
            // Un dato del producto: valores de lista (value_ids), o texto libre, o número.
            'product_attr' => ['attribute_id' => 'ref:attributes!', 'value_ids' => 'strs', 'text' => 'i18n', 'number' => 'float'],
            'file' => ['name' => 'str!', 'url' => 'str', 'path' => 'str', 'version' => 'str', 'bytes' => 'int+'],
            'consent' => ['kind' => 'enum:marketing|terms|privacy|cookies|other!', 'granted' => 'bool!', 'at' => 'date?', 'context' => 'str'],
            'order_item' => [
                'kind' => 'enum:product|shipping|fee|discount!', 'product_id' => 'ref:products?', 'variant_id' => 'str?', 'sku' => 'str', 'name' => 'str!',
                'variant_label' => 'str', 'qty' => 'int!', 'unit_price' => 'cents!', 'discount' => 'cents', 'line_total' => 'cents!', 'line_net' => 'cents!',
                'line_tax' => 'cents!', 'tax_rate' => 'rate', 'tax_name' => 'str', 'weight' => 'int+', 'is_digital' => 'bool', 'refunded_qty' => 'int+',
                'refunded_total' => 'cents',
            ],
            'order_tax' => ['name' => 'str', 'rate' => 'rate', 'base' => 'cents', 'amount' => 'cents!'],
            'payment' => ['method' => 'str!', 'kind' => 'enum:charge|refund!', 'reference' => 'str', 'amount' => 'cents!', 'status' => 'enum:ok|pending|failed', 'at' => 'date?'],
            'refund' => ['amount' => 'cents!', 'tax' => 'cents', 'reason' => 'str', 'at' => 'date?', 'restock' => 'bool', 'lines' => 'list:refund_line'],
            // index: posición de la línea dentro de «items» del pedido (0 = la primera).
            'refund_line' => ['index' => 'int+!', 'qty' => 'int+', 'amount' => 'cents'],
            'history' => ['at' => 'date!', 'kind' => 'enum:state|note|mail|other!', 'from' => 'str', 'to' => 'str', 'note' => 'text', 'public' => 'bool', 'actor' => 'str'],
            'invoice' => ['number' => 'str!', 'date' => 'date?', 'kind' => 'enum:invoice|credit!'],
            'order_coupon' => ['code' => 'str!', 'amount' => 'cents'],
            // ref: identificador de origen de lo enlazado (según type); url: solo si type = url.
            'menu_item' => [
                'label' => 'i18n!', 'type' => 'enum:page|post|category|product|post_category|brand|url|home!', 'ref' => 'str', 'url' => 'str',
                'children' => 'list:menu_item',
            ],
        ];
    }
}

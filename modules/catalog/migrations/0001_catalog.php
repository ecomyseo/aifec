<?php
/**
 * AIFEC — esquema del catálogo: productos, variantes, atributos, categorías, marcas, etiquetas y ficheros.
 *
 * Importes en céntimos y «tal como se escriben» (con o sin impuestos según el ajuste tax.prices_include_tax).
 * Pesos en gramos, medidas en milímetros. Los textos por idioma van en las tablas *_i18n.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;

return static function (Schema $schema, Db $db): void {
    // kind: physical (se envía), digital (se descarga), service (ni una cosa ni otra).
    // status: draft, published, archived. visibility: everywhere, catalog (no sale en el buscador), search, hidden (solo con el enlace).
    // backorders: no, notify (deja apuntarse), yes (se vende sin existencias).
    $schema->create('products', static function (TableDef $t): void {
        $t->id();
        $t->string('kind', 16)->default('physical');
        $t->boolean('has_variants')->default(0);
        $t->string('sku', 64)->nullable();
        $t->string('gtin', 32)->nullable();
        $t->string('mpn', 64)->nullable();
        $t->bigInteger('brand_id')->nullable();
        $t->bigInteger('family_id')->nullable();
        $t->bigInteger('tax_class_id')->nullable();
        $t->integer('price')->default(0);
        $t->integer('compare_price')->nullable();
        $t->integer('sale_price')->nullable();
        $t->datetime('sale_from')->nullable();
        $t->datetime('sale_to')->nullable();
        $t->integer('cost')->nullable();
        $t->boolean('manage_stock')->default(1);
        $t->integer('stock')->default(0);
        $t->integer('stock_alert')->default(0);
        $t->string('backorders', 8)->default('no');
        $t->integer('weight')->default(0);
        $t->integer('length')->default(0);
        $t->integer('width')->default(0);
        $t->integer('height')->default(0);
        $t->integer('min_qty')->default(1);
        $t->integer('max_qty')->default(0);
        $t->integer('delivery_min_days')->nullable();
        $t->integer('delivery_max_days')->nullable();
        $t->string('status', 12)->default('draft');
        $t->string('visibility', 12)->default('everywhere');
        $t->boolean('featured')->default(0);
        $t->bigInteger('main_media_id')->nullable();
        $t->integer('position')->default(0);
        $t->integer('rating_sum')->default(0);
        $t->integer('rating_count')->default(0);
        $t->integer('sales_count')->default(0);
        $t->text('extra')->nullable();
        $t->datetime('published_at')->nullable();
        $t->timestamps();
        $t->index(['status', 'visibility']);
        $t->index(['sku']);
        $t->index(['brand_id']);
        $t->index(['featured', 'status']);
    });

    // facts: lista JSON de datos comprobables [{"label":"Material","value":"Roble macizo"}] (lo que una IA puede citar).
    $schema->create('product_i18n', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('product_id');
        $t->string('lang', 8);
        $t->string('name', 191);
        $t->text('summary')->nullable();
        $t->text('description')->nullable();
        $t->text('facts')->nullable();
        $t->string('meta_title', 191)->nullable();
        $t->string('meta_description', 320)->nullable();
        $t->unique(['product_id', 'lang']);
        $t->index(['lang', 'name']);
    });

    // Una variante es lo que de verdad se compra cuando el producto tiene variantes. price nulo = el del producto.
    $schema->create('variants', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('product_id');
        $t->string('sku', 64)->nullable();
        $t->string('gtin', 32)->nullable();
        $t->integer('price')->nullable();
        $t->integer('compare_price')->nullable();
        $t->integer('cost')->nullable();
        $t->integer('stock')->default(0);
        $t->integer('weight')->nullable();
        $t->bigInteger('media_id')->nullable();
        $t->string('label', 191)->nullable();
        $t->integer('position')->default(0);
        $t->boolean('active')->default(1);
        $t->timestamps();
        $t->index(['product_id', 'position']);
        $t->index(['sku']);
    });

    $schema->create('variant_values', static function (TableDef $t): void {
        $t->bigInteger('variant_id');
        $t->bigInteger('attribute_id');
        $t->bigInteger('value_id');
        $t->primary(['variant_id', 'attribute_id']);
        $t->index(['value_id']);
    });

    // type: select, multiselect, text, number, bool, color. variant = sirve para crear variantes (talla, color).
    $schema->create('attributes', static function (TableDef $t): void {
        $t->id();
        $t->string('code', 64);
        $t->string('type', 16)->default('select');
        $t->string('unit', 16)->nullable();
        $t->boolean('filterable')->default(1);
        $t->boolean('visible')->default(1);
        $t->boolean('variant')->default(0);
        $t->integer('position')->default(0);
        $t->unique(['code']);
    });

    $schema->create('attribute_i18n', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('attribute_id');
        $t->string('lang', 8);
        $t->string('name', 120);
        $t->unique(['attribute_id', 'lang']);
    });

    $schema->create('attribute_values', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('attribute_id');
        $t->string('code', 64);
        $t->string('color', 7)->nullable();
        $t->bigInteger('media_id')->nullable();
        $t->integer('position')->default(0);
        $t->unique(['attribute_id', 'code']);
    });

    $schema->create('attribute_value_i18n', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('value_id');
        $t->string('lang', 8);
        $t->string('label', 120);
        $t->unique(['value_id', 'lang']);
    });

    // Familia = tipo de producto: qué atributos se piden al dar de alta uno (una silla no pide lo mismo que un programa).
    $schema->create('families', static function (TableDef $t): void {
        $t->id();
        $t->string('name', 120);
        $t->integer('position')->default(0);
    });

    $schema->create('family_attributes', static function (TableDef $t): void {
        $t->bigInteger('family_id');
        $t->bigInteger('attribute_id');
        $t->integer('position')->default(0);
        $t->primary(['family_id', 'attribute_id']);
    });

    // Valor de un atributo en un producto: value_id (lista), value_text (texto; lang nulo = igual en todos) o value_number.
    $schema->create('product_attributes', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('product_id');
        $t->bigInteger('attribute_id');
        $t->bigInteger('value_id')->nullable();
        $t->string('lang', 8)->nullable();
        $t->text('value_text')->nullable();
        $t->decimal('value_number', 14, 4)->nullable();
        $t->index(['product_id']);
        $t->index(['attribute_id', 'value_id']);
    });

    // path = identificadores desde la raíz («3/12/40»), para sacar un árbol o una rama con una consulta.
    $schema->create('categories', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('parent_id')->nullable();
        $t->string('path', 191)->default('');
        $t->integer('depth')->default(0);
        $t->integer('position')->default(0);
        $t->boolean('active')->default(1);
        $t->boolean('in_menu')->default(1);
        $t->bigInteger('media_id')->nullable();
        $t->integer('product_count')->default(0);
        $t->timestamps();
        $t->index(['parent_id', 'position']);
        $t->index(['path']);
    });

    $schema->create('category_i18n', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('category_id');
        $t->string('lang', 8);
        $t->string('name', 191);
        $t->text('description')->nullable();
        $t->text('facts')->nullable();
        $t->string('meta_title', 191)->nullable();
        $t->string('meta_description', 320)->nullable();
        $t->unique(['category_id', 'lang']);
    });

    $schema->create('product_categories', static function (TableDef $t): void {
        $t->bigInteger('product_id');
        $t->bigInteger('category_id');
        $t->boolean('is_main')->default(0);
        $t->integer('position')->default(0);
        $t->primary(['product_id', 'category_id']);
        $t->index(['category_id', 'position']);
    });

    $schema->create('brands', static function (TableDef $t): void {
        $t->id();
        $t->string('name', 120);
        $t->text('description')->nullable();
        $t->string('website', 191)->nullable();
        $t->bigInteger('media_id')->nullable();
        $t->boolean('active')->default(1);
        $t->integer('position')->default(0);
        $t->timestamps();
        $t->index(['name']);
    });

    $schema->create('tags', static function (TableDef $t): void {
        $t->id();
        $t->string('lang', 8);
        $t->string('name', 80);
        $t->unique(['lang', 'name']);
    });

    $schema->create('product_tags', static function (TableDef $t): void {
        $t->bigInteger('product_id');
        $t->bigInteger('tag_id');
        $t->primary(['product_id', 'tag_id']);
        $t->index(['tag_id']);
    });

    // kind: related (parecidos), upsell (mejor), cross (se compra junto), accessory.
    $schema->create('product_relations', static function (TableDef $t): void {
        $t->bigInteger('product_id');
        $t->bigInteger('related_id');
        $t->string('kind', 12)->default('related');
        $t->integer('position')->default(0);
        $t->primary(['product_id', 'related_id', 'kind']);
    });

    $schema->create('product_faqs', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('product_id');
        $t->string('lang', 8);
        $t->string('question', 255);
        $t->text('answer');
        $t->integer('position')->default(0);
        $t->index(['product_id', 'lang', 'position']);
    });

    // Ficheros que se entregan al comprar un producto digital. path = ruta dentro de la carpeta privada del sitio.
    $schema->create('product_files', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('product_id');
        $t->bigInteger('variant_id')->nullable();
        $t->string('name', 191);
        $t->string('path', 255);
        $t->integer('bytes')->default(0);
        $t->string('version', 40)->nullable();
        $t->integer('position')->default(0);
        $t->datetime('created_at');
        $t->index(['product_id', 'position']);
    });

    $schema->create('product_media', static function (TableDef $t): void {
        $t->bigInteger('product_id');
        $t->bigInteger('media_id');
        $t->bigInteger('variant_id')->nullable();
        $t->integer('position')->default(0);
        $t->primary(['product_id', 'media_id']);
        $t->index(['product_id', 'position']);
    });
};

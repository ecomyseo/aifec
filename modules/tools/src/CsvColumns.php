<?php
/**
 * AIFEC — columnas del CSV de productos: las que se escriben al exportar y cómo se reconoce cada cabecera al importar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Kernel\App;

/**
 * Columnas (las mismas al exportar y al importar; el orden da igual al importar):
 *
 *   sku              referencia; clave con la que se casa cada fila (obligatoria)
 *   parent_sku       solo en las filas de variantes: la referencia del producto al que pertenecen
 *   name             nombre (en una fila de variante, su etiqueta)
 *   summary          resumen (HTML)
 *   description      descripción (HTML)
 *   price            precio, con coma decimal («19,99»)
 *   compare_price    precio anterior
 *   sale_price       precio de oferta
 *   stock            existencias
 *   status           draft, published o archived
 *   categories       rutas separadas por «|»; los niveles de una ruta, por «>»: «Muebles > Sillas|Hogar»; la primera es la principal
 *   brand            nombre de la marca
 *   tax_class        código de la clase de impuesto
 *   weight           peso en gramos
 *   gtin, mpn        códigos del producto
 *   images           direcciones de las imágenes separadas por «|»; la primera es la principal
 *   attribute:<código>  valor de un atributo; en las filas de variantes, la opción de la variante
 *   meta_title, meta_description   textos para buscadores
 *   name:<idioma>    nombre en otro idioma de la tienda
 *
 * Una celda vacía no cambia nada: una fila de un producto que ya existe solo toca lo que trae escrito.
 */
final class CsvColumns
{
    /** Columnas fijas, en el orden en que se exportan (los atributos van detrás de «images»). */
    public const FIXED = [
        'sku', 'parent_sku', 'name', 'summary', 'description', 'price', 'compare_price', 'sale_price', 'stock', 'status',
        'categories', 'brand', 'tax_class', 'weight', 'gtin', 'mpn', 'images',
    ];

    /** Columnas fijas que van detrás de los atributos. */
    public const AFTER_ATTRIBUTES = ['meta_title', 'meta_description'];

    /** Código de atributo válido. */
    public const ATTRIBUTE_CODE = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /**
     * Las cabeceras de un fichero de exportación de este sitio, en orden.
     *
     * @return string[]
     */
    public static function exportHeader(App $app): array
    {
        $header = self::FIXED;
        foreach (self::attributeCodes($app) as $code) {
            $header[] = 'attribute:' . $code;
        }
        foreach (self::AFTER_ATTRIBUTES as $name) {
            $header[] = $name;
        }
        foreach ($app->languages() as $lang) {
            if ($lang !== $app->defaultLang()) {
                $header[] = 'name:' . $lang;
            }
        }

        return $header;
    }

    /**
     * Códigos de todos los atributos del sitio.
     *
     * @return string[]
     */
    public static function attributeCodes(App $app): array
    {
        if (!$app->db()->tableExists('attributes')) {
            return [];
        }

        return array_map('strval', $app->db()->column('SELECT code FROM {attributes} ORDER BY position, id'));
    }

    /**
     * Qué es cada columna de un fichero. Lo que no se reconoce se devuelve aparte (se avisa y se ignora).
     *
     * @param string[] $header
     *
     * @return array{columns:array<int,array<string,string>>,unknown:string[],new_attributes:string[]}
     *         columns: posición => ['kind' => fixed|attribute|name, 'key' => nombre | código | idioma]
     */
    public static function analyse(array $header, App $app): array
    {
        $out = ['columns' => [], 'unknown' => [], 'new_attributes' => []];
        $existing = [];
        foreach (self::attributeCodes($app) as $code) {
            $existing[strtolower($code)] = true;
        }
        $languages = $app->languages();
        $seen = [];
        foreach ($header as $i => $raw) {
            $name = mb_strtolower(trim((string) $raw), 'UTF-8');
            if ($name === '') {
                continue;
            }
            if (isset($seen[$name])) {
                $out['unknown'][] = (string) $raw;
                continue;
            }
            $column = null;
            if (in_array($name, self::FIXED, true) || in_array($name, self::AFTER_ATTRIBUTES, true)) {
                $column = ['kind' => 'fixed', 'key' => $name];
            } elseif (strpos($name, 'attribute:') === 0) {
                $code = trim(substr($name, 10));
                if (preg_match(self::ATTRIBUTE_CODE, $code) === 1) {
                    $column = ['kind' => 'attribute', 'key' => $code];
                    if (!isset($existing[$code]) && !in_array($code, $out['new_attributes'], true)) {
                        $out['new_attributes'][] = $code;
                    }
                }
            } elseif (strpos($name, 'name:') === 0) {
                $lang = trim(substr($name, 5));
                if (in_array($lang, $languages, true)) {
                    $column = $lang === $app->defaultLang() ? ['kind' => 'fixed', 'key' => 'name'] : ['kind' => 'name', 'key' => $lang];
                }
            }
            if ($column === null) {
                $out['unknown'][] = (string) $raw;
                continue;
            }
            $seen[$name] = true;
            $out['columns'][(int) $i] = $column;
        }

        return $out;
    }

    /**
     * ¿Hay columna «sku»?
     *
     * @param array<int,array<string,string>> $columns
     */
    public static function hasSku(array $columns): bool
    {
        foreach ($columns as $column) {
            if ($column['kind'] === 'fixed' && $column['key'] === 'sku') {
                return true;
            }
        }

        return false;
    }
}

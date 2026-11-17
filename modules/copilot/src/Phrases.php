<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot;

/**
 * Las palabras que se repiten en las órdenes de varias acciones (subir, bajar, producto, precio…) y la manera de quedarse con
 * lo que nombra la persona cuando se quita todo lo demás.
 *
 * Todo en texto normalizado (minúsculas y sin tildes), en castellano y en inglés, para usarlo con Reader::has().
 */
final class Phrases
{
    public const UP = 'sube|subir|subele|aumenta|aumentar|incrementa|incrementar|encarece|encarecer|increase|raise|rise|plus|mas caro|more expensive';

    public const DOWN = 'baja|bajar|bajale|reduce|reducir|rebaja|rebajar|descuenta|descontar|abarata|abaratar|decrease|lower|cut|drop|discount|menos|minus|mas barato|cheaper';

    public const STOCK = 'stock|existencias|existencia|unidades|unidad|units|unit|inventario|inventory|piezas';

    public const PRICE = 'precio|precios|price|prices|cuesta|cuestan|cost|costs|vale|valor';

    /** Lo que habla de envíos: un precio que lo lleva es el de una tarifa, no el de un producto. */
    public const SHIPPING = 'tarifa|tarifas|rate|rates|envio|envios|shipping|portes|transporte|transportista|carrier';

    /** Lo que sobra por delante de lo que se nombra: verbos, artículos, preposiciones y palabras de relleno. */
    private const LEADING = [
        'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'de', 'del', 'al', 'a', 'en', 'por', 'con', 'para', 'y', 'e', 'o',
        'the', 'of', 'to', 'at', 'by', 'for', 'and', 'an', 'on', 'in', 'my', 'mi', 'mis', 'tu', 'su', 'este', 'esta', 'ese', 'esa', 'this', 'that',
        'pon', 'poner', 'ponme', 'pone', 'cambia', 'cambiar', 'cambiame', 'fija', 'fijar', 'ajusta', 'ajustar', 'actualiza', 'actualizar',
        'establece', 'establecer', 'modifica', 'modificar', 'set', 'change', 'update', 'adjust', 'make', 'edit', 'edita',
        'sube', 'subir', 'baja', 'bajar', 'aumenta', 'aumentar', 'reduce', 'reducir', 'rebaja', 'rebajar', 'incrementa', 'increase', 'raise', 'lower',
        'cut', 'drop', 'decrease', 'discount', 'descuenta', 'resta', 'restar', 'quita', 'quitar', 'suma', 'sumar', 'anade', 'anadir', 'agrega',
        'agregar', 'add', 'remove', 'subtract', 'restock', 'repone', 'reponer', 'mete', 'meter',
        'activa', 'activar', 'desactiva', 'desactivar', 'oculta', 'ocultar', 'publica', 'publicar', 'despublica', 'habilita', 'deshabilita',
        'enable', 'disable', 'activate', 'deactivate', 'hide', 'show', 'publish', 'unpublish', 'muestra', 'mostrar',
        'precio', 'precios', 'price', 'prices', 'stock', 'existencias', 'existencia', 'unidades', 'unidad', 'units', 'unit', 'inventario', 'inventory',
        'producto', 'productos', 'articulo', 'articulos', 'product', 'products', 'item', 'items', 'cuesta', 'vale', 'cost', 'costs',
        'quiero', 'necesito', 'please', 'favor', 'porfa', 'gracias', 'que', 'quisiera', 'puedes', 'podrias', 'can', 'you', 'could', 'would', 'i', 'want',
        'todos', 'todas', 'all', 'every', 'cada', 'hay', 'mas', 'menos', 'en', 'stock',
    ];

    /** Lo que sobra por detrás. */
    private const TRAILING = ['a', 'en', 'to', 'at', 'por', 'by', 'de', 'del', 'para', 'for', 'and', 'y', 'with', 'con', 'el', 'la', 'the', 'euros', 'euro', 'eur'];

    /**
     * Lo que nombra la persona (un producto, un transportista…) una vez quitado todo lo demás. Un texto entre comillas manda: se
     * devuelve tal cual lo escribió.
     *
     * @param string $raw  el texto de la persona
     * @param string $norm el texto normalizado SIN lo ya consumido (importes, porcentajes…)
     */
    public static function name(string $raw, string $norm): string
    {
        $quoted = Reader::capture($raw, Reader::fold($raw), '/["“«]([^"“«”»]{2,120})["”»]/u');
        if ($quoted !== null && $quoted !== '') {
            return $quoted;
        }

        return Reader::trimWords($norm, self::LEADING, self::TRAILING);
    }

    /** ¿Habla de un conjunto de productos (categoría, marca, «todos»…) y no de uno? */
    public static function isBulk(string $norm): bool
    {
        return Reader::has($norm, 'categoria|categorias|category|categories|marca de|marcas|de la marca|brand|brands|todos los productos|todos los precios|todos mis productos|todas las|all products|all prices|all the products|todo el catalogo|whole catalog|toda la tienda|los precios|the prices');
    }
}

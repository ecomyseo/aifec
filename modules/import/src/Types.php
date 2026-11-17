<?php
/**
 * AIFEC — los tipos de una migración: en qué orden se traen, qué módulo hace falta para cada uno y cuáles llevan
 * datos personales.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

final class Types
{
    /**
     * Orden de escritura: cada tipo va detrás de los que referencia (un producto necesita sus categorías, un pedido a
     * su cliente). Las referencias dentro del mismo tipo (categoría padre, productos relacionados) se resuelven en la
     * segunda pasada.
     */
    public const ORDER = [
        'settings', 'tax_classes', 'tax_rates', 'shipping_zones', 'carriers', 'payment_methods', 'customer_groups',
        'brands', 'categories', 'attributes', 'products', 'customers', 'coupons', 'orders', 'reviews',
        'media', 'pages', 'post_categories', 'posts', 'menus', 'redirects', 'team', 'subscribers',
    ];

    /** Tipos con segunda pasada (TypeWriter::link). */
    public const LINKED = ['categories', 'products', 'pages', 'post_categories', 'menus'];

    /** Tipos con datos de personas: en el asistente van en casillas aparte y nunca provocan un correo. */
    public const PERSONAL = ['customers', 'orders', 'reviews', 'team', 'subscribers'];

    /** Tipos de configuración: se pueden traer sin el resto, y al repetirlos se actualizan sin mirar si se editaron aquí. */
    public const CONFIG = ['settings', 'tax_classes', 'tax_rates', 'shipping_zones', 'carriers', 'payment_methods', 'customer_groups'];

    /**
     * Módulos de AIFEC que tienen que estar activos para escribir cada tipo. Si falta alguno, el tipo se salta entero
     * y el informe lo dice.
     *
     * @return array<string,string[]>
     */
    public static function requires(): array
    {
        return [
            'settings' => [], 'tax_classes' => ['tax'], 'tax_rates' => ['tax'], 'shipping_zones' => ['shipping'], 'carriers' => ['shipping'],
            'payment_methods' => ['orders'], 'customer_groups' => ['customers'], 'brands' => ['catalog'], 'categories' => ['catalog'],
            'attributes' => ['catalog'], 'products' => ['catalog'], 'customers' => ['customers'], 'coupons' => ['coupons'], 'orders' => ['orders'],
            'reviews' => ['reviews'], 'media' => ['media'], 'pages' => ['cms'], 'post_categories' => ['cms'], 'posts' => ['cms'], 'menus' => ['cms'],
            'redirects' => [], 'team' => [], 'subscribers' => ['customers'],
        ];
    }

    /**
     * Nombre de cada tipo para las pantallas y el informe (cadenas fuente en inglés; se enseñan con t()).
     *
     * @return array<string,string>
     */
    public static function labels(): array
    {
        return [
            'settings' => 'Shop settings', 'tax_classes' => 'Tax classes', 'tax_rates' => 'Tax rates', 'shipping_zones' => 'Shipping zones',
            'carriers' => 'Carriers and rates', 'payment_methods' => 'Payment methods', 'customer_groups' => 'Customer groups', 'brands' => 'Brands',
            'categories' => 'Categories', 'attributes' => 'Attributes and features', 'products' => 'Products', 'customers' => 'Customers',
            'coupons' => 'Coupons', 'orders' => 'Orders', 'reviews' => 'Reviews', 'media' => 'Media library', 'pages' => 'Pages',
            'post_categories' => 'Blog categories', 'posts' => 'Blog posts', 'menus' => 'Menus', 'redirects' => 'Redirects', 'team' => 'Team members',
            'subscribers' => 'Newsletter subscribers',
        ];
    }

    public static function valid(string $type): bool
    {
        return in_array($type, self::ORDER, true);
    }

    /**
     * Ordena una lista de tipos según ORDER y quita los que no existen.
     *
     * @param string[] $types
     *
     * @return string[]
     */
    public static function sorted(array $types): array
    {
        return array_values(array_filter(self::ORDER, static function (string $type) use ($types): bool {
            return in_array($type, $types, true);
        }));
    }
}

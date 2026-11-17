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
 * Un LIKE sin sorpresas: «%» y «_» de lo que escribe la persona valen como letras, no como comodines.
 * El carácter de escape es «!», que se escribe igual en SQLite y en MySQL (la barra invertida no).
 *
 *   $db->select('SELECT id FROM {products} WHERE sku LIKE ?' . Like::ESC, ['%' . Like::escape($text) . '%']);
 */
final class Like
{
    /** Lo que va detrás del LIKE para que el escape valga. */
    public const ESC = " ESCAPE '!'";

    public static function escape(string $text): string
    {
        return strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    /** «%texto%» ya escapado, listo para un parámetro. */
    public static function contains(string $text): string
    {
        return '%' . self::escape($text) . '%';
    }
}

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders;

use Aifec\Db\Db;
use Aifec\Kernel\App;

/**
 * Contadores correlativos (tabla sequences): el número de pedido y, más adelante, el de factura.
 *
 * next() avanza el contador con una sola sentencia condicionada dentro de una transacción: dos pedidos a la vez no
 * reciben el mismo número. Si la transacción de fuera se deshace, el contador vuelve atrás con ella (no quedan huecos).
 */
final class Sequence
{
    public const DEFAULT_FORMAT = '{year}-{number:6}';

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** El siguiente valor del contador (el primero es 1). */
    public function next(string $name): int
    {
        return (int) $this->app->db()->transaction(static function (Db $db) use ($name): int {
            $db->insertIgnore('sequences', ['name' => $name, 'value' => 0]);
            $db->run('UPDATE {sequences} SET value = value + 1 WHERE name = ?', [$name]);

            return (int) $db->value('SELECT value FROM {sequences} WHERE name = ?', [$name]);
        });
    }

    /** El valor actual sin avanzarlo (0 si el contador no existe todavía). */
    public function current(string $name): int
    {
        return (int) $this->app->db()->value('SELECT value FROM {sequences} WHERE name = ?', [$name]);
    }

    /**
     * Aplica el formato del ajuste: {year} es el año y {number:6} el número con ceros a la izquierda hasta 6 cifras
     * ({number} a secas, sin ceros). Un formato sin {number} se completa con «-{number:6}» para que nunca se repita un
     * número, y un resultado de más de 32 letras (lo que cabe en la columna) vuelve al formato de serie.
     */
    public static function format(string $format, int $number, int $year): string
    {
        $format = trim($format);
        if ($format === '' || strpos($format, '{number') === false) {
            $format = ($format === '' ? '' : $format . '-') . '{number:6}';
        }
        $out = self::apply($format, $number, $year);
        if (mb_strlen($out, 'UTF-8') > 32) {
            $out = self::apply(self::DEFAULT_FORMAT, $number, $year);
        }

        return $out;
    }

    private static function apply(string $format, int $number, int $year): string
    {
        $out = str_replace('{year}', (string) $year, $format);
        $replaced = preg_replace_callback('/\{number(?::(\d{1,2}))?\}/', static function (array $m) use ($number): string {
            $width = isset($m[1]) ? (int) $m[1] : 0;

            return str_pad((string) $number, $width, '0', STR_PAD_LEFT);
        }, $out);

        return is_string($replaced) ? $replaced : $out;
    }
}

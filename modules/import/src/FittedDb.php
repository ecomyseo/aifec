<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

use Aifec\Db\Db;

/**
 * La base de datos tal como la ven los escritores: lo mismo que Db, pero cada texto que se inserta o se actualiza en una columna de texto
 * corto se ajusta antes a lo que cabe en esa columna (ColumnWidths). MariaDB rechaza lo largo con «Data too long» y el registro entero
 * se descartaba; SQLite no avisa de nada. Aquí lo largo se recorta, y quien lo pidió se entera por la función que recibe (una nota por
 * registro, no una por texto).
 *
 * Solo toca los valores a escribir, nunca las condiciones (where). Todo lo demás (select, row, value, transaction…) pasa tal cual a Db.
 */
final class FittedDb
{
    /** @var Db */
    private $db;

    /** @var ColumnWidths */
    private $widths;

    /** @var callable function (string $table, string $column, int $from, int $to): void */
    private $onCut;

    public function __construct(Db $db, ColumnWidths $widths, callable $onCut)
    {
        $this->db = $db;
        $this->widths = $widths;
        $this->onCut = $onCut;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        return $this->db->insert($table, $this->fit($table, $data));
    }

    /**
     * @param array<string,mixed> $data
     */
    public function insertIgnore(string $table, array $data): bool
    {
        return $this->db->insertIgnore($table, $this->fit($table, $data));
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        return $this->db->update($table, $this->fit($table, $data), $where);
    }

    /**
     * @param array<string,mixed> $data
     * @param string[]            $uniqueKeys
     */
    public function upsert(string $table, array $data, array $uniqueKeys): void
    {
        $this->db->upsert($table, $this->fit($table, $data), $uniqueKeys);
    }

    /**
     * Un texto ajustado a lo que cabe en esa columna (y avisado, como al insertar): para lo que hay que buscar con el mismo valor que se va a guardar.
     */
    public function fitText(string $table, string $column, string $text): string
    {
        $width = $this->widths->of($table, $column);
        $length = mb_strlen($text, 'UTF-8');
        if ($width === null || $length <= $width) {
            return $text;
        }
        ($this->onCut)($table, $column, $length, $width);

        return self::cut($text, $width);
    }

    /**
     * Cualquier otra operación de Db, sin tocar nada.
     *
     * @param array<int,mixed> $arguments
     *
     * @return mixed
     */
    public function __call(string $name, array $arguments)
    {
        return $this->db->{$name}(...$arguments);
    }

    /**
     * Los valores con cada texto largo ajustado a su columna.
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function fit(string $table, array $data): array
    {
        foreach ($data as $column => $value) {
            if (!is_string($value)) {
                continue;
            }
            $width = $this->widths->of($table, (string) $column);
            if ($width === null) {
                continue;
            }
            $length = mb_strlen($value, 'UTF-8');
            if ($length <= $width) {
                continue;
            }
            $data[$column] = self::cut($value, $width);
            ($this->onCut)($table, (string) $column, $length, $width);
        }

        return $data;
    }

    /**
     * Un texto recortado a $max caracteres (no bytes: una ñ, una tilde o un emoji cuentan uno). Se corta donde acaba una palabra si esa
     * palabra está en la segunda mitad del hueco; si no hay dónde, en seco. Nunca deja delante de la nada una coma, un guion o un espacio,
     * ni la mitad de un emoji compuesto.
     */
    public static function cut(string $text, int $max): string
    {
        $max = max(1, $max);
        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }
        $head = mb_substr($text, 0, $max, 'UTF-8');
        $next = mb_substr($text, $max, 1, 'UTF-8');
        if (preg_match('/\s/u', $next) !== 1 && preg_match('/^(.*\S)\s+\S*$/su', $head, $found) === 1 && mb_strlen($found[1], 'UTF-8') >= (int) ceil($max / 2)) {
            $head = $found[1];
        }
        $clean = (string) preg_replace('/[\s,;:\-\/(\x{2013}\x{2014}\x{00AB}"\'\x{200D}\x{FE0F}\x{FE0E}]+$/u', '', $head);

        return $clean !== '' ? $clean : $head;
    }
}

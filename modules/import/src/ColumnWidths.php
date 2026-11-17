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
 * La anchura (en caracteres) de las columnas de texto corto de las tablas de aquí, leída del esquema y no de una lista escrita a mano.
 *
 * En MySQL y MariaDB sale de lo que dice el propio servidor (information_schema): es lo que de verdad rechaza un texto largo con «Data too
 * long». SQLite no guarda longitudes (todo es TEXT), así que ahí se leen de las migraciones que crearon las tablas, que son las que las
 * declaran: `$t->string('nombre', 191)`, las columnas añadidas después y los ensanchamientos (`widenString`). Una columna de la que no se sabe
 * nada no tiene tope: no se recorta.
 *
 * Los nombres de tabla son los lógicos, sin el prefijo («products», no «af_products»).
 */
final class ColumnWidths
{
    /** Anchura de un texto corto cuando la migración no dice otra (la misma que TableDef::string). */
    private const DEFAULT = 191;

    /** @var array<string,array<string,int>>|null tabla => columna => anchura, leído de las migraciones (una vez por proceso) */
    private static $declared;

    /** @var Db */
    private $db;

    /** @var array<string,array<string,int>> lo que ya dijo el servidor, por tabla */
    private $live = [];

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * Cuántos caracteres caben en esa columna, o null si no es de texto corto o no se sabe.
     */
    public function of(string $table, string $column): ?int
    {
        if ($this->db->driver() === 'mysql') {
            if (!isset($this->live[$table])) {
                $this->live[$table] = $this->fromServer($table);
            }

            return isset($this->live[$table][$column]) ? $this->live[$table][$column] : null;
        }
        $declared = self::declared();

        return isset($declared[$table][$column]) ? $declared[$table][$column] : null;
    }

    /**
     * Todas las columnas de texto corto de una tabla y su anchura.
     *
     * @return array<string,int>
     */
    public function table(string $table): array
    {
        if ($this->db->driver() === 'mysql') {
            if (!isset($this->live[$table])) {
                $this->live[$table] = $this->fromServer($table);
            }

            return $this->live[$table];
        }
        $declared = self::declared();

        return isset($declared[$table]) ? $declared[$table] : [];
    }

    /**
     * @return array<string,int>
     */
    private function fromServer(string $table): array
    {
        $rows = $this->db->select(
            "SELECT COLUMN_NAME AS c, CHARACTER_MAXIMUM_LENGTH AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND DATA_TYPE IN ('varchar', 'char')",
            [$this->db->prefix() . $table]
        );
        $out = [];
        foreach ($rows as $row) {
            if (isset($row['c'], $row['n']) && (int) $row['n'] > 0) {
                $out[(string) $row['c']] = (int) $row['n'];
            }
        }

        return $out;
    }

    /**
     * Lo que declaran las migraciones del núcleo, de los módulos y de los plugins.
     *
     * @return array<string,array<string,int>>
     */
    private static function declared(): array
    {
        if (self::$declared !== null) {
            return self::$declared;
        }
        $root = dirname(__DIR__, 3);
        $files = [];
        foreach ([$root . '/core/migrations', $root . '/modules/*/migrations', $root . '/plugins/*/migrations'] as $pattern) {
            $found = glob($pattern . '/[0-9]*_*.php');
            $found = is_array($found) ? $found : [];
            sort($found);
            $files = array_merge($files, $found);
        }
        $tables = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            if (is_string($source)) {
                self::read($source, $tables);
            }
        }
        self::$declared = $tables;

        return $tables;
    }

    /**
     * Las columnas de texto corto que declara el código de una migración.
     *
     * @param array<string,array<string,int>> $tables
     */
    public static function read(string $source, array &$tables): void
    {
        foreach (['create', 'addColumn'] as $call) {
            if (preg_match_all('/\$schema->' . $call . '\(\s*\'([a-z0-9_]+)\'/', $source, $found, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }
            foreach ($found[1] as $i => $hit) {
                $start = (int) $found[0][$i][1];
                $end = strpos($source, '$schema->', $start + 10);
                $length = ($end === false ? strlen($source) : $end) - $start;
                $block = substr($source, $start, $length);
                if (preg_match_all('/\$t->string\(\s*\'([a-z0-9_]+)\'\s*(?:,\s*(\d+)\s*)?\)/', $block, $columns, PREG_SET_ORDER) < 1) {
                    continue;
                }
                foreach ($columns as $column) {
                    $width = isset($column[2]) && $column[2] !== '' ? (int) $column[2] : self::DEFAULT;
                    $tables[(string) $hit[0]][$column[1]] = max(1, min(768, $width));
                }
            }
        }
        if (strpos($source, 'widenString') === false) {
            return;
        }
        // Un ensanchamiento suelto: $schema->widenString('tabla', 'columna', 320), o una lista de [tabla, columna, anchura].
        $patterns = ['/widenString\(\s*\'([a-z0-9_]+)\'\s*,\s*\'([a-z0-9_]+)\'\s*,\s*(\d+)/', '/\[\s*\'([a-z0-9_]+)\'\s*,\s*\'([a-z0-9_]+)\'\s*,\s*(\d+)\s*\]/'];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $source, $widen, PREG_SET_ORDER) < 1) {
                continue;
            }
            foreach ($widen as $item) {
                $width = max(1, min(768, (int) $item[3]));
                // Solo ensancha lo que ya era de texto corto y más estrecho: nunca estrecha.
                if (isset($tables[$item[1]][$item[2]]) && $tables[$item[1]][$item[2]] < $width) {
                    $tables[$item[1]][$item[2]] = $width;
                }
            }
        }
    }

    /**
     * Para las pruebas: olvida lo leído de las migraciones.
     */
    public static function forget(): void
    {
        self::$declared = null;
    }
}

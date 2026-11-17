<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Db;

/**
 * Crea y cambia tablas con el mismo código para SQLite y para MySQL/MariaDB.
 * Todas las operaciones se pueden repetir sin fallar (si la tabla, la columna o el índice ya existen, no hacen nada).
 */
final class Schema
{
    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /** Crea la tabla si no existe. */
    public function create(string $table, callable $define): void
    {
        if ($this->db->tableExists($table)) {
            return;
        }
        $def = new TableDef();
        $define($def);
        $lines = [];
        foreach ($def->columns as $column) {
            $lines[] = $this->columnSql($column);
        }
        if ($def->primary !== []) {
            $lines[] = 'PRIMARY KEY (' . implode(', ', array_map([$this->db, 'ident'], $def->primary)) . ')';
        }
        $sql = 'CREATE TABLE ' . $this->db->table($table) . " (\n  " . implode(",\n  ", $lines) . "\n)";
        if ($this->db->driver() === 'mysql') {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }
        $this->db->run($sql);
        foreach ($def->indexes as $index) {
            $this->addIndex($table, $index['columns'], $index['unique'], $index['name']);
        }
    }

    public function drop(string $table): void
    {
        $this->db->run('DROP TABLE IF EXISTS ' . $this->db->table($table));
    }

    public function hasTable(string $table): bool
    {
        return $this->db->tableExists($table);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->db->columns($table), true);
    }

    /**
     * Ensancha una columna de texto corto ya creada hasta $length caracteres. Solo hace algo en MySQL/MariaDB (SQLite no mira
     * longitudes) y solo si la columna es VARCHAR y más estrecha: nunca la estrecha. Conserva el resto de su definición (nulos,
     * valor por defecto, cotejamiento) porque la toma tal cual de la que guarda el servidor. Devuelve true si la ha cambiado.
     */
    public function widenString(string $table, string $column, int $length): bool
    {
        if ($this->db->driver() !== 'mysql' || !$this->hasTable($table) || !$this->hasColumn($table, $column)) {
            return false;
        }
        $rows = $this->db->select('SHOW CREATE TABLE ' . $this->db->table($table));
        $ddl = isset($rows[0]) && is_array($rows[0]) ? (string) end($rows[0]) : '';
        $definition = self::widenedDefinition($ddl, $column, $length);
        if ($definition === null) {
            return false;
        }
        $this->db->run('ALTER TABLE ' . $this->db->table($table) . ' MODIFY ' . $definition);

        return true;
    }

    /**
     * La definición de la columna tal como la da SHOW CREATE TABLE, con el VARCHAR ensanchado; null si la columna no está, no
     * es VARCHAR o ya es igual de ancha o más.
     */
    public static function widenedDefinition(string $ddl, string $column, int $length): ?string
    {
        $length = max(1, min(768, $length));
        if (preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
            return null;
        }
        if (preg_match('/^\s*(`' . $column . '`\s+varchar\((\d+)\)[^\r\n]*?),?\s*$/mi', $ddl, $m) !== 1 || (int) $m[2] >= $length) {
            return null;
        }

        return (string) preg_replace('/varchar\(\d+\)/i', 'varchar(' . $length . ')', $m[1], 1);
    }

    /**
     * Añade una columna si no existe. La función recibe un TableDef y declara UNA columna.
     * Una columna añadida a una tabla con filas tiene que ser nullable() o llevar default().
     */
    public function addColumn(string $table, callable $define): void
    {
        $def = new TableDef();
        $define($def);
        foreach ($def->columns as $column) {
            if ($this->hasColumn($table, (string) $column['name'])) {
                continue;
            }
            if (!$column['nullable'] && !$column['has_default']) {
                throw new \InvalidArgumentException('A column added to an existing table must be nullable or have a default: ' . $column['name']);
            }
            $this->db->run('ALTER TABLE ' . $this->db->table($table) . ' ADD COLUMN ' . $this->columnSql($column));
        }
        foreach ($def->indexes as $index) {
            $this->addIndex($table, $index['columns'], $index['unique'], $index['name']);
        }
    }

    /**
     * @param string[] $columns
     */
    public function addIndex(string $table, array $columns, bool $unique = false, ?string $name = null): void
    {
        $name = $name !== null ? $name : $this->indexName($table, $columns, $unique);
        if ($this->hasIndex($table, $name)) {
            return;
        }
        $cols = implode(', ', array_map([$this->db, 'ident'], $columns));
        $this->db->run('CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $this->db->ident($name) . ' ON ' . $this->db->table($table) . ' (' . $cols . ')');
    }

    public function hasIndex(string $table, string $name): bool
    {
        if ($this->db->driver() === 'sqlite') {
            return $this->db->value("SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?", [$name]) !== null;
        }

        return $this->db->value(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->db->prefix() . $table, $name]
        ) !== null;
    }

    /**
     * Nombre de índice estable y único en toda la base (en SQLite los índices comparten espacio de nombres).
     *
     * @param string[] $columns
     */
    public function indexName(string $table, array $columns, bool $unique): string
    {
        $name = $this->db->prefix() . $table . '_' . implode('_', $columns) . ($unique ? '_uq' : '_ix');
        if (strlen($name) > 60) {
            $name = substr($name, 0, 51) . '_' . substr(md5($name), 0, 8);
        }

        return $name;
    }

    /**
     * @param array<string,mixed> $c
     */
    private function columnSql(array $c): string
    {
        $mysql = $this->db->driver() === 'mysql';
        $name = $this->db->ident((string) $c['name']);
        switch ($c['type']) {
            case 'id':
                return $name . ($mysql ? ' BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : ' INTEGER PRIMARY KEY AUTOINCREMENT');
            case 'string':
                $type = $mysql ? 'VARCHAR(' . (int) $c['length'] . ')' : 'TEXT';
                break;
            case 'text':
                $type = $mysql ? 'MEDIUMTEXT' : 'TEXT';
                break;
            case 'integer':
                $type = $mysql ? 'INT' : 'INTEGER';
                break;
            case 'bigint':
                $type = $mysql ? 'BIGINT' : 'INTEGER';
                break;
            case 'boolean':
                $type = $mysql ? 'TINYINT(1)' : 'INTEGER';
                break;
            case 'decimal':
                $type = $mysql ? 'DECIMAL(' . (int) $c['precision'] . ',' . (int) $c['scale'] . ')' : 'NUMERIC';
                break;
            case 'float':
                $type = $mysql ? 'DOUBLE' : 'REAL';
                break;
            case 'datetime':
                $type = $mysql ? 'DATETIME' : 'TEXT';
                break;
            case 'date':
                $type = $mysql ? 'DATE' : 'TEXT';
                break;
            case 'blob':
                $type = $mysql ? 'LONGBLOB' : 'BLOB';
                break;
            default:
                throw new \InvalidArgumentException('Unknown column type: ' . $c['type']);
        }
        $sql = $name . ' ' . $type . ($c['nullable'] ? ' NULL' : ' NOT NULL');
        if ($c['has_default']) {
            // MySQL no admite DEFAULT en columnas de texto largo ni binarias: ahí se ignora y lo pone el código.
            if (!($mysql && in_array($c['type'], ['text', 'blob'], true))) {
                $sql .= ' DEFAULT ' . $this->literal($c['default']);
            }
        } elseif ($c['nullable']) {
            $sql .= ' DEFAULT NULL';
        }

        return $sql;
    }

    /**
     * @param string|int|float|bool|null $value
     */
    private function literal($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }
}

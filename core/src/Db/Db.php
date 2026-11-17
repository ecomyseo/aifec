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
 * Acceso a la base de datos. Vale igual para SQLite y para MySQL/MariaDB.
 *
 * Reglas para quien escribe consultas:
 *  - Las tablas se escriben entre llaves y sin prefijo: «SELECT * FROM {products} WHERE id = ?».
 *  - Los valores van SIEMPRE como parámetros (? o :nombre). Nunca concatenados.
 *  - Solo SQL corriente: nada de funciones propias de un motor (fechas, CONCAT, JSON, RAND…).
 *    Las fechas son texto UTC «Y-m-d H:i:s» y se calculan en PHP.
 *  - Para «da de alta o actualiza» y «da de alta si no existe», upsert() e insertIgnore().
 *  - select() devuelve siempre un array (vacío si no hay nada); row() y value() devuelven null.
 */
final class Db
{
    /** @var \PDO */
    private $pdo;

    /** @var string sqlite o mysql */
    private $driver;

    /** @var string */
    private $prefix;

    /** @var int transacciones anidadas abiertas */
    private $depth = 0;

    /** @var int consultas ejecutadas (diagnóstico) */
    public $queries = 0;

    public function __construct(\PDO $pdo, string $driver, string $prefix = 'af_')
    {
        if ($driver !== 'sqlite' && $driver !== 'mysql') {
            throw new \InvalidArgumentException('Unsupported database driver: ' . $driver);
        }
        if (preg_match('/^[a-z0-9_]{0,20}$/', $prefix) !== 1) {
            throw new \InvalidArgumentException('Invalid table prefix');
        }
        $this->pdo = $pdo;
        $this->driver = $driver;
        $this->prefix = $prefix;
    }

    /**
     * Conecta según la configuración del sitio.
     *
     * @param array<string,mixed> $config driver (sqlite|mysql), path | host, port, name, user, password, prefix
     */
    public static function connect(array $config): self
    {
        $driver = isset($config['driver']) ? (string) $config['driver'] : 'sqlite';
        $prefix = isset($config['prefix']) ? (string) $config['prefix'] : 'af_';
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if ($driver === 'sqlite') {
            $path = (string) $config['path'];
            $pdo = new \PDO('sqlite:' . $path, null, null, $options);
            $pdo->exec('PRAGMA busy_timeout = 8000');
            if ($path !== ':memory:') {
                $pdo->exec('PRAGMA journal_mode = WAL');
                $pdo->exec('PRAGMA synchronous = NORMAL');
            }
            $pdo->exec('PRAGMA foreign_keys = OFF');

            return new self($pdo, 'sqlite', $prefix);
        }
        if ($driver === 'mysql') {
            $dsn = 'mysql:host=' . (isset($config['host']) ? (string) $config['host'] : 'localhost')
                . ';port=' . (isset($config['port']) ? (int) $config['port'] : 3306)
                . ';dbname=' . (string) $config['name'] . ';charset=utf8mb4';
            $options[\PDO::ATTR_EMULATE_PREPARES] = false;
            $pdo = new \PDO($dsn, (string) $config['user'], (string) $config['password'], $options);
            $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");

            return new self($pdo, 'mysql', $prefix);
        }
        throw new \InvalidArgumentException('Unsupported database driver: ' . $driver);
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /** Nombre real de una tabla, con prefijo y entre comillas invertidas. */
    public function table(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid table name: ' . $name);
        }

        return '`' . $this->prefix . $name . '`';
    }

    /** Sustituye {tabla} por su nombre real. */
    public function sql(string $sql): string
    {
        $prefix = $this->prefix;

        return (string) preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/', static function (array $m) use ($prefix): string {
            return '`' . $prefix . $m[1] . '`';
        }, $sql);
    }

    /**
     * @param array<int|string,mixed> $params
     *
     * @return array<int,array<string,mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $rows = $this->statement($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int|string,mixed> $params
     *
     * @return array<string,mixed>|null
     */
    public function row(string $sql, array $params = []): ?array
    {
        $row = $this->statement($sql, $params)->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Primera columna de la primera fila; null si no hay fila.
     *
     * @param array<int|string,mixed> $params
     *
     * @return mixed
     */
    public function value(string $sql, array $params = [])
    {
        $row = $this->statement($sql, $params)->fetch(\PDO::FETCH_NUM);

        return is_array($row) ? $row[0] : null;
    }

    /**
     * Primera columna de todas las filas.
     *
     * @param array<int|string,mixed> $params
     *
     * @return array<int,mixed>
     */
    public function column(string $sql, array $params = []): array
    {
        $rows = $this->statement($sql, $params)->fetchAll(\PDO::FETCH_COLUMN, 0);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Ejecuta una sentencia que no devuelve filas. Devuelve las filas afectadas.
     *
     * @param array<int|string,mixed> $params
     */
    public function run(string $sql, array $params = []): int
    {
        return $this->statement($sql, $params)->rowCount();
    }

    /**
     * @param array<string,mixed> $data columna => valor (null se guarda como NULL)
     */
    public function insert(string $table, array $data): int
    {
        $this->run($this->insertSql('INSERT INTO', $table, array_keys($data)), array_values($data));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Da de alta la fila salvo que choque con una clave única. Devuelve true si se insertó.
     *
     * @param array<string,mixed> $data
     */
    public function insertIgnore(string $table, array $data): bool
    {
        $verb = $this->driver === 'sqlite' ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';

        return $this->run($this->insertSql($verb, $table, array_keys($data)), array_values($data)) > 0;
    }

    /**
     * Da de alta la fila o, si ya existe una con esas claves únicas, la actualiza.
     *
     * @param array<string,mixed> $data
     * @param string[]            $uniqueKeys columnas de la clave única o primaria
     */
    public function upsert(string $table, array $data, array $uniqueKeys): void
    {
        $columns = array_keys($data);
        $update = [];
        foreach ($columns as $column) {
            if (in_array($column, $uniqueKeys, true)) {
                continue;
            }
            $q = $this->ident($column);
            $update[] = $this->driver === 'sqlite' ? $q . ' = excluded.' . $q : $q . ' = VALUES(' . $q . ')';
        }
        $sql = $this->insertSql('INSERT INTO', $table, $columns);
        if ($this->driver === 'sqlite') {
            $conflict = implode(', ', array_map([$this, 'ident'], $uniqueKeys));
            $sql .= ' ON CONFLICT(' . $conflict . ') ' . ($update === [] ? 'DO NOTHING' : 'DO UPDATE SET ' . implode(', ', $update));
        } else {
            $first = $this->ident($uniqueKeys[0]);
            $sql .= ' ON DUPLICATE KEY UPDATE ' . ($update === [] ? $first . ' = ' . $first : implode(', ', $update));
        }
        $this->run($sql, array_values($data));
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where columna => valor, unidas con AND (null compara con IS NULL)
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === [] || $where === []) {
            throw new \InvalidArgumentException('update() needs data and a where clause');
        }
        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = $this->ident((string) $column) . ' = ?';
            $params[] = $value;
        }
        $sql = 'UPDATE ' . $this->table($table) . ' SET ' . implode(', ', $set) . ' WHERE ' . $this->where($where, $params);

        return $this->run($sql, $params);
    }

    /**
     * @param array<string,mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new \InvalidArgumentException('delete() needs a where clause');
        }
        $params = [];

        return $this->run('DELETE FROM ' . $this->table($table) . ' WHERE ' . $this->where($where, $params), $params);
    }

    /**
     * Fila por sus columnas: find('products', ['id' => 5]).
     *
     * @param array<string,mixed> $where
     *
     * @return array<string,mixed>|null
     */
    public function find(string $table, array $where): ?array
    {
        $params = [];

        return $this->row('SELECT * FROM ' . $this->table($table) . ' WHERE ' . $this->where($where, $params), $params);
    }

    /**
     * «?, ?, ?» para un IN, añadiendo los valores a $params. Con una lista vacía devuelve «NULL» (no coincide con nada).
     *
     * @param array<int,mixed>        $values
     * @param array<int|string,mixed> $params
     */
    public function in(array $values, array &$params): string
    {
        if ($values === []) {
            return 'NULL';
        }
        foreach ($values as $value) {
            $params[] = $value;
        }

        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * Ejecuta la función dentro de una transacción (anidable). Si lanza, se deshace y se relanza.
     *
     * @return mixed lo que devuelva la función
     */
    public function transaction(callable $fn)
    {
        if ($this->depth === 0) {
            if ($this->driver === 'sqlite') {
                // IMMEDIATE: se toma el bloqueo de escritura al empezar, y dos procesos no se pisan a mitad.
                $this->pdo->exec('BEGIN IMMEDIATE');
            } else {
                $this->pdo->beginTransaction();
            }
        } else {
            $this->pdo->exec('SAVEPOINT af_sp_' . $this->depth);
        }
        ++$this->depth;
        try {
            $result = $fn($this);
            --$this->depth;
            if ($this->depth === 0) {
                $this->driver === 'sqlite' ? $this->pdo->exec('COMMIT') : $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT af_sp_' . $this->depth);
            }

            return $result;
        } catch (\Throwable $e) {
            --$this->depth;
            try {
                if ($this->depth === 0) {
                    $this->driver === 'sqlite' ? $this->pdo->exec('ROLLBACK') : $this->pdo->rollBack();
                } else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT af_sp_' . $this->depth);
                }
            } catch (\Throwable $ignored) {
                // Si el motor ya deshizo la transacción, no hay nada más que deshacer.
            }
            throw $e;
        }
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    public function tableExists(string $name): bool
    {
        $real = $this->prefix . $name;
        if ($this->driver === 'sqlite') {
            return $this->value("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$real]) !== null;
        }

        return $this->value('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$real]) !== null;
    }

    /**
     * @return string[] nombres de las columnas de una tabla
     */
    public function columns(string $name): array
    {
        if ($this->driver === 'sqlite') {
            $rows = $this->select('PRAGMA table_info(' . $this->table($name) . ')');

            return array_map(static function (array $r): string {
                return (string) $r['name'];
            }, $rows);
        }
        $rows = $this->select(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$this->prefix . $name]
        );

        return array_map(static function (array $r): string {
            return (string) $r['COLUMN_NAME'];
        }, $rows);
    }

    /** Nombre de columna o de índice entre comillas invertidas. */
    public function ident(string $name): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid identifier: ' . $name);
        }

        return '`' . $name . '`';
    }

    /**
     * @param array<int|string,mixed> $params
     */
    private function statement(string $sql, array $params): \PDOStatement
    {
        ++$this->queries;
        $stmt = $this->pdo->prepare($this->sql($sql));
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (strpos($key, ':') === 0 ? $key : ':' . $key);
            if ($value === null) {
                $stmt->bindValue($name, null, \PDO::PARAM_NULL);
            } elseif (is_bool($value)) {
                $stmt->bindValue($name, $value ? 1 : 0, \PDO::PARAM_INT);
            } elseif (is_int($value)) {
                $stmt->bindValue($name, $value, \PDO::PARAM_INT);
            } elseif (is_array($value)) {
                throw new \InvalidArgumentException('Array passed as a SQL parameter');
            } else {
                $stmt->bindValue($name, (string) $value, \PDO::PARAM_STR);
            }
        }
        $stmt->execute();

        return $stmt;
    }

    /**
     * @param string[] $columns
     */
    private function insertSql(string $verb, string $table, array $columns): string
    {
        if ($columns === []) {
            throw new \InvalidArgumentException('insert() needs data');
        }

        return $verb . ' ' . $this->table($table) . ' (' . implode(', ', array_map([$this, 'ident'], $columns)) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';
    }

    /**
     * @param array<string,mixed>     $where
     * @param array<int|string,mixed> $params
     */
    private function where(array $where, array &$params): string
    {
        $parts = [];
        foreach ($where as $column => $value) {
            if ($value === null) {
                $parts[] = $this->ident((string) $column) . ' IS NULL';
            } elseif (is_array($value)) {
                $parts[] = $this->ident((string) $column) . ' IN (' . $this->in(array_values($value), $params) . ')';
            } else {
                $parts[] = $this->ident((string) $column) . ' = ?';
                $params[] = $value;
            }
        }

        return implode(' AND ', $parts);
    }
}

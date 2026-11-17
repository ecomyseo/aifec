<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Readers\PrestaShop;

use Aifec\Modules\Import\SourceDenied;
use Aifec\Modules\Import\SourceError;

/**
 * Acceso de solo lectura a la copia de la base de datos de PrestaShop: consultas con parámetros (nunca un valor dentro del
 * texto de la consulta), tablas con el prefijo de la tienda y paginación por clave. Solo ejecuta SELECT. SQL corriente
 * (nada propio de MySQL: en las pruebas la copia es SQLite; las columnas que son palabra reservada, como «from», «to» o
 * «condition», se piden con SELECT * y no por su nombre).
 */
final class Database
{
    /** @var \PDO */
    private $pdo;

    /** @var string */
    private $prefix;

    /** @var array<string,bool> */
    private $tables = [];

    /** @var array<string,bool> */
    private $columns = [];

    public function __construct(\PDO $pdo, string $prefix)
    {
        if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
            throw new \InvalidArgumentException('The table prefix can only have letters, digits and underscores');
        }
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->prefix = $prefix;
    }

    /** Nombre de una tabla con su prefijo. */
    public function t(string $name): string
    {
        return $this->prefix . $name;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** «?, ?, ?» para una lista de valores. */
    public function marks(int $count): string
    {
        return implode(', ', array_fill(0, max(1, $count), '?'));
    }

    /**
     * Ejecuta una consulta de lectura.
     *
     * @param array<int,int|string> $params
     *
     * @return array<int,array<string,mixed>>
     */
    public function rows(string $sql, array $params = []): array
    {
        if (stripos(ltrim($sql), 'SELECT') !== 0) {
            throw new \LogicException('Only SELECT queries are allowed');
        }
        try {
            $statement = $this->pdo->prepare($sql);
            if ($statement === false) {
                throw new SourceError('The database query could not be prepared');
            }
            foreach (array_values($params) as $i => $value) {
                $statement->bindValue($i + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $statement->execute();
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw $this->failure($e);
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int,int|string> $params
     *
     * @return ?array<string,mixed>
     */
    public function row(string $sql, array $params = []): ?array
    {
        $rows = $this->rows($sql, $params);

        return $rows === [] ? null : $rows[0];
    }

    /**
     * @param array<int,int|string> $params
     *
     * @return mixed
     */
    public function value(string $sql, array $params = [])
    {
        $row = $this->row($sql, $params);

        return $row === null ? null : array_values($row)[0];
    }

    /**
     * @param array<int,int|string> $params
     */
    public function count(string $sql, array $params = []): int
    {
        return (int) $this->value($sql, $params);
    }

    /**
     * Una tanda por clave: «WHERE … AND id > cursor ORDER BY id LIMIT n», sin OFFSET. Se piden n + 1 filas para saber con
     * certeza si queda algo (el final no se deduce de que la tanda venga corta).
     *
     * @param string                $beforeWhere «SELECT … FROM tabla alias [JOIN …]» (puede llevar «?» de sus uniones)
     * @param array<int,int|string> $params      valores de esa parte y de $where, en el orden en que aparecen
     * @param string                $where       condiciones propias («s.id_shop = ?»); «1 = 1» si no hay
     * @param string                $idColumn    columna de la clave, con su alias («p.id_product»)
     * @param string                $idKey       nombre de esa columna en la fila devuelta («id_product»)
     *
     * @return array{0:array<int,array<string,mixed>>,1:?string} filas de la tanda y cursor siguiente (null = se acabó)
     */
    public function page(string $beforeWhere, array $params, string $where, string $idColumn, string $idKey, string $cursor, int $limit): array
    {
        if ($cursor !== '' && preg_match('/^\d+$/', $cursor) !== 1) {
            throw new SourceError('Invalid cursor');
        }
        $limit = max(1, $limit);
        $sql = $beforeWhere . ' WHERE (' . $where . ') AND ' . $idColumn . ' > ? ORDER BY ' . $idColumn . ' LIMIT ?';
        $all = $this->rows($sql, array_merge($params, [$cursor === '' ? 0 : (int) $cursor, $limit + 1]));
        $rows = array_slice($all, 0, $limit);
        $next = count($all) > $limit && $rows !== [] ? (string) $rows[count($rows) - 1][$idKey] : null;

        return [$rows, $next];
    }

    /** ¿Existe la tabla? (una consulta que no devuelve nada; solo SELECT) */
    public function tableExists(string $name): bool
    {
        $table = $this->t($name);
        if (!isset($this->tables[$table])) {
            $this->tables[$table] = $this->probe('SELECT 1 FROM ' . $table . ' WHERE 1 = 0');
        }

        return $this->tables[$table];
    }

    /** ¿Existe la columna en la tabla? (cambia entre versiones de PrestaShop) */
    public function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!isset($this->columns[$key])) {
            $this->columns[$key] = $this->tableExists($table) && $this->probe('SELECT ' . $column . ' FROM ' . $this->t($table) . ' WHERE 1 = 0');
        }

        return $this->columns[$key];
    }

    private function probe(string $sql): bool
    {
        try {
            $statement = $this->pdo->prepare($sql);
            if ($statement === false) {
                return false;
            }
            $statement->execute();

            return true;
        } catch (\PDOException $e) {
            $error = $this->failure($e);
            if ($error instanceof SourceDenied) {
                throw $error;
            }

            return false;
        }
    }

    private function failure(\PDOException $e): SourceError
    {
        $info = is_array($e->errorInfo) ? $e->errorInfo : [];
        $code = isset($info[1]) ? (int) $info[1] : 0;
        if (in_array($code, [1044, 1045, 1142, 1143, 1227], true)) {
            return new SourceDenied('The database user has no permission to read this data');
        }

        return new SourceError('The database could not be read (code ' . (string) $e->getCode() . ')');
    }
}

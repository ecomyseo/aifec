<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Readers\WooCommerce;

use Aifec\Modules\Import\SourceDenied;
use Aifec\Modules\Import\SourceError;

/**
 * Acceso de solo lectura a la copia de la base de datos de WordPress: consultas con parámetros (nunca un valor dentro del
 * texto de la consulta), tablas con el prefijo del sitio, opciones, datos extra por lotes, paginación por clave y lectura
 * segura de datos serializados. Solo ejecuta SELECT. SQL corriente (nada propio de MySQL): en las pruebas la copia es SQLite.
 */
final class Database
{
    /** @var \PDO */
    private $pdo;

    /** @var string prefijo de las tablas compartidas (usuarios) */
    private $base;

    /** @var string prefijo de las tablas del sitio (en un multisitio, «wp_2_») */
    private $site;

    /** @var array<string,?string> */
    private $options = [];

    /** @var array<string,bool> */
    private $tables = [];

    public function __construct(\PDO $pdo, string $basePrefix, string $sitePrefix)
    {
        foreach ([$basePrefix, $sitePrefix] as $prefix) {
            if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
                throw new \InvalidArgumentException('The table prefix can only have letters, digits and underscores');
            }
        }
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->base = $basePrefix;
        $this->site = $sitePrefix;
    }

    /** Nombre de una tabla del sitio. */
    public function t(string $name): string
    {
        return $this->site . $name;
    }

    /** Nombre de una tabla compartida (usuarios y sus datos). */
    public function u(string $name): string
    {
        return $this->base . $name;
    }

    public function sitePrefix(): string
    {
        return $this->site;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
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
     */
    public function count(string $sql, array $params = []): int
    {
        $row = $this->row($sql, $params);

        return $row === null ? 0 : (int) array_values($row)[0];
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

    /** ¿Existe la tabla del sitio? (una consulta que no devuelve nada; solo SELECT) */
    public function tableExists(string $name, bool $shared = false): bool
    {
        $table = $shared ? $this->u($name) : $this->t($name);
        if (!isset($this->tables[$table])) {
            try {
                $statement = $this->pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE 1 = 0');
                if ($statement === false) {
                    $this->tables[$table] = false;
                } else {
                    $statement->execute();
                    $this->tables[$table] = true;
                }
            } catch (\PDOException $e) {
                $error = $this->failure($e);
                if ($error instanceof SourceDenied) {
                    throw $error;
                }
                $this->tables[$table] = false;
            }
        }

        return $this->tables[$table];
    }

    /**
     * Opciones de WordPress (una consulta por lote, con caché).
     *
     * @param string[] $names
     *
     * @return array<string,?string> nombre => valor, o null si no existe
     */
    public function options(array $names): array
    {
        $need = [];
        foreach ($names as $name) {
            if (!array_key_exists($name, $this->options)) {
                $need[] = $name;
            }
        }
        if ($need !== []) {
            foreach ($this->rows('SELECT option_name, option_value FROM ' . $this->t('options') . ' WHERE option_name IN (' . $this->marks(count($need)) . ')', $need) as $row) {
                $this->options[(string) $row['option_name']] = (string) $row['option_value'];
            }
            foreach ($need as $name) {
                if (!array_key_exists($name, $this->options)) {
                    $this->options[$name] = null;
                }
            }
        }
        $out = [];
        foreach ($names as $name) {
            $out[$name] = $this->options[$name];
        }

        return $out;
    }

    public function option(string $name): ?string
    {
        return $this->options([$name])[$name];
    }

    /**
     * Una opción serializada como lista; nunca instancia una clase.
     *
     * @return array<mixed,mixed>
     */
    public function optionArray(string $name): array
    {
        return self::unserialize((string) $this->option($name));
    }

    /**
     * Datos serializados de PHP, leídos sin instanciar ninguna clase (allowed_classes = false). Lo que no es una lista, está
     * corrupto o trae objetos da vacío o los objetos se quitan: nunca se ejecuta código del dato.
     *
     * @return array<mixed,mixed>
     */
    public static function unserialize(string $text): array
    {
        $text = trim($text);
        if ($text === '' || preg_match('/^a:\d+:\{/', $text) !== 1) {
            return [];
        }
        $previous = set_error_handler(static function (): bool {
            return true;
        });
        try {
            $value = unserialize($text, ['allowed_classes' => false]);
        } finally {
            restore_error_handler();
        }
        unset($previous);

        return is_array($value) ? self::withoutObjects($value) : [];
    }

    /**
     * @param array<mixed,mixed> $value
     *
     * @return array<mixed,mixed>
     */
    private static function withoutObjects(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_object($item)) {
                $value[$key] = null;
            } elseif (is_array($item)) {
                $value[$key] = self::withoutObjects($item);
            }
        }

        return $value;
    }

    /**
     * Datos extra de varios objetos de una vez.
     *
     * @param string               $table     tabla de datos («postmeta»)
     * @param string               $idColumn  columna que apunta al objeto («post_id»)
     * @param array<int,int|string> $ids
     * @param string[]             $keys      solo estas claves (vacío = todas)
     * @param string               $keyPrefix además, las claves que empiezan por esto
     * @param bool                 $shared    tabla compartida (usuarios)
     *
     * @return array<int,array<string,string>> id => [clave => valor]
     */
    public function meta(string $table, string $idColumn, array $ids, array $keys = [], string $keyPrefix = '', bool $shared = false): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_values($ids);
        $sql = 'SELECT ' . $idColumn . ' AS oid, meta_key, meta_value FROM ' . ($shared ? $this->u($table) : $this->t($table))
            . ' WHERE ' . $idColumn . ' IN (' . $this->marks(count($ids)) . ')';
        $params = $ids;
        $conditions = [];
        if ($keys !== []) {
            $conditions[] = 'meta_key IN (' . $this->marks(count($keys)) . ')';
            foreach ($keys as $key) {
                $params[] = $key;
            }
        }
        if ($keyPrefix !== '') {
            $conditions[] = 'meta_key LIKE ?';
            $params[] = $keyPrefix . '%';
        }
        if ($conditions !== []) {
            $sql .= ' AND (' . implode(' OR ', $conditions) . ')';
        }
        $out = [];
        foreach ($this->rows($sql, $params) as $row) {
            $out[(int) $row['oid']][(string) $row['meta_key']] = $row['meta_value'] === null ? '' : (string) $row['meta_value'];
        }

        return $out;
    }

    /**
     * Una tanda por clave: «WHERE id > cursor ORDER BY id LIMIT n», sin OFFSET. Se piden n + 1 filas para saber con certeza si
     * queda algo (el final no se deduce de que la tanda venga corta).
     *
     * @param string                $select  «SELECT … FROM tabla alias» (sin WHERE)
     * @param string                $where   condiciones propias («p.post_type = 'product'»), con «?» para sus valores
     * @param array<int,int|string> $params  valores de esas condiciones
     * @param string                $idColumn columna de la clave, con su alias («p.ID»)
     * @param string                $idKey   nombre de esa columna en la fila devuelta («ID»)
     *
     * @return array{0:array<int,array<string,mixed>>,1:?string} filas de la tanda y cursor siguiente (null = se acabó)
     */
    public function page(string $select, string $where, array $params, string $idColumn, string $idKey, string $cursor, int $limit): array
    {
        if ($cursor !== '' && preg_match('/^\d+$/', $cursor) !== 1) {
            throw new SourceError('Invalid cursor');
        }
        $limit = max(1, $limit);
        $sql = $select . ' WHERE ' . $idColumn . ' > ?' . ($where !== '' ? ' AND ' . $where : '') . ' ORDER BY ' . $idColumn . ' LIMIT ?';
        $all = $this->rows($sql, array_merge([$cursor === '' ? 0 : (int) $cursor], $params, [$limit + 1]));
        $rows = array_slice($all, 0, $limit);
        $next = count($all) > $limit && $rows !== [] ? (string) $rows[count($rows) - 1][$idKey] : null;

        return [$rows, $next];
    }
}

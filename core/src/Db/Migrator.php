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
 * Aplica las migraciones pendientes. Cada migración es un fichero «NNNN_nombre.php» que devuelve
 * una función `function (Schema $schema, Db $db): void`. Se aplican por orden de nombre, una sola vez
 * por ámbito (el núcleo es «core»; cada módulo o plugin, su identificador).
 *
 * No hay nada que ejecutar a mano al subir una versión: el sistema las aplica solo al arrancar.
 */
final class Migrator
{
    /** @var Db */
    private $db;

    /** @var Schema */
    private $schema;

    public function __construct(Db $db)
    {
        $this->db = $db;
        $this->schema = new Schema($db);
    }

    /**
     * @return string[] ficheros de migración de una carpeta, ordenados
     */
    public static function files(string $dir): array
    {
        $files = glob(rtrim($dir, '/\\') . '/[0-9]*_*.php');
        $files = is_array($files) ? array_map(static function (string $f): string {
            return str_replace('\\', '/', $f);
        }, $files) : [];
        sort($files);

        return $files;
    }

    /**
     * Huella de todas las migraciones conocidas: si coincide con la guardada, no hay nada pendiente
     * y el arranque no toca la base de datos.
     *
     * @param array<string,string> $scopes ámbito => carpeta
     */
    public static function fingerprint(array $scopes): string
    {
        $names = [];
        foreach ($scopes as $scope => $dir) {
            foreach (self::files($dir) as $file) {
                $names[] = $scope . ':' . basename($file);
            }
        }

        return md5(implode('|', $names));
    }

    /**
     * Aplica lo pendiente de un ámbito.
     *
     * @return string[] nombres de las migraciones aplicadas ahora
     */
    public function run(string $scope, string $dir, string $nowSql): array
    {
        $this->ensureTable();
        $done = $this->db->column('SELECT name FROM {migrations} WHERE scope = ?', [$scope]);
        $applied = [];
        foreach (self::files($dir) as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $done, true)) {
                continue;
            }
            $migration = require $file;
            if (!is_callable($migration)) {
                throw new \RuntimeException('Migration does not return a function: ' . $scope . '/' . $name);
            }
            $migration($this->schema, $this->db);
            $this->db->insertIgnore('migrations', ['scope' => $scope, 'name' => $name, 'applied_at' => $nowSql]);
            $applied[] = $name;
        }

        return $applied;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function applied(): array
    {
        $this->ensureTable();

        return $this->db->select('SELECT scope, name, applied_at FROM {migrations} ORDER BY scope, name');
    }

    /** Olvida las migraciones de un ámbito (al desinstalar un plugin). */
    public function forget(string $scope): void
    {
        $this->ensureTable();
        $this->db->delete('migrations', ['scope' => $scope]);
    }

    private function ensureTable(): void
    {
        $this->schema->create('migrations', static function (TableDef $t): void {
            $t->string('scope', 64);
            $t->string('name', 120);
            $t->datetime('applied_at');
            $t->primary(['scope', 'name']);
        });
    }
}

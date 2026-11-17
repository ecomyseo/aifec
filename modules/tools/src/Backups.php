<?php
/**
 * AIFEC — copias de seguridad de este sitio (servicio «backups»), guardadas en la carpeta privada del sitio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Kernel\App;
use Aifec\Kernel\Cache;

/**
 * Una copia es UN fichero `backup-AAAAMMDD-HHMMSS-NN.zip` (ordenados por nombre quedan en orden cronológico; NN desempata las del
 * mismo segundo) con el volcado de la base de datos de este sitio, la carpeta de medios, los ficheros privados y un
 * `manifest.json` con las sumas de cada parte. Sin la extensión `zip` se hace como antes: solo la base de datos, en un fichero
 * `.sqlite` o `.sql` (con gzip si se puede), que se puede comprobar pero no se restaura desde aquí.
 *
 * SQLite: copia consistente con `VACUUM INTO` (si el motor no lo sabe, se vacía el registro y se copia el fichero).
 * MySQL / MariaDB: volcado escrito en PHP por tandas de las tablas con el prefijo del sitio, sin cargar una tabla entera.
 *
 * Una copia cuenta como buena cuando se ha comprobado (`verify`): el aviso «sin copia reciente» del inicio mira solo esas.
 */
final class Backups
{
    private const NAME = '/^backup-(\d{8})-(\d{6})-(\d{2})\.(sqlite|sql|zip)(\.gz)?$/';

    /** Filas por sentencia INSERT del volcado de MySQL. */
    private const INSERT_ROWS = 200;

    /** Bytes de la base de datos a partir de los cuales el panel la copia en segundo plano. */
    public const BACKGROUND_FROM = 20 * 1024 * 1024;

    /** Lo que se copia: todo, o solo la base de datos. */
    public const MODES = ['full', 'db'];

    /** @var App */
    private $app;

    /** @var bool */
    private $onlyVerified;

    /**
     * @param bool $onlyVerified true = `all()` solo devuelve las copias comprobadas (es lo que ve el aviso del inicio)
     */
    public function __construct(App $app, bool $onlyVerified = false)
    {
        $this->app = $app;
        $this->onlyVerified = $onlyVerified;
    }

    public function app(): App
    {
        return $this->app;
    }

    /** ¿Se pueden hacer copias completas (y restaurarlas)? */
    public static function zipAvailable(): bool
    {
        return class_exists('ZipArchive');
    }

    /** Carpeta de las copias (se crea, con una protección para el servidor web por si la carpeta de datos está a su alcance). */
    public function dir(): string
    {
        $dir = Fs::norm($this->app->site()->path('private/backups', true));
        $guard = $dir . '/.htaccess';
        if (is_dir($dir) && !is_file($guard)) {
            @file_put_contents($guard, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }

        return $dir;
    }

    /**
     * Cuántas copias hay sin comprobar, aunque este servicio solo enseñe las comprobadas: el aviso del inicio lo usa para no decir
     * «no hay ninguna» cuando la pantalla de copias enseña una «Sin comprobar».
     */
    public function unchecked(): int
    {
        $count = 0;
        $names = scandir($this->dir());
        foreach (is_array($names) ? $names : [] as $name) {
            if (preg_match(self::NAME, $name) === 1 && is_file($this->dir() . '/' . $name) && !$this->isVerified($name)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Las copias que hay, la más reciente primero.
     *
     * @return array<int,array{name:string,bytes:int,created_at:string,kind:string,verified:bool}>
     */
    public function all(): array
    {
        $out = [];
        $dir = $this->dir();
        $names = scandir($dir);
        foreach (is_array($names) ? $names : [] as $name) {
            if (preg_match(self::NAME, $name, $m) !== 1 || !is_file($dir . '/' . $name)) {
                continue;
            }
            $verified = $this->isVerified($name);
            if ($this->onlyVerified && !$verified) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'bytes' => (int) filesize($dir . '/' . $name),
                'created_at' => substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2) . ' '
                    . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2),
                'kind' => $m[4],
                'verified' => $verified,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp($b['name'], $a['name']);
        });

        return $out;
    }

    /** Ruta de una copia por su nombre; null si no es una de las que hay. */
    public function path(string $name): ?string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            return null;
        }
        $path = $this->dir() . '/' . $name;

        return is_file($path) ? $path : null;
    }

    public function delete(string $name): bool
    {
        $path = $this->path($name);
        if ($path === null) {
            return false;
        }
        @unlink($path . '.ok');

        return @unlink($path);
    }

    // ------------------------------------------------------------------ comprobación

    /** ¿Se comprobó esta copia y sigue siendo el mismo fichero (mismo tamaño y misma fecha) que se comprobó? */
    public function isVerified(string $name): bool
    {
        $path = $this->dir() . '/' . $name;
        if (!is_file($path) || !is_file($path . '.ok')) {
            return false;
        }
        $data = json_decode((string) file_get_contents($path . '.ok'), true);
        clearstatcache(true, $path);

        return is_array($data) && isset($data['bytes'], $data['mtime'])
            && (int) $data['bytes'] === (int) filesize($path) && (int) $data['mtime'] === (int) filemtime($path);
    }

    /** Anota que una copia está comprobada. Lo usa `verify` al acabar bien; no comprueba nada por sí mismo. */
    public function markVerified(string $name): void
    {
        $path = $this->path($name);
        if ($path === null) {
            return;
        }
        clearstatcache(true, $path);
        @file_put_contents($path . '.ok', json_encode([
            'verified_at' => $this->app->clock()->sql(),
            'bytes' => (int) filesize($path),
            'mtime' => (int) filemtime($path),
        ]), LOCK_EX);
    }

    /**
     * Comprueba una copia sin restaurarla; si está bien, queda anotada como comprobada.
     *
     * @return array{ok:bool,errors:string[],manifest:array<string,mixed>|null,legacy:bool}
     */
    public function verify(string $name): array
    {
        $path = $this->path($name);
        if ($path === null) {
            return ['ok' => false, 'errors' => ['The backup does not exist'], 'manifest' => null, 'legacy' => false];
        }
        @unlink($path . '.ok');
        $result = BackupVerifier::verify($path, $this->app->db()->prefix());
        if ($result['ok']) {
            $this->markVerified($name);
        }

        return $result;
    }

    // ------------------------------------------------------------------ crear

    /** Cuánto ocupa la base de datos (bytes), para decidir si la copia va en segundo plano. */
    public function databaseSize(): int
    {
        $db = $this->app->db();
        if ($db->driver() === 'sqlite') {
            $path = (string) $this->app->site()->get('db.path', '');
            $size = 0;
            foreach ([$path, $path . '-wal'] as $file) {
                if ($file !== '' && is_file($file)) {
                    $size += (int) filesize($file);
                }
            }

            return $size;
        }
        $total = 0;
        foreach ($db->select('SELECT DATA_LENGTH AS d, INDEX_LENGTH AS i, TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $row) {
            if (strpos((string) $row['n'], $db->prefix()) === 0) {
                $total += (int) $row['d'] + (int) $row['i'];
            }
        }

        return $total;
    }

    /** `full`, `db` o `auto` (lo que diga el ajuste `tools.backup_content`, «full» de serie) → `full` o `db`. */
    public function mode(string $mode = 'auto'): string
    {
        if (!in_array($mode, self::MODES, true)) {
            $mode = $this->app->settings()->str('tools.backup_content', 'full');
        }

        return $mode === 'db' ? 'db' : 'full';
    }

    /**
     * Crea una copia entera de un tirón (consola, tarea nocturna) y deja solo las `tools.keep_backups` últimas. Lanza si no se puede.
     *
     * @param string $mode  full | db | auto
     * @param bool   $prune false = no borrar copias viejas (la copia de seguridad que se hace antes de restaurar)
     *
     * @return array{name:string,bytes:int}
     */
    public function create(string $mode = 'auto', bool $prune = true): array
    {
        $mode = $this->mode($mode);
        if (!self::zipAvailable()) {
            $done = $this->createLegacy();
        } else {
            $run = $this->begin($mode);
            try {
                $state = $run->work(86400.0);
            } catch (\Throwable $e) {
                $run->abort();
                throw $e;
            }
            if ($state['stage'] !== 'done') {
                $run->abort();
                throw new \RuntimeException('The backup did not finish');
            }
            $done = ['name' => (string) $state['name'], 'bytes' => (int) $state['bytes_total']];
        }
        if ($prune) {
            $this->prune();
        }

        return $done;
    }

    /** Empieza una copia que se hace por tandas (`BackupRun::work`). Necesita la extensión zip. */
    public function begin(string $mode): BackupRun
    {
        if (!self::zipAvailable()) {
            throw new \RuntimeException('The zip extension is not installed');
        }

        return BackupRun::begin($this, $this->mode($mode));
    }

    /** Retoma una copia por tandas que ya está empezada; null si no existe. */
    public function resume(string $token): ?BackupRun
    {
        return BackupRun::load($this, $token);
    }

    /** Carpeta de trabajo de una copia por tandas. */
    public function runDir(string $token): string
    {
        return $this->dir() . '/.run-' . $token;
    }

    /** Deja solo las últimas copias (`tools.keep_backups`, 7 por defecto) y borra las tandas abandonadas. Devuelve cuántas copias borra. */
    public function prune(): int
    {
        $keep = max(1, $this->app->settings()->int('tools.keep_backups', 7));
        $removed = 0;
        foreach (array_slice($this->all(), $keep) as $old) {
            if ($this->delete($old['name'])) {
                ++$removed;
            }
        }
        $dir = $this->dir();
        $names = scandir($dir);
        foreach (is_array($names) ? $names : [] as $name) {
            if (preg_match('/^\.run-[a-f0-9]{12}$/', $name) === 1 && is_dir($dir . '/' . $name)
                && (int) @filemtime($dir . '/' . $name) < $this->app->clock()->now() - 2 * 86400) {
                Cache::removeDir($dir . '/' . $name);
            }
        }

        return $removed;
    }

    /**
     * Escribe el volcado de la base de datos en un fichero: la base SQLite o el SQL de MySQL.
     *
     * @return array{engine:string,entry:string,tables:array<string,int>} tablas del prefijo del sitio con su número de filas
     */
    public function dumpDatabase(string $target): array
    {
        if ($this->app->db()->driver() === 'sqlite') {
            $this->dumpSqlite($target);

            return ['engine' => 'sqlite', 'entry' => 'db/database.sqlite', 'tables' => $this->sqliteCounts($target)];
        }

        return ['engine' => 'mysql', 'entry' => 'db/dump.sql', 'tables' => $this->dumpMysql($target)];
    }

    /** Un nombre libre para una copia nueva: el número sigue al más alto de ese segundo. */
    public function nextName(string $ext): string
    {
        $dir = $this->dir();
        $stamp = gmdate('Ymd-His', $this->app->clock()->now());
        $existing = scandir($dir);
        $last = 0;
        foreach (is_array($existing) ? $existing : [] as $name) {
            // Si se reutilizara un número libre, una copia nueva quedaría por debajo de las viejas.
            if (preg_match('/^backup-' . $stamp . '-(\d{2})\./', $name, $m) === 1) {
                $last = max($last, (int) $m[1]);
            }
        }
        if ($last >= 99) {
            throw new \RuntimeException('Too many backups in the same second');
        }

        return 'backup-' . $stamp . '-' . sprintf('%02d', $last + 1) . '.' . $ext;
    }

    /** @return array{name:string,bytes:int} */
    private function createLegacy(): array
    {
        $dir = $this->dir();
        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(6));
        try {
            $info = $this->dumpDatabase($tmp);
            $ext = $info['engine'] === 'sqlite' ? 'sqlite' : 'sql';
            $gzip = function_exists('gzopen');
            $final = $this->nextName($ext . ($gzip ? '.gz' : ''));
            if ($gzip) {
                $this->gzip($tmp, $dir . '/' . $final);
                @unlink($tmp);
            } elseif (!@rename($tmp, $dir . '/' . $final)) {
                throw new \RuntimeException('The backup could not be saved');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        return ['name' => $final, 'bytes' => (int) filesize($dir . '/' . $final)];
    }

    // ------------------------------------------------------------------ SQLite

    private function dumpSqlite(string $target): void
    {
        $pdo = $this->app->db()->pdo();
        try {
            $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $target) . "'");

            return;
        } catch (\PDOException $e) {
            // Un SQLite anterior a 3.27 no sabe VACUUM INTO: se vuelca el registro al fichero y se copia.
            @unlink($target);
        }
        $path = (string) $this->app->site()->get('db.path', '');
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('The database file could not be found');
        }
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        if (!@copy($path, $target)) {
            throw new \RuntimeException('The database could not be copied');
        }
    }

    /** @return array<string,int> */
    private function sqliteCounts(string $file): array
    {
        $prefix = $this->app->db()->prefix();
        $pdo = new \PDO('sqlite:' . $file);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $out = [];
        $names = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name");
        foreach ($names !== false ? $names->fetchAll(\PDO::FETCH_COLUMN) : [] as $name) {
            $name = (string) $name;
            if (strncmp($name, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            $out[$name] = (int) $pdo->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $name) . '"')->fetchColumn(); // revisado: nombre leído de sqlite_master
        }
        $pdo = null;

        return $out;
    }

    // ------------------------------------------------------------------ MySQL / MariaDB

    /** @return array<string,int> */
    private function dumpMysql(string $target): array
    {
        $h = @fopen($target, 'wb');
        if ($h === false) {
            throw new \RuntimeException('The backup file could not be written');
        }
        $counts = [];
        try {
            $db = $this->app->db();
            $pdo = $db->pdo();
            fwrite($h, "-- AIFEC backup of the site \"" . $this->app->site()->id . "\"\n-- Created " . $this->app->clock()->sql() . " UTC\n\n");
            fwrite($h, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
            foreach ($this->mysqlTables() as $table) {
                $quoted = '`' . str_replace('`', '``', $table) . '`';
                $create = $db->select('SHOW CREATE TABLE ' . $quoted); // revisado: nombre de tabla leído de information_schema, entre comillas invertidas
                $statement = isset($create[0]['Create Table']) ? (string) $create[0]['Create Table'] : '';
                if ($statement === '') {
                    throw new \RuntimeException('The structure of a table could not be read');
                }
                fwrite($h, 'DROP TABLE IF EXISTS ' . $quoted . ";\n" . $statement . ";\n\n");
                $counts[$table] = $this->dumpRows($h, $pdo, $quoted, $this->primaryKey($table));
            }
            fwrite($h, SqlDump::FOOTER . ";\n");
        } finally {
            fclose($h);
        }

        return $counts;
    }

    /** @return string[] las tablas que empiezan por el prefijo del sitio */
    private function mysqlTables(): array
    {
        $prefix = $this->app->db()->prefix();
        $rows = $this->app->db()->select('SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ? ORDER BY TABLE_NAME', ['BASE TABLE']);
        $out = [];
        foreach ($rows as $row) {
            if (strpos((string) $row['n'], $prefix) === 0) {
                $out[] = (string) $row['n'];
            }
        }

        return $out;
    }

    /** La clave primaria de un solo campo numérico, para recorrer la tabla por tandas sin OFFSET; null si no la tiene. */
    private function primaryKey(string $table): ?string
    {
        $rows = $this->app->db()->select(
            'SELECT COLUMN_NAME AS c, DATA_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_KEY = ?',
            [$table, 'PRI']
        );
        if (count($rows) !== 1 || !in_array(strtolower((string) $rows[0]['t']), ['int', 'bigint', 'smallint', 'mediumint', 'tinyint'], true)) {
            return null;
        }

        return (string) $rows[0]['c'];
    }

    /**
     * @param resource $h
     *
     * @return int filas escritas
     */
    private function dumpRows($h, \PDO $pdo, string $quotedTable, ?string $key): int
    {
        $last = null;
        $offset = 0;
        $keyQuoted = $key !== null ? '`' . str_replace('`', '``', $key) . '`' : '';
        while (true) {
            if ($key !== null) {
                $sql = 'SELECT * FROM ' . $quotedTable . ($last !== null ? ' WHERE ' . $keyQuoted . ' > ' . (int) $last : '') . ' ORDER BY ' . $keyQuoted . ' LIMIT ' . self::INSERT_ROWS;
            } else {
                $sql = 'SELECT * FROM ' . $quotedTable . ' LIMIT ' . self::INSERT_ROWS . ' OFFSET ' . $offset;
            }
            $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows === []) {
                return $offset;
            }
            fwrite($h, self::insertStatement($quotedTable, $rows, static function (string $value) use ($pdo): string {
                return (string) $pdo->quote($value);
            }));
            if ($key !== null) {
                $last = $rows[count($rows) - 1][$key];
            }
            $offset += count($rows);
            if (count($rows) < self::INSERT_ROWS) {
                return $offset;
            }
        }
    }

    /**
     * Una sentencia INSERT con varias filas. Los NULL van como NULL, los números como números y el resto entre comillas.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public static function insertStatement(string $quotedTable, array $rows, callable $quote): string
    {
        if ($rows === []) {
            return '';
        }
        $columns = [];
        foreach (array_keys($rows[0]) as $column) {
            $columns[] = '`' . str_replace('`', '``', (string) $column) . '`';
        }
        $lines = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } elseif (is_int($value)) {
                    $values[] = (string) $value;
                } else {
                    $values[] = (string) $quote((string) $value);
                }
            }
            $lines[] = '(' . implode(', ', $values) . ')';
        }

        return 'INSERT INTO ' . $quotedTable . ' (' . implode(', ', $columns) . ") VALUES\n" . implode(",\n", $lines) . ";\n";
    }

    // ------------------------------------------------------------------ ficheros

    private function gzip(string $source, string $target): void
    {
        $in = @fopen($source, 'rb');
        $out = @gzopen($target, 'wb6');
        if ($in === false || $out === false) {
            if ($in !== false) {
                fclose($in);
            }
            throw new \RuntimeException('The backup could not be compressed');
        }
        try {
            while (!feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false) {
                    throw new \RuntimeException('The backup could not be compressed');
                }
                gzwrite($out, $chunk);
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
    }
}

<?php
/**
 * AIFEC — restaurar una copia sin dejar el sitio a medias.
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
 * Orden, y qué pasa si falla cada paso (en todos los casos el sitio queda como estaba y se dice):
 *
 *  1. Se comprueba la copia entera (manifiesto, sumas, versión, motor, prefijo) → si falla, no se ha tocado nada.
 *  2. Se hace una copia de lo que hay y se comprueba → si falla, no se ha tocado nada.
 *  3. Los ficheros se extraen a carpetas nuevas al lado de las vivas, comprobando la suma de cada uno → si falla se borran.
 *  4. El volcado se extrae a un temporal → si falla se borra.
 *  5. Mantenimiento puesto.
 *  6. Base de datos. SQLite: una transacción (borrar, crear y copiar las tablas desde la copia adjunta); no se confirma hasta el final.
 *     MySQL / MariaDB: las tablas se cargan con otro nombre, se cuentan las filas y un solo `RENAME TABLE` (atómico) las pone en su sitio;
 *     las viejas se guardan con otro nombre hasta el final. → si falla antes del cambio, se borran las temporales.
 *  7. Con la base ya cambiada: las carpetas vivas se apartan (`.before-restore-…`, no se borran) y las nuevas ocupan su sitio, se pasan
 *     las migraciones y se vacían las cachés → si algo falla se deshace el cambio de carpetas y el de la base (transacción o RENAME inverso).
 *  8. Mantenimiento como estaba antes. Solo entonces se tiran las tablas viejas de MySQL.
 */
final class BackupRestorer
{
    /** @var Backups */
    private $backups;

    /** @var App */
    private $app;

    /** @var callable|null */
    private $fault;

    public function __construct(Backups $backups)
    {
        $this->backups = $backups;
        $this->app = $backups->app();
    }

    /**
     * Solo para las pruebas: una función que se llama en cada paso («table», «files», «migrate») y puede lanzar para simular un fallo.
     */
    public function setFault(?callable $fault): void
    {
        $this->fault = $fault;
    }

    /**
     * Mira si una copia se puede restaurar aquí, sin tocar nada.
     *
     * @return array{manifest:array<string,mixed>,other_site:bool,has_files:bool}
     */
    public function inspect(string $name): array
    {
        $path = $this->backups->path($name);
        if ($path === null) {
            throw new BackupException('The backup does not exist');
        }
        $check = BackupVerifier::verify($path, $this->app->db()->prefix());
        if ($check['legacy']) {
            throw new BackupException('This backup has no manifest and cannot be restored from here');
        }
        if (!$check['ok'] || $check['manifest'] === null) {
            $first = $check['errors'] !== [] ? $check['errors'][0] : 'The backup is broken';
            throw new BackupException('The backup is not valid, so nothing was restored :: ' . BackupException::text($first));
        }
        $manifest = $check['manifest'];
        if (version_compare((string) $manifest['version'], App::VERSION, '>')) {
            throw new BackupException('The backup comes from a newer version of AIFEC');
        }
        $db = $this->app->db();
        if ($manifest['database']['engine'] !== $db->driver()) {
            throw new BackupException('The backup is from a different database engine');
        }
        if ($manifest['database']['prefix'] !== $db->prefix()) {
            throw new BackupException('The backup uses a different table prefix');
        }

        return [
            'manifest' => $manifest,
            'other_site' => strtolower(rtrim((string) $manifest['site']['url'], '/')) !== strtolower(rtrim($this->app->site()->url(), '/')),
            'has_files' => $manifest['mode'] === 'full',
        ];
    }

    /**
     * Restaura. Opciones: `only` ('' = todo, 'db' o 'files') y `other_site` (true = se sabe que es de otro sitio y se quiere igual).
     *
     * @param array<string,mixed> $options
     *
     * @return array{safety:string,old:string[],tables:int,files:int,migrations:int}
     */
    public function restore(string $name, array $options = []): array
    {
        $only = isset($options['only']) && is_string($options['only']) ? $options['only'] : '';
        if (!in_array($only, ['', 'db', 'files'], true)) {
            throw new BackupException('The part to restore is not valid');
        }
        $info = $this->inspect($name);
        $manifest = $info['manifest'];
        if ($info['other_site'] && empty($options['other_site'])) {
            throw new BackupException('The backup is from another site: restoring it moves a shop, and the address of this site is not changed');
        }
        if ($only === 'files' && !$info['has_files']) {
            throw new BackupException('This backup has no files, only the database');
        }
        $doDb = $only !== 'files';
        $doFiles = $only !== 'db' && $info['has_files'];

        // 2. La copia de lo que hay.
        try {
            $safety = $this->backups->create($doFiles ? 'full' : 'db', false);
            $checked = $this->backups->verify($safety['name']);
        } catch (\Throwable $e) {
            throw new BackupException('The copy of the current site could not be made, so nothing was restored :: ' . $e->getMessage());
        }
        if (!$checked['ok']) {
            throw new BackupException('The copy of the current site could not be checked, so nothing was restored');
        }

        $path = (string) $this->backups->path($name);
        $token = bin2hex(random_bytes(4));
        $stages = [];
        $tmpDb = '';
        try {
            // 3 y 4. Ficheros y volcado, extraídos.
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) {
                throw new BackupException('The file is not a valid zip');
            }
            try {
                $filesCount = $doFiles ? $this->extractFiles($zip, $token, $stages) : 0;
                if ($doDb) {
                    $tmpDb = $this->extractDump($zip, (string) $manifest['parts']['db']['entry'], $token);
                }
            } finally {
                $zip->close();
            }
            $result = $this->swap($manifest, $doDb, $tmpDb, $stages, $token);
            $stages = []; // ya ocupan su sitio: no hay nada que borrar
        } finally {
            foreach ($stages as $stage) {
                if (is_dir($stage)) {
                    Cache::removeDir($stage);
                }
            }
            if ($tmpDb !== '' && is_file($tmpDb)) {
                @unlink($tmpDb);
            }
        }

        return ['safety' => $safety['name'], 'old' => $result['old'], 'tables' => $doDb ? (int) $manifest['database']['tables'] : 0,
            'files' => $filesCount, 'migrations' => $result['migrations']];
    }

    // ------------------------------------------------------------------ extraer

    /**
     * @param array<string,string> $stages parte => carpeta nueva (se rellena)
     */
    private function extractFiles(\ZipArchive $zip, string $token, array &$stages): int
    {
        $dirs = BackupAreas::dirs($this->app);
        foreach ($dirs as $part => $live) {
            $stage = $live . '.restore-' . $token;
            if (file_exists($stage) || (!is_dir(dirname($live)) && !@mkdir(dirname($live), 0775, true)) || !@mkdir($stage, 0775, true)) {
                throw new BackupException('The folder for the restored files could not be created :: ' . basename($live));
            }
            $stages[$part] = $stage;
        }
        $list = $zip->getStream('files.lst');
        if ($list === false) {
            throw new BackupException('The list of files could not be read');
        }
        $count = 0;
        try {
            while (($line = fgets($list)) !== false) {
                $f = explode("\t", rtrim($line, "\n"), 4);
                if (count($f) !== 4 || !isset($stages[$f[0]]) || !BackupAreas::safeRelative($f[3])) {
                    throw new BackupException('The list of files has a wrong line');
                }
                $this->extractOne($zip, 'files/' . $f[0] . '/' . $f[3], $stages[$f[0]] . '/' . $f[3], $f[1]);
                ++$count;
            }
        } finally {
            fclose($list);
        }

        return $count;
    }

    private function extractOne(\ZipArchive $zip, string $entry, string $target, string $sha): void
    {
        $in = $zip->getStream($entry);
        if ($in === false) {
            throw new BackupException('A file is missing from the backup :: ' . $entry);
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            fclose($in);
            throw new BackupException('The folder for the restored files could not be created :: ' . $entry);
        }
        $out = @fopen($target, 'wb');
        if ($out === false) {
            fclose($in);
            throw new BackupException('A file could not be written :: ' . $entry);
        }
        $ctx = hash_init('sha256');
        try {
            while (!feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false) {
                    throw new BackupException('A file could not be read :: ' . $entry);
                }
                hash_update($ctx, $chunk);
                if ($chunk !== '' && fwrite($out, $chunk) === false) {
                    throw new BackupException('A file could not be written :: ' . $entry);
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }
        if (hash_final($ctx) !== $sha) {
            throw new BackupException('The sum of a file does not match :: ' . $entry);
        }
    }

    private function extractDump(\ZipArchive $zip, string $entry, string $token): string
    {
        $tmp = $this->app->site()->path('tmp', true) . '/restore-' . $token . ($entry === 'db/database.sqlite' ? '.sqlite' : '.sql');
        $this->extractOneNoSum($zip, $entry, $tmp);

        return $tmp;
    }

    private function extractOneNoSum(\ZipArchive $zip, string $entry, string $target): void
    {
        $in = $zip->getStream($entry);
        $out = @fopen($target, 'wb');
        if ($in === false || $out === false) {
            if ($in !== false) {
                fclose($in);
            }
            throw new BackupException('The database dump could not be read');
        }
        try {
            while (!feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false || ($chunk !== '' && fwrite($out, $chunk) === false)) {
                    throw new BackupException('The database dump could not be read');
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    // ------------------------------------------------------------------ cambiar

    /**
     * @param array<string,mixed>  $manifest
     * @param array<string,string> $stages
     *
     * @return array{old:string[],migrations:int}
     */
    private function swap(array $manifest, bool $doDb, string $tmpDb, array $stages, string $token): array
    {
        $settings = $this->app->settings();
        $was = $settings->bool('tools.maintenance', false);
        $result = ['old' => [], 'migrations' => 0];
        $then = function () use ($stages, $token, &$result): void {
            $this->fault('files', '');
            $result['old'] = $this->swapFiles($stages, $token);
            try {
                $this->fault('migrate', '');
                $result['migrations'] = count($this->app->migrate(true));
                $this->app->settings()->reload();
                CacheTools::clear($this->app, 'data');
                CacheTools::clear($this->app, 'pages');
            } catch (\Throwable $e) {
                // Con la base ya cambiada y algo que falla después, las carpetas vuelven; la base la deshace quien llama.
                $this->undoFiles($this->lastFilesUndo);
                $this->lastFilesUndo = [];
                throw $e;
            }
        };
        $settings->set('tools.maintenance', true);
        try {
            if ($doDb) {
                if ($manifest['database']['engine'] === 'sqlite') {
                    $this->swapSqlite($manifest, $tmpDb, $then);
                } else {
                    $this->swapMysql($manifest, $tmpDb, $then, $token);
                }
            } else {
                $then();
            }
        } finally {
            $this->app->settings()->set('tools.maintenance', $was);
            $this->app->settings()->reload();
        }

        return $result;
    }

    /**
     * Mueve las carpetas vivas a un lado y pone las nuevas. Si algo falla, lo deja como estaba.
     *
     * @param array<string,string> $stages parte => carpeta nueva
     *
     * @return string[] las carpetas viejas que quedan apartadas
     */
    private function swapFiles(array $stages, string $token): array
    {
        $dirs = BackupAreas::dirs($this->app);
        $stamp = gmdate('Ymd-His', $this->app->clock()->now());
        $undo = [];
        $old = [];
        try {
            foreach ($stages as $part => $stage) {
                $live = $dirs[$part];
                $aside = $live . '.before-restore-' . $stamp . '-' . $token;
                if (is_dir($live)) {
                    // Las copias viven dentro de la carpeta privada y no entran en ella: pasan a la nueva tal cual.
                    if ($part === 'private' && is_dir($live . '/backups')) {
                        if (!@rename($live . '/backups', $stage . '/backups')) {
                            throw new BackupException('The folder of the backups could not be moved');
                        }
                        $undo[] = static function () use ($live, $stage): void {
                            if (is_dir($stage . '/backups')) {
                                @rename($stage . '/backups', $live . '/backups');
                            }
                        };
                    }
                    if (!@rename($live, $aside)) {
                        throw new BackupException('A folder could not be put aside :: ' . basename($live));
                    }
                    $undo[] = static function () use ($live, $aside): void {
                        @rename($aside, $live);
                    };
                    $old[] = $aside;
                }
                if (!@rename($stage, $live)) {
                    throw new BackupException('The restored folder could not be put in place :: ' . basename($live));
                }
                $undo[] = static function () use ($live, $stage): void {
                    @rename($live, $stage);
                };
            }
        } catch (\Throwable $e) {
            $this->undoFiles($undo);
            throw $e;
        }
        $this->lastFilesUndo = $undo;

        return $old;
    }

    /** @var array<int,callable> cómo deshacer el último cambio de carpetas, por si falla un paso posterior */
    private $lastFilesUndo = [];

    /**
     * @param array<int,callable> $undo
     */
    private function undoFiles(array $undo): void
    {
        foreach (array_reverse($undo) as $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'tools.restore');
            }
        }
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function swapSqlite(array $manifest, string $file, callable $then): void
    {
        $db = $this->app->db();
        $pdo = $db->pdo();
        $prefix = $db->prefix();
        $pdo->exec("ATTACH DATABASE '" . str_replace("'", "''", $file) . "' AS bk");
        try {
            $db->transaction(function () use ($db, $pdo, $prefix, $manifest, $then): void {
                $backup = $this->sqliteTables($pdo, 'bk', $prefix);
                $live = $this->sqliteTables($pdo, 'main', $prefix);
                foreach (array_diff($live, $backup) as $table) {
                    $pdo->exec('DROP TABLE main."' . $table . '"');
                }
                $rows = $manifest['database']['rows_by_table'];
                $sequence = (int) $pdo->query("SELECT COUNT(*) FROM bk.sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'")->fetchColumn() > 0;
                foreach ($backup as $table) {
                    $pdo->exec('DROP TABLE IF EXISTS main."' . $table . '"');
                    $create = $pdo->prepare("SELECT type, sql FROM bk.sqlite_master WHERE tbl_name = ? AND sql IS NOT NULL AND type IN ('table', 'index') ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name");
                    $create->execute([$table]);
                    foreach ($create->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                        $sql = (string) $row['sql'];
                        if (preg_match('/^CREATE (TABLE|UNIQUE INDEX|INDEX) /i', $sql) !== 1) {
                            throw new BackupException('The backup has a structure that is not allowed');
                        }
                        $pdo->exec($sql);
                    }
                    $pdo->exec('INSERT INTO main."' . $table . '" SELECT * FROM bk."' . $table . '"');
                    if ($sequence) {
                        $pdo->prepare('DELETE FROM main.sqlite_sequence WHERE name = ?')->execute([$table]);
                        $pdo->prepare('INSERT INTO main.sqlite_sequence (name, seq) SELECT name, seq FROM bk.sqlite_sequence WHERE name = ?')->execute([$table]);
                    }
                    $count = (int) $pdo->query('SELECT COUNT(*) FROM main."' . $table . '"')->fetchColumn();
                    if (!isset($rows[$table]) || (int) $rows[$table] !== $count) {
                        throw new BackupException('A table does not have the rows of the backup :: ' . $table);
                    }
                    $this->fault('table', $table);
                }
                $then();
            });
            $this->lastFilesUndo = [];
        } catch (\Throwable $e) {
            // Si lo que falla es la propia confirmación de la transacción, las carpetas también vuelven.
            $this->undoFiles($this->lastFilesUndo);
            $this->lastFilesUndo = [];
            throw $e;
        } finally {
            try {
                $pdo->exec('DETACH DATABASE bk');
            } catch (\Throwable $ignored) {
                // Si ya no está adjunta, no hay nada que soltar.
            }
        }
    }

    /**
     * @return string[]
     */
    private function sqliteTables(\PDO $pdo, string $schema, string $prefix): array
    {
        $out = [];
        $names = $pdo->query('SELECT name FROM ' . $schema . ".sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name");
        foreach ($names !== false ? $names->fetchAll(\PDO::FETCH_COLUMN) : [] as $name) {
            $name = (string) $name;
            if (strncmp($name, $prefix, strlen($prefix)) === 0) {
                if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
                    throw new BackupException('The backup has a table with a name that is not allowed');
                }
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function swapMysql(array $manifest, string $file, callable $then, string $token): void
    {
        $db = $this->app->db();
        $pdo = $db->pdo();
        $prefix = $db->prefix();
        $temp = [];   // tabla => nombre temporal
        $old = [];    // tabla => nombre con el que se aparta la viva
        $swapped = false;
        $pairs = [];
        try {
            $h = @fopen($file, 'rb');
            if ($h === false) {
                throw new BackupException('The database dump could not be read');
            }
            try {
                foreach (SqlDump::statements($h) as $statement) {
                    $info = SqlDump::classify($statement, $prefix);
                    if ($info['type'] === 'set') {
                        if (strpos($statement, 'NAMES') !== false) {
                            $pdo->exec($statement);
                        }
                        continue;
                    }
                    if ($info['type'] === 'drop') {
                        continue;
                    }
                    $table = $info['table'];
                    if ($info['type'] === 'create') {
                        $temp[$table] = 'zr' . $token . '_' . substr(md5($table), 0, 12);
                        $old[$table] = 'zo' . $token . '_' . substr(md5($table), 0, 12);
                        $pdo->exec('DROP TABLE IF EXISTS `' . $temp[$table] . '`');
                        $pdo->exec(SqlDump::retarget($statement, $table, $temp[$table]));
                        $this->fault('table', $table);
                        continue;
                    }
                    if (!isset($temp[$table])) {
                        throw new BackupException('The dump inserts rows into a table it did not create :: ' . $table);
                    }
                    $pdo->exec(SqlDump::retarget($statement, $table, $temp[$table]));
                }
            } finally {
                fclose($h);
            }
            $rows = $manifest['database']['rows_by_table'];
            foreach ($temp as $table => $name) {
                $count = (int) $pdo->query('SELECT COUNT(*) FROM `' . $name . '`')->fetchColumn(); // revisado: nombre propio de esta clase
                if (!isset($rows[$table]) || (int) $rows[$table] !== $count) {
                    throw new BackupException('A table does not have the rows of the backup :: ' . $table);
                }
            }
            $live = [];
            foreach ($db->select('SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?', ['BASE TABLE']) as $row) {
                if (strncmp((string) $row['n'], $prefix, strlen($prefix)) === 0) {
                    $live[(string) $row['n']] = true;
                }
            }
            $forward = [];
            $back = [];
            foreach ($live as $table => $_) {
                $aside = isset($old[$table]) ? $old[$table] : 'zo' . $token . '_' . substr(md5($table), 0, 12);
                $old[$table] = $aside;
                $forward[] = '`' . $table . '` TO `' . $aside . '`';
                $pairs[$table] = $aside;
            }
            foreach ($temp as $table => $name) {
                $forward[] = '`' . $name . '` TO `' . $table . '`';
            }
            $pdo->exec('RENAME TABLE ' . implode(', ', $forward));
            $swapped = true;
            // Cómo se deshace el cambio de golpe: lo nuevo vuelve a su nombre temporal y lo viejo a su sitio.
            foreach ($temp as $table => $name) {
                $back[] = '`' . $table . '` TO `' . $name . '`';
            }
            foreach ($pairs as $table => $aside) {
                $back[] = '`' . $aside . '` TO `' . $table . '`';
            }
            try {
                $then();
            } catch (\Throwable $e) {
                $pdo->exec('RENAME TABLE ' . implode(', ', $back));
                $swapped = false;
                throw $e;
            }
            $this->lastFilesUndo = [];
            $temp = [];
            if ($pairs !== []) {
                try {
                    $pdo->exec('DROP TABLE IF EXISTS `' . implode('`, `', array_values($pairs)) . '`');
                } catch (\Throwable $e) {
                    // La restauración ya está hecha; las tablas apartadas solo ocupan sitio.
                    $this->app->log()->exception($e, 'tools.restore');
                }
            }
        } finally {
            if (!$swapped) {
                foreach ($temp as $name) {
                    try {
                        $pdo->exec('DROP TABLE IF EXISTS `' . $name . '`');
                    } catch (\Throwable $ignored) {
                        // Una temporal que no se puede borrar solo estorba; la siguiente restauración usa otro nombre.
                    }
                }
            }
        }
    }

    private function fault(string $stage, string $name): void
    {
        if ($this->fault !== null) {
            ($this->fault)($stage, $name);
        }
    }
}

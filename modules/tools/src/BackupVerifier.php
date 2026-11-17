<?php
/**
 * AIFEC — comprobar una copia sin restaurarla: manifiesto, sumas de cada parte y de cada fichero, y que el volcado se lee entero.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

/**
 * Los mensajes de error van en inglés y llevan, si hace falta, el detalle detrás de « :: » (el panel traduce la primera parte).
 * Se acaba a los 20 errores: con un `.zip` entero roto no hace falta contarlos todos.
 */
final class BackupVerifier
{
    private const MAX_ERRORS = 20;
    private const MAX_MANIFEST = 8 * 1024 * 1024;
    private const MAX_LIST = 512 * 1024 * 1024;

    /**
     * @return array{ok:bool,errors:string[],manifest:array<string,mixed>|null,legacy:bool}
     */
    public static function verify(string $path, string $prefix): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'errors' => ['The backup does not exist'], 'manifest' => null, 'legacy' => false];
        }
        if (substr($path, -4) !== '.zip') {
            $errors = self::verifyLegacy($path, $prefix);

            return ['ok' => $errors === [], 'errors' => $errors, 'manifest' => null, 'legacy' => true];
        }
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'errors' => ['The zip extension is not installed'], 'manifest' => null, 'legacy' => false];
        }
        $errors = [];
        $manifest = self::verifyZip($path, $prefix, $errors);

        return ['ok' => $errors === [], 'errors' => $errors, 'manifest' => $manifest, 'legacy' => false];
    }

    /**
     * El manifiesto de un `.zip`, ya comprobado en su forma; null si no se puede leer (y el motivo en $errors).
     *
     * @param string[] $errors
     *
     * @return array<string,mixed>|null
     */
    public static function readManifest(\ZipArchive $zip, array &$errors): ?array
    {
        $index = $zip->locateName('manifest.json');
        $stat = $index !== false ? $zip->statIndex($index) : false;
        if (!is_array($stat)) {
            $errors[] = 'The backup has no manifest';

            return null;
        }
        if ((int) $stat['size'] > self::MAX_MANIFEST) {
            $errors[] = 'The manifest is too large';

            return null;
        }
        $json = $zip->getFromIndex($index);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) {
            $errors[] = 'The manifest is broken';

            return null;
        }
        $db = isset($data['database']) && is_array($data['database']) ? $data['database'] : [];
        $parts = isset($data['parts']) && is_array($data['parts']) ? $data['parts'] : [];
        $ok = isset($data['format']) && (int) $data['format'] === 1
            && isset($data['version']) && is_string($data['version']) && preg_match('/^\d+(\.\d+){0,3}([-+.][0-9A-Za-z.-]+)?$/', $data['version']) === 1
            && isset($data['mode']) && in_array($data['mode'], Backups::MODES, true)
            && isset($data['site']) && is_array($data['site']) && isset($data['site']['url']) && is_string($data['site']['url'])
            && isset($db['engine']) && in_array($db['engine'], ['sqlite', 'mysql'], true)
            && isset($db['prefix']) && is_string($db['prefix']) && isset($db['rows_by_table']) && is_array($db['rows_by_table'])
            && isset($parts['db']) && is_array($parts['db'])
            && isset($parts['db']['entry'], $parts['db']['sha256'], $parts['db']['bytes'])
            && in_array($parts['db']['entry'], ['db/database.sqlite', 'db/dump.sql'], true)
            && is_string($parts['db']['sha256']) && preg_match('/^[a-f0-9]{64}$/', $parts['db']['sha256']) === 1;
        if (!$ok) {
            $errors[] = 'The manifest is broken';

            return null;
        }

        return $data;
    }

    /**
     * @param string[] $errors
     *
     * @return array<string,mixed>|null
     */
    private static function verifyZip(string $path, string $prefix, array &$errors): ?array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) {
            $errors[] = 'The file is not a valid zip';

            return null;
        }
        try {
            $manifest = self::readManifest($zip, $errors);
            if ($manifest === null) {
                return null;
            }
            $known = ['manifest.json' => true];
            $entries = [];
            for ($i = 0, $n = $zip->numFiles; $i < $n; ++$i) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    $errors[] = 'The zip has an unreadable entry';
                    continue;
                }
                $name = (string) $stat['name'];
                if (substr($name, -1) === '/') {
                    continue; // una carpeta
                }
                if (!BackupAreas::safeRelative($name)) {
                    $errors[] = 'The zip has an entry with an unsafe name :: ' . self::clip($name);
                    continue;
                }
                $entries[$name] = (int) $stat['size'];
            }
            $parts = $manifest['parts'];
            $dbPart = $parts['db'];
            self::checkDb($zip, $manifest, $dbPart, $entries, $prefix, $errors);
            $known[(string) $dbPart['entry']] = true;
            if ($manifest['mode'] === 'full') {
                self::checkFiles($zip, $manifest, $entries, $known, $errors);
            }
            foreach ($entries as $name => $size) {
                if (!isset($known[$name]) && count($errors) < self::MAX_ERRORS) {
                    $errors[] = 'The zip has an entry that is not part of the backup :: ' . self::clip($name);
                }
            }
        } finally {
            $zip->close();
        }

        return $manifest;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $dbPart
     * @param array<string,int>   $entries
     * @param string[]            $errors
     */
    private static function checkDb(\ZipArchive $zip, array $manifest, array $dbPart, array $entries, string $prefix, array &$errors): void
    {
        $entry = (string) $dbPart['entry'];
        if (!isset($entries[$entry])) {
            $errors[] = 'The database dump is missing from the backup';

            return;
        }
        $bytes = 0;
        $sha = self::hashEntry($zip, $entry, $bytes);
        if ($sha === null) {
            $errors[] = 'The database dump could not be read';

            return;
        }
        if ($sha !== $dbPart['sha256'] || $bytes !== (int) $dbPart['bytes']) {
            $errors[] = 'The sum of the database dump does not match';

            return;
        }
        $rows = $manifest['database']['rows_by_table'];
        if ($entry === 'db/database.sqlite') {
            $h = $zip->getStream($entry);
            $head = $h !== false ? fread($h, 16) : '';
            if ($h !== false) {
                fclose($h);
            }
            if ($head !== "SQLite format 3\0") {
                $errors[] = 'The database dump is not a SQLite database';
            }

            return;
        }
        $h = $zip->getStream($entry);
        if ($h === false) {
            $errors[] = 'The database dump could not be read';

            return;
        }
        $created = [];
        $last = '';
        try {
            foreach (SqlDump::statements($h) as $statement) {
                $info = SqlDump::classify($statement, $prefix);
                if ($info['type'] === 'create') {
                    $created[$info['table']] = true;
                }
                $last = $statement;
            }
        } catch (\Throwable $e) {
            $errors[] = 'The database dump cannot be read :: ' . self::clip($e->getMessage());

            return;
        } finally {
            fclose($h);
        }
        if ($last !== SqlDump::FOOTER) {
            $errors[] = 'The database dump is cut short';
        }
        $expected = array_keys($rows);
        sort($expected);
        $found = array_keys($created);
        sort($found);
        if ($expected !== $found) {
            $errors[] = 'The tables in the dump do not match the manifest';
        }
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,int>   $entries
     * @param array<string,bool>  $known
     * @param string[]            $errors
     */
    private static function checkFiles(\ZipArchive $zip, array $manifest, array $entries, array &$known, array &$errors): void
    {
        $parts = $manifest['parts'];
        if (!isset($parts['files_list']) || !is_array($parts['files_list']) || !isset($parts['files_list']['sha256']) || !isset($parts['files_list']['bytes'])) {
            $errors[] = 'The manifest is broken';

            return;
        }
        $known['files.lst'] = true;
        if (!isset($entries['files.lst'])) {
            $errors[] = 'The list of files is missing from the backup';

            return;
        }
        if ($entries['files.lst'] > self::MAX_LIST) {
            $errors[] = 'The list of files is too large';

            return;
        }
        $bytes = 0;
        $sha = self::hashEntry($zip, 'files.lst', $bytes);
        if ($sha === null || $sha !== $parts['files_list']['sha256'] || $bytes !== (int) $parts['files_list']['bytes']) {
            $errors[] = 'The sum of the list of files does not match';

            return;
        }
        $h = $zip->getStream('files.lst');
        if ($h === false) {
            $errors[] = 'The list of files could not be read';

            return;
        }
        $ctx = [];
        $count = [];
        $size = [];
        $total = 0;
        try {
            while (($line = fgets($h)) !== false) {
                $f = explode("\t", rtrim($line, "\n"), 4);
                if (count($f) !== 4 || !isset($parts[$f[0]]) || !in_array($f[0], ['media', 'private'], true) || preg_match('/^[a-f0-9]{64}$/', $f[1]) !== 1
                    || !ctype_digit($f[2]) || !BackupAreas::safeRelative($f[3])) {
                    $errors[] = 'The list of files has a wrong line';
                    break;
                }
                $entry = 'files/' . $f[0] . '/' . $f[3];
                $known[$entry] = true;
                if (!isset($ctx[$f[0]])) {
                    $ctx[$f[0]] = hash_init('sha256');
                    $count[$f[0]] = 0;
                    $size[$f[0]] = 0;
                }
                hash_update($ctx[$f[0]], $line);
                ++$count[$f[0]];
                $size[$f[0]] += (int) $f[2];
                ++$total;
                if (!isset($entries[$entry])) {
                    if (count($errors) < self::MAX_ERRORS) {
                        $errors[] = 'A file is missing from the backup :: ' . self::clip($entry);
                    }
                    continue;
                }
                $read = 0;
                $fileSha = self::hashEntry($zip, $entry, $read);
                if ($fileSha !== $f[1] || $read !== (int) $f[2]) {
                    if (count($errors) < self::MAX_ERRORS) {
                        $errors[] = 'The sum of a file does not match :: ' . self::clip($entry);
                    }
                }
                if (count($errors) >= self::MAX_ERRORS) {
                    break;
                }
            }
        } finally {
            fclose($h);
        }
        if (count($errors) >= self::MAX_ERRORS) {
            return;
        }
        foreach (['media', 'private'] as $part) {
            $info = isset($parts[$part]) && is_array($parts[$part]) ? $parts[$part] : null;
            if ($info === null) {
                if (isset($count[$part])) {
                    $errors[] = 'The manifest does not match the list of files';
                }
                continue;
            }
            $sum = isset($ctx[$part]) ? hash_final($ctx[$part]) : hash('sha256', '');
            if (!isset($info['sha256']) || $sum !== $info['sha256'] || (int) ($info['files'] ?? -1) !== ($count[$part] ?? 0) || (int) ($info['bytes'] ?? -1) !== ($size[$part] ?? 0)) {
                $errors[] = 'The sum of a part does not match :: ' . $part;
            }
        }
        if ((int) ($parts['files_list']['count'] ?? -1) !== $total) {
            $errors[] = 'The manifest does not match the list of files';
        }
    }

    /** Suma SHA-256 de una entrada leyéndola por trozos; null si no se puede leer. */
    private static function hashEntry(\ZipArchive $zip, string $entry, int &$bytes): ?string
    {
        $h = $zip->getStream($entry);
        if ($h === false) {
            return null;
        }
        $ctx = hash_init('sha256');
        $bytes = 0;
        try {
            while (!feof($h)) {
                $chunk = fread($h, 1048576);
                if ($chunk === false) {
                    return null;
                }
                $bytes += strlen($chunk);
                hash_update($ctx, $chunk);
            }
        } finally {
            fclose($h);
        }

        return hash_final($ctx);
    }

    /**
     * Una copia de las de antes (solo la base de datos, un fichero suelto): se ve que se lee entera.
     *
     * @return string[]
     */
    private static function verifyLegacy(string $path, string $prefix): array
    {
        $gz = substr($path, -3) === '.gz';
        $name = $gz ? substr($path, 0, -3) : $path;
        if ($gz && !function_exists('gzopen')) {
            return ['The zlib extension is not installed'];
        }
        if (substr($name, -4) === '.sql') {
            $h = $gz ? @gzopen($path, 'rb') : @fopen($path, 'rb');
            if ($h === false) {
                return ['The backup could not be opened'];
            }
            $last = '';
            try {
                foreach (SqlDump::statements($h) as $statement) {
                    SqlDump::classify($statement, $prefix);
                    $last = $statement;
                }
            } catch (\Throwable $e) {
                return ['The database dump cannot be read :: ' . self::clip($e->getMessage())];
            } finally {
                $gz ? gzclose($h) : fclose($h);
            }

            return $last === SqlDump::FOOTER ? [] : ['The database dump is cut short'];
        }
        $file = $path;
        $tmp = '';
        if ($gz) {
            $tmp = $path . '.check-' . bin2hex(random_bytes(4));
            $in = @gzopen($path, 'rb');
            $out = @fopen($tmp, 'wb');
            if ($in === false || $out === false) {
                return ['The backup could not be opened'];
            }
            while (!gzeof($in)) {
                $chunk = gzread($in, 1048576);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($out, $chunk);
            }
            gzclose($in);
            fclose($out);
            $file = $tmp;
        }
        try {
            $pdo = new \PDO('sqlite:' . $file);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $result = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
            $pdo = null;

            return $result === 'ok' ? [] : ['The database dump is not a SQLite database'];
        } catch (\Throwable $e) {
            return ['The database dump is not a SQLite database'];
        } finally {
            if ($tmp !== '') {
                @unlink($tmp);
            }
        }
    }

    private static function clip(string $text): string
    {
        return mb_substr($text, 0, 160, 'UTF-8');
    }
}

<?php
/**
 * AIFEC — una copia completa que se escribe por tandas y se puede retomar.
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
 * Etapas: `db` (volcado de la base de datos) → `list` (qué ficheros entran) → `files` (de tanda en tanda, con las sumas) →
 * `finish` (lista de ficheros y manifiesto) → `done`. Todo lo que hace falta para seguir está en la carpeta de trabajo
 * `private/backups/.run-<ficha>/` (estado, lista de ficheros y el `.zip` a medias); un fallo entre dos tandas no pierde lo hecho.
 *
 * Nunca se carga un fichero entero en memoria: la suma se saca leyendo por trozos y `ZipArchive` lee el contenido al cerrar la tanda.
 * El `.zip` solo toma su nombre definitivo cuando está completo.
 */
final class BackupRun
{
    /** Tope de ficheros y de bytes de origen por tanda: acotan lo que `ZipArchive` reescribe al cerrar. */
    private const SLICE_FILES = 4000;
    private const SLICE_BYTES = 400 * 1024 * 1024;

    /** Extensiones que ya vienen comprimidas: se guardan tal cual, sin gastar tiempo de procesador. */
    private const STORED = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'zip', 'gz', 'mp4', 'webm', 'woff', 'woff2', 'pdf'];

    /** @var Backups */
    private $backups;

    /** @var string */
    private $dir;

    /** @var array<string,mixed> */
    private $state;

    /** @var int ficheros por tanda de esta llamada a work() */
    private $maxFiles = self::SLICE_FILES;

    /**
     * @param array<string,mixed> $state
     */
    private function __construct(Backups $backups, string $dir, array $state)
    {
        $this->backups = $backups;
        $this->dir = $dir;
        $this->state = $state;
    }

    public static function begin(Backups $backups, string $mode): self
    {
        $token = bin2hex(random_bytes(6));
        $dir = $backups->runDir($token);
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('The backup working folder could not be created');
        }
        $run = new self($backups, $dir, [
            'token' => $token, 'mode' => $mode, 'stage' => 'db', 'offset' => 0, 'files_done' => 0, 'files_total' => 0,
            'bytes_done' => 0, 'skipped' => 0, 'done_size' => 0, 'engine' => '', 'db' => [], 'tables' => [],
            'name' => '', 'bytes_total' => 0,
        ]);
        $run->save();

        return $run;
    }

    public static function load(Backups $backups, string $token): ?self
    {
        if (preg_match('/^[a-f0-9]{12}$/', $token) !== 1) {
            return null;
        }
        $dir = $backups->runDir($token);
        $file = $dir . '/state.json';
        if (!is_file($file)) {
            return null;
        }
        $state = json_decode((string) file_get_contents($file), true);

        return is_array($state) && isset($state['token'], $state['stage']) ? new self($backups, $dir, $state) : null;
    }

    /**
     * Lo que se enseña en la barra de avance.
     *
     * @return array{token:string,mode:string,stage:string,done:int,total:int,percent:int,name:string,bytes_total:int,skipped:int}
     */
    public function state(): array
    {
        $total = (int) $this->state['files_total'];
        $done = (int) $this->state['files_done'];
        $stage = (string) $this->state['stage'];
        if ($stage === 'done') {
            $percent = 100;
        } elseif ($stage === 'db') {
            $percent = 5;
        } elseif ($stage === 'list') {
            $percent = 10;
        } elseif ($stage === 'files') {
            $percent = 10 + ($total > 0 ? (int) floor(85 * $done / $total) : 85);
        } else {
            $percent = 97;
        }

        return [
            'token' => (string) $this->state['token'], 'mode' => (string) $this->state['mode'], 'stage' => $stage,
            'done' => $done, 'total' => $total, 'percent' => $percent, 'name' => (string) $this->state['name'],
            'bytes_total' => (int) $this->state['bytes_total'], 'skipped' => (int) $this->state['skipped'],
        ];
    }

    /**
     * Sigue trabajando hasta que se acaba el tiempo (en segundos) o la copia termina. `$maxFiles` acota cuántos ficheros entran
     * en cada tanda (0 = lo de serie) y `$maxSteps` cuántas etapas o tandas se hacen en esta llamada (0 = hasta el tiempo); las
     * pruebas los bajan para ver varias tandas.
     *
     * @return array{token:string,mode:string,stage:string,done:int,total:int,percent:int,name:string,bytes_total:int,skipped:int}
     */
    public function work(float $seconds, int $maxFiles = 0, int $maxSteps = 0): array
    {
        $deadline = microtime(true) + $seconds;
        $this->maxFiles = $maxFiles > 0 ? $maxFiles : self::SLICE_FILES;
        $steps = 0;
        do {
            switch ((string) $this->state['stage']) {
                case 'db':
                    $this->stageDb();
                    break;
                case 'list':
                    $this->stageList();
                    break;
                case 'files':
                    $this->stageFiles($deadline);
                    break;
                case 'finish':
                    $this->stageFinish();
                    break;
                default:
                    return $this->state();
            }
            ++$steps;
        } while ((string) $this->state['stage'] !== 'done' && microtime(true) < $deadline && ($maxSteps === 0 || $steps < $maxSteps));

        return $this->state();
    }

    /** Deja la copia a medias: borra su carpeta de trabajo. */
    public function abort(): void
    {
        Cache::removeDir($this->dir);
    }

    // ------------------------------------------------------------------ etapas

    private function stageDb(): void
    {
        $tmp = $this->dir . '/db.tmp';
        $info = $this->backups->dumpDatabase($tmp);
        $rows = 0;
        foreach ($info['tables'] as $n) {
            $rows += $n;
        }
        $sha = hash_file('sha256', $tmp);
        if (!is_string($sha)) {
            throw new \RuntimeException('The database dump could not be read');
        }
        $zip = $this->openZip();
        if (!$zip->addFile($tmp, $info['entry'])) {
            throw new \RuntimeException('The database dump could not be added to the backup');
        }
        $this->closeZip($zip);
        $this->state['db'] = ['entry' => $info['entry'], 'bytes' => (int) filesize($tmp), 'sha256' => $sha];
        @unlink($tmp);
        $this->state['engine'] = $info['engine'];
        $this->state['tables'] = $info['tables'];
        $this->state['rows'] = $rows;
        $this->state['stage'] = $this->state['mode'] === 'full' ? 'list' : 'finish';
        $this->save();
    }

    private function stageList(): void
    {
        $list = @fopen($this->dir . '/list.tsv', 'wb');
        if ($list === false) {
            throw new \RuntimeException('The file list could not be written');
        }
        $total = 0;
        try {
            foreach (BackupAreas::dirs($this->backups->app()) as $part => $base) {
                foreach (BackupAreas::files($base, $part) as $rel) {
                    if (strpos($rel, "\t") !== false || strpos($rel, "\n") !== false) {
                        continue; // un nombre con tabulador o salto de línea no cabe en la lista: se deja fuera
                    }
                    fwrite($list, $part . "\t" . $rel . "\n");
                    ++$total;
                }
            }
        } finally {
            fclose($list);
        }
        $this->state['files_total'] = $total;
        $this->state['stage'] = $total > 0 ? 'files' : 'finish';
        $this->save();
    }

    private function stageFiles(float $deadline): void
    {
        $list = @fopen($this->dir . '/list.tsv', 'rb');
        if ($list === false) {
            throw new \RuntimeException('The file list could not be read');
        }
        $dirs = BackupAreas::dirs($this->backups->app());
        $zip = $this->openZip();
        $lines = '';
        $count = 0;
        $bytes = 0;
        $skipped = 0;
        $offset = (int) $this->state['offset'];
        try {
            fseek($list, $offset);
            while (($line = fgets($list)) !== false) {
                $parts = explode("\t", rtrim($line, "\n"), 2);
                $next = $offset + strlen($line);
                $offset = $next;
                if (count($parts) !== 2 || !isset($dirs[$parts[0]])) {
                    ++$skipped;
                    continue;
                }
                $abs = $dirs[$parts[0]] . '/' . $parts[1];
                $size = is_file($abs) ? @filesize($abs) : false;
                $sha = $size !== false ? @hash_file('sha256', $abs) : false;
                if ($size === false || !is_string($sha)) {
                    ++$skipped; // desapareció entre la lista y la copia
                    continue;
                }
                $entry = 'files/' . $parts[0] . '/' . $parts[1];
                if (!$zip->addFile($abs, $entry)) {
                    throw new \RuntimeException('A file could not be added to the backup');
                }
                $this->compression($zip, $entry, $parts[1]);
                $lines .= $parts[0] . "\t" . $sha . "\t" . $size . "\t" . $parts[1] . "\n";
                ++$count;
                $bytes += (int) $size;
                if ($count >= $this->maxFiles || $bytes >= self::SLICE_BYTES || microtime(true) >= $deadline) {
                    break;
                }
            }
            $atEnd = feof($list) || fgets($list) === false;
        } finally {
            fclose($list);
        }
        if ($count > 0) {
            $this->closeZip($zip);
        } else {
            @$zip->close();
        }
        $done = $this->dir . '/done.lst';
        // Si una tanda anterior murió entre escribir la lista y guardar el estado, lo escrito de más se descarta.
        $handle = @fopen($done, 'cb');
        if ($handle === false) {
            throw new \RuntimeException('The file sums could not be written');
        }
        try {
            ftruncate($handle, (int) $this->state['done_size']);
            fseek($handle, 0, SEEK_END);
            fwrite($handle, $lines);
            fflush($handle);
        } finally {
            fclose($handle);
        }
        $this->state['done_size'] = (int) $this->state['done_size'] + strlen($lines);
        $this->state['offset'] = $offset;
        $this->state['files_done'] = (int) $this->state['files_done'] + $count + $skipped;
        $this->state['bytes_done'] = (int) $this->state['bytes_done'] + $bytes;
        $this->state['skipped'] = (int) $this->state['skipped'] + $skipped;
        if ($atEnd) {
            $this->state['stage'] = 'finish';
            $this->state['files_done'] = (int) $this->state['files_total'];
        }
        $this->save();
    }

    private function stageFinish(): void
    {
        $app = $this->backups->app();
        $parts = ['db' => $this->state['db']];
        $zip = $this->openZip();
        $tmpList = $this->dir . '/done.lst';
        $extra = [];
        if ($this->state['mode'] === 'full') {
            if (!is_file($tmpList)) {
                @file_put_contents($tmpList, '');
            }
            $sums = $this->partSums($tmpList);
            $parts['files_list'] = ['entry' => 'files.lst', 'bytes' => (int) filesize($tmpList), 'sha256' => (string) hash_file('sha256', $tmpList), 'count' => $sums['count']];
            foreach ($sums['parts'] as $name => $info) {
                $parts[$name] = $info;
            }
            if (!$zip->addFile($tmpList, 'files.lst')) {
                throw new \RuntimeException('The file list could not be added to the backup');
            }
            $extra['files'] = $sums['count'];
        }
        $tables = $this->state['tables'];
        $manifest = [
            'format' => 1,
            'app' => 'AIFEC',
            'version' => App::VERSION,
            'created_at' => $app->clock()->sql(),
            'mode' => (string) $this->state['mode'],
            'site' => ['id' => $app->site()->id, 'url' => $app->site()->url()],
            'database' => [
                'engine' => (string) $this->state['engine'],
                'prefix' => $app->db()->prefix(),
                'tables' => count($tables),
                'rows' => (int) ($this->state['rows'] ?? 0),
                'rows_by_table' => $tables === [] ? new \stdClass() : $tables,
            ],
            'parts' => $parts,
        ] + ($extra === [] ? [] : ['files' => $extra['files']]);
        $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($json) || !$zip->addFromString('manifest.json', $json)) {
            throw new \RuntimeException('The manifest could not be added to the backup');
        }
        $this->closeZip($zip);
        $final = $this->backups->nextName('zip');
        $target = $this->backups->dir() . '/' . $final;
        if (!@rename($this->zipPath(), $target)) {
            throw new \RuntimeException('The backup could not be saved');
        }
        $this->state['name'] = $final;
        $this->state['bytes_total'] = (int) filesize($target);
        $this->state['stage'] = 'done';
        Cache::removeDir($this->dir);
    }

    // ------------------------------------------------------------------ piezas

    /**
     * Cuenta y suma cada parte a partir de la lista de ficheros: el hash de las líneas de la parte, en el orden de la lista.
     *
     * @return array{count:int,parts:array<string,array{files:int,bytes:int,sha256:string}>}
     */
    private function partSums(string $file): array
    {
        $h = fopen($file, 'rb');
        if ($h === false) {
            throw new \RuntimeException('The file list could not be read');
        }
        $ctx = [];
        $info = [];
        $count = 0;
        try {
            while (($line = fgets($h)) !== false) {
                $f = explode("\t", rtrim($line, "\n"), 4);
                if (count($f) !== 4) {
                    continue;
                }
                $part = $f[0];
                if (!isset($ctx[$part])) {
                    $ctx[$part] = hash_init('sha256');
                    $info[$part] = ['files' => 0, 'bytes' => 0, 'sha256' => ''];
                }
                hash_update($ctx[$part], $line);
                ++$info[$part]['files'];
                $info[$part]['bytes'] += (int) $f[2];
                ++$count;
            }
        } finally {
            fclose($h);
        }
        foreach ($ctx as $part => $c) {
            $info[$part]['sha256'] = hash_final($c);
        }
        ksort($info);

        return ['count' => $count, 'parts' => $info];
    }

    private function zipPath(): string
    {
        return $this->dir . '/archive.zip.part';
    }

    private function openZip(): \ZipArchive
    {
        $zip = new \ZipArchive();
        if ($zip->open($this->zipPath(), \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('The backup file could not be opened');
        }

        return $zip;
    }

    private function closeZip(\ZipArchive $zip): void
    {
        if (!$zip->close()) {
            throw new \RuntimeException('The backup file could not be written');
        }
    }

    private function compression(\ZipArchive $zip, string $entry, string $rel): void
    {
        $ext = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));
        if (in_array($ext, self::STORED, true) && method_exists($zip, 'setCompressionName')) {
            $zip->setCompressionName($entry, \ZipArchive::CM_STORE);
        }
    }

    private function save(): void
    {
        $json = json_encode($this->state);
        if (!is_string($json) || @file_put_contents($this->dir . '/state.json', $json, LOCK_EX) === false) {
            throw new \RuntimeException('The backup state could not be saved');
        }
    }
}

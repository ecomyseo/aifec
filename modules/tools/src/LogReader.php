<?php
/**
 * AIFEC — lectura del registro del sitio: las últimas líneas de un fichero leyéndolo desde el final, y su limpieza.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

/**
 * Los ficheros son los que escribe Aifec\Kernel\Logger: `<canal>-AAAA-MM-DD.log`, una línea JSON por suceso
 * (`time`, `level`, `message`, `context`). El contexto ya viene enmascarado; quien lo pinte tiene que escaparlo.
 */
final class LogReader
{
    private const FILE = '/^([a-z0-9_-]+)-(\d{4}-\d{2}-\d{2})\.log$/';

    /** Bytes que se leen de golpe desde el final. */
    private const CHUNK = 65536;

    /** Tope de caracteres que se enseñan de un mensaje. */
    private const MESSAGE_MAX = 4000;

    /** @var string */
    private $dir;

    public function __construct(string $dir)
    {
        $this->dir = Fs::norm($dir);
    }

    /**
     * Los ficheros de registro que hay, los más recientes primero.
     *
     * @return array<int,array{name:string,channel:string,date:string,bytes:int}>
     */
    public function files(): array
    {
        $out = [];
        $names = is_dir($this->dir) ? scandir($this->dir) : [];
        foreach (is_array($names) ? $names : [] as $name) {
            if (preg_match(self::FILE, $name, $m) !== 1 || !is_file($this->dir . '/' . $name)) {
                continue;
            }
            $out[] = ['name' => $name, 'channel' => $m[1], 'date' => $m[2], 'bytes' => (int) filesize($this->dir . '/' . $name)];
        }
        usort($out, static function (array $a, array $b): int {
            return $a['date'] === $b['date'] ? strcmp($a['channel'], $b['channel']) : strcmp($b['date'], $a['date']);
        });

        return $out;
    }

    /** Ruta de un fichero de registro por su nombre; null si no es uno de los que hay (nunca se confía en el nombre que llega). */
    public function path(string $name): ?string
    {
        foreach ($this->files() as $file) {
            if ($file['name'] === $name) {
                return $this->dir . '/' . $name;
            }
        }

        return null;
    }

    /**
     * Las últimas líneas que cumplen el filtro, la más reciente primero, leyendo el fichero desde el final y sin cargarlo
     * entero: se para en cuanto tiene las que se piden o ha mirado $scanCap bytes.
     *
     * @param string $level '' (todos), error, warning o info
     * @param string $text  fragmento que tiene que aparecer en la línea (sin distinguir mayúsculas)
     *
     * @return array{entries:array<int,array<string,mixed>>,bytes_read:int,size:int,complete:bool}
     *         complete = la lectura llegó al principio del fichero (si es false, hay líneas más antiguas sin mirar)
     */
    public function tail(string $path, int $limit = 100, string $level = '', string $text = '', int $scanCap = 8388608): array
    {
        $out = ['entries' => [], 'bytes_read' => 0, 'size' => 0, 'complete' => true];
        $h = is_file($path) ? @fopen($path, 'rb') : false;
        if ($h === false) {
            return $out;
        }
        $limit = max(1, $limit);
        $size = (int) filesize($path);
        $out['size'] = $size;
        $pos = $size;
        $partial = '';
        try {
            while ($pos > 0 && count($out['entries']) < $limit && $out['bytes_read'] < $scanCap) {
                $chunk = min(self::CHUNK, $pos);
                $pos -= $chunk;
                fseek($h, $pos);
                $data = (string) fread($h, $chunk);
                $out['bytes_read'] += $chunk;
                $lines = explode("\n", $data . $partial);
                // El primer trozo puede estar cortado por la mitad: se guarda para unirlo con lo que venga antes.
                $partial = $pos > 0 ? (string) array_shift($lines) : '';
                for ($i = count($lines) - 1; $i >= 0 && count($out['entries']) < $limit; --$i) {
                    $this->take($lines[$i], $level, $text, $out['entries']);
                }
            }
            $out['complete'] = $pos === 0;
        } finally {
            fclose($h);
        }

        return $out;
    }

    /**
     * Borra los ficheros de registro de más de $keepDays días (por la fecha de su nombre). Devuelve cuántos.
     */
    public function purge(int $keepDays, int $now): int
    {
        $cut = gmdate('Y-m-d', $now - max(1, $keepDays) * 86400);
        $removed = 0;
        foreach ($this->files() as $file) {
            if ($file['date'] < $cut && @unlink($this->dir . '/' . $file['name'])) {
                ++$removed;
            }
        }

        return $removed;
    }

    // ------------------------------------------------------------------ interior

    /**
     * @param array<int,array<string,mixed>> $entries
     */
    private function take(string $line, string $level, string $text, array &$entries): void
    {
        $line = rtrim($line, "\r");
        if ($line === '' || ($text !== '' && stripos($line, $text) === false)) {
            return;
        }
        $entry = self::parse($line);
        if ($level !== '' && $entry['level'] !== $level) {
            return;
        }
        $entries[] = $entry;
    }

    /**
     * @return array{time:string,level:string,message:string,context:array<string,mixed>}
     */
    public static function parse(string $line): array
    {
        $data = json_decode($line, true);
        if (is_array($data) && isset($data['level'], $data['message'])) {
            $message = is_scalar($data['message']) ? (string) $data['message'] : '';

            return [
                'time' => isset($data['time']) && is_scalar($data['time']) ? (string) $data['time'] : '',
                'level' => is_scalar($data['level']) ? strtolower((string) $data['level']) : 'unknown',
                'message' => mb_substr($message, 0, self::MESSAGE_MAX, 'UTF-8'),
                'context' => isset($data['context']) && is_array($data['context']) ? $data['context'] : [],
            ];
        }

        return ['time' => '', 'level' => 'unknown', 'message' => mb_substr($line, 0, self::MESSAGE_MAX, 'UTF-8'), 'context' => []];
    }
}

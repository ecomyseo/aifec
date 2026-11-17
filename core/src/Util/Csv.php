<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Util;

/**
 * Lectura y escritura de CSV en flujo: el fichero no se carga entero en memoria. La lectura
 * detecta el delimitador, quita el BOM y convierte cada celda a UTF-8. La escritura neutraliza
 * las fórmulas (=, +, -, @) para que una hoja de cálculo no las ejecute.
 */
final class Csv
{
    private const DELIMITERS = [',', ';', "\t", '|'];

    /**
     * Filas como arrays asociativos (primera fila = cabecera) o indexados con 'header' => false.
     * Opciones: 'header' (bool, por defecto true) y 'delimiter' (string; si no, se detecta).
     */
    public static function read(string $path, array $options = []): \Generator
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Cannot read CSV file: ' . $path);
        }
        $useHeader = array_key_exists('header', $options) ? (bool) $options['header'] : true;
        $delimiter = $options['delimiter'] ?? null;

        $h = fopen($path, 'rb');
        if ($h === false) {
            throw new \RuntimeException('Cannot open CSV file: ' . $path);
        }
        try {
            if (fread($h, 3) !== "\xEF\xBB\xBF") {
                fseek($h, 0);
            }
            $start = (int) ftell($h);
            if (!is_string($delimiter) || $delimiter === '') {
                $delimiter = self::detectDelimiter(self::sampleLines($h));
                fseek($h, $start);
            }

            $names = null;
            while (($row = fgetcsv($h, 0, $delimiter, '"', '')) !== false) {
                if (self::isBlank($row)) {
                    continue;
                }
                $row = array_map(function ($field): string {
                    return Str::toUtf8($field === null ? '' : (string) $field);
                }, $row);

                if (!$useHeader) {
                    yield $row;
                    continue;
                }
                if ($names === null) {
                    $names = array_map('trim', $row);
                    continue;
                }
                $assoc = [];
                foreach ($names as $i => $name) {
                    $assoc[$name] = $row[$i] ?? '';
                }
                yield $assoc;
            }
        } finally {
            fclose($h);
        }
    }

    /**
     * Escribe cabecera y filas. Cada fila puede ser asociativa (se toma por nombre de columna) o
     * indexada (por posición). Devuelve el número de filas escritas (sin contar la cabecera).
     *
     * @param iterable $rows
     */
    public static function write(string $path, array $header, iterable $rows, string $delimiter = ';', bool $bom = true): int
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException('Cannot write CSV file: ' . $path);
        }
        $h = fopen($path, 'wb');
        if ($h === false) {
            throw new \RuntimeException('Cannot write CSV file: ' . $path);
        }
        try {
            if ($bom) {
                fwrite($h, "\xEF\xBB\xBF");
            }

            return self::writeRows($h, $header, $rows, $delimiter);
        } finally {
            fclose($h);
        }
    }

    /**
     * Igual que write() pero devuelve el texto (sin BOM).
     *
     * @param iterable $rows
     */
    public static function toString(array $header, iterable $rows, string $delimiter = ';'): string
    {
        $h = fopen('php://memory', 'w+b');
        if ($h === false) {
            throw new \RuntimeException('Cannot open memory stream');
        }
        try {
            self::writeRows($h, $header, $rows, $delimiter);
            rewind($h);

            return (string) stream_get_contents($h);
        } finally {
            fclose($h);
        }
    }

    /** @param iterable $rows */
    private static function writeRows($h, array $header, iterable $rows, string $delimiter): int
    {
        $names = array_values($header);
        $cells = [];
        foreach ($names as $name) {
            $cells[] = self::safeCell($name);
        }
        fputcsv($h, $cells, $delimiter, '"', '');

        $written = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $row = [];
            }
            $assoc = Arr::isAssoc($row);
            $values = [];
            foreach ($names as $i => $name) {
                if ($assoc) {
                    $value = array_key_exists($name, $row) ? $row[$name] : null;
                } else {
                    $value = array_key_exists($i, $row) ? $row[$i] : null;
                }
                $values[] = self::safeCell($value);
            }
            fputcsv($h, $values, $delimiter, '"', '');
            ++$written;
        }

        return $written;
    }

    /** Una celda de texto que empieza por =, +, - o @ se escribe con un apóstrofo delante. */
    private static function safeCell($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value) || is_object($value)) {
            return Json::encode($value);
        }
        if (is_string($value)) {
            if ($value !== '' && strpos('=+-@', $value[0]) !== false) {
                return "'" . $value;
            }

            return $value;
        }

        return (string) $value;
    }

    /** @return string[] hasta cinco líneas no vacías, sin fin de línea */
    private static function sampleLines($h): array
    {
        $lines = [];
        while (count($lines) < 5) {
            $line = fgets($h);
            if ($line === false) {
                break;
            }
            $line = rtrim($line, "\r\n");
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Delimitador más frecuente en la cabecera (primera línea de muestra), fuera de comillas.
     * Se mira solo la cabecera: las celdas con saltos de línea dentro rompen cualquier recuento
     * de las líneas siguientes. Si no aparece ninguno, la coma.
     *
     * @param string[] $lines
     */
    private static function detectDelimiter(array $lines): string
    {
        $header = $lines[0] ?? '';
        $best = ',';
        $bestCount = 0;
        foreach (self::DELIMITERS as $delimiter) {
            $count = self::countOutsideQuotes($header, $delimiter);
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $delimiter;
            }
        }

        return $best;
    }

    private static function countOutsideQuotes(string $line, string $delimiter): int
    {
        $count = 0;
        $inQuotes = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; ++$i) {
            $c = $line[$i];
            if ($c === '"') {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes && $c === $delimiter) {
                ++$count;
            }
        }

        return $count;
    }

    /** @param mixed $row */
    private static function isBlank($row): bool
    {
        if (!is_array($row)) {
            return true;
        }
        foreach ($row as $field) {
            if ($field !== null && trim((string) $field) !== '') {
                return false;
            }
        }

        return true;
    }
}

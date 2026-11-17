<?php
/**
 * AIFEC — lectura de un CSV fila a fila que se puede dejar y retomar por donde iba (por posición en el fichero).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Util\Str;

/**
 * Reconoce `;`, `,`, tabulador y `|` mirando la cabecera, quita la marca BOM y pasa cada celda a UTF-8 (un fichero guardado
 * por una hoja de cálculo en Windows-1252 se lee bien). Lo de dentro de comillas, saltos de línea incluidos, es una celda.
 * `Aifec\Util\Csv::read()` no sirve para esto: siempre empieza por el principio y aquí hay que retomar a mitad de fichero.
 */
final class CsvStream
{
    private const DELIMITERS = [';', ',', "\t", '|'];

    /** @var resource */
    private $handle;

    /** @var string */
    private $delimiter;

    /** @var string[] */
    private $header;

    /** @var int posición del fichero justo después de la última fila leída */
    private $position;

    /**
     * @param resource $handle
     * @param string[] $header
     */
    private function __construct($handle, string $delimiter, array $header, int $position)
    {
        $this->handle = $handle;
        $this->delimiter = $delimiter;
        $this->header = $header;
        $this->position = $position;
    }

    /**
     * Abre el fichero, lee la cabecera y, si se pide, salta a una posición ya leída.
     *
     * @param string|null $delimiter null = se reconoce por la cabecera
     * @param int         $offset    posición (la que dio position() de una lectura anterior); 0 = justo después de la cabecera
     *
     * @throws \RuntimeException si no se puede abrir o está vacío
     */
    public static function open(string $path, ?string $delimiter = null, int $offset = 0): self
    {
        $h = is_file($path) ? @fopen($path, 'rb') : false;
        if ($h === false) {
            throw new \RuntimeException('The file could not be opened');
        }
        if (fread($h, 3) !== "\xEF\xBB\xBF") {
            fseek($h, 0);
        }
        $start = (int) ftell($h);
        if ($delimiter === null || $delimiter === '') {
            $line = fgets($h);
            $delimiter = self::detect($line === false ? '' : rtrim($line, "\r\n"));
            fseek($h, $start);
        }
        $row = fgetcsv($h, 0, $delimiter, '"', '');
        if ($row === false || $row === [null]) {
            fclose($h);
            throw new \RuntimeException('The file is empty');
        }
        $header = array_map(static function ($cell): string {
            return trim(Str::toUtf8($cell === null ? '' : (string) $cell));
        }, $row);
        $position = (int) ftell($h);
        if ($offset > 0) {
            fseek($h, $offset);
            $position = $offset;
        }

        return new self($h, $delimiter, $header, $position);
    }

    /** @return string[] */
    public function header(): array
    {
        return $this->header;
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    public function position(): int
    {
        return $this->position;
    }

    /**
     * La fila siguiente (las vacías se saltan), con las celdas en UTF-8, o null si no hay más.
     *
     * @return string[]|null
     */
    public function next(): ?array
    {
        while (($row = fgetcsv($this->handle, 0, $this->delimiter, '"', '')) !== false) {
            $this->position = (int) ftell($this->handle);
            $blank = true;
            foreach ($row as $cell) {
                if ($cell !== null && trim((string) $cell) !== '') {
                    $blank = false;
                    break;
                }
            }
            if ($blank) {
                continue;
            }

            return array_map(static function ($cell): string {
                return Str::toUtf8($cell === null ? '' : (string) $cell);
            }, $row);
        }

        return null;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /** El separador que más aparece en la cabecera, fuera de comillas; la coma si no aparece ninguno. */
    private static function detect(string $line): string
    {
        $best = ';';
        $bestCount = 0;
        foreach (self::DELIMITERS as $delimiter) {
            $count = 0;
            $inQuotes = false;
            $len = strlen($line);
            for ($i = 0; $i < $len; ++$i) {
                if ($line[$i] === '"') {
                    $inQuotes = !$inQuotes;
                } elseif (!$inQuotes && $line[$i] === $delimiter) {
                    ++$count;
                }
            }
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $delimiter;
            }
        }

        return $bestCount > 0 ? $best : ',';
    }
}

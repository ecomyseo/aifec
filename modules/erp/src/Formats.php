<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp;

use Aifec\Commerce\Money;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;

/**
 * Escribe las filas de una exportación en el formato que pida el ERP: CSV, JSON o XML.
 *
 * Opciones de CSV: delimiter («;» «,» tabulador «|»), quote («"» o «'»), encoding (utf8, utf8bom, win1252), decimal (comma o dot),
 * date_format (iso «2026-11-12T12:00:00Z», sql «2026-11-12 12:00:00», date «2026-11-12», es «12/11/2026 13:00» en la hora del sitio),
 * eol (lf o crlf), header (true: fila de cabeceras), safe_cells (true: un apóstrofo delante de lo que empieza por = + - @, para que
 * una hoja de cálculo no lo ejecute). JSON: layout (list o lines) y los importes como texto decimal con punto. XML: root e item.
 *
 * Las columnas son [{field, header, value}]; el campo «_fixed» pone siempre el valor fijo de la columna.
 */
final class Formats
{
    public const DELIMITERS = [';' => 'Semicolon ;', ',' => 'Comma ,', "\t" => 'Tab', '|' => 'Pipe |'];

    /** @var App */
    private $app;

    /** @var string */
    private $format;

    /** @var array<int,array<string,string>> */
    private $columns;

    /** @var array<string,mixed> */
    private $options;

    /** @var array<string,array{0:string,1:string}> */
    private $types;

    /** @var resource */
    private $out;

    /** @var int */
    private $rows = 0;

    /**
     * @param array<int,array<string,string>> $columns
     * @param array<string,mixed>             $options
     */
    public function __construct(App $app, string $kind, string $format, array $columns, array $options)
    {
        $this->app = $app;
        $this->format = $format;
        $this->columns = $columns;
        $this->options = self::options($options);
        $this->types = Sources::fields($kind);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open a temporary stream');
        }
        $this->out = $stream;
    }

    /**
     * Las opciones con sus valores de serie y las erróneas corregidas.
     *
     * @param array<string,mixed> $in
     *
     * @return array<string,mixed>
     */
    public static function options(array $in): array
    {
        $pick = static function ($value, array $allowed, $default) {
            return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
        };

        return [
            'delimiter' => $pick($in['delimiter'] ?? null, array_keys(self::DELIMITERS), ';'),
            'quote' => $pick($in['quote'] ?? null, ['"', "'"], '"'),
            'encoding' => $pick($in['encoding'] ?? null, ['utf8', 'utf8bom', 'win1252'], 'utf8'),
            'decimal' => $pick($in['decimal'] ?? null, ['comma', 'dot'], 'dot'),
            'date_format' => $pick($in['date_format'] ?? null, ['iso', 'sql', 'date', 'es'], 'iso'),
            'eol' => $pick($in['eol'] ?? null, ['lf', 'crlf'], 'lf'),
            'header' => !array_key_exists('header', $in) || !empty($in['header']),
            'safe_cells' => !array_key_exists('safe_cells', $in) || !empty($in['safe_cells']),
            'layout' => $pick($in['layout'] ?? null, ['list', 'lines'], 'list'),
            'root' => self::xmlName(isset($in['root']) && is_string($in['root']) ? $in['root'] : '', 'items'),
            'item' => self::xmlName(isset($in['item']) && is_string($in['item']) ? $in['item'] : '', 'item'),
        ];
    }

    public function extension(): string
    {
        if ($this->format === 'json') {
            return $this->options['layout'] === 'lines' ? 'jsonl' : 'json';
        }

        return $this->format === 'xml' ? 'xml' : 'csv';
    }

    public function mime(): string
    {
        if ($this->format === 'json') {
            return $this->options['layout'] === 'lines' ? 'application/x-ndjson' : 'application/json';
        }

        return $this->format === 'xml' ? 'application/xml' : 'text/csv';
    }

    /** Cuántas filas se han añadido. */
    public function count(): int
    {
        return $this->rows;
    }

    /**
     * Los nombres de las columnas, tal como saldrán en la cabecera.
     *
     * @return string[]
     */
    public function headers(): array
    {
        return array_map(function (array $c): string {
            return $this->header($c);
        }, $this->columns);
    }

    /**
     * Los textos de una fila, uno por columna (para la vista previa del panel).
     *
     * @param array<string,mixed> $row
     *
     * @return string[]
     */
    public function cells(array $row): array
    {
        return array_map(function (array $c) use ($row): string {
            return $this->cell($c, $row, false);
        }, $this->columns);
    }

    /** Abre el fichero: la cabecera del CSV, el «[» del JSON o la raíz del XML. */
    public function begin(): void
    {
        if ($this->format === 'csv' && $this->options['header']) {
            $this->line(array_map(function (array $c): string {
                return $this->header($c);
            }, $this->columns));
        } elseif ($this->format === 'json' && $this->options['layout'] === 'list') {
            fwrite($this->out, '[');
        } elseif ($this->format === 'xml') {
            fwrite($this->out, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<' . $this->options['root'] . ">\n");
        }
    }

    /**
     * @param array<string,mixed> $row campo => valor crudo (céntimos, fechas SQL…)
     */
    public function add(array $row): void
    {
        if ($this->format === 'csv') {
            $this->line(array_map(function (array $c) use ($row): string {
                return $this->cell($c, $row);
            }, $this->columns));
        } elseif ($this->format === 'json') {
            $object = [];
            foreach ($this->columns as $c) {
                $object[$this->header($c)] = $this->jsonValue($c, $row);
            }
            $json = (string) json_encode($object, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            fwrite($this->out, $this->options['layout'] === 'lines' ? $json . "\n" : ($this->rows > 0 ? ',' : '') . "\n  " . $json);
        } else {
            $xml = '  <' . $this->options['item'] . ">\n";
            foreach ($this->columns as $c) {
                $tag = self::xmlName($this->header($c), 'field');
                $xml .= '    <' . $tag . '>' . htmlspecialchars($this->cell($c, $row, false), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</' . $tag . ">\n";
            }
            fwrite($this->out, $xml . '  </' . $this->options['item'] . ">\n");
        }
        ++$this->rows;
    }

    /** Cierra el fichero y devuelve su contenido ya en la codificación pedida. */
    public function finish(): string
    {
        if ($this->format === 'json' && $this->options['layout'] === 'list') {
            fwrite($this->out, $this->rows > 0 ? "\n]\n" : "]\n");
        } elseif ($this->format === 'xml') {
            fwrite($this->out, '</' . $this->options['root'] . ">\n");
        }
        rewind($this->out);
        $text = (string) stream_get_contents($this->out);
        fclose($this->out);
        if ($this->format === 'csv') {
            if ($this->options['encoding'] === 'win1252') {
                return (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
            }
            if ($this->options['encoding'] === 'utf8bom') {
                return "\xEF\xBB\xBF" . $text;
            }
        }

        return $text;
    }

    // ------------------------------------------------------------------ valores

    /**
     * @param array<string,string> $column
     */
    private function header(array $column): string
    {
        $header = isset($column['header']) ? trim($column['header']) : '';

        return $header !== '' ? $header : (string) $column['field'];
    }

    /**
     * El texto de una celda (CSV y XML).
     *
     * @param array<string,string> $column
     * @param array<string,mixed>  $row
     */
    private function cell(array $column, array $row, bool $guard = true): string
    {
        $field = (string) $column['field'];
        if ($field === '_fixed') {
            $text = (string) ($column['value'] ?? '');
            $type = 'text';
        } else {
            $type = isset($this->types[$field]) ? $this->types[$field][1] : 'text';
            $value = array_key_exists($field, $row) ? $row[$field] : null;
            $text = $this->text($value, $type);
        }
        if ($guard && $this->format === 'csv' && $this->options['safe_cells'] && $type === 'text' && $text !== '' && strpos('=+-@', $text[0]) !== false) {
            return "'" . $text;
        }

        return $text;
    }

    /**
     * @param mixed $value
     */
    private function text($value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $comma = $this->options['decimal'] === 'comma';
        switch ($type) {
            case 'money':
            case 'rate':
                $decimal = Money::decimal((int) $value);

                return $comma ? str_replace('.', ',', $decimal) : $decimal;
            case 'int':
                return (string) (int) $value;
            case 'bool':
                return !empty($value) ? '1' : '0';
            case 'date':
                return $this->date((string) $value);
        }

        return (string) $value;
    }

    private function date(string $sql): string
    {
        $time = Clock::parse($sql);
        if ($time <= 0) {
            return '';
        }
        switch ($this->options['date_format']) {
            case 'sql':
                return gmdate('Y-m-d H:i:s', $time);
            case 'date':
                return gmdate('Y-m-d', $time);
            case 'es':
                return (new \DateTimeImmutable('@' . $time))->setTimezone(new \DateTimeZone($this->app->site()->timezone()))->format('d/m/Y H:i');
        }

        return gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    /**
     * @param array<string,string> $column
     * @param array<string,mixed>  $row
     *
     * @return mixed
     */
    private function jsonValue(array $column, array $row)
    {
        $field = (string) $column['field'];
        if ($field === '_fixed') {
            return (string) ($column['value'] ?? '');
        }
        $type = isset($this->types[$field]) ? $this->types[$field][1] : 'text';
        $value = array_key_exists($field, $row) ? $row[$field] : null;
        if ($value === null) {
            return null;
        }
        switch ($type) {
            case 'money':
            case 'rate':
                return Money::decimal((int) $value);
            case 'int':
                return (int) $value;
            case 'bool':
                return !empty($value);
            case 'date':
                return $this->date((string) $value);
        }

        return (string) $value;
    }

    /**
     * @param string[] $cells
     */
    private function line(array $cells): void
    {
        $delimiter = (string) $this->options['delimiter'];
        $quote = (string) $this->options['quote'];
        $parts = [];
        foreach ($cells as $cell) {
            if ($cell !== '' && strpbrk($cell, $delimiter . $quote . "\r\n") !== false) {
                $cell = $quote . str_replace($quote, $quote . $quote, $cell) . $quote;
            }
            $parts[] = $cell;
        }
        fwrite($this->out, implode($delimiter, $parts) . ($this->options['eol'] === 'crlf' ? "\r\n" : "\n"));
    }

    /** Un nombre válido de etiqueta XML. */
    private static function xmlName(string $name, string $default): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9_.-]+/', '_', trim($name));
        $clean = trim($clean, '_.-');
        if ($clean === '') {
            return $default;
        }

        return preg_match('/^[A-Za-z_]/', $clean) === 1 ? $clean : '_' . $clean;
    }
}

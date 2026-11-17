<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Translations;

use Aifec\Kernel\App;

/**
 * Los textos de la interfaz de un idioma en CSV, para un traductor de fuera: columnas «source» (el texto inglés, que es la clave),
 * «translation», «area» y «origin». Se escribe con comillas a la norma (un texto con comas, comillas o saltos de línea va entre
 * comillas dobles y las comillas se doblan) y con la marca UTF-8 delante para que una hoja de cálculo lea bien las tildes.
 *
 * Al importar se aceptan coma o punto y coma, con o sin la marca UTF-8, y cada fila pasa las mismas comprobaciones que lo escrito a
 * mano: lo que no vale se rechaza con su motivo y no se guarda. Una fila con la traducción vacía se salta (no borra nada).
 */
final class Csv
{
    /** Filas que se leen como mucho de un fichero. */
    public const MAX_ROWS = 20000;

    private const BOM = "\xEF\xBB\xBF";

    /** El CSV de un idioma: todas las cadenas de la interfaz con la traducción que tienen ahora (la del sitio o la del código). */
    public static function export(App $app, string $lang): string
    {
        $site = (new SiteCatalog($app))->all($lang);
        $code = Strings::codeCatalog($app, $lang);
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Cannot open a temporary stream');
        }
        fputcsv($out, ['source', 'translation', 'area', 'origin'], ',', '"', '');
        foreach (Strings::all($app) as $string => $info) {
            $string = (string) $string;
            $translation = isset($site[$string]) ? $site[$string] : (isset($code[$string]) ? $code[$string] : '');
            fputcsv($out, [$string, $translation, $info['area'], $info['origin']], ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return self::BOM . $csv;
    }

    /**
     * Lee un CSV y guarda en el catálogo del sitio lo que vale.
     *
     * @return array{saved:int,unchanged:int,skipped:int,rejected:array<int,array{line:int,source:string,reason:string}>}
     */
    public static function import(App $app, string $lang, string $csv): array
    {
        $result = ['saved' => 0, 'unchanged' => 0, 'skipped' => 0, 'rejected' => []];
        if (strpos($csv, self::BOM) === 0) {
            $csv = substr($csv, 3);
        }
        $rows = self::parse($csv);
        if ($rows === []) {
            return $result;
        }
        // La primera fila puede ser la cabecera («source», «translation»): si no lo es, la primera columna es el original.
        $sourceCol = 0;
        $textCol = 1;
        $first = array_map(static function ($cell): string {
            return strtolower(trim((string) $cell));
        }, $rows[0]);
        $line = 1;
        if (in_array('source', $first, true)) {
            $sourceCol = (int) array_search('source', $first, true);
            $textCol = in_array('translation', $first, true) ? (int) array_search('translation', $first, true) : 1;
            array_shift($rows);
            $line = 2;
        }
        $strings = Strings::all($app);
        $code = Strings::codeCatalog($app, $lang);
        $site = (new SiteCatalog($app))->all($lang);
        $changes = [];
        foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
            $current = $line++;
            $source = isset($row[$sourceCol]) ? (string) $row[$sourceCol] : '';
            $text = isset($row[$textCol]) ? Check::clean((string) $row[$textCol]) : '';
            if ($source === '' && $text === '') {
                continue;
            }
            if ($text === '') {
                ++$result['skipped'];
                continue;
            }
            if (!isset($strings[$source])) {
                $result['rejected'][] = ['line' => $current, 'source' => $source, 'reason' => t('This text is not in the code')];
                continue;
            }
            $problem = Check::problem($source, $text);
            if ($problem !== null) {
                $result['rejected'][] = ['line' => $current, 'source' => $source, 'reason' => Check::message($problem)];
                continue;
            }
            $now = isset($site[$source]) ? $site[$source] : (isset($code[$source]) ? $code[$source] : '');
            if ($text === $now) {
                ++$result['unchanged'];
                continue;
            }
            // Igual que la de serie: no hace falta guardarla en el sitio (y se quita la que hubiera).
            $changes[$source] = isset($code[$source]) && $code[$source] === $text ? '' : $text;
            ++$result['saved'];
        }
        if ($changes !== []) {
            (new SiteCatalog($app))->update($lang, $changes);
            $proposals = new Proposals($app);
            $proposals->remove($lang, Strings::SHOP, array_keys($changes));
            $proposals->remove($lang, Strings::PANEL, array_keys($changes));
        }

        return $result;
    }

    /**
     * Las filas de un CSV (coma o punto y coma, comillas dobles a la norma, saltos de línea dentro de las comillas).
     *
     * @return array<int,array<int,string>>
     */
    public static function parse(string $csv): array
    {
        $firstLine = (string) strtok($csv, "\n");
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $in = fopen('php://temp', 'r+');
        if ($in === false) {
            return [];
        }
        fwrite($in, $csv);
        rewind($in);
        $rows = [];
        while (($row = fgetcsv($in, 0, $delimiter, '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $rows[] = array_map(static function ($cell): string {
                return str_replace("\r\n", "\n", (string) $cell);
            }, $row);
            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }
        fclose($in);

        return $rows;
    }
}

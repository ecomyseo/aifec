<?php
/**
 * AIFEC — lectura del volcado de MySQL / MariaDB que escribe «Backups»: sentencia a sentencia, sin cargarlo entero.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

/**
 * El volcado solo trae `SET`, `DROP TABLE IF EXISTS`, `CREATE TABLE` e `INSERT INTO`. Se parte por los `;` que están fuera de
 * comillas (los textos llevan los saltos de línea escapados, pero un `;` dentro de un texto es corriente), y al restaurar solo
 * se acepta eso: un volcado que traiga otra cosa se rechaza sin ejecutar nada.
 */
final class SqlDump
{
    /** Marca con la que acaba un volcado completo: si falta, el fichero se cortó. */
    public const FOOTER = 'SET FOREIGN_KEY_CHECKS = 1';

    /**
     * Las sentencias de un volcado, sin el «;» final.
     *
     * @param resource $handle
     *
     * @return \Generator<int,string>
     */
    public static function statements($handle): \Generator
    {
        $buffer = '';
        $quote = '';
        $escape = false;
        while (($line = fgets($handle)) !== false) {
            if ($buffer === '' && $quote === '') {
                $trim = ltrim($line);
                if ($trim === '' || strncmp($trim, '--', 2) === 0) {
                    continue;
                }
            }
            $length = strlen($line);
            $start = 0;
            for ($i = 0; $i < $length; ++$i) {
                $c = $line[$i];
                if ($quote !== '') {
                    if ($escape) {
                        $escape = false;
                    } elseif ($c === '\\' && $quote === "'") {
                        $escape = true;
                    } elseif ($c === $quote) {
                        $quote = '';
                    }
                    continue;
                }
                if ($c === "'" || $c === '`') {
                    $quote = $c;
                } elseif ($c === ';') {
                    $statement = trim($buffer . substr($line, $start, $i - $start));
                    $buffer = '';
                    $start = $i + 1;
                    if ($statement !== '') {
                        yield $statement;
                    }
                }
            }
            $buffer .= substr($line, $start);
        }
        $rest = trim($buffer);
        if ($rest !== '') {
            // Una sentencia sin su «;»: el volcado está cortado.
            throw new \RuntimeException('The dump ends in the middle of a statement');
        }
    }

    /**
     * Qué es una sentencia del volcado y de qué tabla. Lanza si no es de las que escribe «Backups» o no es de una tabla del prefijo.
     *
     * @return array{type:string,table:string}  type: set | drop | create | insert
     */
    public static function classify(string $statement, string $prefix): array
    {
        if (preg_match('/^SET (NAMES utf8mb4|FOREIGN_KEY_CHECKS = [01])$/', $statement) === 1) {
            return ['type' => 'set', 'table' => ''];
        }
        if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `((?:[^`]|``)+)`/', $statement, $m) !== 1) {
            throw new \RuntimeException('The dump has a statement that is not part of a backup');
        }
        $table = str_replace('``', '`', $m[2]);
        if ($prefix === '' || strncmp($table, $prefix, strlen($prefix)) !== 0 || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new \RuntimeException('The dump has a table that does not belong to this site');
        }
        $types = ['DROP TABLE IF EXISTS' => 'drop', 'CREATE TABLE' => 'create', 'INSERT INTO' => 'insert'];
        if ($m[1] === 'CREATE TABLE' && preg_match('/FOREIGN KEY/i', $statement) === 1) {
            throw new \RuntimeException('Foreign keys are not supported in a restore');
        }

        return ['type' => $types[$m[1]], 'table' => $table];
    }

    /** La misma sentencia apuntando a otra tabla (la temporal). Solo cambia el nombre que sigue a CREATE TABLE o INSERT INTO. */
    public static function retarget(string $statement, string $from, string $to): string
    {
        $needle = '`' . str_replace('`', '``', $from) . '`';
        $pos = strpos($statement, $needle);
        if ($pos === false || $pos > 20) {
            throw new \RuntimeException('The statement does not name the table it should');
        }

        return substr($statement, 0, $pos) . '`' . $to . '`' . substr($statement, $pos + strlen($needle));
    }
}

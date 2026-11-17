<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

/**
 * Conexión a la copia de la base de datos de otra tienda, de solo lectura de hecho: rechaza todo lo que no sea una
 * sola sentencia SELECT, SHOW o DESCRIBE, y además le pide al servidor una sesión de solo lectura.
 *
 * Es un \PDO de verdad (un lector de copia lo recibe como tal) con tres métodos vigilados. Las sentencias se
 * comprueban sin tener en cuenta lo que va entre comillas ni los comentarios; un comentario ejecutable de MySQL
 * (barra, asterisco y signo de exclamación) se rechaza entero, porque el servidor SÍ lo ejecuta.
 */
final class ReadOnlyPdo extends \PDO
{
    /** @var string[] primeras palabras permitidas */
    private const ALLOWED = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC'];

    /**
     * Pide al servidor una sesión de solo lectura. Si no la admite (otro motor, versión vieja), se sigue: la
     * comprobación de sentencias de este envoltorio ya impide escribir.
     */
    public function hardenSession(): void
    {
        try {
            parent::exec('SET SESSION TRANSACTION READ ONLY');
        } catch (\Throwable $e) {
            // Sin esa orden el envoltorio sigue protegiendo.
        }
    }

    /**
     * @param string               $query
     * @param array<string,mixed>  $options
     *
     * @return \PDOStatement|false
     */
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        self::check((string) $query);

        return parent::prepare($query, is_array($options) ? $options : []);
    }

    /**
     * @param string $query
     * @param mixed  $fetchMode
     * @param mixed  ...$fetchModeArgs
     *
     * @return \PDOStatement|false
     */
    #[\ReturnTypeWillChange]
    public function query($query, $fetchMode = null, ...$fetchModeArgs)
    {
        self::check((string) $query);
        if ($fetchMode === null) {
            return parent::query($query);
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    /**
     * @param string $statement
     *
     * @return int|false
     */
    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        self::check((string) $statement);

        return parent::exec($statement);
    }

    /**
     * Lanza SourceError si la sentencia no es una sola lectura.
     *
     * @throws SourceError
     */
    public static function check(string $sql): void
    {
        if (strpos($sql, '/*!') !== false) {
            throw new SourceError('Only read queries are allowed');
        }
        $code = self::codeOnly($sql);
        $code = trim($code);
        while ($code !== '' && substr($code, -1) === ';') {
            $code = rtrim(substr($code, 0, -1));
        }
        if ($code === '' || strpos($code, ';') !== false) {
            throw new SourceError('Only one read query at a time is allowed');
        }
        if (preg_match('/^([A-Za-z]+)/', $code, $m) !== 1 || !in_array(strtoupper($m[1]), self::ALLOWED, true)) {
            throw new SourceError('Only read queries are allowed');
        }
        // «SELECT … INTO OUTFILE» escribe un fichero en el servidor.
        if (preg_match('/\bINTO\b/i', $code) === 1) {
            throw new SourceError('Only read queries are allowed');
        }
    }

    /**
     * La sentencia sin comentarios y con las cadenas y los nombres entrecomillados vaciados.
     */
    private static function codeOnly(string $sql): string
    {
        $out = '';
        $n = strlen($sql);
        for ($i = 0; $i < $n; ++$i) {
            $c = $sql[$i];
            $next = $i + 1 < $n ? $sql[$i + 1] : '';
            if ($c === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $n : $end;
                $out .= ' ';
                continue;
            }
            if ($c === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $n : $end;
                $out .= ' ';
                continue;
            }
            if ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $n : $end + 1;
                $out .= ' ';
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                for ($j = $i + 1; $j < $n; ++$j) {
                    if ($sql[$j] === '\\' && $c !== '`') {
                        ++$j;
                        continue;
                    }
                    if ($sql[$j] === $c) {
                        if ($j + 1 < $n && $sql[$j + 1] === $c) {
                            ++$j;
                            continue;
                        }
                        break;
                    }
                }
                $i = $j;
                $out .= $c . $c;
                continue;
            }
            $out .= $c;
        }

        return $out;
    }
}

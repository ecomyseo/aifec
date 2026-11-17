<?php
/**
 * AIFEC — utilidades de las pruebas. Sin dependencias: funciones globales y un contador.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

final class AifecTestFailure extends \RuntimeException
{
}

final class AifecTests
{
    /** @var array<int,array{0:string,1:callable,2:string}> */
    public static array $tests = [];

    public static int $checks = 0;

    public static string $file = '';

    /** @var string[] carpetas temporales que hay que borrar al acabar */
    public static array $tmp = [];
}

/** Declara una prueba. El cuerpo lanza si algo no se cumple. */
function test(string $name, callable $fn): void
{
    AifecTests::$tests[] = [$name, $fn, AifecTests::$file];
}

function check(bool $cond, string $msg = ''): void
{
    ++AifecTests::$checks;
    if (!$cond) {
        throw new AifecTestFailure($msg !== '' ? $msg : 'la condición no se cumple');
    }
}

/** Igualdad estricta (===) con el detalle de lo esperado y lo obtenido. */
function same($expected, $actual, string $msg = ''): void
{
    ++AifecTests::$checks;
    if ($expected !== $actual) {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        if (strlen($e) > 1500) {
            $e = substr($e, 0, 1500) . '…';
        }
        if (strlen($a) > 1500) {
            $a = substr($a, 0, 1500) . '…';
        }
        throw new AifecTestFailure(($msg !== '' ? $msg . "\n" : '') . "  esperado: {$e}\n  obtenido: {$a}");
    }
}

function near(float $expected, float $actual, float $eps = 1e-6, string $msg = ''): void
{
    ++AifecTests::$checks;
    if (abs($expected - $actual) > $eps) {
        throw new AifecTestFailure(($msg !== '' ? $msg . ': ' : '') . "esperado {$expected} ± {$eps}, obtenido {$actual}");
    }
}

function contains(string $needle, string $haystack, string $msg = ''): void
{
    ++AifecTests::$checks;
    if (!str_contains($haystack, $needle)) {
        $h = strlen($haystack) > 600 ? substr($haystack, 0, 600) . '…' : $haystack;
        throw new AifecTestFailure(($msg !== '' ? $msg . ': ' : '') . "no contiene «{$needle}» en «{$h}»");
    }
}

function notContains(string $needle, string $haystack, string $msg = ''): void
{
    ++AifecTests::$checks;
    if (str_contains($haystack, $needle)) {
        throw new AifecTestFailure(($msg !== '' ? $msg . ': ' : '') . "contiene «{$needle}» y no debería");
    }
}

/** El cuerpo TIENE QUE lanzar una excepción de esa clase. */
function throws(callable $fn, string $class = \Throwable::class, string $msg = ''): void
{
    ++AifecTests::$checks;
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new AifecTestFailure(($msg !== '' ? $msg . ': ' : '') . 'lanzó ' . get_class($e) . ' («' . $e->getMessage() . "») y se esperaba {$class}");
    }
    throw new AifecTestFailure(($msg !== '' ? $msg . ': ' : '') . "no lanzó nada y se esperaba {$class}");
}

/** Carpeta temporal propia de la prueba; se borra sola al terminar la pasada. */
function tmpDir(string $prefix = 'aifec'): string
{
    $dir = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/' . $prefix . '-' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    AifecTests::$tmp[] = $dir;

    return $dir;
}

function rmTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    foreach (is_array($items) ? $items : [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            rmTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

<?php
/**
 * AIFEC — lanzador de pruebas.
 *
 *   php tests/run.php                  todas
 *   php tests/run.php Mail Pdf         solo los ficheros cuya ruta contiene «Mail» o «Pdf»
 *   php tests/run.php --lista          enseña los ficheros que se ejecutarían
 *
 * Busca *Test.php en tests/, modules/<m>/tests/, plugins/<p>/tests/ y themes/<t>/tests/.
 * Imprime solo los fallos y una línea final «PRUEBAS: n OK, m FALLAN». Sale con 1 si falla
 * algo o si el filtro no encuentra ninguna prueba (cero pruebas no es un verde).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if ((error_reporting() & $no) === 0) {
        return false; // silenciado a propósito con @ (borrar un temporal que quizá no existe)
    }
    // Un aviso de PHP en una prueba es un fallo: no se deja pasar.
    throw new \ErrorException($str, 0, $no, $file, $line);
});

$root = str_replace('\\', '/', dirname(__DIR__));
require $root . '/core/bootstrap.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/support.php';

$args = array_slice($argv, 1);
$list = in_array('--lista', $args, true);
$filters = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--')));

/** @return string[] */
function aifecFindTests(string $dir): array
{
    $found = [];
    if (!is_dir($dir)) {
        return $found;
    }
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = str_replace('\\', '/', (string) $file);
        if (str_ends_with($path, 'Test.php')) {
            $found[] = $path;
        }
    }

    return $found;
}

$files = aifecFindTests($root . '/tests');
foreach (['modules', 'plugins', 'themes'] as $base) {
    $dirs = glob($root . '/' . $base . '/*/tests', GLOB_ONLYDIR);
    foreach (is_array($dirs) ? $dirs : [] as $dir) {
        $files = array_merge($files, aifecFindTests($dir));
    }
}
sort($files);

if ($filters !== []) {
    $files = array_values(array_filter($files, static function (string $f) use ($filters, $root): bool {
        $rel = substr($f, strlen($root) + 1);
        foreach ($filters as $filter) {
            if (stripos($rel, str_replace('\\', '/', $filter)) !== false) {
                return true;
            }
        }

        return false;
    }));
}

if ($list) {
    foreach ($files as $f) {
        echo substr($f, strlen($root) + 1), "\n";
    }
    echo count($files), " ficheros\n";
    exit(0);
}

$ok = 0;
$failed = 0;
$t0 = microtime(true);

foreach ($files as $file) {
    AifecTests::$tests = [];
    AifecTests::$file = substr($file, strlen($root) + 1);
    try {
        require $file;
    } catch (\Throwable $e) {
        ++$failed;
        echo 'FALLA ', AifecTests::$file, ' (al cargar): ', get_class($e), ': ', $e->getMessage(),
            ' en ', basename($e->getFile()), ':', $e->getLine(), "\n";
        continue;
    }
    foreach (AifecTests::$tests as [$name, $fn, $rel]) {
        AifecTestEnv::$hookErrors = [];
        AifecTestEnv::$hookErrorsExpected = false;
        try {
            $fn();
            if (AifecTestEnv::$hookErrors !== [] && !AifecTestEnv::$hookErrorsExpected) {
                throw new AifecTestFailure("un oyente de un gancho ha lanzado y la prueba no lo esperaba (expectHookErrors()):\n" . implode("\n", array_slice(AifecTestEnv::$hookErrors, 0, 5)));
            }
            ++$ok;
        } catch (AifecTestFailure $e) {
            ++$failed;
            echo 'FALLA ', $rel, ' · ', $name, "\n  ", str_replace("\n", "\n  ", $e->getMessage()), "\n";
        } catch (\Throwable $e) {
            ++$failed;
            echo 'FALLA ', $rel, ' · ', $name, "\n  ", get_class($e), ': ', $e->getMessage(),
                ' en ', basename($e->getFile()), ':', $e->getLine(), "\n";
        }
    }
}

// Se sueltan las aplicaciones de prueba (y sus bases de datos abiertas) antes de borrar los temporales.
AifecTests::$tests = [];
AifecTestEnv::$templates = [];
if (class_exists('Aifec\\Kernel\\App', false)) {
    \Aifec\Kernel\App::clearCurrent();
}
gc_collect_cycles();
foreach (AifecTests::$tmp as $dir) {
    rmTree($dir);
}

$ms = (int) round((microtime(true) - $t0) * 1000);
if ($ok + $failed === 0) {
    echo "PRUEBAS: 0 — el filtro no encuentra ninguna prueba (", implode(' ', $filters), ")\n";
    exit(1);
}
echo 'PRUEBAS: ', $ok, ' OK, ', $failed, ' FALLAN (', AifecTests::$checks, ' comprobaciones, ', count($files), ' ficheros, ', $ms, " ms)\n";
exit($failed > 0 ? 1 : 0);

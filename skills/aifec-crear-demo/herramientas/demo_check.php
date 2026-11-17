<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/*
 * Lo mismo que `php aifec demo:check <paquete>`, pero sobre un paquete que está en CUALQUIER carpeta (por ejemplo
 * el scratchpad de trabajo) en lugar de en demos/. Usa la misma clase que la orden oficial (Package::load), así que
 * el veredicto es el mismo; solo cambia dónde busca el paquete.
 *
 *   php herramientas/demo_check.php <carpeta del paquete>
 *
 * Termina con «DEMO <nombre>: 0 problems» (código 0) o con una línea «PROBLEM: …» por problema (código 1).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 3);
require $root . '/core/bootstrap.php';

$path = isset($argv[1]) ? (string) $argv[1] : '';
$real = $path !== '' ? realpath($path) : false;
if ($real === false || !is_dir($real)) {
    fwrite(STDERR, "Usage: php demo_check.php <package folder>\n");
    exit(2);
}
$real = str_replace('\\', '/', $real);
$name = basename($real);

try {
    $package = \Aifec\Modules\Sites\Package::load(dirname($real), $name, $root);
} catch (\Aifec\Modules\Sites\DemoPackageException $e) {
    foreach ($e->problems() as $problem) {
        echo 'PROBLEM: ' . $problem . "\n";
    }
    echo 'DEMO ' . $name . ': ' . count($e->problems()) . " problems\n";
    exit(1);
}

$products = $package->has('products.json') ? count($package->data('products.json')) : 0;
$images = 0;
$bytes = 0;
$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->isFile()) {
        $bytes += (int) $file->getSize();
        if (strpos(str_replace('\\', '/', $file->getPathname()), '/media/') !== false) {
            ++$images;
        }
    }
}
echo 'Products: ' . $products . "\n";
echo 'Images:   ' . $images . "\n";
echo 'Size:     ' . number_format($bytes / 1048576, 2, '.', '') . " MB\n";
echo 'DEMO ' . $name . ": 0 problems\n";
exit(0);

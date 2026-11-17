<?php
/**
 * AIFEC — pruebas de las comprobaciones del servidor: lo imprescindible bloquea, lo recomendado avisa y dice qué deja de funcionar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Install\Installer;
use Aifec\Install\Requirements;

require_once __DIR__ . '/support.php';

if (!function_exists('reqRows')) {
    /**
     * Las comprobaciones de un servidor al que le faltan estas extensiones.
     *
     * @param string[] $missing
     *
     * @return array<string,array<string,mixed>> por id
     */
    function reqRows(array $missing): array
    {
        $rows = Requirements::check(tmpDir('aifec-req-data'), tmpDir('aifec-req-public'), static function (string $name) use ($missing): bool {
            return !in_array($name, $missing, true);
        });
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
        }

        return $byId;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    function reqBlocked(array $rows): bool
    {
        $installer = (new ReflectionClass(Installer::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Installer::class, 'blockedBy');
        $method->setAccessible(true);

        return (bool) $method->invoke($installer, $rows);
    }

    /** La pantalla 1 del instalador pintada con unas comprobaciones dadas (el instalador solo las lee de Requirements). */
    function reqScreen(array $rows, string $lang): string
    {
        $i = aifecInstEmpty();
        $ctor = new ReflectionMethod(Installer::class, '__construct');
        $ctor->setAccessible(true);
        $installer = (new ReflectionClass(Installer::class))->newInstanceWithoutConstructor();
        $ctor->invoke($installer, aifecInstRoot(), $i[1], $i[2], '');
        $setLang = new ReflectionMethod(Installer::class, 'setLang');
        $setLang->setAccessible(true);
        $setLang->invoke($installer, $lang);
        $blocked = new ReflectionMethod(Installer::class, 'blockedBy');
        $blocked->setAccessible(true);
        $page = new ReflectionMethod(Installer::class, 'page');
        $page->setAccessible(true);
        $response = $page->invoke($installer, 'step1', ['title' => 'Welcome to AIFEC', 'step' => 1, 'checks' => array_values($rows), 'blocked' => $blocked->invoke($installer, array_values($rows))]);

        return $response->body;
    }
}

test('con todo instalado no falta nada; y lo que el código usa de verdad está en la lista', function (): void {
    $rows = reqRows([]);
    foreach ($rows as $id => $row) {
        same(true, $row['ok'], $id . ' (con todo cargado)');
    }
    foreach (['ctype', 'dom', 'zlib', 'exif', 'ftp', 'zip'] as $id) {
        check(isset($rows[$id]), 'se comprueba ' . $id);
    }
    // Lo que el código del núcleo y de los módulos pide a PHP, recorrido a mano: todo lo que no es de serie está en la lista.
    $text = '';
    foreach (['core/src', 'modules'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(aifecInstRoot() . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (substr($file->getFilename(), -4) === '.php' && strpos(str_replace('\\', '/', $file->getPathname()), '/tests/') === false) {
                $text .= (string) file_get_contents($file->getPathname()) . "\n";
            }
        }
    }
    $uses = [
        'dom' => '/new \\\\?DOMDocument/', 'zip' => '/\bZipArchive\b/', 'fileinfo' => '/\bfinfo_open\b/', 'intl' => '/\bIntlDateFormatter\b|\bNumberFormatter\b/',
        'curl' => '/\bcurl_init\b/', 'zlib' => '/\bgzcompress\b|\bgzopen\b/', 'exif' => '/\bexif_read_data\b/', 'ftp' => '/\bftp_connect\b/',
        'images' => '/\bimagecreatetruecolor\b|\bimagejpeg\b/', 'ctype' => '/\bctype_[a-z]+\(/', 'json' => '/\bjson_decode\b/', 'mbstring' => '/\bmb_[a-z_]+\(/',
    ];
    foreach ($uses as $id => $pattern) {
        if (preg_match($pattern, $text) === 1) {
            check(isset($rows[$id]), 'el código usa «' . $id . '» y el instalador no lo comprueba');
        }
    }
});

test('si falta una extensión imprescindible el instalador bloquea, y dice qué deja de funcionar', function (): void {
    foreach (['mbstring', 'json', 'ctype', 'dom'] as $name) {
        $rows = reqRows([$name]);
        same(false, $rows[$name]['ok'], $name . ' falta');
        same(true, $rows[$name]['required'], $name . ' es imprescindible');
        same('Not installed', $rows[$name]['detail']);
        check($rows[$name]['impact'] !== '');
        same(true, reqBlocked(array_values($rows)), 'sin ' . $name . ' no se puede instalar');
    }
    $rows = reqRows(['openssl', 'sodium']);
    same(false, $rows['crypto']['ok'], 'sin openssl ni sodium');
    same(true, reqBlocked(array_values($rows)));
    $rows = reqRows(['openssl']);
    same(true, $rows['crypto']['ok'], 'con sodium basta');
    same(false, reqBlocked(array_values($rows)));
});

test('si falta una recomendada el instalador avisa y deja seguir, y cada una dice sin qué función se queda la tienda', function (): void {
    $expect = ['zip' => 'complete backups', 'zlib' => 'PDF documents', 'curl' => 'outside services', 'fileinfo' => 'not an image', 'intl' => 'simpler format', 'exif' => 'rotated', 'ftp' => 'FTP'];
    foreach ($expect as $name => $words) {
        $rows = reqRows([$name]);
        same(false, $rows[$name]['ok'], $name . ' falta');
        same(false, $rows[$name]['required'], $name . ' es recomendada');
        contains($words, $rows[$name]['impact']);
        same(false, reqBlocked(array_values($rows)), 'sin ' . $name . ' se puede seguir');
    }
    $rows = reqRows(['gd', 'imagick']);
    same(false, $rows['images']['ok']);
    same(false, $rows['images']['required']);
    same(false, reqBlocked(array_values($rows)));
    $rows = reqRows(['zip', 'zlib', 'curl', 'exif', 'ftp', 'intl', 'fileinfo', 'gd', 'imagick']);
    same(false, reqBlocked(array_values($rows)), 'faltan todas las recomendadas y aun así se puede seguir');
});

test('la pantalla 1 pinta «Obligatorio» o «Recomendado» y, al lado, lo que deja de funcionar; en castellano también', function (): void {
    $html = reqScreen(array_values(reqRows(['dom', 'zip'])), 'en');
    contains('data-check="dom"', $html);
    contains('af-badge-bad', $html);
    contains('Required', $html);
    contains('The HTML of descriptions and pages cannot be cleaned', $html);
    contains('Recommended', $html);
    contains('Plugins cannot be installed from a zip and complete backups cannot be made or restored', $html);
    contains('Fix the items marked', $html, 'bloqueado: pide corregir');

    $es = reqScreen(array_values(reqRows(['zip'])), 'es');
    contains('Extensión zip', $es);
    contains('No se pueden instalar plugins desde un zip ni hacer o restaurar copias completas', $es);
    contains('Recomendado', $es);
    notContains('Corrige lo marcado', $es, 'solo falta una recomendada: no bloquea');

    $ok = reqScreen(array_values(reqRows([])), 'es');
    notContains('cannot be', $ok);
    notContains('No se pueden', $ok, 'con todo en orden no se enseña ningún «qué deja de funcionar»');
});

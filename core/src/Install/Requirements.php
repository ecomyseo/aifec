<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Install;

/**
 * Qué necesita AIFEC del servidor. La lista la enseña el instalador y la consola; no escribe nada
 * (la prueba de escritura crea y borra un fichero vacío).
 *
 * Las etiquetas y los detalles fijos van en inglés: quien los pinta los pasa por la traducción.
 */
final class Requirements
{
    /**
     * Qué deja de funcionar si falta cada cosa. La lista sale de recorrer el código: `ctype_*` en 29 ficheros, `DOMDocument` en el
     * limpiador de HTML, `ZipArchive` en los plugins y las copias completas, `gzcompress` en los PDF, `finfo` en los tipos de
     * fichero, `Intl*` en fechas e importes, `exif_read_data` al girar fotos y `ftp_*` en el envío al ERP. Lo imprescindible es
     * lo que, sin ello, deja la tienda caída; lo recomendado tiene una alternativa o solo afecta a una función.
     *
     * @var array<string,string>
     */
    public const IMPACT = [
        'php' => 'AIFEC does not run on older versions of PHP',
        'pdo' => 'There is nowhere to keep the data of the shop',
        'mbstring' => 'Texts with accents and symbols break',
        'json' => 'Settings and answers of the API cannot be read or written',
        'ctype' => 'Forms and imports cannot check what they receive',
        'dom' => 'The HTML of descriptions and pages cannot be cleaned',
        'crypto' => 'Passwords and secret keys cannot be protected',
        'images' => 'Uploaded images are not resized and PDF documents cannot show images',
        'intl' => 'Dates and amounts are written in a simpler format',
        'curl' => 'The shop cannot call outside services: payments, shipping, feeds or AI',
        'fileinfo' => 'The type of an uploaded file that is not an image is not recognised',
        'zip' => 'Plugins cannot be installed from a zip and complete backups cannot be made or restored',
        'zlib' => 'PDF documents (invoices, delivery notes) cannot be made and backups are not compressed',
        'exif' => 'Photos taken with a phone can show rotated',
        'ftp' => 'The ERP cannot send files by FTP',
        'data_dir' => 'The shop cannot save its data',
        'media_dir' => 'Uploaded images cannot be saved',
    ];

    /**
     * @return array<int,array{id:string,label:string,ok:bool,required:bool,detail:string,impact:string}>
     */
    public static function check(string $dataRoot, string $publicDir, ?callable $loaded = null): array
    {
        $rows = [];
        $add = static function (string $id, string $label, bool $ok, bool $required, string $detail) use (&$rows): void {
            $rows[] = ['id' => $id, 'label' => $label, 'ok' => $ok, 'required' => $required, 'detail' => $detail, 'impact' => self::IMPACT[$id]];
        };
        // `$loaded` sustituye a extension_loaded(): solo lo usan las pruebas para simular un servidor al que le falta algo.
        $ext = static function (string $name) use ($loaded): bool {
            return $loaded !== null ? (bool) $loaded($name) : extension_loaded($name);
        };
        $missing = static function (bool $ok): string {
            return $ok ? '' : 'Not installed';
        };

        $add('php', 'PHP 7.4 or newer', version_compare(PHP_VERSION, '7.4.0', '>='), true, PHP_VERSION);

        $drivers = class_exists('PDO') ? \PDO::getAvailableDrivers() : [];
        $found = [];
        if (in_array('sqlite', $drivers, true)) {
            $found[] = 'SQLite';
        }
        if (in_array('mysql', $drivers, true)) {
            $found[] = 'MySQL';
        }
        $add('pdo', 'Database driver (SQLite or MySQL/MariaDB)', $found !== [], true, $found !== [] ? implode(', ', $found) : 'Not installed');

        $add('mbstring', 'Extension mbstring', $ext('mbstring'), true, $missing($ext('mbstring')));
        $add('json', 'Extension json', $ext('json'), true, $missing($ext('json')));
        $add('ctype', 'Extension ctype', $ext('ctype'), true, $missing($ext('ctype')));
        $add('dom', 'Extension dom', $ext('dom') && class_exists('DOMDocument'), true, $missing($ext('dom') && class_exists('DOMDocument')));
        $crypto = $ext('openssl') || $ext('sodium');
        $add('crypto', 'Extension openssl or sodium', $crypto, true, $missing($crypto));

        $images = $ext('gd') || $ext('imagick');
        $add('images', 'Image library (gd or imagick)', $images, false, $missing($images));
        $add('intl', 'Extension intl', $ext('intl'), false, $missing($ext('intl')));
        $add('curl', 'Extension curl', $ext('curl'), false, $missing($ext('curl')));
        $add('fileinfo', 'Extension fileinfo', $ext('fileinfo'), false, $missing($ext('fileinfo')));
        $add('zip', 'Extension zip', $ext('zip') && class_exists('ZipArchive'), false, $missing($ext('zip') && class_exists('ZipArchive')));
        $add('zlib', 'Extension zlib', $ext('zlib'), false, $missing($ext('zlib')));
        $add('exif', 'Extension exif', $ext('exif'), false, $missing($ext('exif')));
        $add('ftp', 'Extension ftp', $ext('ftp'), false, $missing($ext('ftp')));

        $dataOk = self::writable($dataRoot);
        $add('data_dir', 'The data folder is writable', $dataOk, true, $dataOk ? self::norm($dataRoot) : 'Not writable');
        $media = self::norm($publicDir) . '/media';
        $mediaOk = self::writable($media);
        $add('media_dir', 'The public media folder is writable', $mediaOk, true, $mediaOk ? $media : 'Not writable');

        return $rows;
    }

    /**
     * ¿Se puede escribir en esa carpeta, o crearla? Si no existe se mira la primera carpeta que sí exista por encima;
     * un fichero en medio del camino la hace imposible.
     */
    private static function writable(string $dir): bool
    {
        $probe = self::norm($dir);
        if ($probe === '') {
            return false;
        }
        while (!is_dir($probe)) {
            if (file_exists($probe)) {
                return false;
            }
            $parent = self::norm(dirname($probe));
            if ($parent === $probe || $parent === '' || $parent === '.') {
                return false;
            }
            $probe = $parent;
        }
        $file = $probe . '/.aifec-write-' . bin2hex(random_bytes(4));
        // Probar a escribir es la única medida fiable (los permisos de Windows y de las carpetas montadas engañan a is_writable).
        $ok = @file_put_contents($file, 'x') !== false;
        if ($ok) {
            @unlink($file);
        }

        return $ok;
    }

    private static function norm(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Media;

/**
 * Tipos MIME: el real de un fichero (por su contenido), la extensión que le toca, si se admite
 * como subida y un nombre de fichero seguro. La lista de subidas admitidas es una lista blanca:
 * lo que no está, no entra (PHP, HTML y ejecutables incluidos).
 */
final class Mime
{
    /** @var array<string,string> tipo => extensión */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'audio/mpeg' => 'mp3',
        'audio/ogg' => 'ogg',
    ];

    /** @var array<string,string> sinónimos habituales => tipo normal */
    private const ALIASES = [
        'image/jpg' => 'image/jpeg',
        'image/pjpeg' => 'image/jpeg',
        'application/x-zip-compressed' => 'application/zip',
        'application/csv' => 'text/csv',
        'text/x-csv' => 'text/csv',
        'application/ogg' => 'audio/ogg',
        'audio/mp3' => 'audio/mpeg',
    ];

    /** @var array<string,string> letras con signos que se pasan a ASCII en un nombre de fichero */
    private const TRANSLITERATION = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe',
    ];

    /** Extensiones que nunca se conservan en un nombre: el servidor las podría ejecutar. */
    private const DANGEROUS = '/^(?:php\d*|phtml|phar|pht|phps|phpt|html?|xhtml|shtml?|exe|dll|bat|cmd|com|msi|scr|sh|cgi|pl|py|rb|asp|aspx|jsp|jspx|jar|js|mjs|vbs|ps1|htaccess|htpasswd|ini)$/';

    /**
     * Tipo MIME real del fichero, mirando su contenido. Las imágenes (también AVIF y SVG, que
     * `finfo` no siempre reconoce) se identifican por su firma.
     *
     * @throws MediaException si el fichero no existe o no se puede leer
     */
    public static function fromContent(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new MediaException('File not found or unreadable');
        }
        $head = file_get_contents($path, false, null, 0, 4096);
        $head = is_string($head) ? $head : '';
        $image = self::imageFromHead($head);
        if ($image !== null) {
            return $image;
        }
        if (!function_exists('finfo_open')) {
            return 'application/octet-stream';
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }
        $mime = finfo_file($finfo, $path);
        if (PHP_VERSION_ID < 80100) {
            finfo_close($finfo);
        }

        return is_string($mime) && $mime !== '' ? self::normalize($mime) : 'application/octet-stream';
    }

    /**
     * Tipo MIME de imagen según los primeros bytes (firma del formato), o null si no es una imagen
     * conocida: JPEG, PNG, GIF, WebP, AVIF o SVG.
     */
    public static function imageFromHead(string $head): ?string
    {
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return 'image/png';
        }
        if (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) {
            return 'image/gif';
        }
        if (strlen($head) >= 12 && strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
            $brand = substr($head, 8, 4);
            if ($brand === 'avif' || $brand === 'avis') {
                return 'image/avif';
            }
            if ($brand === 'mif1' && strpos(substr($head, 8, 64), 'avif') !== false) {
                return 'image/avif';
            }
        }
        $text = strncmp($head, "\xEF\xBB\xBF", 3) === 0 ? substr($head, 3) : $head;
        $text = ltrim($text);
        if (
            $text !== '' && $text[0] === '<'
            && preg_match('/^<(?:\?xml|!--|!DOCTYPE\s+svg|svg)/i', $text) === 1
            && preg_match('/<svg[\s>]/i', substr($text, 0, 4096)) === 1
        ) {
            return 'image/svg+xml';
        }

        return null;
    }

    /** Extensión (sin punto) que le toca a un tipo MIME, o null si no se conoce. */
    public static function extension(string $mime): ?string
    {
        $mime = self::normalize($mime);

        return self::EXTENSIONS[$mime] ?? null;
    }

    /**
     * ¿Se admite como subida? Imágenes, PDF, ZIP, texto, CSV, vídeo MP4/WebM y audio MP3/OGG.
     * Nunca PHP, HTML ni ejecutables.
     */
    public static function isAllowedUpload(string $mime): bool
    {
        return isset(self::EXTENSIONS[self::normalize($mime)]);
    }

    /**
     * Nombre de fichero seguro: sin rutas, en minúsculas, solo letras, cifras y guiones, de
     * 120 caracteres como mucho. Las extensiones ejecutables (`.php`, `.phtml`, `.html`, `.exe`…)
     * se eliminan en cualquier posición, así que `.php.jpg` queda en `.jpg`. Nunca devuelve vacío.
     */
    public static function safeFilename(string $name): string
    {
        $name = str_replace(['\\', "\0"], ['/', ''], $name);
        $slash = strrpos($name, '/');
        if ($slash !== false) {
            $name = substr($name, $slash + 1);
        }
        $name = mb_strtolower(mb_scrub($name, 'UTF-8'), 'UTF-8');
        $name = strtr($name, self::TRANSLITERATION);
        $name = ltrim($name, '.');

        $parts = explode('.', $name);
        $ext = '';
        if (count($parts) > 1) {
            $ext = self::slug((string) array_pop($parts));
            if (preg_match(self::DANGEROUS, $ext) === 1) {
                $ext = '';
            }
            $ext = substr($ext, 0, 10);
        }
        $clean = [];
        foreach ($parts as $part) {
            $slug = self::slug($part);
            if ($slug === '' || preg_match(self::DANGEROUS, $slug) === 1) {
                continue;
            }
            $clean[] = $slug;
        }
        $base = implode('-', $clean);
        if ($base === '') {
            $base = 'file';
        }
        $room = 120 - ($ext !== '' ? strlen($ext) + 1 : 0);
        $base = rtrim(substr($base, 0, $room), '-');
        if ($base === '') {
            $base = 'file';
        }

        return $ext !== '' ? $base . '.' . $ext : $base;
    }

    /** Minúsculas, cifras y guiones; lo demás se agrupa en un solo guion. */
    private static function slug(string $text): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', $text);

        return trim(is_string($slug) ? $slug : '', '-');
    }

    /** Tipo en minúsculas, sin parámetros (`; charset=…`) y con los sinónimos pasados al normal. */
    private static function normalize(string $mime): string
    {
        $mime = strtolower(trim($mime));
        $semi = strpos($mime, ';');
        if ($semi !== false) {
            $mime = rtrim(substr($mime, 0, $semi));
        }

        return self::ALIASES[$mime] ?? $mime;
    }
}

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog;

use Aifec\Kernel\App;
use Aifec\Media\SvgSanitizer;

/**
 * La dirección PÚBLICA de un fichero descargable de un producto (como los medios de WordPress, pero que no se puede adivinar ni
 * listar): una copia del fichero en «public/descargas/<código>/<nombre>», con un código al azar de 48 cifras hexadecimales. Sirve para
 * avisar de una actualización a todos los clientes con un enlace que no caduca. Sin ella, el fichero sigue siendo privado y solo se
 * entrega con el enlace del pedido.
 *
 * - El código se genera al activarla y se conserva mientras siga activa: la dirección de un aviso ya enviado sigue valiendo, también
 *   cuando se sube una versión nueva (la copia se sustituye dentro de su carpeta y conserva su nombre).
 * - Desactivarla, quitar la fila o borrar el producto borra la carpeta.
 * - Nada de listados: la carpeta de descargas y cada carpeta llevan un index.php que redirige y la de descargas, un .htaccess sin índices.
 * - El nombre se sanea a [A-Za-z0-9._-]; nunca «index.php»; las extensiones de código (php, phtml, phar…) acaban en «.txt».
 */
final class PublicFiles
{
    public const DIR = 'descargas';

    /** Un nombre o un trozo de nombre que un servidor podría ejecutar. */
    private const CODE = '/^(?:php\d*|phtml|phar|pht|phps|phpt|shtml?|cgi|pl|py|asp|aspx|jsp)$/i';

    /** Lo que un navegador abre como página y ejecuta en el origen de la tienda: la copia pública lo lleva con «.txt» detrás. */
    private const ACTIVE = '/^(?:x?html?|xht|xml|xsl|xslt|js|mjs|hta)$/i';

    /** @return string la carpeta de descargas públicas, sin barra final */
    public static function baseDir(App $app): string
    {
        return rtrim(str_replace('\\', '/', $app->publicDir()), '/') . '/' . self::DIR;
    }

    /** Un código nuevo: 48 cifras hexadecimales, de 24 bytes al azar. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function validToken(?string $token): bool
    {
        return is_string($token) && preg_match('/^[a-f0-9]{48}$/D', $token) === 1;
    }

    /**
     * El nombre con el que se publica un fichero: sin carpetas, sin «..», sin caracteres de control ni nada fuera de [A-Za-z0-9._-].
     */
    public static function sanitize(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = (string) substr($name, (int) strrpos('/' . $name, '/'));
        $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        $name = trim((string) preg_replace('/\.{2,}/', '.', $name), '.-');
        $name = (string) preg_replace('/-{2,}/', '-', $name);
        if ($name === '') {
            $name = 'fichero';
        }
        if (strlen($name) > 100) {
            $ext = (string) pathinfo($name, PATHINFO_EXTENSION);
            $name = substr($name, 0, 90) . ($ext !== '' && strlen($ext) <= 8 ? '.' . $ext : '');
        }
        foreach (array_slice(explode('.', $name), 1) as $part) {
            if (preg_match(self::CODE, $part) === 1 || preg_match(self::ACTIVE, $part) === 1) {
                $name .= '.txt';
                break;
            }
        }
        if (strtolower($name) === 'index.php' || strtolower($name) === '.htaccess') {
            $name .= '.txt';
        }

        return $name;
    }

    /**
     * La dirección completa de la copia pública.
     */
    public static function url(App $app, string $token, string $name): string
    {
        return $app->url()->abs('/' . self::DIR . '/' . $token . '/' . rawurlencode($name), [], '');
    }

    /**
     * Pone un fichero en el estado que se pide: público (se crea el código si no lo tenía y se renueva la copia) o privado (se borra
     * la copia y el código). Devuelve el código con el que queda, o null.
     *
     * @param array{id:int|string,path:string,name:string,public_token:?string} $row
     */
    public static function sync(App $app, array $row, bool $public): ?string
    {
        $db = $app->db();
        $id = (int) $row['id'];
        $token = isset($row['public_token']) && self::validToken((string) $row['public_token']) ? (string) $row['public_token'] : null;
        if (!$public) {
            if ($token !== null) {
                self::remove($app, $token);
            }
            if (isset($row['public_token']) && $row['public_token'] !== null) {
                $db->update('product_files', ['public_token' => null], ['id' => $id]);
            }

            return null;
        }
        $source = $app->has('files') ? $app->service('files')->path((string) $row['path']) : null;
        if ($source === null) {
            // No hay fichero que copiar: se queda como estaba (con su código si ya lo tenía).
            return $token;
        }
        if (!self::ensureBase($app)) {
            return $token;
        }
        $created = false;
        if ($token === null) {
            do {
                $token = self::newToken();
            } while (is_dir(self::baseDir($app) . '/' . $token) || (int) $db->value('SELECT COUNT(*) FROM {product_files} WHERE public_token = ?', [$token]) > 0);
            $created = true;
        }
        $dir = self::baseDir($app) . '/' . $token;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $created ? null : $token;
        }
        self::writeIndex($dir);
        // La dirección de un aviso ya enviado tiene que seguir valiendo: si la carpeta ya tiene su fichero, la copia nueva lo sustituye
        // con el mismo nombre, aunque la versión nueva se llame de otra manera.
        $existing = self::copyIn($dir);
        $name = $existing !== null ? $existing : self::sanitize((string) preg_replace('/^[0-9a-f]{16}-/', '', basename(str_replace('\\', '/', (string) $row['path']))));
        $target = $dir . '/' . $name;
        $part = $target . '.part';
        $copied = @copy($source, $part);
        if ($copied && strtolower((string) pathinfo($target, PATHINFO_EXTENSION)) === 'svg') {
            // Un SVG se abre como página en el origen de la tienda: se publica solo el SVG ya limpio de guiones.
            try {
                $clean = SvgSanitizer::clean((string) file_get_contents($part));
                $copied = file_put_contents($part, $clean) !== false;
            } catch (\Throwable $e) {
                $copied = false;
            }
        }
        if (!$copied || !@rename($part, $target)) {
            @unlink($part);
            if ($created) {
                self::remove($app, $token);
            }

            return $created ? null : $token;
        }
        if ($created || (isset($row['public_token']) ? (string) $row['public_token'] : '') !== $token) {
            $db->update('product_files', ['public_token' => $token], ['id' => $id]);
        }

        return $token;
    }

    /**
     * Borra la carpeta pública de un código (y solo esa).
     */
    public static function remove(App $app, ?string $token): void
    {
        if (!self::validToken($token)) {
            return;
        }
        $dir = self::baseDir($app) . '/' . $token;
        if (!is_dir($dir)) {
            return;
        }
        $base = realpath(self::baseDir($app));
        $real = realpath($dir);
        if ($base === false || $real === false || strpos(str_replace('\\', '/', $real), str_replace('\\', '/', $base) . '/') !== 0) {
            return;
        }
        foreach (glob($dir . '/{,.}*', GLOB_BRACE | GLOB_NOSORT) ?: [] as $entry) {
            if (is_file($entry) || is_link($entry)) {
                @unlink($entry);
            }
        }
        @rmdir($dir);
    }

    /**
     * Lo que enseña el formulario de un fichero: dónde está guardado, cuánto pesa y, si es público, su dirección.
     *
     * @param array<string,mixed> $row path, bytes, public_token
     *
     * @return array{path:string,size:string,url:string}
     */
    public static function location(App $app, array $row): array
    {
        $path = rtrim(str_replace('\\', '/', $app->site()->dataDir), '/') . '/private/' . ltrim((string) $row['path'], '/');
        $bytes = (int) (isset($row['bytes']) ? $row['bytes'] : 0);
        $size = $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : number_format(max(1, (int) ceil($bytes / 1024)), 0, ',', '.') . ' KB';
        $url = '';
        $token = isset($row['public_token']) ? (string) $row['public_token'] : '';
        if (self::validToken($token)) {
            $copy = self::copyIn(self::baseDir($app) . '/' . $token);
            $url = self::url($app, $token, $copy !== null ? $copy : self::sanitize((string) preg_replace('/^[0-9a-f]{16}-/', '', basename((string) $row['path']))));
        }

        return ['path' => $path, 'size' => $size, 'url' => $url];
    }

    /** El único fichero de una carpeta pública (sin el index.php), o null. */
    private static function copyIn(string $dir): ?string
    {
        foreach (glob($dir . '/*', GLOB_NOSORT) ?: [] as $entry) {
            $leaf = basename($entry);
            if (is_file($entry) && $leaf !== 'index.php' && substr($leaf, -5) !== '.part') {
                return $leaf;
            }
        }

        return null;
    }

    /** Crea public/descargas con su index.php (redirige) y su .htaccess (sin índices). */
    private static function ensureBase(App $app): bool
    {
        $base = self::baseDir($app);
        if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) {
            return false;
        }
        self::writeIndex($base);
        $rules = "# AIFEC: las descargas públicas no se listan, no se interpretan y se bajan como fichero.\nOptions -Indexes\n"
            . "<IfModule mod_headers.c>\nHeader set X-Content-Type-Options \"nosniff\"\nHeader set Content-Security-Policy \"default-src 'none'; sandbox\"\n"
            . "<FilesMatch \"(?i)\\.(svgz?|x?html?|xht|xml|js|mjs)$\">\nHeader set Content-Disposition \"attachment\"\n</FilesMatch>\n</IfModule>\n";
        if (!is_file($base . '/.htaccess') || strpos((string) @file_get_contents($base . '/.htaccess'), 'nosniff') === false) {
            @file_put_contents($base . '/.htaccess', $rules);
        }

        return true;
    }

    /** El index.php de una carpeta: redirige a la de arriba, para que nadie vea un listado. */
    private static function writeIndex(string $dir): void
    {
        if (is_file($dir . '/index.php')) {
            return;
        }
        @file_put_contents($dir . '/index.php', "<?php\n/**\n * AIFEC — AI-First E-Commerce.\n *\n * @author    Ecom Experts <ecomyseo@gmail.com>\n * @copyright 2026 Ecom Experts\n * @license   Ver LICENSE.md\n */\n\nheader('Location: ../');\nexit;\n");
    }
}

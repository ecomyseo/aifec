<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Media;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Response;
use Aifec\Media\MediaException;
use Aifec\Media\Mime;
use Aifec\Util\Str;

/**
 * Ficheros privados (servicio «files»): lo que se vende o se adjunta y no puede tener una dirección pública.
 *
 * Viven en `<carpeta de datos del sitio>/private/<zona>/`, fuera de la raíz web, y solo se sirven con response()
 * (una descarga). Una ruta relativa que se salga de esa carpeta no se resuelve nunca. No se admiten ficheros que el
 * servidor pueda ejecutar o interpretar (PHP, HTML, guiones, ejecutables), ni por el nombre ni por el contenido.
 */
final class PrivateFiles
{
    /** Extensiones que no se guardan nunca, en cualquier posición del nombre (`a.php.pdf` también). */
    private const DANGEROUS = '/^(?:php\d*|phtml|phar|pht|phps|phpt|html?|xhtml|shtml?|exe|dll|bat|cmd|com|msi|scr|sh|cgi|pl|py|rb|asp|aspx|jsp|jspx|jar|js|mjs|vbs|ps1|htaccess|htpasswd|ini)$/i';

    /** @var string[] tipos reales de contenido que se rechazan aunque el nombre parezca inocente */
    private const BLOCKED_MIME = [
        'text/x-php', 'application/x-httpd-php', 'text/html', 'application/xhtml+xml', 'application/x-dosexec',
        'application/x-msdownload', 'application/x-executable', 'application/x-sharedlib', 'text/x-shellscript',
        'application/x-sh', 'text/x-python', 'application/javascript', 'text/javascript',
    ];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Guarda un fichero en la carpeta privada.
     *
     * @return array{path:string,name:string,bytes:int,mime:string} path es relativa a la carpeta privada («files/ab12…-factura.pdf»)
     *
     * @throws MediaException si no se puede leer o es de un tipo que no se admite
     */
    public function store(string $tmpPath, string $originalName, string $area = 'files'): array
    {
        if (!is_file($tmpPath) || !is_readable($tmpPath)) {
            throw new MediaException('The file could not be read');
        }
        $bytes = filesize($tmpPath);
        if ($bytes === false || $bytes < 1) {
            throw new MediaException('The file is empty');
        }
        $leaf = basename(str_replace('\\', '/', $originalName));
        foreach (array_slice(explode('.', $leaf), 1) as $part) {
            if (preg_match(self::DANGEROUS, $part) === 1) {
                throw new MediaException('This file type is not allowed');
            }
        }
        $mime = Mime::fromContent($tmpPath);
        $head = (string) file_get_contents($tmpPath, false, null, 0, 512);
        $start = ltrim($head);
        if (in_array($mime, self::BLOCKED_MIME, true) || strncmp($start, '<?', 2) === 0 || strncmp($start, '#!', 2) === 0) {
            throw new MediaException('This file type is not allowed');
        }

        $zone = Str::slug($area, 40);
        $zone = $zone !== '' ? $zone : 'files';
        $name = Mime::safeFilename($originalName);
        $relative = $zone . '/' . bin2hex(random_bytes(8)) . '-' . $name;

        $this->prepareRoot();
        $dir = $this->root() . '/' . $zone;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new MediaException('The private folder is not writable');
        }
        $target = $this->root() . '/' . $relative;
        $part = $target . '.part';
        if (!copy($tmpPath, $part) || !rename($part, $target)) {
            if (is_file($part)) {
                @unlink($part);
            }
            throw new MediaException('The private folder is not writable');
        }

        return ['path' => $relative, 'name' => $name, 'bytes' => (int) $bytes, 'mime' => $mime];
    }

    /**
     * Ruta completa de un fichero privado, o null si no existe o la ruta se sale de la carpeta privada.
     */
    public function path(string $relative): ?string
    {
        if ($relative === '' || strpos($relative, "\0") !== false || strpos($relative, '\\') !== false || $relative[0] === '/'
            || preg_match('#(^|/)\.\.?(/|$)#', $relative) === 1) {
            return null;
        }
        $root = realpath($this->root());
        if ($root === false) {
            return null;
        }
        $full = realpath($root . '/' . $relative);
        if ($full === false) {
            return null;
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $full = str_replace('\\', '/', $full);
        if (strpos($full, $root . '/') !== 0 || !is_file($full)) {
            return null;
        }

        return $full;
    }

    /**
     * Descarga del fichero con el nombre que se pida.
     *
     * @throws HttpError 404 si no existe
     */
    public function response(string $relative, string $downloadName): Response
    {
        $path = $this->path($relative);
        if ($path === null) {
            throw HttpError::notFound();
        }

        $mime = Mime::fromContent($path);

        return Response::file($path, self::withExtension($downloadName, $mime), $mime);
    }

    /**
     * Un nombre que acaba en un número («Versión 1.0.0», lo que dejan algunas importaciones) o sin punto se baja sin
     * extensión y no se abre como lo que es: se le pone la que corresponde al contenido. Uno que ya la lleva se respeta.
     */
    public static function withExtension(string $name, string $mime): string
    {
        $extension = Mime::extension($mime);
        if ($extension === null) {
            return $name;
        }
        $current = pathinfo($name, PATHINFO_EXTENSION);
        if ($current !== '' && !ctype_digit($current)) {
            return $name;
        }

        return rtrim($name, '. ') . '.' . $extension;
    }

    /** Borra el fichero si existe. */
    public function delete(string $relative): void
    {
        $path = $this->path($relative);
        if ($path !== null) {
            @unlink($path);
        }
    }

    /** Carpeta privada del sitio, sin barra final. */
    private function root(): string
    {
        return rtrim(str_replace('\\', '/', $this->app->site()->path('private')), '/');
    }

    /**
     * La primera vez: la carpeta y, por si alguien la pone dentro de la raíz web, un .htaccess que lo niega todo.
     *
     * @throws MediaException
     */
    private function prepareRoot(): void
    {
        $root = $this->root();
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new MediaException('The private folder is not writable');
        }
        if (!is_file($root . '/.htaccess')) {
            file_put_contents($root . '/.htaccess', "Require all denied\n");
        }
        if (!is_file($root . '/index.html')) {
            file_put_contents($root . '/index.html', '');
        }
    }
}

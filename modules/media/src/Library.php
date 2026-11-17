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

use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Kernel\App;
use Aifec\Media\ImageInfo;
use Aifec\Media\ImageProcessor;
use Aifec\Media\MediaException;
use Aifec\Media\Mime;
use Aifec\Media\SvgSanitizer;
use Aifec\Util\Str;

/**
 * Biblioteca de imágenes del sitio (servicio «media»).
 *
 * Guarda el original en `<carpeta de medios>/AAAA/MM/<nombre>-<8 del resumen>.<ext>` y, junto a él, una variante por
 * cada tamaño del tema activo (`image_sizes`), en WebP si el servidor sabe escribirlo. La fila de la tabla `media`
 * lleva las medidas, el peso, el resumen SHA-256 (el mismo fichero dos veces es una sola fila), el color medio y
 * las variantes en JSON. Todo lo que no sea una imagen de verdad se rechaza mirando el CONTENIDO, nunca el nombre.
 *
 * Los mensajes de MediaException son fijos y están en inglés: quien los enseñe los pasa por t().
 */
final class Library
{
    /** @var array<string,array{0:int,1:int}> tamaños que siempre existen (el tema puede cambiarlos o añadir más) */
    private const DEFAULT_SIZES = ['thumb' => [160, 160], 'card' => [480, 480], 'large' => [1200, 1200]];

    /** Megapíxeles máximos de una imagen subida. */
    private const MAX_PIXELS = 50000000;

    /** Lado máximo de una variante, para que un tema mal escrito no pida un tamaño absurdo. */
    private const MAX_SIDE = 6000;

    /** @var App */
    private $app;

    /** @var bool la carpeta de medios ya tiene su .htaccess y su index.html */
    private $rootReady = false;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ alta

    /**
     * Guarda una imagen: la valida por su contenido, la copia a la carpeta de medios y genera sus variantes.
     * Si ya existe el mismo fichero (mismo SHA-256) devuelve su fila y no escribe nada.
     *
     * @param array<string,mixed> $options alt, title, folder, user_id
     *
     * @return array<string,mixed> fila de media (ver get())
     *
     * @throws MediaException con un mensaje fijo (traducible) si no vale
     */
    public function store(string $tmpPath, string $originalName, array $options = []): array
    {
        if (!is_file($tmpPath) || !is_readable($tmpPath)) {
            throw new MediaException('The file could not be read');
        }
        $bytes = filesize($tmpPath);
        if ($bytes === false || $bytes < 1) {
            throw new MediaException('The file is empty');
        }
        if ($bytes > $this->maxBytes()) {
            throw new MediaException('The file is too large');
        }
        $head = file_get_contents($tmpPath, false, null, 0, 65536);
        $mime = Mime::imageFromHead(is_string($head) ? $head : '');
        if ($mime === null) {
            throw new MediaException('The file is not a supported image');
        }

        $source = $tmpPath;
        $cleaned = null;
        if ($mime === 'image/svg+xml') {
            $cleaned = $this->cleanSvg($tmpPath);
            $source = $cleaned;
        }
        try {
            return $this->storeChecked($source, $originalName, $options);
        } finally {
            if ($cleaned !== null) {
                $this->remove($cleaned);
            }
        }
    }

    /**
     * Baja una imagen de una dirección http(s) (con el cliente HTTP de la aplicación) y sigue como store().
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     *
     * @throws MediaException
     */
    public function importUrl(string $url, array $options = []): array
    {
        $url = trim($url);
        $parts = parse_url($url);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = is_array($parts) && isset($parts['host']) ? strtolower(trim((string) $parts['host'], '[]')) : '';
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            throw new MediaException('Only http and https addresses can be imported');
        }
        if ($this->isInternalHost($host)) {
            throw new MediaException('That address is not allowed');
        }
        try {
            $response = $this->app->http()->send(HttpRequest::get($url, ['Accept' => 'image/*'], 30.0));
        } catch (HttpException $e) {
            throw new MediaException('The image could not be downloaded');
        }
        if (!$response->ok() || $response->body === '') {
            throw new MediaException('The image could not be downloaded');
        }
        if (strlen($response->body) > $this->maxBytes()) {
            throw new MediaException('The file is too large');
        }

        $tmp = $this->tmpPath('import');
        if (file_put_contents($tmp, $response->body) === false) {
            throw new MediaException('The file could not be saved');
        }
        try {
            $path = isset($parts['path']) ? (string) $parts['path'] : '';
            $name = $path !== '' ? basename(rawurldecode($path)) : '';

            return $this->store($tmp, $name !== '' ? $name : 'image', $options);
        } finally {
            $this->remove($tmp);
        }
    }

    // ------------------------------------------------------------------ lectura

    /**
     * Fila de media con los tipos ya puestos: id, path, filename, mime, kind, bytes, width, height (null si no se
     * conocen), hash, dominant, alt, title, folder (cadenas), variants (nombre => ['path', 'width', 'height']),
     * created_by y created_at.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $row = $this->app->db()->row('SELECT * FROM {media} WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Lo que necesita una plantilla: ['id', 'url', 'alt', 'width', 'height', 'thumb', 'card', 'large', 'srcset', 'dominant'].
     * `url` es el original; las variantes que no existan apuntan al original. El texto alternativo pedido manda sobre el guardado.
     *
     * @return array<string,mixed>|null
     */
    public function image(int $id, ?string $alt = null): ?array
    {
        $row = $this->get($id);

        return $row === null ? null : $this->describe($row, $alt);
    }

    /**
     * Varias imágenes con UNA sola consulta. Los identificadores que no existen no salen.
     *
     * @param array<int,mixed> $ids
     *
     * @return array<int,array<string,mixed>> id => lo mismo que image()
     */
    public function images(array $ids): array
    {
        $wanted = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $wanted, true)) {
                $wanted[] = $id;
            }
        }
        if ($wanted === []) {
            return [];
        }
        $params = [];
        $marks = $this->app->db()->in($wanted, $params);
        $rows = [];
        foreach ($this->app->db()->select('SELECT * FROM {media} WHERE id IN (' . $marks . ')', $params) as $row) {
            $rows[(int) $row['id']] = $row;
        }
        $out = [];
        foreach ($wanted as $id) {
            if (isset($rows[$id])) {
                $out[$id] = $this->describe($this->hydrate($rows[$id]), null);
            }
        }

        return $out;
    }

    /** Dirección de una imagen: `full` (el original), `thumb`, `card`, `large` u otra variante. Vacío si no existe. */
    public function url(int $id, string $variant = 'full'): string
    {
        $row = $this->get($id);
        if ($row === null) {
            return '';
        }

        return $this->urlOf($row, $variant);
    }

    /**
     * Imágenes de la biblioteca, las nuevas primero.
     *
     * @return array<int,array<string,mixed>> filas como get()
     */
    public function search(string $q = '', string $folder = '', int $limit = 48, int $offset = 0): array
    {
        [$where, $params] = $this->filter($q, $folder);
        $params[] = max(1, $limit);
        $params[] = max(0, $offset);
        $rows = $this->app->db()->select('SELECT * FROM {media}' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?', $params);

        return array_map(function (array $row): array {
            return $this->hydrate($row);
        }, $rows);
    }

    /** Cuántas imágenes cumplen ese filtro. */
    public function count(string $q = '', string $folder = ''): int
    {
        [$where, $params] = $this->filter($q, $folder);

        return (int) $this->app->db()->value('SELECT COUNT(*) FROM {media}' . $where, $params);
    }

    /**
     * Carpetas que se usan y cuántas imágenes tiene cada una.
     *
     * @return array<string,int>
     */
    public function folders(): array
    {
        $out = [];
        foreach ($this->app->db()->select("SELECT folder, COUNT(*) AS n FROM {media} WHERE folder IS NOT NULL AND folder <> '' GROUP BY folder ORDER BY folder") as $row) {
            $out[(string) $row['folder']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Lo mismo que image() a partir de una fila ya leída (get() o search()), sin otra consulta.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public function describe(array $row, ?string $alt = null): array
    {
        $variants = isset($row['variants']) && is_array($row['variants']) ? $row['variants'] : [];
        $srcset = [];
        foreach ($variants as $variant) {
            $srcset[$this->app->url()->media((string) $variant['path'])] = (int) $variant['width'];
        }
        asort($srcset);
        $parts = [];
        foreach ($srcset as $url => $width) {
            if ($width > 0) {
                $parts[] = $url . ' ' . $width . 'w';
            }
        }
        $stored = isset($row['alt']) ? (string) $row['alt'] : '';

        return [
            'id' => (int) $row['id'],
            'url' => $this->urlOf($row, 'full'),
            'alt' => $alt !== null && $alt !== '' ? $alt : $stored,
            'width' => isset($row['width']) ? (int) $row['width'] : null,
            'height' => isset($row['height']) ? (int) $row['height'] : null,
            'thumb' => $this->urlOf($row, 'thumb'),
            'card' => $this->urlOf($row, 'card'),
            'large' => $this->urlOf($row, 'large'),
            'srcset' => implode(', ', $parts),
            'dominant' => isset($row['dominant']) && $row['dominant'] !== '' ? (string) $row['dominant'] : null,
        ];
    }

    // ------------------------------------------------------------------ cambios

    /**
     * Cambia el texto alternativo, el título o la carpeta. Lo que no venga en $data no se toca.
     *
     * @param array<string,mixed> $data alt, title, folder
     */
    public function update(int $id, array $data): void
    {
        $set = [];
        if (array_key_exists('alt', $data)) {
            $set['alt'] = $this->nullable($this->text($data['alt'], 500));
        }
        if (array_key_exists('title', $data)) {
            $set['title'] = $this->nullable($this->text($data['title'], 191));
        }
        if (array_key_exists('folder', $data)) {
            $set['folder'] = $this->nullable(trim($this->text($data['folder'], 120), " /\\"));
        }
        if ($set !== []) {
            $this->app->db()->update('media', $set, ['id' => $id]);
        }
    }

    /**
     * Dónde se usa una imagen: los módulos contestan al filtro «media.usage» `(array $uses, int $id)` con textos
     * como «Producto: Silla».
     *
     * @return string[]
     */
    public function usage(int $id): array
    {
        $uses = $this->app->hooks()->apply('media.usage', [], $id);
        $out = [];
        foreach (is_array($uses) ? $uses : [] as $use) {
            if (is_string($use) && $use !== '') {
                $out[] = $use;
            }
        }

        return $out;
    }

    /**
     * Borra la fila y todos sus ficheros (original y variantes). Si está en uso y no se fuerza, no borra nada y
     * devuelve false; también false si no existe.
     */
    public function delete(int $id, bool $force = false): bool
    {
        $row = $this->get($id);
        if ($row === null) {
            return false;
        }
        if (!$force && $this->usage($id) !== []) {
            return false;
        }
        foreach ($this->filesOf($row) as $relative) {
            $absolute = $this->absolute($relative);
            if ($absolute !== null) {
                $this->remove($absolute);
            }
        }
        $this->app->db()->delete('media', ['id' => $id]);

        return true;
    }

    /**
     * Rehace las variantes a partir del original (cambió el tema o sus tamaños).
     *
     * @throws MediaException si la imagen no existe o falta su fichero
     */
    public function regenerate(int $id): void
    {
        $row = $this->get($id);
        if ($row === null) {
            throw new MediaException('The image does not exist');
        }
        $original = $this->absolute((string) $row['path']);
        if ($original === null || !is_file($original)) {
            throw new MediaException('The original file is missing');
        }
        $info = $this->probe($original);
        $dir = dirname((string) $row['path']);
        $base = pathinfo((string) $row['path'], PATHINFO_FILENAME);
        $variants = $this->makeVariants($original, $dir, $base, $info);

        $kept = [];
        foreach ($variants as $variant) {
            $kept[] = $variant['path'];
        }
        foreach ($this->filesOf($row) as $relative) {
            if ($relative !== $row['path'] && !in_array($relative, $kept, true)) {
                $absolute = $this->absolute($relative);
                if ($absolute !== null) {
                    $this->remove($absolute);
                }
            }
        }
        $this->app->db()->update('media', ['variants' => $variants === [] ? null : $this->json($variants)], ['id' => $id]);
    }

    // ------------------------------------------------------------------ alta (interior)

    /**
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    private function storeChecked(string $source, string $originalName, array $options): array
    {
        $info = $this->probe($source);
        $hash = (string) hash_file('sha256', $source);
        $db = $this->app->db();

        $existing = $db->row('SELECT * FROM {media} WHERE hash = ?', [$hash]);
        if ($existing !== null) {
            $alt = $this->text(isset($options['alt']) ? $options['alt'] : '', 500);
            if ($alt !== '' && ($existing['alt'] === null || $existing['alt'] === '')) {
                $db->update('media', ['alt' => $alt], ['id' => (int) $existing['id']]);
                $existing['alt'] = $alt;
            }

            return $this->hydrate($existing);
        }

        $this->prepareRoot();
        $now = $this->app->clock()->now();
        $dirRel = gmdate('Y/m', $now);
        $dirAbs = $this->root() . '/' . $dirRel;
        if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
            throw new MediaException('The media folder is not writable');
        }

        $ext = (string) Mime::extension($info->mime);
        $stem = Str::slug((string) preg_replace('/\.[^.]*$/', '', Mime::safeFilename($originalName)), 60);
        $stem = $stem !== '' ? $stem : 'image';
        $base = $stem . '-' . substr($hash, 0, 8);
        $relative = $dirRel . '/' . $base . '.' . $ext;
        $original = $dirAbs . '/' . $base . '.' . $ext;

        $written = [$original];
        try {
            $this->copyAtomic($source, $original);
            $variants = $this->makeVariants($original, $dirRel, $base, $info);
            foreach ($variants as $variant) {
                $written[] = $this->root() . '/' . $variant['path'];
            }
            $dominant = null;
            if ($info->format !== 'svg') {
                try {
                    $dominant = ImageProcessor::dominantColor($original);
                } catch (MediaException $e) {
                    $dominant = null;
                }
            }
            $id = $db->insert('media', [
                'path' => $relative,
                'filename' => $stem . '.' . $ext,
                'mime' => $info->mime,
                'kind' => 'image',
                'bytes' => $info->bytes,
                'width' => $info->width > 0 ? $info->width : null,
                'height' => $info->height > 0 ? $info->height : null,
                'hash' => $hash,
                'dominant' => $dominant,
                'alt' => $this->nullable($this->text(isset($options['alt']) ? $options['alt'] : '', 500)),
                'title' => $this->nullable($this->text(isset($options['title']) ? $options['title'] : '', 191)),
                'variants' => $variants === [] ? null : $this->json($variants),
                'folder' => $this->nullable(trim($this->text(isset($options['folder']) ? $options['folder'] : '', 120), " /\\")),
                'created_by' => isset($options['user_id']) && (int) $options['user_id'] > 0 ? (int) $options['user_id'] : null,
                'created_at' => $this->app->clock()->sql($now),
            ]);
        } catch (\Throwable $e) {
            foreach ($written as $file) {
                $this->remove($file);
            }
            throw $e;
        }

        $row = $this->get($id);
        if ($row === null) {
            throw new MediaException('The image could not be saved');
        }

        return $row;
    }

    /**
     * Mide la imagen y aplica los límites de la biblioteca, con mensajes fijos.
     *
     * @throws MediaException
     */
    private function probe(string $path): ImageInfo
    {
        try {
            $info = ImageProcessor::probe($path);
        } catch (MediaException $e) {
            throw new MediaException(strpos($e->getMessage(), 'too large') !== false ? 'The image is too large in pixels' : 'The file is not a valid image');
        }
        if ($info->width * $info->height > self::MAX_PIXELS) {
            throw new MediaException('The image is too large in pixels');
        }

        return $info;
    }

    /**
     * Limpia un SVG y lo deja en un fichero temporal. Se rechaza si no está bien formado o si, quitado lo peligroso,
     * no queda nada dentro.
     *
     * @throws MediaException
     */
    private function cleanSvg(string $path): string
    {
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new MediaException('The file could not be read');
        }
        try {
            $clean = SvgSanitizer::clean($raw);
        } catch (MediaException $e) {
            throw new MediaException('The SVG file is not valid');
        }
        $trimmed = trim($clean);
        $selfClosed = preg_match('#^<svg\b[^>]*/>$#s', $trimmed) === 1;
        $inner = preg_match('#^<svg\b[^>]*>(.*)</svg>$#s', $trimmed, $m) === 1 ? trim($m[1]) : null;
        if ($selfClosed || $inner === '') {
            throw new MediaException('The SVG file is empty after cleaning');
        }
        $tmp = $this->tmpPath('svg');
        if (file_put_contents($tmp, $clean) === false) {
            throw new MediaException('The file could not be saved');
        }

        return $tmp;
    }

    /**
     * Genera las variantes de un original ya guardado. Un SVG o un GIF animado no llevan.
     *
     * @return array<string,array{path:string,width:int,height:int}> nombre => variante (ruta relativa a la carpeta de medios)
     */
    private function makeVariants(string $original, string $dirRel, string $base, ImageInfo $info): array
    {
        if ($info->format === 'svg' || ($info->format === 'gif' && $info->animated)) {
            return [];
        }
        $format = $this->variantFormat($info);
        if ($format === '') {
            return [];
        }
        $ext = (string) Mime::extension('image/' . $format);
        $quality = $this->webpQuality();

        $out = [];
        $seen = [];
        foreach ($this->sizes() as $name => $box) {
            $relative = $dirRel . '/' . $base . '-' . $name . '.' . $ext;
            $target = $this->root() . '/' . $relative;
            try {
                $made = ImageProcessor::resize($original, $target, $box[0], $box[1], ['mode' => 'fit', 'format' => $format, 'quality' => $quality]);
            } catch (MediaException $e) {
                $this->app->log()->warning('Media variant failed', ['path' => $relative, 'error' => $e->getMessage()]);
                continue;
            }
            $key = $made->width . 'x' . $made->height;
            if (isset($seen[$key])) {
                // Una imagen pequeña sale igual en varios tamaños: se guarda una sola copia.
                $this->remove($target);
                $out[$name] = $seen[$key];
                continue;
            }
            $seen[$key] = ['path' => $relative, 'width' => $made->width, 'height' => $made->height];
            $out[$name] = $seen[$key];
        }

        return $out;
    }

    /** WebP si el servidor lo escribe; si no, el formato del original, y si tampoco, JPEG o PNG. Vacío si no se puede ninguno. */
    private function variantFormat(ImageInfo $info): string
    {
        foreach (['webp', $info->format, 'jpeg', 'png'] as $format) {
            if (ImageProcessor::supports($format)) {
                return $format === 'jpg' ? 'jpeg' : $format;
            }
        }

        return '';
    }

    /**
     * Tamaños de las variantes: los de `image_sizes` del tema activo sobre los de serie (thumb, card y large siempre existen).
     *
     * @return array<string,array{0:int,1:int}>
     */
    private function sizes(): array
    {
        $sizes = self::DEFAULT_SIZES;
        $theme = $this->app->theme();
        $declared = isset($theme['image_sizes']) && is_array($theme['image_sizes']) ? $theme['image_sizes'] : [];
        foreach ($declared as $name => $box) {
            $name = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower((string) $name)), '-');
            $box = is_array($box) ? array_values($box) : [];
            $width = isset($box[0]) ? (int) $box[0] : 0;
            $height = isset($box[1]) ? (int) $box[1] : 0;
            if ($name !== '' && $width > 0 && $height > 0) {
                $sizes[$name] = [min($width, self::MAX_SIDE), min($height, self::MAX_SIDE)];
            }
        }

        return $sizes;
    }

    // ------------------------------------------------------------------ ajustes y rutas

    private function maxBytes(): int
    {
        return max(1, $this->app->settings()->int('media.max_mb', 8)) * 1048576;
    }

    private function webpQuality(): int
    {
        return max(50, min(95, $this->app->settings()->int('media.webp_quality', 82)));
    }

    /** Carpeta de los medios de este sitio, sin barra final. */
    private function root(): string
    {
        return rtrim(str_replace('\\', '/', $this->app->site()->mediaDir($this->app->publicDir())), '/');
    }

    /**
     * Ruta completa de un fichero de la biblioteca, o null si la ruta relativa no es de fiar (con «..», absoluta…).
     */
    private function absolute(string $relative): ?string
    {
        if ($relative === '' || strpos($relative, "\0") !== false || strpos($relative, '\\') !== false || $relative[0] === '/'
            || preg_match('#(^|/)\.\.?(/|$)#', $relative) === 1) {
            return null;
        }

        return $this->root() . '/' . $relative;
    }

    /**
     * La primera vez que se escribe: la carpeta, un .htaccess que impide ejecutar guiones y un index.html vacío.
     *
     * @throws MediaException
     */
    private function prepareRoot(): void
    {
        if ($this->rootReady) {
            return;
        }
        $root = $this->root();
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new MediaException('The media folder is not writable');
        }
        if (!is_file($root . '/.htaccess')) {
            $rules = "<FilesMatch \"\\.(php\\d?|phtml|phar|pl|py|cgi|sh)$\">\nRequire all denied\n</FilesMatch>\nOptions -ExecCGI -Indexes\n";
            if (file_put_contents($root . '/.htaccess', $rules) === false) {
                throw new MediaException('The media folder is not writable');
            }
        }
        if (!is_file($root . '/index.html') && file_put_contents($root . '/index.html', '') === false) {
            throw new MediaException('The media folder is not writable');
        }
        $this->rootReady = true;
    }

    /** Fichero temporal en la carpeta de datos del sitio (fuera de la raíz web). */
    private function tmpPath(string $kind): string
    {
        return $this->app->site()->path('tmp', true) . '/media-' . $kind . '-' . bin2hex(random_bytes(6));
    }

    /**
     * @throws MediaException
     */
    private function copyAtomic(string $source, string $target): void
    {
        $part = $target . '.part';
        if (!copy($source, $part)) {
            $this->remove($part);
            throw new MediaException('The media folder is not writable');
        }
        if (!rename($part, $target)) {
            $this->remove($part);
            throw new MediaException('The media folder is not writable');
        }
    }

    /** Borra un fichero si existe. Se usa también con temporales que quizá ya no están: un fallo al borrar no es un error. */
    private function remove(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    // ------------------------------------------------------------------ piezas

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        $decoded = isset($row['variants']) && is_string($row['variants']) && $row['variants'] !== '' ? json_decode($row['variants'], true) : null;
        $variants = [];
        foreach (is_array($decoded) ? $decoded : [] as $name => $variant) {
            if (is_array($variant) && isset($variant['path']) && is_string($variant['path'])) {
                $variants[(string) $name] = [
                    'path' => $variant['path'],
                    'width' => isset($variant['width']) ? (int) $variant['width'] : 0,
                    'height' => isset($variant['height']) ? (int) $variant['height'] : 0,
                ];
            }
        }

        return [
            'id' => (int) $row['id'],
            'path' => (string) $row['path'],
            'filename' => (string) $row['filename'],
            'mime' => (string) $row['mime'],
            'kind' => (string) $row['kind'],
            'bytes' => (int) $row['bytes'],
            'width' => isset($row['width']) ? (int) $row['width'] : null,
            'height' => isset($row['height']) ? (int) $row['height'] : null,
            'hash' => isset($row['hash']) ? (string) $row['hash'] : '',
            'dominant' => isset($row['dominant']) ? (string) $row['dominant'] : null,
            'alt' => isset($row['alt']) ? (string) $row['alt'] : '',
            'title' => isset($row['title']) ? (string) $row['title'] : '',
            'folder' => isset($row['folder']) ? (string) $row['folder'] : '',
            'variants' => $variants,
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : '',
        ];
    }

    /**
     * @param array<string,mixed> $row fila de get()
     */
    private function urlOf(array $row, string $variant): string
    {
        $variants = isset($row['variants']) && is_array($row['variants']) ? $row['variants'] : [];
        if ($variant !== 'full' && isset($variants[$variant]['path'])) {
            return $this->app->url()->media((string) $variants[$variant]['path']);
        }

        return $this->app->url()->media((string) $row['path']);
    }

    /**
     * Todos los ficheros de una imagen (rutas relativas, sin repetir): el original y las variantes.
     *
     * @param array<string,mixed> $row fila de get()
     *
     * @return string[]
     */
    private function filesOf(array $row): array
    {
        $files = [(string) $row['path']];
        foreach (isset($row['variants']) && is_array($row['variants']) ? $row['variants'] : [] as $variant) {
            if (!in_array((string) $variant['path'], $files, true)) {
                $files[] = (string) $variant['path'];
            }
        }

        return $files;
    }

    /**
     * @return array{0:string,1:array<int,mixed>} cláusula WHERE (con su espacio delante, o vacía) y parámetros
     */
    private function filter(string $q, string $folder): array
    {
        $where = [];
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            $where[] = "(filename LIKE ? ESCAPE '!' OR alt LIKE ? ESCAPE '!' OR title LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like);
        }
        $folder = trim($folder);
        if ($folder !== '') {
            $where[] = 'folder = ?';
            $params[] = $folder;
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * @param mixed $value
     */
    private function text($value, int $max): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return mb_substr($text, 0, $max);
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Una dirección que apunta a la propia máquina o a una red interna no se descarga. */
    private function isInternalHost(string $host): bool
    {
        if ($host === 'localhost' || substr($host, -10) === '.localhost' || substr($host, -6) === '.local' || substr($host, -9) === '.internal') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return false;
    }
}

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
 * Medir, redimensionar y convertir imágenes con la extensión GD (sin Imagick).
 *
 * - `probe` mira el contenido del fichero (firma y cabecera), nunca su extensión, y rechaza lo
 *   que mide más de 12.000 px de lado o más de 80 megapíxeles antes de decodificar nada.
 * - `resize` y `variants` no amplían nunca, corrigen la orientación EXIF de los JPEG, conservan
 *   la transparencia en PNG, WebP y AVIF y escriben de forma atómica (temporal + renombrado).
 * - GD no guarda metadatos: todo lo que sale va sin EXIF ni perfiles.
 * - Un GIF animado se copia tal cual (GD no sabe escribir animaciones).
 */
final class ImageProcessor
{
    private const MAX_SIDE = 12000;

    private const MAX_PIXELS = 80000000;

    /** GIF animados: como mucho se lee esta cantidad de bytes para contar fotogramas. */
    private const GIF_SCAN_BYTES = 30000000;

    /** @var string[] formatos que se pueden escribir */
    private const WRITABLE = ['jpeg', 'png', 'gif', 'webp', 'avif'];

    /**
     * ¿Sabe el GD instalado escribir este formato? (`jpeg`/`jpg`, `png`, `gif`, `webp`, `avif`).
     */
    public static function supports(string $format): bool
    {
        $format = self::normalizeFormat($format);
        $info = function_exists('gd_info') ? gd_info() : [];
        switch ($format) {
            case 'jpeg':
                return function_exists('imagejpeg') && (!empty($info['JPEG Support']) || !empty($info['JPG Support']));
            case 'png':
                return function_exists('imagepng') && !empty($info['PNG Support']);
            case 'gif':
                return function_exists('imagegif') && !empty($info['GIF Create Support']);
            case 'webp':
                return function_exists('imagewebp') && !empty($info['WebP Support']);
            case 'avif':
                return function_exists('imageavif') && !empty($info['AVIF Support']);
            default:
                return false;
        }
    }

    /**
     * Mide una imagen por su contenido.
     *
     * @throws MediaException si no es una imagen admitida o se pasa de 12.000 px de lado o 80 megapíxeles
     */
    public static function probe(string $path): ImageInfo
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new MediaException('Image file not found or unreadable');
        }
        $bytes = filesize($path);
        if ($bytes === false || $bytes < 1) {
            throw new MediaException('Image file is empty');
        }
        $head = self::readHead($path, 65536);
        $mime = Mime::imageFromHead($head);
        if ($mime === null) {
            throw new MediaException('Not a supported image');
        }
        $format = self::formatOfMime($mime);

        if ($format === 'svg') {
            [$width, $height] = self::svgSize($head);

            return new ImageInfo($width, $height, $mime, 'svg', $bytes, false, true);
        }

        // Un fichero corrupto da un aviso de lectura: se informa con la excepción, no con el aviso.
        $size = @getimagesize($path);
        $width = is_array($size) ? (int) $size[0] : 0;
        $height = is_array($size) ? (int) $size[1] : 0;
        if (($width < 1 || $height < 1) && $format === 'avif') {
            [$width, $height] = self::avifSize($head);
        }
        if ($width < 1 || $height < 1) {
            throw new MediaException('Could not read the image dimensions');
        }
        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw new MediaException('Image is too large (' . $width . 'x' . $height . ')');
        }

        [$animated, $hasAlpha] = self::flags($path, $format, $head);

        return new ImageInfo($width, $height, $mime, $format, $bytes, $animated, $hasAlpha);
    }

    /**
     * Redimensiona y/o convierte una imagen.
     *
     * Opciones: `mode` (`fit` por defecto: cabe dentro sin recortar ni ampliar; `cover`: rellena
     * la caja y recorta centrado; `pad`: cabe y rellena con `background`), `format` (`jpeg`,
     * `png`, `gif`, `webp`, `avif` o `auto` = el del origen), `quality` (1-100; por defecto 82 en
     * JPEG, 80 en WebP y 55 en AVIF) y `background` (`[r, g, b]`, blanco por defecto, para pasar
     * la transparencia a JPEG y GIF o rellenar con `pad`).
     *
     * Nunca se amplía: en `cover` y `pad` la caja de salida se encoge, conservando su
     * proporción, hasta caber dentro del original. Devuelve los datos del fichero escrito.
     *
     * @param array<string,mixed> $options
     *
     * @throws MediaException si el origen no es una imagen, las opciones no valen o no se puede escribir
     */
    public static function resize(string $source, string $target, int $maxWidth, int $maxHeight, array $options = []): ImageInfo
    {
        if ($maxWidth < 1 || $maxHeight < 1) {
            throw new MediaException('The maximum width and height must be positive');
        }
        $info = self::probe($source);
        if ($info->format === 'svg') {
            throw new MediaException('SVG images cannot be rasterised');
        }
        $mode = self::readMode($options);
        $format = self::readFormat($options, $info);
        $quality = self::readQuality($options, $format);
        [$background, $explicitBackground] = self::readBackground($options);

        if ($info->animated && $info->format === 'gif') {
            self::copyAtomic($source, $target);

            return self::probe($target);
        }

        $image = self::loadOriented($source, $info);
        try {
            $canvas = self::render($image, $mode, $maxWidth, $maxHeight, $format, $background, $explicitBackground);
            try {
                self::writeAtomic($canvas, $format, $target, $quality);
            } finally {
                self::release($canvas);
            }
        } finally {
            self::release($image);
        }

        return self::probe($target);
    }

    /**
     * Color medio de la imagen en `#rrggbb` (se reduce a 1×1), para el fondo mientras carga.
     * La transparencia se compone sobre blanco.
     *
     * @throws MediaException si no es una imagen que GD pueda leer
     */
    public static function dominantColor(string $path): string
    {
        $info = self::probe($path);
        if ($info->format === 'svg') {
            throw new MediaException('SVG images have no dominant color');
        }
        $image = self::loadOriented($path, $info);
        try {
            $pixel = imagecreatetruecolor(1, 1);
            if ($pixel === false) {
                throw new MediaException('Could not allocate the image');
            }
            try {
                imagealphablending($pixel, true);
                imagefilledrectangle($pixel, 0, 0, 0, 0, (int) imagecolorallocate($pixel, 255, 255, 255));
                imagecopyresampled($pixel, $image, 0, 0, 0, 0, 1, 1, imagesx($image), imagesy($image));
                $rgb = imagecolorat($pixel, 0, 0);
            } finally {
                self::release($pixel);
            }
        } finally {
            self::release($image);
        }

        return sprintf('#%02x%02x%02x', ($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255);
    }

    /**
     * Genera todas las combinaciones de tamaño y formato.
     *
     * `$sizes` = `['card' => [400, 400, 'fit'], 'thumb' => [100, 100, 'cover']]` (el modo es
     * opcional y vale `fit`). `$formats` = `['webp', 'jpeg']`: los que GD no sepa escribir se
     * saltan sin fallar. Un tamaño cuya caja es mayor que el original en ancho y en alto se salta
     * (no hay nada que reducir); nunca sale una variante más ancha ni más alta que el original.
     * Los ficheros se llaman `<base>-<tamaño>.<extensión>`. El original se decodifica una sola vez.
     *
     * @param array<string|int,mixed> $sizes
     * @param string[]                $formats
     *
     * @return array<int,array{size:string,format:string,path:string,width:int,height:int,bytes:int}>
     *
     * @throws MediaException si el origen no es una imagen, un tamaño no vale o no se puede escribir
     */
    public static function variants(string $source, string $targetDir, string $baseName, array $sizes, array $formats): array
    {
        $info = self::probe($source);
        if ($info->format === 'svg') {
            throw new MediaException('SVG images cannot be rasterised');
        }
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new MediaException('Could not create the target directory');
        }
        $dir = rtrim(str_replace('\\', '/', $targetDir), '/');
        $base = Mime::safeFilename($baseName);

        $animatedGif = $info->animated && $info->format === 'gif';
        $wanted = [];
        foreach (($animatedGif ? ['gif'] : $formats) as $format) {
            $format = self::normalizeFormat((string) $format);
            if ($format === 'auto') {
                $format = $info->format;
            }
            if (in_array($format, self::WRITABLE, true) && self::supports($format) && !in_array($format, $wanted, true)) {
                $wanted[] = $format;
            }
        }

        $specs = [];
        foreach ($sizes as $name => $spec) {
            $spec = is_array($spec) ? array_values($spec) : [];
            $width = isset($spec[0]) ? (int) $spec[0] : 0;
            $height = isset($spec[1]) ? (int) $spec[1] : 0;
            $mode = isset($spec[2]) ? strtolower((string) $spec[2]) : 'fit';
            if ($width < 1 || $height < 1) {
                throw new MediaException('Invalid size "' . $name . '": width and height must be positive');
            }
            self::readMode(['mode' => $mode]);
            $slug = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower((string) $name)), '-');
            $specs[] = [$slug !== '' ? $slug : 'size', $width, $height, $mode];
        }

        $result = [];
        if ($wanted === [] || $specs === []) {
            return $result;
        }
        $image = self::loadOriented($source, $info);
        try {
            $imageWidth = imagesx($image);
            $imageHeight = imagesy($image);
            foreach ($specs as [$slug, $width, $height, $mode]) {
                if ($width > $imageWidth && $height > $imageHeight) {
                    continue;
                }
                foreach ($wanted as $format) {
                    $ext = (string) Mime::extension('image/' . $format);
                    $path = $dir . '/' . $base . '-' . $slug . '.' . $ext;
                    if ($animatedGif) {
                        self::copyAtomic($source, $path);
                    } else {
                        $canvas = self::render($image, $mode, $width, $height, $format, [255, 255, 255], false);
                        try {
                            self::writeAtomic($canvas, $format, $path, self::defaultQuality($format));
                        } finally {
                            self::release($canvas);
                        }
                    }
                    $written = self::probe($path);
                    $result[] = [
                        'size' => $slug,
                        'format' => $format,
                        'path' => $path,
                        'width' => $written->width,
                        'height' => $written->height,
                        'bytes' => $written->bytes,
                    ];
                }
            }
        } finally {
            self::release($image);
        }

        return $result;
    }

    // ---------------------------------------------------------------- opciones

    private static function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));

        return $format === 'jpg' ? 'jpeg' : $format;
    }

    /**
     * @param array<string,mixed> $options
     */
    private static function readMode(array $options): string
    {
        $mode = isset($options['mode']) ? strtolower((string) $options['mode']) : 'fit';
        if (!in_array($mode, ['fit', 'cover', 'pad'], true)) {
            throw new MediaException('Unknown resize mode "' . $mode . '"');
        }

        return $mode;
    }

    /**
     * @param array<string,mixed> $options
     */
    private static function readFormat(array $options, ImageInfo $info): string
    {
        $format = isset($options['format']) ? self::normalizeFormat((string) $options['format']) : 'auto';
        if ($format === 'auto') {
            $format = $info->format;
        }
        if (!in_array($format, self::WRITABLE, true)) {
            throw new MediaException('Unknown output format "' . $format . '"');
        }
        if (!self::supports($format)) {
            throw new MediaException('This PHP cannot write ' . $format . ' images');
        }

        return $format;
    }

    /**
     * @param array<string,mixed> $options
     */
    private static function readQuality(array $options, string $format): int
    {
        if (isset($options['quality'])) {
            return max(1, min(100, (int) $options['quality']));
        }

        return self::defaultQuality($format);
    }

    private static function defaultQuality(string $format): int
    {
        switch ($format) {
            case 'webp':
                return 80;
            case 'avif':
                return 55;
            default:
                return 82;
        }
    }

    /**
     * @param array<string,mixed> $options
     *
     * @return array{0:array{0:int,1:int,2:int},1:bool} color y si lo pidió el llamador
     */
    private static function readBackground(array $options): array
    {
        if (!isset($options['background'])) {
            return [[255, 255, 255], false];
        }
        $bg = $options['background'];
        if (!is_array($bg)) {
            throw new MediaException('The background must be an [r, g, b] array');
        }
        $bg = array_values($bg);
        if (count($bg) !== 3) {
            throw new MediaException('The background must be an [r, g, b] array');
        }
        $rgb = [];
        foreach ($bg as $channel) {
            if (!is_int($channel) && !(is_string($channel) && ctype_digit($channel))) {
                throw new MediaException('The background channels must be integers from 0 to 255');
            }
            $channel = (int) $channel;
            if ($channel < 0 || $channel > 255) {
                throw new MediaException('The background channels must be integers from 0 to 255');
            }
            $rgb[] = $channel;
        }

        return [[$rgb[0], $rgb[1], $rgb[2]], true];
    }

    // ---------------------------------------------------------------- lectura de cabeceras

    private static function formatOfMime(string $mime): string
    {
        switch ($mime) {
            case 'image/jpeg':
                return 'jpeg';
            case 'image/png':
                return 'png';
            case 'image/gif':
                return 'gif';
            case 'image/webp':
                return 'webp';
            case 'image/avif':
                return 'avif';
            default:
                return 'svg';
        }
    }

    private static function readHead(string $path, int $length): string
    {
        $data = file_get_contents($path, false, null, 0, $length);

        return is_string($data) ? $data : '';
    }

    /**
     * @return array{0:int,1:int}
     */
    private static function svgSize(string $head): array
    {
        if (preg_match('/<svg\b[^>]*>/is', $head, $tag) !== 1) {
            return [0, 0];
        }
        $tag = $tag[0];
        $attr = static function (string $name) use ($tag): string {
            if (preg_match('/(?<![\w:-])' . $name . '\s*=\s*(["\'])(.*?)\1/is', $tag, $m) === 1) {
                return trim($m[2]);
            }

            return '';
        };
        $toPixels = static function (string $value): int {
            if (preg_match('/^(\d+(?:\.\d+)?)\s*(px)?$/i', $value, $m) === 1) {
                return (int) round((float) $m[1]);
            }

            return 0;
        };
        $width = $toPixels($attr('width'));
        $height = $toPixels($attr('height'));
        if (($width < 1 || $height < 1) && preg_match('/^\s*-?[\d.]+[\s,]+-?[\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)\s*$/', $attr('viewBox'), $m) === 1) {
            $width = $width < 1 ? (int) round((float) $m[1]) : $width;
            $height = $height < 1 ? (int) round((float) $m[2]) : $height;
        }

        return [$width, $height];
    }

    /**
     * Medidas de un AVIF leídas de la caja `ispe`, para versiones de PHP que no lo entienden.
     *
     * @return array{0:int,1:int}
     */
    private static function avifSize(string $head): array
    {
        $pos = strpos($head, 'ispe');
        if ($pos === false || strlen($head) < $pos + 16) {
            return [0, 0];
        }
        $dims = unpack('N2', substr($head, $pos + 8, 8));

        return is_array($dims) ? [(int) $dims[1], (int) $dims[2]] : [0, 0];
    }

    /**
     * Si tiene más de un fotograma y si puede tener transparencia, según los bytes del fichero.
     *
     * @return array{0:bool,1:bool} animada, con alfa
     */
    private static function flags(string $path, string $format, string $head): array
    {
        switch ($format) {
            case 'png':
                $ctype = strlen($head) > 25 ? ord($head[25]) : 0;
                $idat = strpos($head, 'IDAT');
                $trns = strpos($head, 'tRNS');
                $actl = strpos($head, 'acTL');
                $alpha = $ctype === 4 || $ctype === 6 || ($trns !== false && ($idat === false || $trns < $idat));

                return [$actl !== false && ($idat === false || $actl < $idat), $alpha];
            case 'gif':
                $data = $head;
                if (strlen($head) >= 65536) {
                    $data = self::readHead($path, self::GIF_SCAN_BYTES);
                }
                $frames = preg_match_all('/\x21\xF9\x04.{4}\x00[\x21\x2C]/s', $data);
                $alpha = false;
                if (preg_match_all('/\x21\xF9\x04(.)/s', $data, $flags) > 0) {
                    foreach ($flags[1] as $byte) {
                        if ((ord($byte) & 1) === 1) {
                            $alpha = true;
                            break;
                        }
                    }
                }

                return [(int) $frames > 1, $alpha];
            case 'webp':
                $chunk = substr($head, 12, 4);
                if ($chunk === 'VP8X' && strlen($head) > 20) {
                    $bits = ord($head[20]);

                    return [($bits & 0x02) !== 0, ($bits & 0x10) !== 0];
                }
                if ($chunk === 'VP8L' && strlen($head) >= 25) {
                    $word = unpack('V', substr($head, 21, 4));

                    return [false, is_array($word) && ((((int) $word[1]) >> 28) & 1) === 1];
                }

                return [false, false];
            case 'avif':
                $animated = substr($head, 8, 4) === 'avis';
                $alpha = strpos($head, 'auxC') !== false && stripos($head, 'alpha') !== false;

                return [$animated, $alpha];
            default:
                return [false, false];
        }
    }

    // ---------------------------------------------------------------- GD

    /**
     * Carga la imagen en GD (siempre en color verdadero) y le corrige la orientación EXIF.
     *
     * @return \GdImage|resource
     */
    private static function loadOriented(string $path, ImageInfo $info)
    {
        $loaders = [
            'jpeg' => 'imagecreatefromjpeg',
            'png' => 'imagecreatefrompng',
            'gif' => 'imagecreatefromgif',
            'webp' => 'imagecreatefromwebp',
            'avif' => 'imagecreatefromavif',
        ];
        $loader = $loaders[$info->format] ?? '';
        if ($loader === '' || !function_exists($loader)) {
            throw new MediaException('This PHP cannot read ' . $info->format . ' images');
        }
        // Una imagen corrupta da avisos de la librería: se informa con la excepción.
        $image = @$loader($path);
        if ($image === false || $image === null) {
            throw new MediaException('Could not decode the image');
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        if ($info->format === 'jpeg') {
            $image = self::applyOrientation($image, $path);
        }

        return $image;
    }

    /**
     * @param \GdImage|resource $image
     *
     * @return \GdImage|resource
     */
    private static function applyOrientation($image, string $path)
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        // Un EXIF mal formado avisa y devuelve false: da igual, la imagen se usa sin girar.
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;
        if ($orientation < 2 || $orientation > 8) {
            return $image;
        }
        $steps = [
            2 => [0, true, false],
            3 => [180, false, false],
            4 => [0, false, true],
            5 => [-90, true, false],
            6 => [-90, false, false],
            7 => [90, true, false],
            8 => [90, false, false],
        ][$orientation];
        [$angle, $flipHorizontal, $flipVertical] = $steps;

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated !== false) {
                self::release($image);
                $image = $rotated;
            }
        }
        if ($flipHorizontal) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        if ($flipVertical) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        return $image;
    }

    /**
     * Dibuja el origen en un lienzo nuevo según el modo, sin ampliar.
     *
     * @param \GdImage|resource $image
     * @param int[]             $background [r, g, b]
     *
     * @return \GdImage|resource
     */
    private static function render($image, string $mode, int $maxWidth, int $maxHeight, string $format, array $background, bool $explicitBackground)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $srcX = 0;
        $srcY = 0;
        $srcW = $width;
        $srcH = $height;
        $dstX = 0;
        $dstY = 0;

        if ($mode === 'fit') {
            $scale = min($maxWidth / $width, $maxHeight / $height, 1.0);
            $dstW = max(1, (int) round($width * $scale));
            $dstH = max(1, (int) round($height * $scale));
            $canvasW = $dstW;
            $canvasH = $dstH;
        } else {
            // La caja se encoge, con su proporción, hasta caber dentro del original.
            $shrink = min(1.0, $width / $maxWidth, $height / $maxHeight);
            $canvasW = max(1, (int) round($maxWidth * $shrink));
            $canvasH = max(1, (int) round($maxHeight * $shrink));
            if ($mode === 'cover') {
                $scale = max($canvasW / $width, $canvasH / $height);
                $srcW = max(1, min($width, (int) round($canvasW / $scale)));
                $srcH = max(1, min($height, (int) round($canvasH / $scale)));
                $srcX = (int) floor(($width - $srcW) / 2);
                $srcY = (int) floor(($height - $srcH) / 2);
                $dstW = $canvasW;
                $dstH = $canvasH;
            } else {
                $scale = min($canvasW / $width, $canvasH / $height, 1.0);
                $dstW = max(1, (int) round($width * $scale));
                $dstH = max(1, (int) round($height * $scale));
                $dstX = (int) floor(($canvasW - $dstW) / 2);
                $dstY = (int) floor(($canvasH - $dstH) / 2);
            }
        }

        $canvas = imagecreatetruecolor($canvasW, $canvasH);
        if ($canvas === false) {
            throw new MediaException('Could not allocate the image');
        }
        $keepsAlpha = in_array($format, ['png', 'webp', 'avif'], true);
        if ($keepsAlpha && !($mode === 'pad' && $explicitBackground)) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $canvasW - 1, $canvasH - 1, (int) $clear);
        } else {
            imagealphablending($canvas, true);
            if ($keepsAlpha) {
                imagesavealpha($canvas, true);
            }
            $fill = imagecolorallocate($canvas, $background[0], $background[1], $background[2]);
            imagefilledrectangle($canvas, 0, 0, $canvasW - 1, $canvasH - 1, (int) $fill);
        }
        if (!imagecopyresampled($canvas, $image, $dstX, $dstY, $srcX, $srcY, $dstW, $dstH, $srcW, $srcH)) {
            self::release($canvas);
            throw new MediaException('Could not resample the image');
        }

        return $canvas;
    }

    /**
     * Escribe la imagen en un temporal de la misma carpeta y lo renombra al destino.
     *
     * @param \GdImage|resource $canvas
     */
    private static function writeAtomic($canvas, string $format, string $target, int $quality): void
    {
        $tmp = self::tempPath($target);
        switch ($format) {
            case 'jpeg':
                $ok = imagejpeg($canvas, $tmp, $quality);
                break;
            case 'png':
                $ok = imagepng($canvas, $tmp, 6);
                break;
            case 'gif':
                $ok = imagegif($canvas, $tmp);
                break;
            case 'webp':
                $ok = imagewebp($canvas, $tmp, $quality);
                break;
            default:
                $ok = imageavif($canvas, $tmp, $quality);
                break;
        }
        self::publish($ok, $tmp, $target);
    }

    private static function copyAtomic(string $source, string $target): void
    {
        $tmp = self::tempPath($target);
        self::publish(copy($source, $tmp), $tmp, $target);
    }

    private static function tempPath(string $target): string
    {
        $dir = dirname($target);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new MediaException('The target directory does not exist or is not writable');
        }

        return $dir . '/.' . basename($target) . '.' . bin2hex(random_bytes(4)) . '.tmp';
    }

    private static function publish(bool $written, string $tmp, string $target): void
    {
        if (!$written || !is_file($tmp) || filesize($tmp) < 1) {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            throw new MediaException('Could not write the image');
        }
        if (!rename($tmp, $target)) {
            unlink($tmp);
            throw new MediaException('Could not move the image into place');
        }
    }

    /**
     * @param \GdImage|resource|null $image
     */
    private static function release($image): void
    {
        // Desde PHP 8 las imágenes son objetos y se liberan solas; antes eran recursos.
        if (PHP_VERSION_ID < 80000 && is_resource($image)) {
            imagedestroy($image);
        }
    }
}

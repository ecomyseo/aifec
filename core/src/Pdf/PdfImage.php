<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Pdf;

/**
 * Imagen lista para incrustar en un PDF.
 *
 * JPEG: se incrusta tal cual (DCTDecode). PNG: se pasa por GD a RGB, con la transparencia
 * aplanada sobre blanco, y se comprime con FlateDecode.
 */
final class PdfImage
{
    /** @var int */
    public $width;

    /** @var int */
    public $height;

    /** @var string DeviceRGB, DeviceGray o DeviceCMYK */
    public $colorSpace;

    /** @var string DCTDecode o FlateDecode */
    public $filter;

    /** @var string los bytes del flujo, ya en su filtro */
    public $data;

    /** @var string entrada /Decode del diccionario (solo para JPEG CMYK), o vacío */
    public $decode;

    public function __construct(int $width, int $height, string $colorSpace, string $filter, string $data, string $decode = '')
    {
        $this->width = $width;
        $this->height = $height;
        $this->colorSpace = $colorSpace;
        $this->filter = $filter;
        $this->data = $data;
        $this->decode = $decode;
    }

    /** Carga un JPEG o un PNG. Cualquier otro formato lanza PdfException. */
    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new PdfException('Image file not found or not readable: ' . $path);
        }
        if (!function_exists('getimagesize')) {
            throw new PdfException('Image support is not available');
        }
        $info = self::inspect($path);
        if ($info === null) {
            throw new PdfException('Not a valid image: ' . $path);
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            return self::fromJpeg($path, $info);
        }
        if ($info[2] === IMAGETYPE_PNG) {
            return self::fromPng($path);
        }
        throw new PdfException('Unsupported image format (only JPEG and PNG): ' . $path);
    }

    /**
     * @return array<int|string,mixed>|null
     */
    private static function inspect(string $path): ?array
    {
        // Un fichero que no es una imagen hace que getimagesize() avise y devuelva false: se contesta con null.
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $info = getimagesize($path);
        } finally {
            restore_error_handler();
        }

        return is_array($info) && ($info[0] ?? 0) > 0 && ($info[1] ?? 0) > 0 ? $info : null;
    }

    /**
     * @param array<int|string,mixed> $info
     */
    private static function fromJpeg(string $path, array $info): self
    {
        $data = file_get_contents($path);
        if (!is_string($data) || $data === '') {
            throw new PdfException('Cannot read image: ' . $path);
        }
        $channels = (int) ($info['channels'] ?? 3);
        $decode = '';
        if ($channels === 1) {
            $space = 'DeviceGray';
        } elseif ($channels === 4) {
            $space = 'DeviceCMYK';
            // Los JPEG CMYK de Adobe se guardan invertidos.
            $decode = '[1 0 1 0 1 0 1 0]';
        } else {
            $space = 'DeviceRGB';
        }

        return new self((int) $info[0], (int) $info[1], $space, 'DCTDecode', $data, $decode);
    }

    private static function fromPng(string $path): self
    {
        if (!function_exists('imagecreatefrompng') || !function_exists('imagecreatetruecolor')) {
            throw new PdfException('The GD extension is required to embed PNG images');
        }
        $source = false;
        set_error_handler(static function (int $no, string $str): bool {
            throw new PdfException('Cannot decode PNG: ' . $str);
        });
        try {
            $source = imagecreatefrompng($path);
        } finally {
            restore_error_handler();
        }
        if ($source === false) {
            throw new PdfException('Cannot decode PNG: ' . $path);
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            throw new PdfException('Cannot allocate image memory');
        }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, (int) $white);
        imagealphablending($canvas, true);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

        $raw = '';
        for ($y = 0; $y < $height; ++$y) {
            $row = [];
            for ($x = 0; $x < $width; ++$x) {
                $rgb = (int) imagecolorat($canvas, $x, $y);
                $row[] = ($rgb >> 16) & 0xFF;
                $row[] = ($rgb >> 8) & 0xFF;
                $row[] = $rgb & 0xFF;
            }
            $raw .= pack('C*', ...$row);
        }
        $data = gzcompress($raw, 6);
        if (!is_string($data)) {
            throw new PdfException('Cannot compress image data');
        }

        return new self($width, $height, 'DeviceRGB', 'FlateDecode', $data);
    }
}

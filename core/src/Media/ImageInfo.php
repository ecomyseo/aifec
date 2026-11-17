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
 * Datos de una imagen medidos sobre su contenido (no sobre su extensión).
 *
 * Objeto de datos: se crea una vez y no se modifica. En un SVG sin medidas declaradas el
 * ancho y el alto valen 0.
 */
final class ImageInfo
{
    /** @var int ancho en píxeles */
    public int $width;

    /** @var int alto en píxeles */
    public int $height;

    /** @var string tipo MIME real, por ejemplo `image/webp` */
    public string $mime;

    /** @var string `jpeg`, `png`, `gif`, `webp`, `avif` o `svg` */
    public string $format;

    /** @var int tamaño del fichero en bytes */
    public int $bytes;

    /** @var bool tiene más de un fotograma */
    public bool $animated;

    /** @var bool puede tener píxeles transparentes */
    public bool $hasAlpha;

    public function __construct(int $width, int $height, string $mime, string $format, int $bytes, bool $animated, bool $hasAlpha)
    {
        $this->width = $width;
        $this->height = $height;
        $this->mime = $mime;
        $this->format = $format;
        $this->bytes = $bytes;
        $this->animated = $animated;
        $this->hasAlpha = $hasAlpha;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'mime' => $this->mime,
            'format' => $this->format,
            'bytes' => $this->bytes,
            'animated' => $this->animated,
            'hasAlpha' => $this->hasAlpha,
        ];
    }
}

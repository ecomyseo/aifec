<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai\Generators;

/**
 * El texto alternativo de una imagen de la biblioteca, a partir del nombre del producto y de la posición de la imagen en su galería.
 * No usa visión: el modelo no ve la imagen, así que no puede decir qué se ve en ella más allá de lo que dicen los datos.
 */
final class AltGenerator extends Generator
{
    private const MAX_CHARS = 125;

    public function task(): string
    {
        return 'media.alt';
    }

    public function field(): string
    {
        return 'alt';
    }

    public function entity(): string
    {
        return 'media';
    }

    protected function instruction(string $language, array $options): string
    {
        return 'You write the alternative text of a product image for an online shop. Write in ' . $language . '. '
            . 'One short line of at most ' . self::MAX_CHARS . ' characters that names the product and, when the position says so, the kind of view (main photo, detail, side view). '
            . 'You cannot see the image: do not describe colours, backgrounds or details that are not in the product data. No "image of" or "photo of" at the start. '
            . 'Answer with a JSON object that has an "alt" string.';
    }

    protected function schema(array $options): array
    {
        return ['type' => 'object', 'properties' => ['alt' => ['type' => 'string']], 'required' => ['alt']];
    }

    protected function describe(array $context, array $options): string
    {
        $lines = [parent::describe($context, $options)];
        $image = isset($context['image']) && is_array($context['image']) ? $context['image'] : [];
        if (isset($image['position'], $image['total'])) {
            $lines[] = 'Image position in the gallery: ' . (int) $image['position'] . ' of ' . (int) $image['total'];
        }
        if (isset($image['filename']) && is_string($image['filename']) && $image['filename'] !== '') {
            $lines[] = 'File name: ' . $image['filename'];
        }
        if (isset($image['alt']) && is_string($image['alt']) && $image['alt'] !== '') {
            $lines[] = 'Current alternative text: ' . $image['alt'];
        }

        return implode("\n", $lines);
    }

    public function proposed(array $json, array $context): ?string
    {
        return self::line($json, 'alt', self::MAX_CHARS);
    }
}

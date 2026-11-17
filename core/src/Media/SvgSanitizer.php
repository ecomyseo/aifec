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
 * Limpia un SVG subido por una persona para que no pueda ejecutar nada en el navegador.
 *
 * Quita `<!DOCTYPE>` y las declaraciones de entidades, las instrucciones de proceso, los
 * elementos activos (`script`, `foreignObject`, `iframe`, `object`, `embed`…), los atributos
 * `on*`, los enlaces (`href`, `xlink:href`) que no sean un ancla interna `#…` o un `data:image/…`,
 * las animaciones que cambian un enlace o un atributo `on*`, y de las hojas de estilo, los
 * `@import` y las `url()` externas. Si el texto no es un SVG bien formado, lanza
 * {@see MediaException}.
 */
final class SvgSanitizer
{
    /** Tamaño máximo admitido del texto de entrada. */
    private const MAX_BYTES = 5000000;

    /** @var string[] elementos que se eliminan con todo su contenido (en minúsculas) */
    private const FORBIDDEN_ELEMENTS = [
        'script', 'foreignobject', 'iframe', 'object', 'embed', 'applet', 'handler', 'listener',
        'audio', 'video', 'canvas', 'meta', 'link', 'base', 'frame', 'frameset',
    ];

    /**
     * @throws MediaException si no es un SVG bien formado
     */
    public static function clean(string $svg): string
    {
        if (strlen($svg) > self::MAX_BYTES) {
            throw new MediaException('SVG is too large');
        }
        if (strncmp($svg, "\xEF\xBB\xBF", 3) === 0) {
            $svg = substr($svg, 3);
        }
        $svg = trim($svg);
        if ($svg === '') {
            throw new MediaException('Empty SVG');
        }
        $svg = self::stripDoctype($svg);

        $previous = libxml_use_internal_errors(true);
        $legacyLoader = null;
        if (PHP_VERSION_ID < 80000) {
            $legacyLoader = libxml_disable_entity_loader(true);
        }
        try {
            $doc = new \DOMDocument();
            $doc->resolveExternals = false;
            $doc->substituteEntities = false;
            $loaded = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($legacyLoader !== null) {
                libxml_disable_entity_loader($legacyLoader);
            }
        }
        $root = $loaded ? $doc->documentElement : null;
        if ($root === null || strtolower($root->localName) !== 'svg') {
            throw new MediaException('Not a well-formed SVG document');
        }

        self::cleanAttributes($root);
        self::cleanNode($root);

        $out = $doc->saveXML($root);
        if (!is_string($out) || $out === '') {
            throw new MediaException('Could not serialise the SVG');
        }

        return $out;
    }

    /** Quita `<!DOCTYPE …>` (con su subconjunto interno) y cualquier `<!ENTITY …>` suelto. */
    private static function stripDoctype(string $svg): string
    {
        $out = preg_replace('/<!DOCTYPE\b(?:[^\[>]|\[.*?\])*>/is', '', $svg);
        $svg = is_string($out) ? $out : $svg;
        $out = preg_replace('/<!ENTITY\b[^>]*>/is', '', $svg);

        return is_string($out) ? $out : $svg;
    }

    private static function cleanNode(\DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                if (self::isForbiddenElement($child)) {
                    $node->removeChild($child);
                    continue;
                }
                self::cleanAttributes($child);
                if (strtolower($child->localName) === 'style') {
                    self::cleanStyleElement($child);
                }
                self::cleanNode($child);
            } elseif ($child instanceof \DOMText || $child instanceof \DOMCdataSection) {
                continue; // el texto se escapa solo al serializar
            } else {
                // comentarios, instrucciones de proceso y referencias a entidades
                $node->removeChild($child);
            }
        }
    }

    private static function isForbiddenElement(\DOMElement $el): bool
    {
        $name = strtolower($el->localName);
        if (in_array($name, self::FORBIDDEN_ELEMENTS, true)) {
            return true;
        }
        if ($name === 'set' || $name === 'animate') {
            $target = strtolower($el->getAttribute('attributeName'));

            return strpos($target, 'href') !== false || strncmp($target, 'on', 2) === 0;
        }

        return false;
    }

    private static function cleanAttributes(\DOMElement $el): void
    {
        $remove = [];
        foreach ($el->attributes as $attr) {
            $local = strtolower($attr->localName);
            $value = $attr->value;
            if (strncmp($local, 'on', 2) === 0) {
                $remove[] = $attr;
            } elseif ($local === 'href') {
                if (!self::isSafeLink($value)) {
                    $remove[] = $attr;
                }
            } elseif ($local === 'style') {
                $attr->value = self::cleanCss($value);
            } elseif (preg_match('/(?:javascript|vbscript)\s*:/i', $value) === 1) {
                $remove[] = $attr;
            } elseif (preg_match('/url\s*\(/i', $value) === 1 && self::cleanCss($value) !== $value) {
                $remove[] = $attr;
            }
        }
        foreach ($remove as $attr) {
            $el->removeAttributeNode($attr);
        }
    }

    /** Solo anclas internas (`#id`) e imágenes incrustadas (`data:image/…`). */
    private static function isSafeLink(string $value): bool
    {
        $value = trim($value);
        // los espacios y los caracteres de control no pueden esconder el esquema
        $flat = preg_replace('/[\x00-\x20]+/', '', $value);
        $flat = is_string($flat) ? $flat : '';
        if ($flat !== '' && $flat[0] === '#') {
            return true;
        }

        return preg_match('/^data:image\/[a-z0-9.+-]+[;,]/i', $flat) === 1;
    }

    private static function cleanStyleElement(\DOMElement $style): void
    {
        foreach ($style->childNodes as $child) {
            if ($child instanceof \DOMText || $child instanceof \DOMCdataSection) {
                $child->data = self::cleanCss($child->data);
            }
        }
    }

    /** Quita `@import`, `expression()`, `javascript:` y las `url()` que no sean `#…` o `data:image/…`. */
    private static function cleanCss(string $css): string
    {
        $css = (string) preg_replace('/@import\b[^;{]*;?/i', '', $css);
        $css = (string) preg_replace('/expression\s*\(/i', 'blocked(', $css);
        $css = (string) preg_replace('/(?:javascript|vbscript)\s*:/i', 'blocked:', $css);
        $result = preg_replace_callback(
            '/url\s*\(\s*(["\']?)(.*?)\1\s*\)/is',
            static function (array $m): string {
                return self::isSafeLink($m[2]) ? $m[0] : 'url(#)';
            },
            $css
        );

        return is_string($result) ? $result : '';
    }
}

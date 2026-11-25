<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Text;

/**
 * Saneador de HTML por lista blanca. Lo que escribe un editor no puede ejecutar nada en el
 * navegador: solo salen las etiquetas y los atributos permitidos del perfil, con sus valores
 * comprobados y todo escapado. La salida se construye desde el árbol DOM, no por sustitución
 * de texto, y limpiar dos veces da lo mismo que limpiar una.
 *
 * Perfiles: `inline` (solo texto con formato), `content` (páginas y artículos) y `embed`
 * (`content` más vídeos de YouTube y Vimeo en un iframe).
 */
final class HtmlSanitizer
{
    private const PROFILES = ['inline', 'content', 'embed'];

    private const INLINE = ['a', 'b', 'strong', 'i', 'em', 'u', 's', 'mark', 'code', 'kbd', 'sub', 'sup', 'br', 'span', 'abbr'];

    private const CONTENT = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'cite', 'pre', 'hr',
        'figure', 'figcaption', 'img', 'picture', 'source', 'table', 'thead', 'tbody', 'tfoot', 'tr',
        'th', 'td', 'caption', 'colgroup', 'col', 'details', 'summary', 'div', 'section', 'dl', 'dt',
        'dd', 'video', 'audio',
    ];

    /** Se eliminan con todo su contenido. */
    private const DROP = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea',
        'link', 'meta', 'base', 'svg', 'math', 'template', 'applet', 'frame', 'frameset', 'noembed',
        'noframes', 'title', 'head', 'option', 'optgroup', 'datalist', 'canvas',
    ];

    private const VOID = ['br', 'hr', 'img', 'source', 'col'];

    /** Al quitar la etiqueta de estos se deja un espacio para que el texto no se pegue. */
    private const BLOCK = [
        'p', 'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'figure', 'figcaption',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'blockquote', 'pre', 'hr',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'details', 'summary', 'address',
        'fieldset', 'center',
    ];

    private const BOOLEAN = ['controls', 'muted', 'loop', 'playsinline', 'open', 'reversed', 'allowfullscreen'];

    private const TAG_ATTRS = [
        'a' => ['href', 'target', 'rel', 'name'],
        'img' => ['src', 'srcset', 'sizes', 'alt', 'width', 'height', 'loading', 'decoding'],
        'source' => ['src', 'srcset', 'type', 'media', 'sizes'],
        'td' => ['colspan', 'rowspan', 'scope', 'headers'],
        'th' => ['colspan', 'rowspan', 'scope', 'headers'],
        'ol' => ['start', 'reversed', 'type'],
        'video' => ['src', 'controls', 'poster', 'preload', 'width', 'height', 'muted', 'loop', 'playsinline'],
        'audio' => ['src', 'controls', 'preload', 'muted', 'loop'],
        'details' => ['open'],
        'iframe' => ['src', 'width', 'height', 'allow', 'allowfullscreen', 'title'],
    ];

    private const CSS_PROPERTIES = [
        'color', 'background-color', 'font-size', 'font-weight', 'font-style', 'text-align', 'text-transform',
        'text-decoration', 'letter-spacing', 'line-height', 'border-radius', 'border-width', 'border-style',
        'border-color', 'width', 'max-width', 'height', 'min-height',
    ];

    private const CSS_FORBIDDEN = ['url(', 'expression', '@', '\\', '<', 'javascript', '!', 'behavior', 'binding', 'image-set', 'src('];

    public const EMBED_HOSTS = ['www.youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com'];

    /** @var string */
    private $profile;

    /** @var array<string,bool> */
    private $allowed;

    /** @var array<string,bool> */
    private $drop;

    private function __construct(string $profile)
    {
        $this->profile = $profile;
        $names = $profile === 'inline' ? self::INLINE : array_merge(self::INLINE, self::CONTENT);
        $this->allowed = array_fill_keys($names, true);
        $this->drop = array_fill_keys(self::DROP, true);
    }

    /**
     * Devuelve el HTML limpio para el perfil indicado.
     *
     * @throws \InvalidArgumentException si el perfil no existe
     */
    public static function clean(string $html, string $profile = 'content'): string
    {
        if (!in_array($profile, self::PROFILES, true)) {
            throw new \InvalidArgumentException('Unknown sanitizer profile: ' . $profile);
        }
        $out = self::pass($html, $profile);

        // El analizador de HTML recoloca algunas estructuras al leer (un enlace dentro de otro,
        // una tabla mal formada…). Se repite hasta que la salida ya no cambia al volver a leerla:
        // así limpiar dos veces da siempre lo mismo que limpiar una y lo que sale es estable.
        for ($i = 0; $i < 4; ++$i) {
            $next = self::pass($out, $profile);
            if ($next === $out) {
                return $out;
            }
            $out = $next;
        }

        // Sin punto fijo (no se espera): solo el texto, escapado, que sí es estable.
        return trim(self::escapeText(self::text($out)));
    }

    private static function pass(string $html, string $profile): string
    {
        $body = HtmlDom::body($html);
        if ($body === null) {
            return '';
        }
        $sanitizer = new self($profile);

        return trim($sanitizer->children($body, false));
    }

    /**
     * Texto plano: sin etiquetas, con las entidades decodificadas, los espacios colapsados y un
     * salto de línea entre bloques.
     */
    public static function text(string $html): string
    {
        $body = HtmlDom::body($html);
        if ($body === null) {
            return '';
        }
        $sanitizer = new self('content');
        $raw = $sanitizer->plain($body);

        $lines = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line, " \t\r\f");
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    private function plain(\DOMNode $parent): string
    {
        $out = '';
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $t = preg_replace('/[ \t\r\n\f\x{00A0}]+/u', ' ', $child->data);
                $out .= is_string($t) ? $t : $child->data;
                continue;
            }
            if (!($child instanceof \DOMElement)) {
                continue;
            }
            $name = strtolower($child->nodeName);
            if (isset($this->drop[$name])) {
                continue;
            }
            if ($name === 'br') {
                $out .= "\n";
            } elseif ($name === 'td' || $name === 'th') {
                $out .= $this->plain($child) . ' ';
            } elseif (in_array($name, self::BLOCK, true)) {
                $out .= "\n" . $this->plain($child) . "\n";
            } else {
                $out .= $this->plain($child);
            }
        }

        return $out;
    }

    private function children(\DOMNode $parent, bool $inLink): string
    {
        $kids = [];
        foreach ($parent->childNodes as $child) {
            $kids[] = $child;
        }
        $last = count($kids) - 1;

        $out = '';
        foreach ($kids as $index => $child) {
            if ($child instanceof \DOMText) {
                $out .= self::escapeText($child->data);
            } elseif ($child instanceof \DOMElement) {
                $out .= $this->element($child, $index < $last, $inLink);
            }
            // Comentarios, instrucciones de proceso y el resto: fuera.
        }

        return $out;
    }

    private function element(\DOMElement $el, bool $hasNext, bool $inLink): string
    {
        $name = strtolower($el->nodeName);

        if ($name === 'iframe') {
            return $this->iframe($el);
        }
        if (isset($this->drop[$name])) {
            return '';
        }
        // Un enlace dentro de otro no existe en HTML: el navegador lo parte. Se quita el de dentro.
        if (isset($this->allowed[$name]) && !($name === 'a' && $inLink)) {
            $attrs = $this->attributes($el, $name);
            if (in_array($name, self::VOID, true)) {
                return '<' . $name . $attrs . '>';
            }

            return '<' . $name . $attrs . '>' . $this->children($el, $inLink || $name === 'a') . '</' . $name . '>';
        }

        // Etiqueta no permitida: se quita y se conserva su texto.
        $inner = $this->children($el, $inLink);
        if ($hasNext && in_array($name, self::BLOCK, true)) {
            $inner .= ' ';
        }

        return $inner;
    }

    private function iframe(\DOMElement $el): string
    {
        if ($this->profile !== 'embed') {
            return '';
        }
        if (self::embedSource($el->getAttribute('src')) === null) {
            return '';
        }

        return '<iframe' . $this->attributes($el, 'iframe', ' loading="lazy"') . '></iframe>';
    }

    private static function embedSource(string $src): ?string
    {
        $url = UrlFilter::safe($src);
        if ($url === null || preg_match('/[\\\\\s]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (strtolower($parts['scheme']) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        if (!in_array(strtolower($parts['host']), self::EMBED_HOSTS, true)) {
            return null;
        }

        return $url;
    }

    private function attributes(\DOMElement $el, string $tag, string $extra = ''): string
    {
        /** @var array<string,string> $out */
        $out = [];
        foreach ($el->attributes as $attr) {
            $name = strtolower($attr->nodeName);
            if (isset($out[$name])) {
                continue;
            }
            $value = $this->attribute($tag, $name, (string) $attr->value);
            if ($value !== null) {
                $out[$name] = $value;
            }
        }

        if ($tag === 'a' && isset($out['target']) && $out['target'] === '_blank') {
            $tokens = isset($out['rel']) ? explode(' ', $out['rel']) : [];
            foreach (['noopener', 'noreferrer'] as $needed) {
                if (!in_array($needed, $tokens, true)) {
                    $tokens[] = $needed;
                }
            }
            $out['rel'] = implode(' ', $tokens);
        }

        $html = '';
        foreach ($out as $name => $value) {
            if (in_array($name, self::BOOLEAN, true)) {
                $html .= ' ' . $name;
            } else {
                $html .= ' ' . $name . '="' . self::escapeAttribute($value) . '"';
            }
        }

        return $html . $extra;
    }

    /** Los únicos atributos «data-» que pasan: describen el contenido y ningún guion de la tienda los lee para actuar. */
    private const DATA_ATTRS = ['data-id', 'data-align', 'data-lang', 'data-language', 'data-caption', 'data-width', 'data-height', 'data-ratio', 'data-columns', 'data-object-fit', 'data-aspect-ratio'];

    /**
     * @return string|null el valor limpio, o null si el atributo se descarta
     */
    private function attribute(string $tag, string $name, string $value): ?string
    {
        // Atributos globales.
        switch ($name) {
            case 'class':
            case 'title':
                return trim($value) === '' ? null : $value;
            case 'id':
                return self::identifier($value);
            case 'lang':
                return preg_match('/^[A-Za-z0-9-]{1,35}$/', $value) === 1 ? $value : null;
            case 'dir':
                $dir = strtolower(trim($value));

                return in_array($dir, ['ltr', 'rtl', 'auto'], true) ? $dir : null;
            case 'role':
                return preg_match('/^[A-Za-z][A-Za-z -]{0,60}$/', $value) === 1 ? $value : null;
            case 'style':
                $css = self::style($value);

                return $css === '' ? null : $css;
        }
        if (preg_match('/^aria-[a-z0-9_.-]+$/', $name) === 1) {
            return $value;
        }
        // Los «data-» son la entrada de los guiones de la tienda (el consentimiento activa los «data-src», los menús y las ventanas
        // leen los suyos): un texto del editor no puede traer los que quiera. Solo una lista corta de datos inertes.
        if (in_array($name, self::DATA_ATTRS, true)) {
            return $value;
        }

        if (!in_array($name, self::TAG_ATTRS[$tag] ?? [], true)) {
            return null;
        }
        if (in_array($name, self::BOOLEAN, true)) {
            return '';
        }

        switch ($name) {
            case 'href':
                return UrlFilter::safe($value);
            case 'src':
                if ($tag === 'iframe') {
                    return self::embedSource($value);
                }

                return UrlFilter::safe($value, $tag === 'img');
            case 'poster':
                return UrlFilter::safe($value);
            case 'srcset':
                return self::srcset($value);
            case 'target':
                return in_array($value, ['_blank', '_self', '_parent', '_top'], true) ? $value : null;
            case 'rel':
                preg_match_all('/[A-Za-z][A-Za-z-]*/', strtolower($value), $m);
                $tokens = array_values(array_unique($m[0]));

                return $tokens === [] ? null : implode(' ', $tokens);
            case 'name':
                return self::identifier($value);
            case 'alt':
                return $value;
            case 'width':
            case 'height':
                return preg_match('/^\d{1,5}(\.\d{1,3})?(px|%)?$/', $value) === 1 ? $value : null;
            case 'colspan':
            case 'rowspan':
                return preg_match('/^\d{1,4}$/', $value) === 1 ? $value : null;
            case 'start':
                return preg_match('/^-?\d{1,9}$/', $value) === 1 ? $value : null;
            case 'scope':
                return in_array($value, ['row', 'col', 'rowgroup', 'colgroup'], true) ? $value : null;
            case 'headers':
                return preg_match('/^[A-Za-z0-9_ -]+$/', $value) === 1 ? $value : null;
            case 'type':
                if ($tag === 'ol') {
                    return preg_match('/^[1aAiI]$/', $value) === 1 ? $value : null;
                }

                return preg_match('#^[A-Za-z0-9.+/-]{1,80}$#', $value) === 1 ? $value : null;
            case 'loading':
                return in_array($value, ['lazy', 'eager'], true) ? $value : null;
            case 'decoding':
                return in_array($value, ['async', 'sync', 'auto'], true) ? $value : null;
            case 'preload':
                return in_array($value, ['none', 'metadata', 'auto'], true) ? $value : null;
            case 'sizes':
            case 'media':
                return preg_match('/^[A-Za-z0-9 ,.():%<>=\/+*-]{1,200}$/', $value) === 1 ? $value : null;
            case 'allow':
                return preg_match('/^[A-Za-z0-9; -]{1,300}$/', $value) === 1 ? $value : null;
        }

        return null;
    }

    /** Solo letras, cifras, guion y guion bajo. */
    private static function identifier(string $value): ?string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $value);

        return is_string($clean) && $clean !== '' ? $clean : null;
    }

    private static function srcset(string $value): ?string
    {
        $candidates = [];
        $len = strlen($value);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && (self::isSpace($value[$i]) || $value[$i] === ',')) {
                ++$i;
            }
            $start = $i;
            while ($i < $len && !self::isSpace($value[$i])) {
                ++$i;
            }
            $url = substr($value, $start, $i - $start);
            if ($url === '') {
                break;
            }
            $descriptor = '';
            if (substr($url, -1) === ',') {
                $url = rtrim($url, ',');
            } else {
                $dstart = $i;
                while ($i < $len && $value[$i] !== ',') {
                    ++$i;
                }
                $descriptor = trim(substr($value, $dstart, $i - $dstart));
            }
            $safe = UrlFilter::safe($url);
            if ($safe === null || preg_match('/[\s,]$/', $safe) === 1 || strpos($safe, ' ') !== false) {
                continue;
            }
            if ($descriptor !== '' && preg_match('/^\d+(\.\d+)?[wx]$/', $descriptor) !== 1) {
                $descriptor = '';
            }
            $candidates[] = $descriptor === '' ? $safe : $safe . ' ' . $descriptor;
        }

        return $candidates === [] ? null : implode(', ', $candidates);
    }

    private static function isSpace(string $char): bool
    {
        return strpos(" \t\n\r\f", $char) !== false;
    }

    /** Conserva solo las declaraciones permitidas, con valores sencillos. */
    private static function style(string $css): string
    {
        /** @var array<string,string> $kept */
        $kept = [];
        foreach (explode(';', $css) as $declaration) {
            $pos = strpos($declaration, ':');
            if ($pos === false) {
                continue;
            }
            $property = strtolower(trim(substr($declaration, 0, $pos)));
            $value = trim(substr($declaration, $pos + 1));
            if ($value === '' || !self::cssPropertyAllowed($property) || !self::cssValueAllowed($value)) {
                continue;
            }
            $collapsed = preg_replace('/\s+/', ' ', $value);
            $kept[$property] = is_string($collapsed) ? $collapsed : $value;
        }

        $parts = [];
        foreach ($kept as $property => $value) {
            $parts[] = $property . ':' . $value;
        }

        return implode(';', $parts);
    }

    private static function cssPropertyAllowed(string $property): bool
    {
        if (in_array($property, self::CSS_PROPERTIES, true)) {
            return true;
        }

        return preg_match('/^(margin|padding)(-(top|right|bottom|left))?$/', $property) === 1;
    }

    private static function cssValueAllowed(string $value): bool
    {
        $compact = preg_replace('/\s+/', '', strtolower($value));
        if (!is_string($compact)) {
            return false;
        }
        foreach (self::CSS_FORBIDDEN as $forbidden) {
            if (strpos($compact, $forbidden) !== false) {
                return false;
            }
        }

        return preg_match('/^[A-Za-z0-9#%.,+\s()\/-]+$/', $value) === 1;
    }

    private static function escapeText(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return str_replace("\xC2\xA0", '&nbsp;', $escaped);
    }

    private static function escapeAttribute(string $value): string
    {
        return str_replace('`', '&#96;', htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}

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
 * Carga un fragmento de HTML como UTF-8 en un DOMDocument, sin añadir `<html>` ni `<body>` a
 * lo que se lea después, y limpia el texto de bytes que rompen al analizador.
 */
final class HtmlDom
{
    /**
     * Texto en UTF-8 válido y sin caracteres de control (salvo tabulador, salto de línea y
     * retorno de carro).
     */
    public static function normalize(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        if (!is_string($clean)) {
            return '';
        }
        $clean = preg_replace('/[\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}]/u', '', $clean);

        return is_string($clean) ? $clean : '';
    }

    /**
     * El `<body>` del documento que sale de analizar el fragmento, o null si no hay nada.
     */
    public static function body(string $html): ?\DOMElement
    {
        $html = self::normalize($html);
        if (trim($html) === '') {
            return null;
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }

        $body = $doc->getElementsByTagName('body')->item(0);

        return $body instanceof \DOMElement ? $body : null;
    }
}

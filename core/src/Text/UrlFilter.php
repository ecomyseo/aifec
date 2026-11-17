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
 * Filtro de direcciones: decide si una URL escrita por un editor se puede poner en un
 * `href`, un `src` o un `poster`. Lo comparten el saneador de HTML y el conversor de Markdown.
 *
 * Solo pasan `http:`, `https:`, `mailto:`, `tel:`, las rutas relativas y las anclas. Una
 * imagen `data:image/png|jpeg|gif|webp;base64` pasa solo si quien llama lo permite.
 */
final class UrlFilter
{
    private const SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * @return string|null la dirección limpia (sin caracteres de control) o null si no es segura
     */
    public static function safe(string $url, bool $allowDataImage = false): ?string
    {
        // Lo que ve el navegador: sin caracteres de control (tabuladores y saltos incluidos) y sin
        // espacios en los extremos.
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $url);
        if (!is_string($clean)) {
            return null;
        }
        $clean = trim($clean);
        if ($clean === '') {
            return null;
        }

        $scheme = self::scheme($clean);
        // Copia agresiva: entidades decodificadas y sin espacios ni caracteres invisibles. Sirve
        // solo para rechazar: «jav&#x09;ascript:» o «java​script:» no pasan por ninguna puerta.
        $probe = self::probe($clean);
        if ($probe === null) {
            return null;
        }
        $probeScheme = self::scheme($probe);

        if ($scheme === 'data') {
            return $allowDataImage ? self::dataImage($clean) : null;
        }
        if ($scheme !== null && !in_array($scheme, self::SCHEMES, true)) {
            return null;
        }
        if ($probeScheme !== null && !in_array($probeScheme, self::SCHEMES, true)) {
            return null;
        }

        return $clean;
    }

    private static function scheme(string $url): ?string
    {
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $url, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }

    private static function probe(string $url): ?string
    {
        $p = $url;
        for ($i = 0; $i < 3; ++$i) {
            $d = html_entity_decode($p, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($d === $p) {
                break;
            }
            $p = $d;
        }
        $p = preg_replace('/[\s\p{Z}\p{Cc}\p{Cf}]+/u', '', $p);

        return is_string($p) ? $p : null;
    }

    private static function dataImage(string $url): ?string
    {
        $compact = preg_replace('/\s+/', '', $url);
        if (!is_string($compact)) {
            return null;
        }
        if (preg_match('#^data:image/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/]+={0,2}$#i', $compact) !== 1) {
            return null;
        }

        return $compact;
    }
}

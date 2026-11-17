<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Util;

/**
 * Manipulación de URLs y rutas sin salir del texto: unir trozos, parámetros de consulta y
 * comprobación de redirecciones seguras. No hace peticiones de red.
 */
final class Url
{
    /** Une trozos con una sola barra entre ellos. Respeta «https://». */
    public static function join(string ...$parts): string
    {
        $out = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if ($out === '') {
                $out = $part;
                continue;
            }
            if (substr($out, -3) === '://') {
                $out .= ltrim($part, '/');
                continue;
            }
            $out = rtrim($out, '/') . '/' . ltrim($part, '/');
        }

        return $out;
    }

    /** Añade o sustituye parámetros; un valor null quita el parámetro. Conserva el ancla. */
    public static function withQuery(string $url, array $params): string
    {
        [$base, $query, $fragment] = self::split($url);
        $names = [];
        foreach (array_keys($params) as $k) {
            $names[(string) $k] = true;
        }

        $pairs = [];
        foreach (self::pairs($query) as $pair) {
            if (!isset($names[self::pairName($pair)])) {
                $pairs[] = $pair;
            }
        }
        foreach ($params as $k => $v) {
            if ($v === null) {
                continue;
            }
            $pairs[] = http_build_query([(string) $k => $v], '', '&', PHP_QUERY_RFC3986);
        }

        return self::build($base, $pairs, $fragment);
    }

    /** @param string[] $names */
    public static function withoutQuery(string $url, array $names): string
    {
        [$base, $query, $fragment] = self::split($url);
        $drop = [];
        foreach ($names as $n) {
            $drop[(string) $n] = true;
        }

        $pairs = [];
        foreach (self::pairs($query) as $pair) {
            if (!isset($drop[self::pairName($pair)])) {
                $pairs[] = $pair;
            }
        }

        return self::build($base, $pairs, $fragment);
    }

    /** Con esquema («https:», «javascript:») o de red («//host»). */
    public static function isAbsolute(string $url): bool
    {
        return preg_match('#^(?:[a-z][a-z0-9+.\-]*:|//)#i', $url) === 1;
    }

    public static function isHttp(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1;
    }

    /** Host en minúsculas, o null si la URL no tiene. */
    public static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /** Resuelve «.» y «..» sin salir de la raíz y deja una sola barra entre segmentos. */
    public static function normalizePath(string $path): string
    {
        $absolute = $path !== '' && $path[0] === '/';
        $trailing = substr($path, -1) === '/';
        $stack = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($stack !== []) {
                    array_pop($stack);
                }
                continue;
            }
            $stack[] = $segment;
        }

        $out = implode('/', $stack);
        if ($trailing && $stack !== []) {
            $out .= '/';
        }

        return $absolute ? '/' . $out : $out;
    }

    /**
     * Solo rutas relativas que empiezan por una sola «/» (no «//», no «/\»), o URL http(s) del
     * mismo host. Cualquier otra cosa (javascript:, rutas sin barra, «//host») se rechaza.
     */
    public static function isSafeRedirect(string $target, string $allowedHost): bool
    {
        if ($target === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $target) === 1) {
            return false;
        }
        if ($target[0] === '/') {
            return strpos($target, '//') !== 0;
        }
        if (self::isHttp($target)) {
            $host = self::host($target);

            return $host !== null && $allowedHost !== '' && $host === strtolower($allowedHost);
        }

        return false;
    }

    /** @return array{0: string, 1: string, 2: string} base, consulta (sin «?») y ancla (con «#») */
    private static function split(string $url): array
    {
        $fragment = '';
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = (string) substr($url, $hash);
            $url = (string) substr($url, 0, $hash);
        }
        $query = '';
        $question = strpos($url, '?');
        if ($question !== false) {
            $query = (string) substr($url, $question + 1);
            $url = (string) substr($url, 0, $question);
        }

        return [$url, $query, $fragment];
    }

    /** @return string[] */
    private static function pairs(string $query): array
    {
        if ($query === '') {
            return [];
        }

        return array_values(array_filter(explode('&', $query), 'strlen'));
    }

    private static function pairName(string $pair): string
    {
        return urldecode(explode('=', $pair, 2)[0]);
    }

    /** @param string[] $pairs */
    private static function build(string $base, array $pairs, string $fragment): string
    {
        $out = $base;
        if ($pairs !== []) {
            $out .= '?' . implode('&', $pairs);
        }

        return $out . $fragment;
    }
}

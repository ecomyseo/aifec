<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\SearchLearning\Engine;

use Aifec\Kernel\App;

/** Ámbito explícito y saneamiento: nunca contiene claves, una cookie ni información de una persona. */
final class Scope
{
    public static function capture(App $app, string $lang, bool $fresh = false): array
    {
        $site = $app->site();
        $file = $site->dataDir . '/config.php';
        $fileHash = '';
        $backed = false;
        if ($fresh) {
            clearstatcache(true, $file);
            $app->settings()->reload();
            if (is_file($file)) {
                if (function_exists('opcache_invalidate')) { opcache_invalidate($file, true); }
                $loaded = \Aifec\Kernel\SiteResolver::load(dirname($site->dataDir), (string) $site->id, (string) $site->basePath, (string) $site->host);
                if ($loaded === null || $loaded->url() !== $site->url() || $loaded->get('db') !== $site->get('db')) {
                    throw new \RuntimeException('learning.context_changed');
                }
                $site = $loaded;
                $backed = true;
                $fileHash = (string) hash_file('sha256', $file);
            }
        }
        $langs = $fresh ? array_values(array_filter($app->settings()->arr('i18n.languages'), static function ($value): bool {
            return is_string($value) && preg_match('/^[a-z]{2}$/', $value) === 1;
        })) : $app->languages();
        if ($langs === []) { $langs = [strtolower(substr($site->locale(), 0, 2))]; }
        if (!in_array($lang, $langs, true)) {
            throw new \InvalidArgumentException('learning.language');
        }
        $parts = parse_url($site->url());
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('learning.url');
        }
        $scheme = strtolower($parts['scheme']);
        $port = isset($parts['port']) && !(($scheme === 'https' && $parts['port'] === 443) || ($scheme === 'http' && $parts['port'] === 80)) ? ':' . $parts['port'] : '';
        $url = $scheme . '://' . strtolower($parts['host']) . $port . rtrim(isset($parts['path']) ? $parts['path'] : '', '/');
        $context = ['site_id' => (string) $site->id, 'url' => $url, 'lang' => $lang];
        $context['hash'] = hash('sha256', self::json($context));
        $context['config_backed'] = $backed;
        $context['config_hash'] = $fileHash;

        return $context;
    }

    public static function assert(App $app, array $run): void
    {
        $now = self::capture($app, (string) $run['lang'], true);
        if (!hash_equals((string) $run['context_hash'], $now['hash'])) {
            throw new \RuntimeException('learning.context_changed');
        }
        $saved = is_array($run['context'] ?? null) ? $run['context'] : self::decode($run['context'] ?? null);
        if (!empty($saved['config_backed']) && (!$now['config_backed'] || !hash_equals((string) ($saved['config_hash'] ?? ''), $now['config_hash']))) {
            throw new \RuntimeException('learning.context_changed');
        }
    }

    public static function text(string $text, int $max = 200): string
    {
        if (!mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/u', $text) === 1 || mb_strlen($text) > $max) {
            throw new \InvalidArgumentException('learning.unsafe_text');
        }
        $text = trim($text);
        if ($text === '' || preg_match('/(?:https?:\/\/|www\.|\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}\b|[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}|(?:\+?\d[ .()-]*){7,}|\b(?:bearer|api[_ -]?key|password|token|secret|clave|contrasena)\b\s*[:=]|sk-[a-z0-9]{12,}|-----BEGIN)/iu', $text) === 1) {
            throw new \InvalidArgumentException('learning.personal_or_secret');
        }

        return $text;
    }

    public static function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)), 'UTF-8');
    }

    public static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function decode($value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }
}

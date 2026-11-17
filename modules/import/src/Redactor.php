<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

/**
 * Quita los secretos de cualquier texto que vaya a un registro, un informe o una pantalla de la migración.
 *
 * Los secretos son de dos clases: los datos de conexión que escribió quien migra (clave de la API, contraseña de la
 * base de datos…) y los campos de clase «secret» y «secrets» del registro que se está escribiendo (contraseñas de
 * clientes, claves de pasarelas). Lo primero lo da Connections; lo segundo se saca comparando el registro con
 * Neutral::redact().
 */
final class Redactor
{
    /** Longitud mínima de un valor para tratarlo como secreto: con menos, taparía palabras corrientes. */
    private const MIN_LENGTH = 4;

    /**
     * Sustituye cada secreto por «***» y acorta el resultado.
     *
     * @param string[] $secrets
     */
    public static function scrub(string $text, array $secrets, int $max = 255): string
    {
        $list = [];
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= self::MIN_LENGTH) {
                $list[$secret] = true;
            }
        }
        $keys = array_keys($list);
        usort($keys, static function ($a, $b): int {
            return strlen((string) $b) <=> strlen((string) $a);
        });
        foreach ($keys as $secret) {
            $text = str_replace((string) $secret, '***', $text);
        }
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    /**
     * Los valores secretos de un registro neutro (los que Neutral::redact() tapa).
     *
     * @param array<string,mixed> $record
     *
     * @return string[]
     */
    public static function secretsOf(string $type, array $record): array
    {
        $found = [];
        self::walk($record, Neutral::redact($type, $record), $found);

        return array_values(array_unique($found));
    }

    /**
     * Los datos de conexión que no pueden salir nunca en un texto.
     *
     * @param array<string,mixed> $conn lo que guarda Connections: ['config' => […], 'db' => […]]
     *
     * @return string[]
     */
    public static function connectionSecrets(array $conn): array
    {
        $out = [];
        $config = isset($conn['config']) && is_array($conn['config']) ? $conn['config'] : [];
        foreach (['key', 'secret', 'cookie_key', 'url', 'base_url'] as $name) {
            if (isset($config[$name]) && is_string($config[$name])) {
                $out[] = $config[$name];
            }
        }
        $db = isset($conn['db']) && is_array($conn['db']) ? $conn['db'] : [];
        foreach (['host', 'name', 'user', 'password'] as $name) {
            if (isset($db[$name]) && is_string($db[$name])) {
                $out[] = $db[$name];
            }
        }

        return $out;
    }

    /**
     * @param mixed         $original
     * @param mixed         $redacted
     * @param array<mixed>  $found
     */
    private static function walk($original, $redacted, array &$found): void
    {
        if (is_array($original) && is_array($redacted)) {
            foreach ($original as $key => $value) {
                if (array_key_exists($key, $redacted)) {
                    self::walk($value, $redacted[$key], $found);
                }
            }

            return;
        }
        if (is_string($original) && $original !== '' && $redacted === '***' && $original !== '***') {
            $found[] = $original;
        }
    }
}

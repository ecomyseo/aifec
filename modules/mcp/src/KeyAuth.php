<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Mcp;

use Aifec\Kernel\App;

/**
 * La clave de la API que puede mandar un cliente MCP (Authorization: Bearer). La tabla api_keys es la del núcleo y las claves las
 * da de alta el módulo API; aquí solo se comprueban: solo valen las que empiezan por «aifec_api_» (una del ERP no entra) y siguen
 * activas. Se guarda el resumen SHA-256 de la clave, nunca la clave.
 */
final class KeyAuth
{
    private const PREFIX = 'aifec_api_';

    /**
     * @return array<string,mixed>|null la fila de api_keys más 'scopes' como lista, o null si la clave no vale
     */
    public static function authenticate(App $app, string $key): ?array
    {
        if ($key === '' || strlen($key) > 200 || strpos($key, self::PREFIX) !== 0) {
            return null;
        }
        $row = $app->db()->row('SELECT * FROM {api_keys} WHERE key_hash = ?', [hash('sha256', $key)]);
        if ($row === null || (int) $row['active'] !== 1) {
            return null;
        }
        $scopes = is_string($row['scopes']) && $row['scopes'] !== '' ? json_decode($row['scopes'], true) : null;
        $row['id'] = (int) $row['id'];
        $row['scopes'] = is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];

        return $row;
    }

    /**
     * Apunta que la clave se ha usado (como mucho una vez por minuto: no se escribe en la base en cada petición).
     *
     * @param array<string,mixed> $key
     */
    public static function touch(App $app, array $key): void
    {
        $now = $app->clock()->now();
        $app->db()->run(
            'UPDATE {api_keys} SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)',
            [$app->clock()->sql($now), (int) $key['id'], $app->clock()->sql($now - 60)]
        );
    }
}

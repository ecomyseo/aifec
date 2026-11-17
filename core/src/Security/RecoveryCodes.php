<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Security;

use Aifec\Db\Db;

/**
 * Códigos de recuperación de la verificación en dos pasos: quien pierde el móvil entra con uno de ellos.
 *
 *   - Cada persona tiene diez, con el formato legible «abcd-2345» (ocho caracteres sin los que se confunden: 0/o, 1/l/i).
 *   - Solo se guarda su resumen: un HMAC con la clave del sitio y el número de la persona. El código en claro existe únicamente
 *     en la pantalla que lo enseña, una vez, y en la mano de quien lo apunta.
 *   - Cada código vale una sola vez. Gastarlo es una actualización condicional («used_at IS NULL»): si dos peticiones llegan
 *     a la vez con el mismo código, solo una lo consigue.
 *   - Generar códigos nuevos borra los anteriores.
 *
 * Ni el código ni su resumen se escriben en ningún registro: esta clase no sabe que existe un registro.
 */
final class RecoveryCodes
{
    /** Cuántos se generan cada vez. */
    public const COUNT = 10;

    /** Caracteres de cada código (sin el guion). */
    public const LENGTH = 8;

    /** Alfabeto sin 0, o, 1, l ni i, para que se pueda copiar a mano sin dudas. */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** @var Db */
    private $db;

    /** @var string */
    private $secret;

    public function __construct(Db $db, string $secret)
    {
        $this->db = $db;
        $this->secret = $secret;
    }

    /**
     * Genera una tanda nueva: borra los códigos de la persona y guarda el resumen de los nuevos.
     *
     * @return string[] los códigos en claro («abcd-2345»); es la única vez que existen
     */
    public function issue(int $userId, string $nowSql): array
    {
        $codes = [];
        while (count($codes) < self::COUNT) {
            $code = Random::pick(self::ALPHABET, self::LENGTH);
            if (!in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
        $this->db->transaction(function () use ($userId, $codes, $nowSql): void {
            $this->db->delete('recovery_codes', ['user_id' => $userId]);
            foreach ($codes as $code) {
                $this->db->insert('recovery_codes', ['user_id' => $userId, 'hash' => $this->digest($userId, $code), 'created_at' => $nowSql]);
            }
        });

        return array_map(static function (string $code): string {
            return self::format($code);
        }, $codes);
    }

    /**
     * Gasta un código. Devuelve true solo si era de esa persona, bien escrito y no usado.
     * Acepta mayúsculas, espacios y la falta del guion.
     */
    public function consume(int $userId, string $input, string $nowSql): bool
    {
        $code = self::normalize($input);
        if ($code === '') {
            return false;
        }

        return $this->db->run(
            'UPDATE {recovery_codes} SET used_at = ? WHERE user_id = ? AND hash = ? AND used_at IS NULL',
            [$nowSql, $userId, $this->digest($userId, $code)]
        ) === 1;
    }

    /** Cuántos códigos sin gastar le quedan. */
    public function remaining(int $userId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM {recovery_codes} WHERE user_id = ? AND used_at IS NULL', [$userId]);
    }

    /** Borra todos (al apagar la verificación en dos pasos o al quitársela a una persona). Devuelve cuántos había. */
    public function clear(int $userId): int
    {
        return $this->db->delete('recovery_codes', ['user_id' => $userId]);
    }

    /** Lo escrito por una persona → ocho caracteres en minúscula, o '' si no tiene la forma de un código. */
    public static function normalize(string $input): string
    {
        $clean = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $input));

        return strlen($clean) === self::LENGTH && strspn($clean, self::ALPHABET) === self::LENGTH ? $clean : '';
    }

    /** «abcd2345» → «abcd-2345». */
    public static function format(string $code): string
    {
        return substr($code, 0, 4) . '-' . substr($code, 4);
    }

    private function digest(int $userId, string $code): string
    {
        return hash_hmac('sha256', $code, $this->secret . '|recovery|' . $userId);
    }
}

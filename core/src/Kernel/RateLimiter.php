<?php
/**
 * AIFEC — límite de uso por ventana fija: «como mucho N veces cada S segundos» por clave (IP, cuenta, clave de API).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Kernel;

use Aifec\Db\Db;

final class RateLimiter
{
    /** @var Db */
    private $db;

    /** @var Clock */
    private $clock;

    public function __construct(Db $db, Clock $clock)
    {
        $this->db = $db;
        $this->clock = $clock;
    }

    /** Apunta un uso. Devuelve false si con este ya se pasa del tope (y no lo apunta). */
    public function hit(string $key, int $max, int $windowSeconds): bool
    {
        $k = self::key($key);
        $now = $this->clock->now();
        $row = $this->db->row('SELECT hits, window_start FROM {rate_limits} WHERE k = ?', [$k]);
        if ($row === null || (int) $row['window_start'] + $windowSeconds <= $now) {
            $this->db->upsert('rate_limits', ['k' => $k, 'hits' => 1, 'window_start' => $now], ['k']);

            return $max >= 1;
        }
        if ((int) $row['hits'] >= $max) {
            return false;
        }
        $this->db->run('UPDATE {rate_limits} SET hits = hits + 1 WHERE k = ?', [$k]);

        return true;
    }

    /** Usos que quedan en la ventana actual, sin apuntar ninguno. */
    public function remaining(string $key, int $max, int $windowSeconds): int
    {
        $row = $this->db->row('SELECT hits, window_start FROM {rate_limits} WHERE k = ?', [self::key($key)]);
        if ($row === null || (int) $row['window_start'] + $windowSeconds <= $this->clock->now()) {
            return $max;
        }

        return max(0, $max - (int) $row['hits']);
    }

    public function clear(string $key): void
    {
        $this->db->delete('rate_limits', ['k' => self::key($key)]);
    }

    /** Borra los contadores caducados hace más de un día. Lo llama la tarea de limpieza. */
    public function purge(): int
    {
        return $this->db->run('DELETE FROM {rate_limits} WHERE window_start < ?', [$this->clock->now() - 86400]);
    }

    private static function key(string $key): string
    {
        return strlen($key) <= 100 ? $key : substr($key, 0, 60) . ':' . md5($key);
    }
}

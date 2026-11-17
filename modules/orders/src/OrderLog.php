<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders;

use Aifec\Kernel\App;

/**
 * El historial de un pedido (tabla order_history): todo lo que le pasa, en orden. Las apuntaciones de tipo «system» con
 * valor «stock_held» o «stock_released» llevan la cuenta de si el pedido tiene las existencias descontadas.
 */
final class OrderLog
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Apunta una línea. $kind: status, payment, shipment, refund, note, mail, system. $public = el cliente la ve.
     *
     * @param array<string,mixed> $data datos sueltos (se guardan como JSON)
     */
    public function add(int $orderId, string $kind, ?string $from, ?string $to, string $note = '', bool $public = false, ?int $userId = null, array $data = []): int
    {
        return $this->app->db()->insert('order_history', [
            'order_id' => $orderId,
            'kind' => $kind,
            'from_value' => $from !== null ? mb_substr($from, 0, 40, 'UTF-8') : null,
            'to_value' => $to !== null ? mb_substr($to, 0, 40, 'UTF-8') : null,
            'note' => $note !== '' ? $note : null,
            'is_public' => $public ? 1 : 0,
            'user_id' => $userId,
            'actor' => $userId !== null ? 'user' : 'system',
            'data' => self::json($data),
            'created_at' => $this->app->clock()->sql(),
        ]);
    }

    /**
     * JSON para una columna de texto. Una lista vacía o un valor nulo se guardan como NULL.
     *
     * @param mixed $value
     */
    public static function json($value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }
        $out = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($out) ? $out : null;
    }

    /**
     * Lo contrario: un array siempre (vacío si no hay nada o no es JSON).
     *
     * @param mixed $value
     *
     * @return array<int|string,mixed>
     */
    public static function decode($value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $data = json_decode($value, true);

        return is_array($data) ? $data : [];
    }
}

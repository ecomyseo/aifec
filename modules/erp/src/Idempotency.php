<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp;

use Aifec\Kernel\App;
use Aifec\Kernel\Response;

/**
 * Idempotency-Key: una escritura repetida con la misma clave no se hace dos veces y devuelve la misma respuesta.
 *
 * begin() reserva la clave ANTES de hacer nada (una fila con estado 0 = en curso; la base impide dos reservas iguales), finish()
 * guarda la respuesta y abandon() libera la clave si hubo un error del servidor (para que se pueda reintentar). La misma clave con
 * otra petición distinta es un error: casi seguro es un fallo del programa que llama.
 */
final class Idempotency
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @return Response|null la respuesta guardada si es una repetición, o null si hay que hacer el trabajo
     *
     * @throws ApiException clave reutilizada con otra petición, o petición idéntica todavía en curso
     */
    public function begin(int $connectionId, string $key, string $fingerprint): ?Response
    {
        $db = $this->app->db();
        $row = $db->row('SELECT * FROM {erp_idempotency} WHERE connection_id = ? AND idem_key = ?', [$connectionId, $key]);
        $ttl = max(1, $this->app->settings()->int('erp.idempotency_hours', 24)) * 3600;
        if ($row !== null && \Aifec\Kernel\Clock::parse((string) $row['created_at']) + $ttl <= $this->app->clock()->now()) {
            $db->delete('erp_idempotency', ['id' => (int) $row['id']]);
            $row = null;
        }
        if ($row !== null) {
            if (!hash_equals((string) $row['fingerprint'], $fingerprint)) {
                throw new ApiException(422, 'idempotency_key_reuse', 'This Idempotency-Key was already used with a different request', 'Idempotency-Key');
            }
            if ((int) $row['status_code'] === 0) {
                throw new ApiException(409, 'idempotency_in_progress', 'A request with this Idempotency-Key is still being processed');
            }
            $response = new Response((string) $row['body'], (int) $row['status_code'], [
                'Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ]);
            $response->headers['Idempotency-Replayed'] = 'true';

            return $response;
        }
        $reserved = $db->insertIgnore('erp_idempotency', [
            'connection_id' => $connectionId, 'idem_key' => $key, 'fingerprint' => $fingerprint, 'status_code' => 0,
            'created_at' => $this->app->clock()->sql(),
        ]);
        if (!$reserved) {
            throw new ApiException(409, 'idempotency_in_progress', 'A request with this Idempotency-Key is still being processed');
        }

        return null;
    }

    public function finish(int $connectionId, string $key, Response $response): void
    {
        $this->app->db()->update('erp_idempotency', ['status_code' => $response->status, 'body' => $response->body], ['connection_id' => $connectionId, 'idem_key' => $key]);
    }

    public function abandon(int $connectionId, string $key): void
    {
        $this->app->db()->delete('erp_idempotency', ['connection_id' => $connectionId, 'idem_key' => $key]);
    }

    /** Borra las claves caducadas. Lo llama la tarea diaria. */
    public function purge(): int
    {
        $ttl = max(1, $this->app->settings()->int('erp.idempotency_hours', 24)) * 3600;

        return $this->app->db()->run('DELETE FROM {erp_idempotency} WHERE created_at < ?', [$this->app->clock()->sql($this->app->clock()->now() - $ttl)]);
    }
}

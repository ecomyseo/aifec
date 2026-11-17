<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Reviews;

use Aifec\Kernel\App;

/**
 * Datos personales de las valoraciones: lo que se borra cuando un cliente pide que se le olvide y lo que entra en la copia de sus datos.
 *
 * Las opiniones publicadas se quedan (la media del producto no cambia) pero sin nombre ni correo; las peticiones de opinión
 * pendientes se borran.
 */
final class ReviewsPrivacy
{
    /** Dominio reservado que no existe ni existirá: un correo así no llega a nadie. */
    private const NOBODY = '@anonymous.invalid';

    /**
     * Acción «customer.anonymised» (id del cliente y el correo que tenía). Devuelve cuántas opiniones se han dejado anónimas.
     */
    public static function forget(App $app, int $customerId, string $email): int
    {
        $db = $app->db();
        $email = strtolower(trim($email));
        $parts = [];
        $params = [];
        if ($customerId > 0) {
            $parts[] = 'customer_id = ?';
            $params[] = $customerId;
        }
        if ($email !== '') {
            $parts[] = 'email = ?';
            $params[] = $email;
        }
        if ($parts === []) {
            return 0;
        }
        $done = 0;
        foreach ($db->select('SELECT id FROM {reviews} WHERE ' . implode(' OR ', $parts), $params) as $row) {
            $id = (int) $row['id'];
            $db->update('reviews', ['name' => 'Anonymous', 'email' => 'deleted-' . $id . self::NOBODY, 'customer_id' => null, 'order_id' => null], ['id' => $id]);
            ++$done;
        }
        if ($email !== '') {
            $db->run('DELETE FROM {review_requests} WHERE email = ?', [$email]);
        }

        return $done;
    }

    /**
     * Filtro «customer.export» (datos, id del cliente): añade sus opiniones a la copia de sus datos.
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public static function export(App $app, array $data, int $customerId): array
    {
        if ($customerId < 1) {
            return $data;
        }
        $reviews = [];
        foreach ($app->db()->select('SELECT product_id, name, email, rating, title, body, status, verified, created_at FROM {reviews} WHERE customer_id = ? ORDER BY id', [$customerId]) as $row) {
            $reviews[] = [
                'product_id' => (int) $row['product_id'], 'name' => (string) $row['name'], 'email' => (string) $row['email'], 'rating' => (int) $row['rating'],
                'title' => (string) $row['title'], 'body' => (string) $row['body'], 'status' => (string) $row['status'],
                'verified' => !empty($row['verified']), 'created_at' => (string) $row['created_at'],
            ];
        }
        $data['reviews'] = $reviews;

        return $data;
    }
}

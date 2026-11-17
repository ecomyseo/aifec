<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Returns;

use Aifec\Kernel\App;

/**
 * Los datos personales de las devoluciones: lo que se borra cuando un cliente pide que se le olvide (el comentario, las fotos y el
 * correo) y lo que se entrega en la copia de sus datos. El historial y los importes se conservan: son contabilidad, no datos de la persona.
 */
final class ReturnsPrivacy
{
    private const NOBODY = '@anonymous.invalid';

    /**
     * Acción «customer.anonymised» (id del cliente y el correo que tenía).
     *
     * @return array{returns:int,photos:int}
     */
    public static function forget(App $app, int $customerId, string $email): array
    {
        $out = ['returns' => 0, 'photos' => 0];
        $email = trim($email);
        $where = [];
        $params = [];
        if ($customerId > 0) {
            $where[] = 'customer_id = ?';
            $params[] = $customerId;
        }
        if ($email !== '') {
            $where[] = 'email = ?';
            $params[] = $email;
        }
        if ($where === []) {
            return $out;
        }
        $db = $app->db();
        $photos = new ReturnPhotos($app);
        foreach ($db->select('SELECT id, photos FROM {returns} WHERE ' . implode(' OR ', $where), $params) as $row) {
            $list = is_string($row['photos']) && $row['photos'] !== '' ? json_decode($row['photos'], true) : [];
            $list = is_array($list) ? $list : [];
            $photos->delete($list);
            $out['photos'] += count($list);
            $db->update('returns', ['email' => 'deleted-' . (int) $row['id'] . self::NOBODY, 'comment' => null, 'photos' => null], ['id' => (int) $row['id']]);
            ++$out['returns'];
        }

        return $out;
    }

    /**
     * Filtro «customer.export» (datos, id del cliente): añade sus devoluciones a la copia de sus datos.
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
        $returns = [];
        $db = $app->db();
        foreach ($db->select('SELECT * FROM {returns} WHERE customer_id = ? ORDER BY id', [$customerId]) as $row) {
            $items = [];
            foreach ($db->select('SELECT name, variant_label, sku, qty FROM {return_items} WHERE return_id = ? ORDER BY id', [(int) $row['id']]) as $item) {
                $items[] = ['name' => (string) $item['name'], 'variant' => (string) $item['variant_label'], 'sku' => (string) $item['sku'], 'qty' => (int) $item['qty']];
            }
            $photoList = is_string($row['photos']) && $row['photos'] !== '' ? json_decode($row['photos'], true) : null;
            $returns[] = [
                'number' => (string) $row['number'], 'kind' => (string) $row['kind'], 'status' => (string) $row['status'], 'reason' => (string) $row['reason'],
                'comment' => (string) $row['comment'], 'requested_at' => (string) $row['requested_at'], 'refunded_amount' => (int) $row['refunded_amount'],
                'photos' => is_array($photoList) ? count($photoList) : 0, 'items' => $items,
            ];
        }
        $data['returns'] = $returns;

        return $data;
    }
}

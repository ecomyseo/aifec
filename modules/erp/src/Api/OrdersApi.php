<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp\Api;

use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Erp\ApiException;
use Aifec\Modules\Erp\Present;
use Aifec\Modules\Orders\OrderLog;
use Aifec\Modules\Orders\Orders;

/**
 * Pedidos para el ERP: lista por cursor, ficha, confirmación de recibo, cambio de estado y seguimiento.
 */
final class OrdersApi extends ApiBase
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $limit = $this->limit($request);
        $after = $this->cursor($request);
        $db = $this->app->db();
        $sql = 'SELECT id FROM {orders} WHERE id > ?';
        $args = [$after];
        $status = $request->get('status');
        if (is_string($status) && $status !== '') {
            $sql .= ' AND status IN (' . $db->in($this->textList($status), $args) . ')';
        }
        $since = $request->get('updated_since');
        if (is_string($since) && $since !== '') {
            $when = Present::toSql($since);
            if ($when === null) {
                throw new ApiException(400, 'invalid_date', 'updated_since must be an ISO 8601 date', 'updated_since');
            }
            $sql .= ' AND updated_at >= ?';
            $args[] = $when;
        }
        $args[] = $limit + 1;
        $ids = array_map('intval', $db->column($sql . ' ORDER BY id ASC LIMIT ?', $args));
        $connectionId = (int) $this->connection($request)['id'];

        return $this->page($request, $ids, $limit, $after, function (int $id) use ($connectionId): ?array {
            $order = $this->orders()->find($id);

            return $order === null ? null : Present::order($this->app, $order, $connectionId);
        });
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $order = $this->orders()->findByNumber((string) $params['number']);
        if ($order === null) {
            throw ApiException::notFound('order_not_found', 'There is no order with that number');
        }

        return $this->ok(Present::order($this->app, $order, (int) $this->connection($request)['id']));
    }

    /** @param array<string,string> $params */
    public function ack(Request $request, array $params): Response
    {
        $order = $this->find((string) $params['number']);
        $external = $this->requireString($this->body($request)['external_id'] ?? null, 'external_id', 120);
        $connection = $this->connection($request);
        $db = $this->app->db();
        $now = $this->app->clock()->sql();
        $current = $db->row('SELECT external_id FROM {erp_acks} WHERE order_id = ? AND connection_id = ?', [(int) $order['id'], (int) $connection['id']]);
        $changed = $current === null || (string) $current['external_id'] !== $external;
        if ($changed) {
            $db->upsert('erp_acks', ['order_id' => (int) $order['id'], 'connection_id' => (int) $connection['id'], 'external_id' => $external, 'acked_at' => $now], ['order_id', 'connection_id']);
            $this->orders()->addNote((int) $order['id'], t('ERP «{name}» has received the order. Its identifier there: {id}', ['name' => (string) $connection['name'], 'id' => $external]), false);
            $this->activity($request, 'erp.order.ack', 'order', (string) $order['id'], (string) $order['number'] . ' = ' . $external);
        }

        return $this->ok(['number' => (string) $order['number'], 'external_id' => $external, 'changed' => $changed]);
    }

    /** @param array<string,string> $params */
    public function status(Request $request, array $params): Response
    {
        $order = $this->find((string) $params['number']);
        $body = $this->body($request);
        $target = $this->requireString($body['status'] ?? null, 'status', 32);
        if ($this->app->db()->row('SELECT id FROM {order_states} WHERE code = ?', [$target]) === null) {
            throw new ApiException(422, 'unknown_status', 'There is no order status with the code ' . $target, 'status');
        }
        $note = isset($body['note']) && is_string($body['note']) ? mb_substr(trim($body['note']), 0, 1000, 'UTF-8') : '';
        $notify = !empty($body['notify']) && $body['notify'] !== 'false' && $body['notify'] !== '0';
        $from = (string) $order['status'];
        $changed = false;
        if ($from !== $target) {
            if (!$this->orders()->setStatus((int) $order['id'], $target, $note, null, $notify)) {
                throw new ApiException(422, 'transition_not_allowed', 'The order cannot go from ' . $from . ' to ' . $target, 'status');
            }
            $changed = true;
            $this->activity($request, 'erp.order.status', 'order', (string) $order['id'], (string) $order['number'], ['status' => [$from, $target]]);
        }
        $fresh = $this->orders()->find((int) $order['id']);

        return $this->ok(['changed' => $changed, 'order' => Present::order($this->app, $fresh !== null ? $fresh : $order, (int) $this->connection($request)['id'])]);
    }

    /** @param array<string,string> $params */
    public function tracking(Request $request, array $params): Response
    {
        $order = $this->find((string) $params['number']);
        $body = $this->body($request);
        $number = $this->requireString($body['tracking_number'] ?? null, 'tracking_number', 80);
        $carrier = isset($body['carrier']) && is_string($body['carrier']) ? mb_substr(trim($body['carrier']), 0, 120, 'UTF-8') : '';
        $url = isset($body['url']) && is_string($body['url']) ? trim($body['url']) : '';
        if ($url !== '' && (strlen($url) > 255 || filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $url) !== 1)) {
            throw ApiException::invalid('The url must be a link that starts with http:// or https://', 'url');
        }
        $set = ['tracking_code' => $number, 'updated_at' => $this->app->clock()->sql()];
        if ($carrier !== '') {
            $set['carrier_title'] = $carrier;
        }
        $this->app->db()->update('orders', $set, ['id' => (int) $order['id']]);
        $old = (string) ($order['tracking_code'] ?? '');
        (new OrderLog($this->app))->add((int) $order['id'], 'shipment', $old !== '' ? $old : null, $number, t('Tracking code: {code}', ['code' => $number]), true, null, array_filter(['carrier' => $carrier, 'url' => $url, 'source' => 'erp']));
        $this->activity($request, 'erp.order.tracking', 'order', (string) $order['id'], (string) $order['number'], ['tracking_code' => [$old, $number]]);
        $fresh = $this->orders()->find((int) $order['id']);

        return $this->ok(Present::order($this->app, $fresh !== null ? $fresh : $order, (int) $this->connection($request)['id']));
    }

    private function orders(): Orders
    {
        $orders = $this->app->service('orders');
        if (!$orders instanceof Orders) {
            throw new \LogicException('The orders service is not available');
        }

        return $orders;
    }

    /**
     * @return array<string,mixed>
     */
    private function find(string $number): array
    {
        $order = $this->orders()->findByNumber($number);
        if ($order === null) {
            throw ApiException::notFound('order_not_found', 'There is no order with that number');
        }

        return $order;
    }
}

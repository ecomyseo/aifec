<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Customers\Front;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Kernel\HttpError;

/**
 * Los pedidos de quien ha entrado, para la zona de cliente.
 *
 * Solo salen los del cliente identificado. Si su correo no está verificado, tampoco los anteriores al registro (los
 * hechos como invitado con ese mismo correo): quien se registra con un correo ajeno no puede leer sus compras. El
 * registro se fecha con la primera ficha de verificación que se emitió para la cuenta; sin ficha (una cuenta migrada
 * sin verificar) no se enseña ninguno hasta que confirme el correo.
 *
 * El listado lee solo los identificadores de la tabla de pedidos (el servicio «orders» no tiene un listado por
 * cliente); el resto de datos sale de Orders::find().
 */
final class OrderList
{
    /** Fecha de relleno que ningún pedido alcanza: «no se ve ninguno». */
    private const NOTHING = '9999-12-31 23:59:59';

    /** @var App */
    private $app;

    /** @var Account */
    private $account;

    public function __construct(App $app, Account $account)
    {
        $this->app = $app;
        $this->account = $account;
    }

    /**
     * El servicio de pedidos.
     *
     * @return object
     *
     * @throws HttpError 404 si el sitio no tiene módulo de pedidos
     */
    public function orders()
    {
        if (!$this->app->has('orders')) {
            throw HttpError::notFound();
        }

        return $this->app->service('orders');
    }

    /**
     * Desde cuándo puede ver pedidos ese cliente: null si los ve todos, o la fecha de su registro si aún no ha
     * verificado el correo.
     *
     * @param array<string,mixed> $me
     */
    public function since(array $me): ?string
    {
        if (!empty($me['verified'])) {
            return null;
        }
        $first = $this->app->db()->value("SELECT MIN(created_at) FROM {tokens} WHERE kind = 'customer_verify' AND data = ?", [(string) (int) $me['id']]);

        return is_string($first) && $first !== '' ? $first : self::NOTHING;
    }

    /**
     * Cuántos pedidos suyos quedan ocultos hasta que verifique el correo.
     *
     * @param array<string,mixed> $me
     */
    public function hidden(array $me): int
    {
        $since = $this->since($me);
        if ($since === null || !$this->app->has('orders')) {
            return 0;
        }

        return (int) $this->app->db()->value('SELECT COUNT(*) FROM {orders} WHERE customer_id = ? AND placed_at < ?', [(int) $me['id'], $since]);
    }

    /**
     * @param array<string,mixed> $me
     *
     * @return array{0:string,1:array<int,mixed>} condición SQL y sus valores
     */
    private function where(array $me, bool $paidOnly): array
    {
        $sql = 'customer_id = ?';
        $args = [(int) $me['id']];
        $since = $this->since($me);
        if ($since !== null) {
            $sql .= ' AND placed_at >= ?';
            $args[] = $since;
        }
        if ($paidOnly) {
            $sql .= " AND payment_status IN ('paid', 'partially_refunded')";
        }

        return [$sql, $args];
    }

    /**
     * @param array<string,mixed> $me
     */
    public function count(array $me): int
    {
        list($sql, $args) = $this->where($me, false);

        return (int) $this->app->db()->value('SELECT COUNT(*) FROM {orders} WHERE ' . $sql, $args);
    }

    /**
     * Una página de pedidos, los más recientes primero, ya preparados para enseñar.
     *
     * @param array<string,mixed> $me
     *
     * @return array<int,array<string,mixed>> number, placed, status_name, status_class, total, url
     */
    public function page(array $me, int $page, int $perPage): array
    {
        list($sql, $args) = $this->where($me, false);
        $ids = $this->app->db()->column(
            'SELECT id FROM {orders} WHERE ' . $sql . ' ORDER BY placed_at DESC, id DESC LIMIT ? OFFSET ?',
            array_merge($args, [$perPage, max(0, ($page - 1) * $perPage)])
        );
        $rows = [];
        foreach ($ids as $id) {
            $order = $this->orders()->find((int) $id);
            if (is_array($order)) {
                $rows[] = $this->row($order);
            }
        }

        return $rows;
    }

    /**
     * Los pedidos pagados de ese cliente (los más recientes primero), para las descargas.
     *
     * @param array<string,mixed> $me
     *
     * @return array<int,array<string,mixed>> id, number, placed
     */
    public function paid(array $me, int $limit): array
    {
        list($sql, $args) = $this->where($me, true);
        $rows = $this->app->db()->select(
            'SELECT id, number, placed_at FROM {orders} WHERE ' . $sql . ' ORDER BY placed_at DESC, id DESC LIMIT ?',
            array_merge($args, [$limit])
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row['id'], 'number' => (string) $row['number'], 'placed' => $this->date((string) $row['placed_at'])];
        }

        return $out;
    }

    /**
     * El pedido completo con ese número, solo si es de ese cliente y puede verlo; si no, null (la página es un 404).
     *
     * @param array<string,mixed> $me
     *
     * @return array<string,mixed>|null
     */
    public function find(array $me, string $number): ?array
    {
        $order = $this->orders()->findByNumber($number);
        if (!is_array($order) || (int) $order['customer_id'] !== (int) $me['id']) {
            return null;
        }
        $since = $this->since($me);
        if ($since !== null && (string) $order['placed_at'] < $since) {
            return null;
        }

        return $order;
    }

    /**
     * Las descargas de los pedidos pagados, agrupadas por pedido.
     *
     * @param array<string,mixed> $me
     *
     * @return array<int,array<string,mixed>> number, url, items (lo de downloadsOf())
     */
    public function downloadGroups(array $me): array
    {
        $groups = [];
        foreach ($this->paid($me, 50) as $order) {
            $items = $this->downloadsOf((int) $order['id']);
            if ($items !== []) {
                $groups[] = ['number' => $order['number'], 'placed' => $order['placed'], 'url' => $this->account->link('account.order', ['number' => $order['number']]), 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * Las descargas de un pedido preparadas para enseñar.
     *
     * @return array<int,array<string,mixed>> id, name, item, available, expired, remaining (null = sin tope), expires
     */
    public function downloadsOf(int $orderId): array
    {
        $out = [];
        foreach ($this->orders()->downloads()->forOrder($orderId) as $download) {
            $out[] = [
                'id' => (int) $download['id'],
                'name' => (string) $download['name'],
                'item' => (string) $download['item'],
                'available' => !empty($download['available']),
                'expired' => !empty($download['expired']),
                'remaining' => $download['remaining'] === null ? null : (int) $download['remaining'],
                'expires' => $download['expires_at'] !== null ? $this->date((string) $download['expires_at']) : '',
            ];
        }

        return $out;
    }

    /**
     * Una fila del listado.
     *
     * @param array<string,mixed> $order
     *
     * @return array<string,mixed>
     */
    private function row(array $order): array
    {
        $currency = (string) $order['currency'];

        return [
            'number' => (string) $order['number'],
            'placed' => $this->date((string) $order['placed_at']),
            'status_name' => $this->statusName($order),
            'status_class' => $this->statusClass($order),
            'total' => money((int) $order['total'], $currency),
            'url' => $this->account->link('account.order', ['number' => (string) $order['number']]),
            'return_url' => $this->returnUrl($order),
        ];
    }

    /**
     * El enlace a devolver o desistir de un pedido pagado (cadena vacía si la tienda no tiene devoluciones o el pedido no
     * se ha pagado o ya está cancelado). El módulo de devoluciones decide después qué se puede pedir.
     *
     * @param array<string,mixed> $order
     */
    private function returnUrl(array $order): string
    {
        if (!$this->app->router()->has('returns.account')) {
            return '';
        }
        $paid = in_array((string) $order['payment_status'], ['paid', 'partially_refunded'], true);
        if (!$paid || in_array((string) $order['status'], ['cancelled', 'refunded', 'failed', 'pending_payment'], true)) {
            return '';
        }

        return $this->account->link('returns.account', ['number' => (string) $order['number']]);
    }

    /**
     * El pedido preparado para la página de detalle.
     *
     * @param array<string,mixed> $order
     *
     * @return array<string,mixed>
     */
    public function view(array $order): array
    {
        $currency = (string) $order['currency'];
        $money = static function ($cents) use ($currency): string {
            return money((int) $cents, $currency);
        };
        $lines = [];
        foreach (is_array($order['items']) ? $order['items'] : [] as $item) {
            if ((string) $item['kind'] !== 'product') {
                continue;
            }
            $lines[] = [
                'name' => (string) $item['name'],
                'variant' => (string) ($item['variant_label'] !== null ? $item['variant_label'] : ''),
                'qty' => (int) $item['qty'],
                'total' => $money($item['line_total']),
            ];
        }
        $totals = [[t('Subtotal'), $money($order['items_total']), false]];
        if ((int) $order['discount_total'] > 0) {
            $totals[] = [t('Discount'), '-' . $money($order['discount_total']), false];
        }
        $totals[] = [t('Shipping'), $money($order['shipping_total']), false];
        if ((int) $order['fee_total'] > 0) {
            $totals[] = [t('Fees'), $money($order['fee_total']), false];
        }
        $totals[] = [!empty($order['prices_include_tax']) ? t('Taxes included') : t('Taxes'), $money($order['total_tax']), false];
        $totals[] = [t('Total'), $money($order['total']), true];

        $notes = [];
        foreach (is_array($order['history']) ? $order['history'] : [] as $entry) {
            if (!empty($entry['is_public']) && trim((string) $entry['note']) !== '') {
                $notes[] = ['date' => $this->date((string) $entry['created_at']), 'text' => trim((string) $entry['note'])];
            }
        }
        $paid = in_array((string) $order['payment_status'], ['paid', 'partially_refunded'], true);

        return [
            'number' => (string) $order['number'],
            'placed' => $this->date((string) $order['placed_at']),
            'status_name' => $this->statusName($order),
            'status_class' => $this->statusClass($order),
            'payment_title' => (string) ($order['payment_title'] !== null ? $order['payment_title'] : ''),
            'carrier_title' => (string) ($order['carrier_title'] !== null ? $order['carrier_title'] : ''),
            'tracking_code' => (string) ($order['tracking_code'] !== null ? $order['tracking_code'] : ''),
            'customer_note' => trim((string) ($order['customer_note'] !== null ? $order['customer_note'] : '')),
            'lines' => $lines,
            'totals' => $totals,
            'billing' => $this->account->addressLines(is_array($order['billing']) ? $order['billing'] : []),
            'shipping' => $this->account->addressLines(is_array($order['shipping']) ? $order['shipping'] : []),
            'notes' => $notes,
            'downloads' => $paid ? $this->downloadsOf((int) $order['id']) : [],
            'return_url' => $this->returnUrl($order),
        ];
    }

    /** @param array<string,mixed> $order */
    private function statusName(array $order): string
    {
        $state = isset($order['state']) && is_array($order['state']) ? $order['state'] : null;

        return $state !== null ? t((string) $state['name']) : (string) $order['status'];
    }

    /** @param array<string,mixed> $order */
    private function statusClass(array $order): string
    {
        $state = isset($order['state']) && is_array($order['state']) ? $order['state'] : null;
        $color = $state !== null ? (string) $state['color'] : '';
        if ($color === 'ok') {
            return 'af-badge af-badge-new';
        }

        return $color === 'bad' ? 'af-badge af-badge-out' : 'af-badge';
    }

    private function date(string $sql): string
    {
        return $this->app->i18n()->date(Clock::parse($sql), 'medium');
    }
}

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Admin;

use Aifec\Admin\Admin;
use Aifec\Admin\Table;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Orders\OrderLog;
use Aifec\Modules\Orders\OrderMails;
use Aifec\Modules\Orders\Orders;

/**
 * Pantallas de pedidos del panel: listado, ficha con sus acciones, carritos y exportación.
 *
 * Cada acción de la ficha es un POST pequeño que llama al servicio «orders» y vuelve a la ficha con un aviso. El panel no
 * escribe en las tablas de pedidos por su cuenta; la única excepción es el código de seguimiento, para el que el servicio
 * no tiene método (se guarda y se apunta en el historial antes de marcar el pedido como enviado, porque el correo lo lleva).
 */
final class OrdersController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ listado y carritos

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('orders.view');
        $table = new Table($app, OrdersAdmin::listConfig($app, false));
        $counts = OrdersAdmin::tabCounts($app);
        $filters = $request->get('f', []);
        $filters = is_array($filters) ? $filters : [];
        $clean = $request->str('q') === '' && count($filters) <= 1;
        $tab = static function (string $filter, string $value) use ($filters, $clean): bool {
            return $clean && count($filters) === 1 && isset($filters[$filter]) && (string) $filters[$filter] === $value;
        };
        $base = $app->url()->plain('/admin/orders');
        $tabs = [
            ['label' => t('All'), 'url' => $base, 'count' => $counts['all'], 'kind' => '', 'active' => $clean && $filters === []],
            ['label' => t('To prepare'), 'url' => $app->url()->plain('/admin/orders', ['f' => ['prepare' => '1']]), 'count' => $counts['prepare'], 'kind' => 'warn', 'active' => $tab('prepare', '1')],
            ['label' => t('Pending payment'), 'url' => $app->url()->plain('/admin/orders', ['f' => ['status' => 'pending_payment']]), 'count' => $counts['pending'], 'kind' => '', 'active' => $tab('status', 'pending_payment')],
            ['label' => t('On hold'), 'url' => $app->url()->plain('/admin/orders', ['f' => ['status' => 'on_hold']]), 'count' => $counts['hold'], 'kind' => 'info', 'active' => $tab('status', 'on_hold')],
        ];
        // La lista de preparación, junto a los pedidos (y no en el menú, que ya tiene su forma acordada).
        $actions = $counts['prepare'] > 0 ? '<a class="af-btn" href="' . e($app->url()->plain('/admin/orders/picking')) . '">' . e(t('Picking list')) . '</a>' : '';
        if ($app->auth()->can('orders.export')) {
            $actions .= '<a class="af-btn" href="' . e($app->url()->plain('/admin/orders/export.csv', $request->query)) . '">' . Admin::icon('download') . e(t('Export CSV')) . '</a>';
        }
        if ($app->auth()->can(ManualOrderController::CAP)) {
            $actions .= '<a class="af-btn af-btn-primary" href="' . e($app->url()->plain('/admin/orders/new')) . '">' . Admin::icon('plus') . e(t('New order')) . '</a>';
        }

        return Admin::page($app, 'orders::admin/list', ['tabs' => $tabs, 'table' => $table->render($request)], [
            'title' => t('Orders'),
            'actions' => $actions,
            'active' => '/admin/orders',
        ]);
    }

    /** Los pedidos que se ven (con los mismos filtros) en CSV. Quien exporta queda apuntado en la auditoría. */
    public function export(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('orders.export');
        $table = new Table($app, OrdersAdmin::listConfig($app, true));
        $info = $table->query($request);
        Admin::audit($app, 'export', 'orders', '', $info['total'] . ' rows');

        return $table->csv($request, 'orders-' . gmdate('Ymd', $app->clock()->now()) . '.csv');
    }

    /** @param array<string,string> $params */
    public function carts(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('orders.view');
        $table = new Table($app, OrdersAdmin::cartsConfig($app));

        return Admin::page($app, 'orders::admin/carts', ['table' => $table->render($request)], [
            'title' => t('Carts'),
            'crumbs' => [[t('Orders'), $app->url()->plain('/admin/orders')]],
            'active' => '/admin/orders/carts',
            'help' => Admin::helpText("Carts that have products and were not turned into an order, from the last 30 days.\nThis list is only for reading: nothing is sent to anyone from here."),
        ]);
    }

    /**
     * GET /admin/orders/picking: lo que hay que coger del almacén para los pedidos por preparar, sumado por producto y variante, con
     * los pedidos de cada uno (14/11/2026, el mozo de almacén: «hay que abrir cada pedido para ver sus líneas»).
     *
     * @param array<string,string> $params
     */
    public function picking(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('orders.view');
        $rows = $app->db()->select(
            'SELECT i.product_id, i.variant_id, i.sku, i.name, i.variant_label, i.qty, o.id AS order_id, o.number FROM {order_items} i '
            . "JOIN {orders} o ON o.id = i.order_id WHERE i.kind = 'product' AND " . OrdersAdmin::TO_PREPARE . ' ORDER BY i.name, i.variant_label, o.id'
        );
        $lines = [];
        $orders = [];
        foreach ($rows as $row) {
            $key = (int) $row['product_id'] . ':' . (int) $row['variant_id'] . ':' . (string) $row['sku'] . ':' . (string) $row['name'];
            if (!isset($lines[$key])) {
                $lines[$key] = ['name' => (string) $row['name'], 'variant' => (string) $row['variant_label'], 'sku' => (string) $row['sku'], 'qty' => 0, 'orders' => []];
            }
            $lines[$key]['qty'] += (int) $row['qty'];
            $lines[$key]['orders'][(int) $row['order_id']] = (string) $row['number'];
            $orders[(int) $row['order_id']] = true;
        }

        return Admin::page($app, 'orders::admin/picking', ['lines' => array_values($lines), 'orders' => count($orders)], [
            'title' => t('Picking list'),
            'crumbs' => [[t('Orders'), $app->url()->plain('/admin/orders')]],
            'active' => '/admin/orders',
            'help' => Admin::helpText("What to take from the warehouse for the orders to prepare (paid and not sent yet), added up by product.\nOpen an order from its number to mark it as sent with its tracking code."),
        ]);
    }

    // ------------------------------------------------------------------ ficha

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('orders.view');

        return $this->screen($this->order((int) $params['id']));
    }

    /**
     * @param array<string,mixed> $order
     */
    private function screen(array $order): Response
    {
        $app = $this->app;
        $auth = $app->auth();
        $db = $app->db();
        $id = (int) $order['id'];
        $status = (string) $order['status'];

        // Estados: nombres para el historial y los que se pueden elegir en el desplegable.
        $names = [];
        $choices = [];
        foreach ($db->select('SELECT code, name, color FROM {order_states} ORDER BY position, id') as $row) {
            $names[(string) $row['code']] = (string) $row['name'];
            if (in_array((string) $row['code'], ['pending_payment', 'cancelled', 'refunded', 'failed', $status], true)) {
                continue;
            }
            if ((string) $row['code'] === 'shipped' && (string) $order['fulfillment_status'] === 'not_needed') {
                continue;
            }
            $choices[] = ['code' => (string) $row['code'], 'name' => (string) $row['name']];
        }
        $closed = in_array($status, ['cancelled', 'refunded'], true);

        // Quién hizo cada cosa del historial.
        $userIds = [];
        foreach ($order['history'] as $entry) {
            if ($entry['user_id'] !== null) {
                $userIds[(int) $entry['user_id']] = true;
            }
        }
        $users = [];
        if ($userIds !== []) {
            $params = [];
            foreach ($db->select('SELECT id, name FROM {users} WHERE id IN (' . $db->in(array_keys($userIds), $params) . ')', $params) as $row) {
                $users[(int) $row['id']] = (string) $row['name'];
            }
        }

        // Cada devolución con su motivo, para enseñarla junto al movimiento de dinero.
        $refundsByPayment = [];
        foreach ($order['refunds'] as $refund) {
            if ($refund['payment_id'] !== null) {
                $refundsByPayment[(int) $refund['payment_id']] = $refund;
            }
        }

        $method = (string) $order['payment_method'];
        $gateway = $app->service('payments')->get($method);
        $paid = in_array((string) $order['payment_status'], ['paid', 'partially_refunded'], true);
        $refundable = max(0, (int) $order['paid_total'] - (int) $order['refunded_total']);
        $thanks = (new OrderMails($app))->orderUrl($order);

        $data = [
            'order' => $order,
            'lines' => $this->lines($order),
            'extras' => $this->extras($order),
            'refundsByPayment' => $refundsByPayment,
            'names' => $names,
            'choices' => $choices,
            'users' => $users,
            'canEdit' => $auth->can('orders.edit'),
            'canNote' => $auth->can('orders.edit') || $auth->can('orders.notes'),
            'canRefund' => $auth->can('orders.refund'),
            'closed' => $closed,
            'canMarkPaid' => !$closed && $status !== 'failed' && !$paid && (string) $order['payment_status'] !== 'refunded' && OrdersAdmin::isManualMethod($method),
            'canCancel' => !$closed && !$paid && $status !== 'failed',
            'canRefundNow' => $paid && $refundable > 0,
            'refundable' => $refundable,
            'gatewayRefund' => $gateway !== null && $gateway->supportsRefund(),
            'customer' => $this->customerBox($order),
            'thanksUrl' => $thanks,
            'urls' => [
                'status' => $app->url()->plain('/admin/orders/' . $id . '/status'),
                'note' => $app->url()->plain('/admin/orders/' . $id . '/note'),
                'tracking' => $app->url()->plain('/admin/orders/' . $id . '/tracking'),
                'paid' => $app->url()->plain('/admin/orders/' . $id . '/paid'),
                'cancel' => $app->url()->plain('/admin/orders/' . $id . '/cancel'),
                'refund' => $app->url()->plain('/admin/orders/' . $id . '/refund'),
                'resend' => $app->url()->plain('/admin/orders/' . $id . '/resend'),
            ],
            'stateMail' => (string) $db->value('SELECT mail FROM {order_states} WHERE code = ?', [$status]),
        ];

        $actions = '<button type="button" class="af-btn" data-af-print>' . Admin::icon('receipt') . e(t('Print')) . '</button>';
        if ($auth->can(ManualOrderController::CAP)) {
            // «Repetir»: abre «Pedido nuevo» con el mismo cliente, las líneas y la dirección (ManualOrderController::repeat()).
            $actions .= '<form method="post" action="' . e($app->url()->plain('/admin/orders/' . $id . '/repeat')) . '" class="af-inline">' . Admin::tokenField($app)
                . '<button type="submit" class="af-btn">' . Admin::icon('copy') . e(t('Repeat')) . '</button></form>';
        }
        if ($thanks !== '') {
            $actions = '<a class="af-btn" href="' . e($thanks) . '" target="_blank" rel="noopener">' . Admin::icon('external') . e(t('View as the customer')) . '</a>' . $actions;
        }

        return Admin::page($app, 'orders::admin/order', $data, [
            'title' => t('Order {number}', ['number' => (string) $order['number']]),
            'crumbs' => [[t('Orders'), $app->url()->plain('/admin/orders')]],
            'actions' => $actions,
            'active' => '/admin/orders',
        ]);
    }

    /**
     * Las líneas de producto con su imagen (de la tarjeta del catálogo, si el producto sigue existiendo).
     *
     * @param array<string,mixed> $order
     *
     * @return array<int,array<string,mixed>>
     */
    private function lines(array $order): array
    {
        $ids = [];
        foreach ($order['items'] as $item) {
            if ((string) $item['kind'] === 'product' && $item['product_id'] !== null) {
                $ids[(int) $item['product_id']] = true;
            }
        }
        $cards = $ids !== [] && $this->app->has('catalog') ? $this->app->service('catalog')->cards(array_keys($ids)) : [];
        $lines = [];
        foreach ($order['items'] as $item) {
            if ((string) $item['kind'] !== 'product') {
                continue;
            }
            $card = $item['product_id'] !== null && isset($cards[(int) $item['product_id']]) ? $cards[(int) $item['product_id']] : null;
            $image = is_array($card) && isset($card['image']) && is_array($card['image']) ? $card['image'] : null;
            $item['image'] = $image !== null ? (string) (isset($image['thumb']) && $image['thumb'] !== '' ? $image['thumb'] : (isset($image['url']) ? $image['url'] : '')) : '';
            $lines[] = $item;
        }

        return $lines;
    }

    /**
     * Lo que está entre las líneas y el total: descuentos (con su código), envío, recargos y ajustes (con su origen).
     *
     * @param array<string,mixed> $order
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function extras(array $order): array
    {
        $out = ['discounts' => [], 'shipping' => [], 'fees' => []];
        foreach ($order['items'] as $item) {
            $kind = (string) $item['kind'];
            $data = is_array($item['data']) ? $item['data'] : [];
            if ($kind === 'discount' && !empty($data['informational'])) {
                $out['discounts'][] = ['name' => (string) $item['name'], 'code' => isset($data['code']) ? (string) $data['code'] : '', 'amount' => -(int) $item['line_total']];
            } elseif ($kind === 'shipping') {
                $out['shipping'][] = ['name' => (string) $item['name'], 'amount' => (int) $item['line_total']];
            } elseif ($kind === 'fee' || $kind === 'discount') {
                $out['fees'][] = ['name' => (string) $item['name'], 'source' => !empty($data['adjustment']) && isset($data['source']) ? (string) $data['source'] : '', 'amount' => (int) $item['line_total']];
            }
        }

        return $out;
    }

    /**
     * El cliente con sus pedidos anteriores y lo que ha gastado (sin contar los de pruebas ni lo devuelto).
     *
     * @param array<string,mixed> $order
     *
     * @return array<string,mixed>
     */
    private function customerBox(array $order): array
    {
        $app = $this->app;
        $db = $app->db();
        $customer = is_array($order['customer']) ? $order['customer'] : null;
        $id = (int) $order['id'];
        $where = $order['customer_id'] !== null ? 'customer_id = ?' : 'email = ?';
        $value = $order['customer_id'] !== null ? (int) $order['customer_id'] : (string) $order['email'];
        // Los de prueba aparte: «Otros pedidos 17» con «Total gastado 0,00 €» no cuadraba (14/11/2026).
        $previous = (int) $db->value('SELECT COUNT(*) FROM {orders} WHERE ' . $where . ' AND id <> ? AND test = 0', [$value, $id]);
        $previousTest = (int) $db->value('SELECT COUNT(*) FROM {orders} WHERE ' . $where . ' AND id <> ? AND test = 1', [$value, $id]);
        $spent = (int) $db->value('SELECT COALESCE(SUM(paid_total - refunded_total), 0) FROM {orders} WHERE ' . $where . ' AND test = 0', [$value]);
        $billing = is_array($order['billing']) ? $order['billing'] : [];
        $name = $customer !== null ? trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name']) : '';
        if ($name === '') {
            $name = trim((isset($billing['first_name']) ? (string) $billing['first_name'] : '') . ' ' . (isset($billing['last_name']) ? (string) $billing['last_name'] : ''));
        }
        $url = '';
        if ($customer !== null && $app->auth()->can('customers.view')) {
            $url = Admin::resourceUrl($app, 'customers', (int) $customer['id']);
        }

        return [
            'name' => $name,
            'email' => (string) $order['email'],
            'phone' => isset($billing['phone']) ? (string) $billing['phone'] : '',
            'guest' => $customer === null || !empty($customer['guest']),
            'url' => $url,
            'previous' => $previous,
            'previous_test' => $previousTest,
            'previous_url' => $app->url()->plain('/admin/orders', ['q' => (string) $order['email']]),
            'spent' => $spent,
        ];
    }

    // ------------------------------------------------------------------ acciones

    /** Cambia el estado, con una nota para el cliente (opcional) y el aviso por correo si se marca. @param array<string,string> $params */
    public function status(Request $request, array $params): Response
    {
        $order = $this->guard($params, 'orders.edit');
        $id = (int) $order['id'];
        $state = $request->str('status');
        $name = $this->stateName($state);
        $ok = $this->orders()->setStatus($id, $state, mb_substr($request->str('note'), 0, 4000, 'UTF-8'), $this->userId(), $request->bool('notify'));
        if ($ok) {
            Admin::audit($this->app, 'order.status', 'order', (string) $id, (string) $order['number'], ['status' => [(string) $order['status'], $state]]);
            Admin::flash($this->app, 'ok', t('Status changed to {status}', ['status' => t($name)]));
        } else {
            Admin::flash($this->app, 'bad', t('The status could not be changed: that change is not allowed.'));
        }

        return $this->back($id);
    }

    /** Una nota en el historial, pública (la ve el cliente) o privada. @param array<string,string> $params */
    public function note(Request $request, array $params): Response
    {
        $auth = $this->app->auth();
        if (!$auth->can('orders.edit') && !$auth->can('orders.notes')) {
            $auth->requireCap('orders.edit');
        }
        $order = $this->order((int) $params['id']);
        $id = (int) $order['id'];
        $note = $request->str('note');
        if ($note === '') {
            Admin::flash($this->app, 'warn', t('Write the note first.'));

            return $this->back($id);
        }
        $public = $request->str('visibility') === 'public';
        $this->orders()->addNote($id, $note, $public, $this->userId());
        Admin::audit($this->app, 'order.note', 'order', (string) $id, (string) $order['number'], ['public' => [null, $public ? '1' : '0']]);
        Admin::flash($this->app, 'ok', $public ? t('Note saved. The customer can see it.') : t('Private note saved.'));

        return $this->back($id);
    }

    /** El código de seguimiento y, si se pide, pasa el pedido a enviado. @param array<string,string> $params */
    public function tracking(Request $request, array $params): Response
    {
        $order = $this->guard($params, 'orders.edit');
        $id = (int) $order['id'];
        $code = mb_substr($request->str('tracking_code'), 0, 80, 'UTF-8');
        $ship = $request->bool('ship');
        if ($code === '' && !$ship) {
            Admin::flash($this->app, 'warn', t('Write the tracking code or tick «Mark as shipped».'));

            return $this->back($id);
        }
        if ($code !== '' && $code !== (string) $order['tracking_code']) {
            $this->saveTracking($order, $code);
        }
        if ($ship) {
            if ($this->orders()->setStatus($id, 'shipped', '', $this->userId(), $request->bool('notify'))) {
                Admin::audit($this->app, 'order.status', 'order', (string) $id, (string) $order['number'], ['status' => [(string) $order['status'], 'shipped']]);
                Admin::flash($this->app, 'ok', t('The order is marked as shipped.'));
                if ($code === '' && (string) $order['tracking_code'] === '') {
                    // Se puede enviar sin código (una entrega en mano, un mensajero sin seguimiento), pero se dice (14/11/2026).
                    Admin::flash($this->app, 'warn', t('It has no tracking code: the customer will not be able to follow the parcel. Add it here if you have one.'));
                }
            } else {
                Admin::flash($this->app, 'bad', $code !== '' ? t('The tracking code was saved, but the order could not be marked as shipped.') : t('The order could not be marked as shipped.'));
            }
        } else {
            Admin::flash($this->app, 'ok', t('Tracking code saved.'));
        }

        return $this->back($id);
    }

    /** Da por cobrado a mano un pedido de transferencia o de contra reembolso. @param array<string,string> $params */
    public function paid(Request $request, array $params): Response
    {
        $order = $this->guard($params, 'orders.edit');
        $id = (int) $order['id'];
        $method = (string) $order['payment_method'];
        if (!OrdersAdmin::isManualMethod($method)) {
            Admin::flash($this->app, 'bad', t('This payment goes through a gateway: the order is marked as paid when the gateway confirms it.'));

            return $this->back($id);
        }
        $reference = mb_substr($request->str('reference'), 0, 120, 'UTF-8');
        if ($reference === '') {
            Admin::flash($this->app, 'warn', t('Enter the payment reference.'));

            return $this->back($id);
        }
        $user = $this->userId();
        if ($this->orders()->markPaid($id, $method, $reference, (int) $order['total'], ['manual' => true, 'user_id' => $user])) {
            Admin::audit($this->app, 'order.paid', 'order', (string) $id, (string) $order['number'], ['reference' => [null, $reference]]);
            Admin::flash($this->app, 'ok', t('The payment is recorded.'));
        } else {
            Admin::flash($this->app, 'bad', t('The payment could not be recorded. Check the status of the order.'));
        }

        return $this->back($id);
    }

    /** Cancela un pedido sin pagar. @param array<string,string> $params */
    public function cancel(Request $request, array $params): Response
    {
        $order = $this->guard($params, 'orders.edit');
        $id = (int) $order['id'];
        $reason = mb_substr($request->str('reason'), 0, 4000, 'UTF-8');
        if ($this->orders()->cancel($id, $reason !== '' ? $reason : t('Cancelled'), $request->bool('restock'), $this->userId())) {
            Admin::audit($this->app, 'order.cancel', 'order', (string) $id, (string) $order['number']);
            Admin::flash($this->app, 'ok', t('The order is cancelled.'));
        } else {
            Admin::flash($this->app, 'bad', t('The order could not be cancelled. A paid order has to be refunded instead.'));
        }

        return $this->back($id);
    }

    /** Devuelve dinero: un importe o unas líneas con cantidades. @param array<string,string> $params */
    public function refund(Request $request, array $params): Response
    {
        $order = $this->guard($params, 'orders.refund');
        $id = (int) $order['id'];
        $items = [];
        foreach ($request->arr('items') as $lineId => $qty) {
            if (is_numeric($qty) && (int) $qty > 0) {
                $items[(int) $lineId] = (int) $qty;
            }
        }
        $text = $request->str('amount');
        if ($text !== '') {
            $amount = OrdersAdmin::parseMoney($text);
            if ($amount === null) {
                Admin::flash($this->app, 'bad', t('Enter a valid amount, for example 12.50.'));

                return $this->back($id);
            }
        } elseif ($items !== []) {
            $amount = min(OrdersAdmin::itemsAmount($order, $items), max(0, (int) $order['paid_total'] - (int) $order['refunded_total']));
        } else {
            Admin::flash($this->app, 'warn', t('Enter an amount or choose the lines to refund.'));

            return $this->back($id);
        }
        $reason = mb_substr($request->str('reason'), 0, 255, 'UTF-8');
        try {
            $this->orders()->refund($id, $amount, $items, $reason, $request->bool('gateway'), $request->bool('restock'), $this->userId());
        } catch (\InvalidArgumentException $e) {
            Admin::flash($this->app, 'bad', t($e->getMessage()));

            return $this->back($id);
        } catch (\RuntimeException $e) {
            Admin::flash($this->app, 'bad', t('The refund failed: {reason}', ['reason' => t($e->getMessage())]));

            return $this->back($id);
        }
        Admin::audit($this->app, 'order.refund', 'order', (string) $id, (string) $order['number'], ['refunded_total' => [(string) $order['refunded_total'], (string) ((int) $order['refunded_total'] + $amount)]]);
        Admin::flash($this->app, 'ok', t('Refund of {amount} recorded.', ['amount' => money($amount, (string) $order['currency'])]));

        return $this->back($id);
    }

    /** Vuelve a mandar el correo del estado actual. @param array<string,string> $params */
    public function resend(Request $request, array $params): Response
    {
        $order = $this->guard($params, 'orders.edit');
        $id = (int) $order['id'];
        $template = (string) $this->app->db()->value('SELECT mail FROM {order_states} WHERE code = ?', [(string) $order['status']]);
        if ($template === '') {
            Admin::flash($this->app, 'warn', t('This status has no email to send.'));

            return $this->back($id);
        }
        $extra = [];
        if ($template === 'order_on_hold') {
            foreach (array_reverse($order['history']) as $entry) {
                if ((string) $entry['kind'] === 'payment' && isset($entry['data']['instructions'])) {
                    $extra['instructions'] = (string) $entry['data']['instructions'];
                    break;
                }
            }
        }
        if ((new OrderMails($this->app))->send($order, $template, $extra) > 0) {
            (new OrderLog($this->app))->add($id, 'mail', null, $template, t('Email sent again: {template}', ['template' => $template]), false, $this->userId());
            Admin::audit($this->app, 'order.resend', 'order', (string) $id, (string) $order['number'], ['template' => [null, $template]]);
            Admin::flash($this->app, 'ok', t('The email is on its way to the customer.'));
        } else {
            Admin::flash($this->app, 'bad', t('The email could not be queued. Check the email settings.'));
        }

        return $this->back($id);
    }

    // ------------------------------------------------------------------ piezas

    private function orders(): Orders
    {
        $orders = $this->app->service('orders');
        if (!$orders instanceof Orders) {
            throw new \LogicException('The orders service is not available');
        }

        return $orders;
    }

    /**
     * El pedido de la dirección, tras pedir el permiso.
     *
     * @param array<string,string> $params
     *
     * @return array<string,mixed>
     */
    private function guard(array $params, string $cap): array
    {
        $this->app->auth()->requireCap($cap);

        return $this->order((int) $params['id']);
    }

    /**
     * @return array<string,mixed>
     */
    private function order(int $id): array
    {
        $order = $this->orders()->find($id);
        if ($order === null) {
            throw HttpError::notFound();
        }

        return $order;
    }

    private function back(int $id): Response
    {
        return Response::redirect($this->app->url()->plain('/admin/orders/' . $id));
    }

    private function userId(): ?int
    {
        return $this->app->auth()->adminId();
    }

    private function stateName(string $code): string
    {
        $name = $this->app->db()->value('SELECT name FROM {order_states} WHERE code = ?', [$code]);

        return is_string($name) && $name !== '' ? $name : $code;
    }

    /**
     * Guarda el código de seguimiento y lo apunta en el historial (el cliente lo ve).
     *
     * @param array<string,mixed> $order
     */
    private function saveTracking(array $order, string $code): void
    {
        $id = (int) $order['id'];
        $this->app->db()->update('orders', ['tracking_code' => $code, 'updated_at' => $this->app->clock()->sql()], ['id' => $id]);
        $old = (string) $order['tracking_code'];
        (new OrderLog($this->app))->add($id, 'shipment', $old !== '' ? $old : null, $code, t('Tracking code: {code}', ['code' => $code]), true, $this->userId());
        Admin::audit($this->app, 'order.tracking', 'order', (string) $id, (string) $order['number'], ['tracking_code' => [$old, $code]]);
    }
}

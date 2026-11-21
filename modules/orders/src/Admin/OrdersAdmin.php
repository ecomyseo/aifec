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
use Aifec\Commerce\Money;
use Aifec\Kernel\App;
use Aifec\Modules\Orders\OrderLog;

/**
 * Piezas del panel de pedidos: la configuración del listado y de los carritos, el recurso de estados (formulario,
 * validación y borrado) y unas ayudas pequeñas que comparten la ficha, el inicio y los avisos.
 *
 * El panel no escribe en las tablas de pedidos: todo lo que cambia un pedido pasa por el servicio «orders».
 */
final class OrdersAdmin
{
    /**
     * Pedidos pagados y sin enviar: lo que hay que preparar. El alias de la tabla de pedidos es siempre «o».
     */
    public const TO_PREPARE = "o.payment_status = 'paid' AND o.fulfillment_status IN ('unfulfilled', 'partial') AND o.status NOT IN ('cancelled', 'refunded', 'failed', 'completed')";

    /**
     * Formas de pago sin pasarela: el dinero llega fuera y se da por cobrado a mano. Una forma sin código también.
     *
     * @var string[]
     */
    public const MANUAL_METHODS = ['transfer', 'cod', 'manual', 'cash'];

    // ------------------------------------------------------------------ listado

    /**
     * Configuración del listado de pedidos. Con $csv son las columnas de la exportación (sin HTML).
     *
     * @return array<string,mixed>
     */
    public static function listConfig(App $app, bool $csv = false): array
    {
        $config = [
            'from' => '{orders} o LEFT JOIN {customers} c ON c.id = o.customer_id LEFT JOIN {order_states} s ON s.code = o.status',
            'select' => 'o.id, o.number, o.email, o.currency, o.status, o.payment_status, o.fulfillment_status, o.payment_title, o.payment_method, o.carrier_title, '
                . 'o.tracking_code, o.total, o.test, o.source, o.placed_at, o.billing, c.first_name AS cu_first, c.last_name AS cu_last, '
                . 's.name AS state_name, s.color AS state_color',
            'key' => 'id',
            'key_sql' => 'o.id',
            'search' => [
                'o.number', 'o.email', 'c.first_name', 'c.last_name',
                '(SELECT GROUP_CONCAT(p.reference) FROM {order_payments} p WHERE p.order_id = o.id)',
            ],
            'filters' => [
                'status' => ['label' => 'Status', 'column' => 'o.status', 'options' => static function (App $app): array {
                    $options = [];
                    foreach ($app->db()->select('SELECT code, name FROM {order_states} ORDER BY position, id') as $row) {
                        $options[(string) $row['code']] = (string) $row['name'];
                    }

                    return $options;
                }],
                'payment' => ['label' => 'Payment', 'column' => 'o.payment_status', 'options' => self::paymentOptions()],
                'method' => ['label' => 'Payment method', 'column' => 'o.payment_method', 'raw' => true, 'options' => static function (App $app): array {
                    $options = [];
                    $sql = "SELECT payment_method, MAX(payment_title) AS title FROM {orders} WHERE payment_method IS NOT NULL AND payment_method <> '' GROUP BY payment_method ORDER BY payment_method";
                    foreach ($app->db()->select($sql) as $row) {
                        $options[(string) $row['payment_method']] = PaymentsAdmin::labelFor($app, (string) $row['payment_method'], (string) $row['title']);
                    }

                    return $options;
                }],
                'prepare' => ['label' => 'To prepare', 'options' => ['1' => 'To prepare'], 'sql' => ['1' => self::TO_PREPARE]],
                // De dónde salió el pedido: «Panel» son los hechos a mano (pedido por teléfono, WhatsApp o mostrador).
                'source' => ['label' => 'Channel', 'column' => 'o.source', 'options' => [
                    'web' => 'Web shop', 'admin' => 'Admin panel', 'assistant' => 'AI assistant', 'api' => 'API', 'mcp' => 'MCP', 'ucp' => 'AI platform (UCP)', 'acp' => 'AI platform (ACP)', 'import' => 'Import',
                ]],
                'from' => ['label' => 'From', 'type' => 'date_from', 'column' => 'o.placed_at'],
                'to' => ['label' => 'To', 'type' => 'date_to', 'column' => 'o.placed_at'],
            ],
            'sort' => ['o.id', 'desc'],
            'per_page' => 25,
        ];
        if ($csv) {
            $config['columns'] = [
                'number' => ['label' => 'Order'],
                'placed_at' => ['label' => 'Date'],
                'email' => ['label' => 'Email'],
                'cu_first' => ['label' => 'First name'],
                'cu_last' => ['label' => 'Last name'],
                'total' => ['label' => 'Total', 'type' => 'money'],
                'currency' => ['label' => 'Currency'],
                'status' => ['label' => 'Status'],
                'payment_status' => ['label' => 'Payment'],
                'fulfillment_status' => ['label' => 'Shipping'],
                'payment_title' => ['label' => 'Payment method'],
                'carrier_title' => ['label' => 'Carrier'],
                'tracking_code' => ['label' => 'Tracking code'],
                'test' => ['label' => 'Test order'],
            ];

            return $config;
        }
        $config['empty'] = ['title' => 'No orders yet', 'text' => 'Orders show up here as soon as a customer completes the checkout.'];
        $config['row_url'] = static function (array $row) use ($app): string {
            return $app->url()->plain('/admin/orders/' . (int) $row['id']);
        };
        $config['columns'] = [
            'number' => ['label' => 'Order', 'link' => true, 'sort' => 'o.number', 'render' => static function (array $row, App $app): string {
                $html = '<span class="af-nowrap">' . e((string) $row['number']) . '</span>';
                if (!empty($row['test'])) {
                    $html .= ' ' . Admin::badge(t('Test'), 'warn');
                }
                if (isset($row['source']) && (string) $row['source'] === 'admin') {
                    $html .= ' ' . Admin::badge(t('Panel'), 'info');
                }

                return $html;
            }],
            'placed_at' => ['label' => 'Date', 'type' => 'datetime', 'sort' => 'o.placed_at', 'hide_sm' => true],
            'customer' => ['label' => 'Customer', 'render' => static function (array $row, App $app): string {
                $name = self::customerName($row);
                $html = $name !== '' ? '<strong>' . e($name) . '</strong><br>' : '';

                return $html . '<span class="af-small af-muted">' . e((string) $row['email']) . '</span>';
            }],
            'total' => ['label' => 'Total', 'type' => 'money', 'currency_field' => 'currency', 'sort' => 'o.total'],
            'payment_status' => ['label' => 'Payment', 'sort' => 'o.payment_status', 'render' => static function (array $row, App $app): string {
                return self::paymentBadge((string) $row['payment_status']);
            }],
            'status' => ['label' => 'Status', 'sort' => 'o.status', 'render' => static function (array $row, App $app): string {
                $name = isset($row['state_name']) && (string) $row['state_name'] !== '' ? (string) $row['state_name'] : (string) $row['status'];
                $color = isset($row['state_color']) ? (string) $row['state_color'] : '';

                return Admin::badge(t($name), $color);
            }],
            'fulfillment_status' => ['label' => 'Shipping', 'hide_sm' => true, 'render' => static function (array $row, App $app): string {
                $html = self::fulfillmentBadge((string) $row['fulfillment_status']);
                if ((string) $row['carrier_title'] !== '') {
                    $html .= '<br><span class="af-small af-muted">' . e((string) $row['carrier_title']) . '</span>';
                }

                return $html;
            }],
            'payment_title' => ['label' => 'Payment method', 'hide_sm' => true, 'render' => static function (array $row, App $app): string {
                return e(PaymentsAdmin::labelFor($app, (string) $row['payment_method'], (string) $row['payment_title']));
            }],
        ];

        return $config;
    }

    /**
     * Los números de las pestañas rápidas del listado: todos, por preparar, pendientes de pago y en espera.
     *
     * @return array{all:int,prepare:int,pending:int,hold:int}
     */
    public static function tabCounts(App $app): array
    {
        $row = $app->db()->row(
            'SELECT COUNT(*) AS n_all, SUM(CASE WHEN ' . self::TO_PREPARE . " THEN 1 ELSE 0 END) AS n_prepare, "
            . "SUM(CASE WHEN o.status = 'pending_payment' THEN 1 ELSE 0 END) AS n_pending, "
            . "SUM(CASE WHEN o.status = 'on_hold' THEN 1 ELSE 0 END) AS n_hold FROM {orders} o"
        );
        $row = is_array($row) ? $row : [];

        return [
            'all' => isset($row['n_all']) ? (int) $row['n_all'] : 0,
            'prepare' => isset($row['n_prepare']) ? (int) $row['n_prepare'] : 0,
            'pending' => isset($row['n_pending']) ? (int) $row['n_pending'] : 0,
            'hold' => isset($row['n_hold']) ? (int) $row['n_hold'] : 0,
        ];
    }

    // ------------------------------------------------------------------ carritos

    /**
     * Carritos sin pedido de los últimos 30 días y con algo dentro. Solo lectura.
     *
     * @return array<string,mixed>
     */
    public static function cartsConfig(App $app): array
    {
        $since = $app->clock()->sql($app->clock()->now() - 30 * 86400);
        // Cada fila se calcula una sola vez aunque la pinten dos columnas: la memoria es de esta configuración y se va con ella.
        $memo = [];
        $compute = static function (array $row) use ($app, &$memo): array {
            $id = (int) $row['id'];
            if (!isset($memo[$id])) {
                $memo[$id] = self::cartLines($app, $row);
            }

            return $memo[$id];
        };

        return [
            'from' => '{carts} c LEFT JOIN {customers} cu ON cu.id = c.customer_id',
            'select' => 'c.id, c.email, c.items, c.lang, c.updated_at, cu.email AS customer_email',
            'key' => 'id',
            'key_sql' => 'c.id',
            'search' => ['c.email', 'cu.email'],
            'where' => ['c.order_id IS NULL', 'c.updated_at >= ?', 'c.items IS NOT NULL', "c.items <> '[]'"],
            'params' => [$since],
            'sort' => ['updated_at', 'desc'],
            'per_page' => 25,
            'empty' => ['title' => 'No open carts', 'text' => 'Carts with products that were not turned into an order show up here for 30 days.'],
            'columns' => [
                'email' => ['label' => 'Customer', 'render' => static function (array $row, App $app): string {
                    $email = (string) $row['email'] !== '' ? (string) $row['email'] : (isset($row['customer_email']) ? (string) $row['customer_email'] : '');

                    return $email !== '' ? e($email) : '<span class="af-muted">' . e(t('No email left')) . '</span>';
                }],
                'items' => ['label' => 'Items', 'render' => static function (array $row, App $app) use ($compute): string {
                    $data = $compute($row);
                    $html = '';
                    foreach (array_slice($data['lines'], 0, 3) as $line) {
                        $name = $line['missing'] ? t('A product that no longer exists') : $line['name'];
                        $html .= '<div>' . e($name) . ($line['variant'] !== '' ? ' <span class="af-small af-muted">' . e($line['variant']) . '</span>' : '')
                            . ' <span class="af-muted">× ' . (int) $line['qty'] . '</span></div>';
                    }
                    if (count($data['lines']) > 3) {
                        $html .= '<div class="af-small af-muted">' . e(tn('And {n} more line', 'And {n} more lines', count($data['lines']) - 3)) . '</div>';
                    }

                    return $html;
                }],
                'amount' => ['label' => 'Amount', 'render' => static function (array $row, App $app) use ($compute): string {
                    $data = $compute($row);

                    return '<span class="af-nowrap">' . e(money((int) $data['amount'])) . '</span><br><span class="af-small af-muted">'
                        . e(tn('{n} unit', '{n} units', (int) $data['qty'])) . '</span>';
                }],
                'updated_at' => ['label' => 'Last activity', 'type' => 'datetime', 'sort' => 'c.updated_at'],
            ],
        ];
    }

    /**
     * Las líneas de un carrito con su nombre y el importe de lo que contiene (precio de hoy, sin envío ni descuentos).
     *
     * @param array<string,mixed> $row fila de carts con items y lang
     *
     * @return array{lines:array<int,array{name:string,variant:string,qty:int,missing:bool}>,amount:int,qty:int}
     */
    public static function cartLines(App $app, array $row): array
    {
        $items = OrderLog::decode(isset($row['items']) ? $row['items'] : null);
        $catalog = $app->has('catalog') ? $app->service('catalog') : null;
        $lang = isset($row['lang']) && (string) $row['lang'] !== '' ? (string) $row['lang'] : null;
        $lines = [];
        $amount = 0;
        $units = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = isset($item['qty']) && is_numeric($item['qty']) ? max(0, (int) $item['qty']) : 0;
            $productId = isset($item['product_id']) && is_numeric($item['product_id']) ? (int) $item['product_id'] : 0;
            $variantId = isset($item['variant_id']) && is_numeric($item['variant_id']) ? (int) $item['variant_id'] : 0;
            $purchasable = $catalog !== null && $productId > 0 ? $catalog->purchasable($productId, $variantId, $lang) : null;
            if (is_array($purchasable)) {
                $amount += (int) $purchasable['unit_price'] * $qty;
            }
            $lines[] = [
                'name' => is_array($purchasable) ? (string) $purchasable['name'] : '',
                'variant' => is_array($purchasable) ? (string) $purchasable['variant_label'] : '',
                'qty' => $qty,
                'missing' => !is_array($purchasable),
            ];
            $units += $qty;
        }

        return ['lines' => $lines, 'amount' => $amount, 'qty' => $units];
    }

    // ------------------------------------------------------------------ estados de pedido (recurso «order_states»)

    /**
     * Formulario de un estado. En los de serie el código se ve pero no se cambia.
     *
     * @return array<string,mixed>
     */
    public static function stateForm(App $app, ?int $id): array
    {
        $system = false;
        if ($id !== null) {
            $row = $app->db()->find('order_states', ['id' => $id]);
            $system = $row !== null && !empty($row['system']);
        }
        $code = ['label' => 'Code', 'rules' => 'required|max:32|regex:/^[a-z][a-z0-9_]*$/|unique:order_states,code', 'width' => 'md',
            'hint' => 'Lowercase letters, numbers and underscores. It cannot be changed in the built-in statuses.'];
        if ($system) {
            $code['attrs'] = ['readonly' => true];
            $code['store'] = false;
        }

        return ['fields' => [
            'name' => ['label' => 'Name', 'rules' => 'required|max:80', 'width' => 'md'],
            'code' => $code,
            'kind' => ['type' => 'select', 'label' => 'Class', 'rules' => 'required', 'default' => 'open', 'width' => 'md',
                'options' => ['open' => 'In progress', 'done' => 'Finished', 'cancelled' => 'Cancelled'],
                'hint' => 'In progress: still to be handled. Finished: delivered. Cancelled: it will not be delivered.'],
            'color' => ['type' => 'select', 'label' => 'Colour', 'rules' => 'required', 'default' => 'info', 'width' => 'md',
                'options' => ['info' => 'Blue', 'ok' => 'Green', 'warn' => 'Amber', 'bad' => 'Red']],
            'mail' => ['type' => 'select', 'label' => 'Email sent to the customer', 'empty' => 'None', 'null_empty' => true, 'width' => 'md',
                'options' => static function (App $app): array {
                    return self::mailOptions($app);
                },
                'hint' => 'Sent when an order enters this status, if the person who changes it chooses to notify the customer.'],
            'position' => ['type' => 'number', 'label' => 'Order in the lists', 'rules' => 'nullable|min:0', 'width' => 'sm',
                'hint' => 'Smaller numbers come first. Empty = at the end.'],
        ]];
    }

    /**
     * Plantillas de correo «order_*» que se pueden mandar al entrar en un estado: código => descripción.
     *
     * @return array<string,string>
     */
    public static function mailOptions(App $app): array
    {
        $options = [];
        if ($app->has('mailer')) {
            foreach ($app->service('mailer')->templates() as $key => $template) {
                if (strpos((string) $key, 'order_') !== 0) {
                    continue;
                }
                $description = is_array($template) && isset($template['description']) ? (string) $template['description'] : '';
                $options[(string) $key] = $description !== '' ? $description : (string) $key;
            }
        }

        return $options;
    }

    /**
     * Antes de guardar un estado: el código de uno de serie no cambia, el de uno en uso tampoco, y sin posición va el último.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function stateBeforeSave(array $values, ?array $current, App $app)
    {
        $code = isset($values['code']) ? strtolower(trim((string) $values['code'])) : '';
        if ($current !== null) {
            $old = (string) $current['code'];
            if (!empty($current['system'])) {
                $code = $old;
            } elseif ($code !== $old) {
                $used = (int) $app->db()->value('SELECT COUNT(*) FROM {orders} WHERE status = ?', [$old]);
                if ($used > 0) {
                    return ['_errors' => ['code' => t('This status is in use: its code cannot change')]];
                }
            }
        }
        $values['code'] = $code;
        if (!isset($values['position']) || $values['position'] === null || $values['position'] === '') {
            $values['position'] = (int) $app->db()->value('SELECT COALESCE(MAX(position), 0) FROM {order_states}') + 1;
        }

        return $values;
    }

    /**
     * Un estado de serie no se borra, y uno propio con pedidos tampoco.
     *
     * @param array<string,mixed> $row
     */
    public static function stateBeforeDelete(array $row, App $app): ?string
    {
        if (!empty($row['system'])) {
            return t('A built-in status cannot be deleted');
        }
        $used = (int) $app->db()->value('SELECT COUNT(*) FROM {orders} WHERE status = ?', [(string) $row['code']]);
        if ($used > 0) {
            return tn('This status is used by {n} order, so it cannot be deleted.', 'This status is used by {n} orders, so it cannot be deleted.', $used);
        }

        return null;
    }

    // ------------------------------------------------------------------ piezas pequeñas

    /**
     * Estados del cobro: código => [texto en inglés, color].
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function paymentStatuses(): array
    {
        return [
            'pending' => ['Pending', 'warn'],
            'authorized' => ['Authorized', 'info'],
            'paid' => ['Paid', 'ok'],
            'partially_refunded' => ['Partly refunded', 'warn'],
            'refunded' => ['Refunded', 'bad'],
            'failed' => ['Failed', 'bad'],
            'cancelled' => ['Cancelled', 'bad'],
        ];
    }

    /**
     * @return array<string,string> código => texto
     */
    private static function paymentOptions(): array
    {
        $options = [];
        foreach (self::paymentStatuses() as $code => $info) {
            $options[$code] = $info[0];
        }

        return $options;
    }

    public static function paymentBadge(string $status): string
    {
        $map = self::paymentStatuses();
        $info = isset($map[$status]) ? $map[$status] : [$status, ''];

        return Admin::badge(t($info[0]), $info[1]);
    }

    public static function fulfillmentBadge(string $status): string
    {
        $map = [
            'unfulfilled' => ['Not shipped', 'warn'],
            'partial' => ['Partly shipped', 'info'],
            'fulfilled' => ['Shipped', 'ok'],
            'returned' => ['Returned', 'bad'],
            'not_needed' => ['Nothing to ship', ''],
        ];
        $info = isset($map[$status]) ? $map[$status] : [$status, ''];

        return Admin::badge(t($info[0]), $info[1]);
    }

    /**
     * El nombre de quien compró, de la ficha de cliente y, si no, de la dirección de facturación del pedido.
     *
     * @param array<string,mixed> $row fila del listado (cu_first, cu_last, billing)
     */
    public static function customerName(array $row): string
    {
        $name = trim(self::text($row, 'cu_first') . ' ' . self::text($row, 'cu_last'));
        if ($name === '') {
            $billing = OrderLog::decode(isset($row['billing']) ? $row['billing'] : null);
            $name = trim(self::text($billing, 'first_name') . ' ' . self::text($billing, 'last_name'));
        }

        return $name;
    }

    /**
     * ¿Se puede dar por cobrado a mano? Solo lo que no pasa por una pasarela.
     */
    public static function isManualMethod(string $method): bool
    {
        $method = strtolower(trim($method));

        return $method === '' || in_array($method, self::MANUAL_METHODS, true);
    }

    /**
     * Un importe escrito por una persona («12,50», «12.5», «1.234,50 €») a céntimos. Null si no es un importe válido o es cero.
     */
    public static function parseMoney(string $text): ?int
    {
        $text = trim(str_replace(["\xC2\xA0", ' ', '€'], '', $text));
        if ($text === '') {
            return null;
        }
        $comma = strrpos($text, ',');
        $dot = strrpos($text, '.');
        if ($comma !== false && $dot !== false) {
            // El último de los dos es el decimal; el otro separa miles.
            $thousands = $comma > $dot ? '.' : ',';
            $text = str_replace($thousands, '', $text);
        }
        $text = str_replace(',', '.', $text);
        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $text, $m) !== 1) {
            return null;
        }
        $cents = (int) $m[1] * 100 + (isset($m[2]) ? (int) str_pad($m[2], 2, '0') : 0);

        return $cents > 0 ? $cents : null;
    }

    /**
     * Lo que se devuelve por unas unidades de líneas de producto (con impuestos), repartiendo lo cobrado de la línea.
     *
     * @param array<string,mixed> $order el pedido completo (Orders::find)
     * @param array<int,int>      $items línea => unidades
     */
    public static function itemsAmount(array $order, array $items): int
    {
        $amount = 0;
        foreach (isset($order['items']) && is_array($order['items']) ? $order['items'] : [] as $line) {
            $id = (int) $line['id'];
            if ((string) $line['kind'] !== 'product' || !isset($items[$id]) || (int) $line['qty'] < 1) {
                continue;
            }
            $qty = min((int) $items[$id], (int) $line['qty']);
            $amount += Money::divRound(((int) $line['line_net'] + (int) $line['line_tax']) * $qty, (int) $line['qty']);
        }

        return $amount;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        return isset($data[$key]) && is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
    }
}

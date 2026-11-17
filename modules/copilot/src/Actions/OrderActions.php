<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot\Actions;

use Aifec\Db\Db;
use Aifec\Kernel\App;
use Aifec\Modules\Copilot\Finder;
use Aifec\Modules\Copilot\Fmt;
use Aifec\Modules\Copilot\Reader;
use Aifec\Modules\Orders\OrderLog;
use Aifec\Util\Str;

/**
 * Las acciones de pedidos: cambiar el estado, marcar como enviado con transportista y seguimiento, y añadir una nota interna.
 *
 * NUNCA devuelve dinero ni cancela: para un reembolso o una cancelación solo propone el enlace a la ficha del pedido
 * (orders.refund, que no se aplica). Todo cambio de estado pasa por el servicio «orders» con sus mismas reglas.
 * Un cambio de estado desde aquí solo avisa al cliente si la propuesta lo dice (por defecto, no).
 */
final class OrderActions
{
    /** Estados a los que no se llega desde el copiloto: hay una pantalla propia que pide más cosas (pasarela, existencias). */
    private const BLOCKED = ['pending_payment', 'failed', 'refunded', 'cancelled'];

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function definitions(App $app): array
    {
        $codes = [];
        $labels = [];
        foreach ($app->db()->select('SELECT code, name FROM {order_states} ORDER BY position, id') as $row) {
            if (!in_array((string) $row['code'], self::BLOCKED, true)) {
                $codes[] = (string) $row['code'];
                $labels[(string) $row['code']] = (string) $row['name'];
            }
        }

        return [
            [
                'id' => 'orders.status', 'module' => 'orders', 'permission' => 'orders.edit',
                'label' => 'Change the status of an order',
                'description' => 'Moves an order to another status (processing, on hold, shipped, completed…) with an optional note. It can email the customer only if asked. Cancelling and refunding are not done here.',
                'params' => [
                    ['name' => 'order', 'type' => 'string', 'required' => true, 'description' => 'Order number'],
                    ['name' => 'status', 'type' => 'enum', 'required' => true, 'enum' => $codes === [] ? ['processing'] : $codes, 'labels' => $labels, 'description' => 'The new status'],
                    ['name' => 'note', 'type' => 'string', 'description' => 'A note for the order history'],
                    ['name' => 'notify', 'type' => 'bool', 'description' => 'Email the customer about the change (default: no)'],
                ],
                'preview' => [self::class, 'previewStatus'], 'apply' => [self::class, 'applyStatus'], 'undo' => [self::class, 'undoStatus'],
                'understand' => [self::class, 'readStatus'],
                'examples' => ['Change order 12 to completed', 'Put order 12 on hold'],
            ],
            [
                'id' => 'orders.ship', 'module' => 'orders', 'permission' => 'orders.edit',
                'label' => 'Mark an order as shipped',
                'description' => 'Marks an order as shipped, with the carrier and the tracking code if given. It can email the customer only if asked.',
                'params' => [
                    ['name' => 'order', 'type' => 'string', 'required' => true, 'description' => 'Order number'],
                    ['name' => 'carrier', 'type' => 'string', 'description' => 'Carrier name'],
                    ['name' => 'tracking', 'type' => 'string', 'description' => 'Tracking code'],
                    ['name' => 'notify', 'type' => 'bool', 'description' => 'Email the customer that it is shipped (default: no)'],
                ],
                'preview' => [self::class, 'previewShip'], 'apply' => [self::class, 'applyShip'], 'undo' => [self::class, 'undoShip'],
                'understand' => [self::class, 'readShip'],
                'examples' => ['Mark order 12 as shipped with tracking 4455'],
            ],
            [
                'id' => 'orders.note', 'module' => 'orders', 'permission' => [self::class, 'notePermission'],
                'label' => 'Add a note to an order',
                'description' => 'Adds a note to the history of an order. Private by default; it can be visible to the customer if asked.',
                'params' => [
                    ['name' => 'order', 'type' => 'string', 'required' => true, 'description' => 'Order number'],
                    ['name' => 'note', 'type' => 'string', 'required' => true, 'description' => 'The text of the note'],
                    ['name' => 'public', 'type' => 'bool', 'description' => 'Visible to the customer (default: no, internal)'],
                ],
                'preview' => [self::class, 'previewNote'], 'apply' => [self::class, 'applyNote'],
                'understand' => [self::class, 'readNote'],
                'examples' => ['Add a private note to order 12: the customer called'],
            ],
            [
                'id' => 'orders.refund', 'module' => 'orders', 'permission' => 'orders.view', 'readonly' => true,
                'label' => 'Refund or cancel an order',
                'description' => 'The copilot never refunds or cancels: this only gives the link to the order page, where the payment gateway and the stock are handled.',
                'params' => [
                    ['name' => 'order', 'type' => 'string', 'required' => true, 'description' => 'Order number'],
                ],
                'preview' => [self::class, 'previewRefund'], 'apply' => [self::class, 'applyRefund'],
                'understand' => [self::class, 'readRefund'],
                'examples' => ['Refund order 12'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $args
     */
    public static function notePermission(App $app, array $args): string
    {
        return $app->auth()->can('orders.edit') ? 'orders.edit' : 'orders.notes';
    }

    // ------------------------------------------------------------------ entender

    /** El tramo «avisar / sin avisar al cliente» de un texto: true, false o null si no dice nada. */
    private static function notifyWord(string $norm): ?bool
    {
        if (Reader::has($norm, 'sin avisar|sin notificar|no avises|no notifiques|no le avises|without notifying|without notice|do not notify|dont notify|no notify|silenciosamente|silently')) {
            return false;
        }
        if (Reader::has($norm, 'avisa|avisando|avisar|notifica|notificando|notificar|notify|notifying|email al cliente|email the customer|con aviso|and tell the customer|y avisa')) {
            return true;
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function readShip(App $app, string $raw, string $norm): ?array
    {
        $order = Reader::orderRef($norm);
        if ($order === '' || Reader::has($norm, 'nota|note|reembolso|reembolsa|reembolsar|refund|devolver|devolucion|cancela|cancelar|anula|cancel')
            || !Reader::has($norm, 'enviado|enviada|enviados|shipped|despachado|despachada|ship|enviar|envia|enviarlo|mandar|manda|mandalo')) {
            return null;
        }
        $args = ['order' => $order];
        $fold = Reader::fold($raw);
        $tracking = Reader::capture($raw, $fold, '/(?:seguimiento|tracking|rastreo|waybill|guia)\s*(?:code|number|numero|codigo|n[o°º]|es|is)?\s*[:=#]?\s*([A-Za-z0-9][A-Za-z0-9\-]{2,40})(?![A-Za-z0-9\-])/i');
        if ($tracking !== null && $tracking !== '') {
            $args['tracking'] = $tracking;
        }
        $carriers = Finder::carriersIn($app, $norm);
        if (count($carriers) === 1) {
            $args['carrier'] = (string) $carriers[0]['name'];
        }
        $notify = self::notifyWord($norm);
        if ($notify !== null) {
            $args['notify'] = $notify;
        }

        return ['args' => $args, 'score' => 90 + (isset($args['tracking']) ? 5 : 0)];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function readStatus(App $app, string $raw, string $norm): ?array
    {
        $order = Reader::orderRef($norm);
        if ($order === '' || Reader::has($norm, 'nota|note|reembolso|reembolsa|reembolsar|refund|devolver|devolucion|cancela|cancelar|anula|cancel')) {
            return null;
        }
        $found = '';
        foreach ($app->db()->select('SELECT code, name FROM {order_states} ORDER BY position, id') as $row) {
            $code = (string) $row['code'];
            if (in_array($code, self::BLOCKED, true) || $code === 'shipped') {
                continue;
            }
            foreach ([(string) $row['name'], t((string) $row['name'])] as $name) {
                $n = Reader::norm($name);
                if ($n !== '' && Reader::has($norm, preg_quote($n, '/'))) {
                    $found = $code;
                }
            }
        }
        $words = [
            'completed' => 'completado|completada|completed|complete|terminado|terminada|finalizado|finalizada|entregado|entregada|delivered',
            'processing' => 'procesando|en proceso|preparando|en preparacion|preparacion|processing|preparing|confirmado|confirmed',
            'on_hold' => 'en espera|retenido|retenida|pausado|pausada|on hold|hold',
        ];
        foreach ($words as $code => $alternatives) {
            if ($found === '' && Reader::has($norm, $alternatives)) {
                $found = $code;
            }
        }
        if ($found === '') {
            return null;
        }
        $known = (int) $app->db()->value('SELECT COUNT(*) FROM {order_states} WHERE code = ?', [$found]);
        if ($known === 0) {
            return null;
        }
        $args = ['order' => $order, 'status' => $found];
        $notify = self::notifyWord($norm);
        if ($notify !== null) {
            $args['notify'] = $notify;
        }

        return ['args' => $args, 'score' => 80];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function readNote(App $app, string $raw, string $norm): ?array
    {
        $order = Reader::orderRef($norm);
        if ($order === '' || !Reader::has($norm, 'nota|notas|note|notes')) {
            return null;
        }
        $fold = Reader::fold($raw);
        $text = Reader::capture($raw, $fold, '/(?:nota|notas|note|notes)\b[^:]*:\s*(.+)$/is');
        if ($text === null) {
            $text = Reader::capture($raw, $fold, '/(?:que diga|que dice|saying|that says|con el texto|with the text|diciendo|que ponga)\s*:?\s*(.+)$/is');
        }
        if ($text === null) {
            $text = Reader::capture($raw, $fold, '/(?:nota|note)\s+(?:interna|privada|publica|private|internal|public)?\s*(?:al|a|to|on|for|del|de)?\s*(?:pedido|order)\s*#?[\d\-]+\s*(?:interna|privada|publica|private|internal|public)?\s+(.+)$/is');
        }
        $args = ['order' => $order];
        if ($text !== null) {
            $text = Str::trim($text, " \t\n\r\"“”«»");
            if ($text !== '') {
                $args['note'] = $text;
            }
        }
        $public = Reader::has($norm, 'publica|visible para el cliente|visible al cliente|public|visible to the customer|para el cliente|for the customer|el cliente la vea|customer can see');
        $private = Reader::has($norm, 'interna|privada|private|internal');
        if ($private) {
            $args['public'] = false;
        } elseif ($public) {
            $args['public'] = true;
        }

        return ['args' => $args, 'score' => 96];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function readRefund(App $app, string $raw, string $norm): ?array
    {
        $order = Reader::orderRef($norm);
        if ($order === '' || Reader::has($norm, 'nota|note') || !Reader::has($norm, 'reembolso|reembolsa|reembolsar|reembolsame|refund|devuelve|devolver|devolucion|reintegro|reintegra|cancela|cancelar|anula|anular|cancel|dinero')) {
            return null;
        }

        return ['args' => ['order' => $order], 'score' => 95];
    }

    // ------------------------------------------------------------------ reglas

    /**
     * Por qué un pedido no puede pasar a un estado (las mismas reglas que Orders::setStatus), o vacío si puede.
     *
     * @param array<string,mixed> $row
     */
    private static function cannotMove(App $app, array $row, string $to): string
    {
        $from = (string) $row['status'];
        if (in_array($to, self::BLOCKED, true)) {
            return t('Cancelling and refunding are done in the order page.');
        }
        if ($from === $to) {
            return t('The order is already in that status.');
        }
        if (in_array($from, ['cancelled', 'refunded'], true)) {
            return t('A cancelled or refunded order cannot change its status.');
        }
        if ($from === 'failed' && in_array($to, ['processing', 'shipped', 'completed'], true)) {
            return t('The payment of this order failed: it cannot go on.');
        }
        if ($from === 'pending_payment' && in_array($to, ['processing', 'shipped', 'completed'], true)) {
            return t('The order is not paid yet: mark it as paid in the order page first.');
        }
        if ($to === 'shipped' && (string) $row['fulfillment_status'] === 'not_needed') {
            return t('This order does not need shipping.');
        }
        if ($app->db()->find('order_states', ['code' => $to]) === null) {
            return t('That status does not exist.');
        }

        return '';
    }

    /**
     * El pedido de una orden, o por qué no.
     *
     * @param array<string,mixed> $args
     *
     * @return array{order:array<string,mixed>|null,errors:string[],choices:array<int,array<string,string>>}
     */
    private static function find(App $app, array $args): array
    {
        $found = Finder::order($app, (string) $args['order']);
        if ($found['order'] === null) {
            return ['order' => null, 'errors' => [$found['error']], 'choices' => $found['choices']];
        }

        return ['order' => $found['order'], 'errors' => [], 'choices' => []];
    }

    private static function stateName(App $app, string $code): string
    {
        $name = $app->db()->value('SELECT name FROM {order_states} WHERE code = ?', [$code]);

        return is_string($name) && $name !== '' ? t($name) : $code;
    }

    private static function orderUrl(App $app, int $id): string
    {
        return $app->url()->plain('/admin/orders/' . $id);
    }

    // ------------------------------------------------------------------ cambiar el estado

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function previewStatus(App $app, array $args): array
    {
        $found = self::find($app, $args);
        if ($found['order'] === null) {
            return ['summary' => t('Change the status of an order'), 'changes' => [], 'count' => 0, 'errors' => $found['errors'], 'choices' => $found['choices']];
        }
        $row = $found['order'];
        $to = (string) $args['status'];
        $reason = self::cannotMove($app, $row, $to);
        $changes = [Fmt::change(t('Status'), self::stateName($app, (string) $row['status']), self::stateName($app, $to))];
        if (!empty($args['notify'])) {
            $changes[] = Fmt::change(t('Email to the customer'), t('No'), t('Yes'));
        }
        if (isset($args['note'])) {
            $changes[] = Fmt::change(t('Note'), '—', (string) $args['note']);
        }

        return [
            'summary' => t('Change order {number} to «{status}»', ['number' => (string) $row['number'], 'status' => self::stateName($app, $to)]),
            'changes' => $changes, 'count' => 1,
            'warnings' => [], 'errors' => $reason !== '' ? [$reason] : [],
            'links' => [['label' => t('Open order {number}', ['number' => (string) $row['number']]), 'url' => self::orderUrl($app, (int) $row['id'])]],
        ];
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function applyStatus(App $app, array $args): array
    {
        $found = self::find($app, $args);
        if ($found['order'] === null) {
            return ['ok' => false, 'message' => (string) $found['errors'][0], 'undo' => null];
        }
        $row = $found['order'];
        $to = (string) $args['status'];
        $reason = self::cannotMove($app, $row, $to);
        if ($reason !== '') {
            return ['ok' => false, 'message' => $reason, 'undo' => null];
        }
        $from = (string) $row['status'];
        $done = $app->service('orders')->setStatus((int) $row['id'], $to, isset($args['note']) ? (string) $args['note'] : '', Fmt::userId($app), !empty($args['notify']));
        if (!$done) {
            return ['ok' => false, 'message' => t('The order could not change its status.'), 'undo' => null];
        }

        return [
            'ok' => true,
            'message' => t('Order {number} is now «{status}».', ['number' => (string) $row['number'], 'status' => self::stateName($app, $to)]),
            'undo' => ['order_id' => (int) $row['id'], 'from' => $from, 'to' => $to],
        ];
    }

    /**
     * @param array<string,mixed> $undo
     *
     * @return array<string,mixed>
     */
    public static function undoStatus(App $app, array $undo): array
    {
        $row = $app->db()->row('SELECT id, status FROM {orders} WHERE id = ?', [(int) $undo['order_id']]);
        if ($row === null || (string) $row['status'] !== (string) $undo['to']) {
            return ['ok' => false, 'message' => t('The order has changed since. It is not undone so that nothing else is lost.')];
        }
        $done = $app->service('orders')->setStatus((int) $row['id'], (string) $undo['from'], t('Undone from the copilot'), Fmt::userId($app), false);

        return $done
            ? ['ok' => true, 'message' => t('The order is back to «{status}».', ['status' => self::stateName($app, (string) $undo['from'])])]
            : ['ok' => false, 'message' => t('The order cannot go back to that status.')];
    }

    // ------------------------------------------------------------------ marcar como enviado

    /**
     * Lo que se va a hacer con un pedido al marcarlo enviado.
     *
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    private static function planShip(App $app, array $args): array
    {
        $out = ['errors' => [], 'choices' => [], 'order' => null, 'carrier' => null, 'tracking' => null, 'status' => false];
        $found = self::find($app, $args);
        if ($found['order'] === null) {
            $out['errors'] = $found['errors'];
            $out['choices'] = $found['choices'];

            return $out;
        }
        $row = $found['order'];
        $out['order'] = $row;
        if (isset($args['carrier'])) {
            $carriers = Finder::carriers($app, (string) $args['carrier']);
            if ($carriers === []) {
                $out['errors'][] = t('No carrier matches «{text}».', ['text' => (string) $args['carrier']]);
            } elseif (count($carriers) > 1) {
                $out['errors'][] = t('Several carriers match «{text}». Choose one.', ['text' => (string) $args['carrier']]);
                foreach ($carriers as $carrier) {
                    $out['choices'][] = ['param' => 'carrier', 'value' => '#' . $carrier['id'], 'label' => (string) $carrier['name']];
                }
            } else {
                $out['carrier'] = ['id' => (int) $carriers[0]['id'], 'name' => (string) $carriers[0]['name']];
            }
        }
        if (isset($args['tracking']) && (string) $args['tracking'] !== (string) $row['tracking_code']) {
            $out['tracking'] = mb_substr((string) $args['tracking'], 0, 80, 'UTF-8');
        }
        $out['status'] = (string) $row['status'] !== 'shipped';
        if ($out['status']) {
            $reason = self::cannotMove($app, $row, 'shipped');
            if ($reason !== '') {
                $out['errors'][] = $reason;
            }
        } elseif ($out['tracking'] === null && $out['carrier'] === null) {
            $out['errors'][] = t('The order is already shipped.');
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function previewShip(App $app, array $args): array
    {
        $plan = self::planShip($app, $args);
        if ($plan['order'] === null) {
            return ['summary' => t('Mark an order as shipped'), 'changes' => [], 'count' => 0, 'errors' => $plan['errors'], 'choices' => $plan['choices']];
        }
        $row = $plan['order'];
        $changes = [];
        if ($plan['status']) {
            $changes[] = Fmt::change(t('Status'), self::stateName($app, (string) $row['status']), self::stateName($app, 'shipped'));
        }
        if ($plan['tracking'] !== null) {
            $changes[] = Fmt::change(t('Tracking code'), (string) $row['tracking_code'] !== '' ? (string) $row['tracking_code'] : '—', (string) $plan['tracking']);
        }
        if ($plan['carrier'] !== null && (int) $plan['carrier']['id'] !== (int) $row['carrier_id']) {
            $changes[] = Fmt::change(t('Carrier'), (string) $row['carrier_title'] !== '' ? (string) $row['carrier_title'] : '—', (string) $plan['carrier']['name']);
        }
        if (!empty($args['notify']) && $plan['status']) {
            $changes[] = Fmt::change(t('Email to the customer'), t('No'), t('Yes'));
        }

        return [
            'summary' => t('Mark order {number} as shipped', ['number' => (string) $row['number']]),
            'changes' => $changes, 'count' => 1,
            'warnings' => [], 'errors' => $plan['errors'], 'choices' => $plan['choices'],
            'links' => [['label' => t('Open order {number}', ['number' => (string) $row['number']]), 'url' => self::orderUrl($app, (int) $row['id'])]],
        ];
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function applyShip(App $app, array $args): array
    {
        $plan = self::planShip($app, $args);
        if ($plan['order'] === null || $plan['errors'] !== [] || $plan['choices'] !== []) {
            return ['ok' => false, 'message' => $plan['errors'] !== [] ? (string) $plan['errors'][0] : t('Nothing to change.'), 'undo' => null];
        }
        $row = $plan['order'];
        $id = (int) $row['id'];
        $userId = Fmt::userId($app);
        $undo = [
            'order_id' => $id, 'status' => $plan['status'] ? ['from' => (string) $row['status'], 'to' => 'shipped'] : null,
            'tracking' => $plan['tracking'] !== null ? ['from' => (string) $row['tracking_code'], 'to' => (string) $plan['tracking']] : null,
            'carrier' => $plan['carrier'] !== null && (int) $plan['carrier']['id'] !== (int) $row['carrier_id']
                ? ['from_id' => $row['carrier_id'] !== null ? (int) $row['carrier_id'] : null, 'from_title' => (string) $row['carrier_title'], 'to_id' => (int) $plan['carrier']['id'], 'to_title' => (string) $plan['carrier']['name']]
                : null,
        ];
        try {
            $app->db()->transaction(function (Db $db) use ($plan, $row, $id, $userId, $undo, $app, $args): void {
                $now = $app->clock()->sql();
                if ($undo['tracking'] !== null) {
                    $db->update('orders', ['tracking_code' => $undo['tracking']['to'], 'updated_at' => $now], ['id' => $id]);
                    (new OrderLog($app))->add($id, 'shipment', $undo['tracking']['from'] !== '' ? $undo['tracking']['from'] : null, $undo['tracking']['to'], t('Tracking code: {code}', ['code' => $undo['tracking']['to']]), true, $userId);
                }
                if ($undo['carrier'] !== null) {
                    $db->update('orders', ['carrier_id' => $undo['carrier']['to_id'], 'carrier_title' => $undo['carrier']['to_title'], 'updated_at' => $now], ['id' => $id]);
                }
                if ($undo['status'] !== null && !$app->service('orders')->setStatus($id, 'shipped', '', $userId, !empty($args['notify']))) {
                    throw new \RuntimeException('The order could not be marked as shipped');
                }
            });
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => t('The order could not be marked as shipped. Nothing was changed.'), 'undo' => null];
        }

        return ['ok' => true, 'message' => t('Order {number} is marked as shipped.', ['number' => (string) $row['number']]), 'undo' => $undo];
    }

    /**
     * @param array<string,mixed> $undo
     *
     * @return array<string,mixed>
     */
    public static function undoShip(App $app, array $undo): array
    {
        $db = $app->db();
        $id = (int) $undo['order_id'];
        $row = $db->row('SELECT id, status, tracking_code, carrier_id, carrier_title FROM {orders} WHERE id = ?', [$id]);
        $moved = is_array($undo['status']) ? $undo['status'] : null;
        $tracking = is_array($undo['tracking']) ? $undo['tracking'] : null;
        $carrier = is_array($undo['carrier']) ? $undo['carrier'] : null;
        $same = $row !== null
            && ($moved === null || (string) $row['status'] === (string) $moved['to'])
            && ($tracking === null || (string) $row['tracking_code'] === (string) $tracking['to'])
            && ($carrier === null || ((int) $row['carrier_id'] === (int) $carrier['to_id'] && (string) $row['carrier_title'] === (string) $carrier['to_title']));
        if (!$same) {
            return ['ok' => false, 'message' => t('The order has changed since. It is not undone so that nothing else is lost.')];
        }
        $userId = Fmt::userId($app);
        if ($moved !== null && !$app->service('orders')->setStatus($id, (string) $moved['from'], t('Undone from the copilot'), $userId, false)) {
            return ['ok' => false, 'message' => t('The order cannot go back to that status.')];
        }
        $now = $app->clock()->sql();
        if ($tracking !== null) {
            $db->update('orders', ['tracking_code' => (string) $tracking['from'] !== '' ? (string) $tracking['from'] : null, 'updated_at' => $now], ['id' => $id]);
        }
        if ($carrier !== null) {
            $db->update('orders', ['carrier_id' => $carrier['from_id'], 'carrier_title' => (string) $carrier['from_title'] !== '' ? (string) $carrier['from_title'] : null, 'updated_at' => $now], ['id' => $id]);
        }

        return ['ok' => true, 'message' => t('The order is back as it was.')];
    }

    // ------------------------------------------------------------------ nota

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function previewNote(App $app, array $args): array
    {
        $found = self::find($app, $args);
        if ($found['order'] === null) {
            return ['summary' => t('Add a note to an order'), 'changes' => [], 'count' => 0, 'errors' => $found['errors'], 'choices' => $found['choices']];
        }
        $row = $found['order'];
        $public = !empty($args['public']);

        return [
            'summary' => $public ? t('Add a note visible to the customer to order {number}', ['number' => (string) $row['number']]) : t('Add an internal note to order {number}', ['number' => (string) $row['number']]),
            'changes' => [Fmt::change($public ? t('Note for the customer') : t('Internal note'), '—', (string) $args['note'])],
            'count' => 1, 'warnings' => $public ? [t('The customer will be able to read this note.')] : [], 'errors' => [],
            'links' => [['label' => t('Open order {number}', ['number' => (string) $row['number']]), 'url' => self::orderUrl($app, (int) $row['id'])]],
        ];
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function applyNote(App $app, array $args): array
    {
        $found = self::find($app, $args);
        if ($found['order'] === null) {
            return ['ok' => false, 'message' => (string) $found['errors'][0], 'undo' => null];
        }
        $row = $found['order'];
        $app->service('orders')->addNote((int) $row['id'], (string) $args['note'], !empty($args['public']), Fmt::userId($app));

        return ['ok' => true, 'message' => t('Note added to order {number}.', ['number' => (string) $row['number']]), 'undo' => null];
    }

    // ------------------------------------------------------------------ reembolso (solo el enlace)

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function previewRefund(App $app, array $args): array
    {
        $found = self::find($app, $args);
        if ($found['order'] === null) {
            return ['summary' => t('Refund or cancel an order'), 'changes' => [], 'count' => 0, 'errors' => $found['errors'], 'choices' => $found['choices']];
        }
        $row = $found['order'];

        return [
            'summary' => t('Refunds and cancellations are made in the order page, where the payment and the stock are handled.'),
            'changes' => [], 'count' => 0, 'warnings' => [], 'errors' => [],
            'links' => [['label' => t('Open order {number}', ['number' => (string) $row['number']]), 'url' => self::orderUrl($app, (int) $row['id'])]],
        ];
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function applyRefund(App $app, array $args): array
    {
        return ['ok' => false, 'message' => t('The copilot never refunds or cancels an order.'), 'undo' => null];
    }
}

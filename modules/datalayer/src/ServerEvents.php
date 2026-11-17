<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Datalayer;

use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Modules\Jobs\PermanentFailure;

/**
 * Los eventos de compra y de reembolso que manda el servidor (protocolo de medición de GA4 y API de conversiones de Meta), con el
 * mismo identificador que el del navegador para que no se cuenten dos veces.
 *
 * Reglas (están probadas):
 *  - Solo con el interruptor «datalayer.server_events» encendido (nace apagado).
 *  - Solo con consentimiento: el que dio el visitante al hacer el pedido (estadísticas para GA4, marketing para Meta), guardado en
 *    la marca del pedido. Sin decisión, no se manda.
 *  - Nunca recorre pedidos: solo se encola lo que ocurre (order.paid, order.refunded) con el módulo y el interruptor encendidos,
 *    y un pedido sin marca (anterior al módulo) no se manda nunca. Encender el interruptor no envía nada del pasado.
 *  - Un evento no se manda si tiene más de 24 horas: todo se hace en minutos o no se hace.
 *  - Si algo falla (red, 5xx) se reintenta por la cola; un 4xx es definitivo. Todo queda anotado en datalayer_events.
 */
final class ServerEvents
{
    /** Segundos que puede esperar un evento antes de descartarlo (24 horas). */
    public const MAX_AGE = 86400;

    /** @var App */
    private $app;

    /** @var Config */
    private $config;

    /** @var EventBuilder */
    private $builder;

    /** @var OrderMarks */
    private $marks;

    public function __construct(App $app, Config $config, EventBuilder $builder, OrderMarks $marks)
    {
        $this->app = $app;
        $this->config = $config;
        $this->builder = $builder;
        $this->marks = $marks;
    }

    // ------------------------------------------------------------------ encolar

    /**
     * Un pedido acaba de pagarse (acción order.paid): encola su compra.
     *
     * @param array<string,mixed> $order
     */
    public function onPaid(array $order): int
    {
        if (!$this->active() || !OrderMarks::eligible($order)) {
            return 0;
        }

        return $this->enqueue('purchase', $order, '');
    }

    /**
     * Se acaba de devolver dinero de un pedido (acción order.refunded): encola el reembolso.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $refund
     */
    public function onRefunded(array $order, array $refund): int
    {
        if (!$this->active() || !empty($order['test'])) {
            return 0;
        }

        return $this->enqueue('refund', $order, (string) (int) $refund['id']);
    }

    /** ¿Se puede encolar algo ahora mismo? Interruptor encendido, módulo encendido y no es una carga de histórico. */
    private function active(): bool
    {
        return $this->config->enabled() && $this->config->serverEvents() && !$this->app->silenced() && $this->app->has('jobs');
    }

    /** @param array<string,mixed> $order */
    private function enqueue(string $kind, array $order, string $ref): int
    {
        $mark = $this->marks->find((int) $order['id']);
        if ($mark === null) {
            return 0;
        }
        $queued = 0;
        $db = $this->app->db();
        foreach ($this->destinations() as $destination => $need) {
            $eventId = $kind === 'purchase' ? (string) $mark['event_id'] : 'refund_' . (string) $order['number'] . '_' . $ref;
            $allowed = strpos((string) $mark['consent'], $need) !== false;
            $inserted = $db->insertIgnore('datalayer_events', [
                'order_id' => (int) $order['id'], 'kind' => $kind, 'destination' => $destination, 'ref' => $ref, 'event_id' => $eventId,
                'status' => $allowed ? 'queued' : 'skipped', 'response' => $allowed ? null : 'No consent', 'created_at' => $this->app->clock()->sql(),
            ]);
            if ($inserted && $allowed) {
                $id = (int) $db->value('SELECT id FROM {datalayer_events} WHERE order_id = ? AND kind = ? AND destination = ? AND ref = ?', [(int) $order['id'], $kind, $destination, $ref]);
                $this->app->service('jobs')->push('datalayer.server_event', ['id' => $id], ['unique' => 'datalayer.event.' . $id]);
                ++$queued;
            }
        }

        return $queued;
    }

    /**
     * Los destinos configurados del servidor y la letra de consentimiento que cada uno necesita.
     *
     * @return array<string,string>
     */
    private function destinations(): array
    {
        $out = [];
        if ($this->config->ga4() !== '' && $this->app->secret('datalayer.ga4_api_secret') !== '') {
            $out['ga4'] = 's';
        }
        if ($this->config->metaPixel() !== '' && $this->app->secret('datalayer.meta_token') !== '') {
            $out['meta'] = 'm';
        }

        return $out;
    }

    // ------------------------------------------------------------------ mandar

    /**
     * Manda un evento encolado. Devuelve su estado final: sent, failed o skipped (o «gone» si ya no hay nada que hacer).
     * Lanza HttpException si la red falla (la cola lo reintenta) y PermanentFailure si el servicio dice que no.
     */
    public function send(int $id): string
    {
        $db = $this->app->db();
        $row = $db->row('SELECT * FROM {datalayer_events} WHERE id = ?', [$id]);
        if ($row === null || (string) $row['status'] !== 'queued') {
            return 'gone';
        }
        if (!$this->config->enabled() || !$this->config->serverEvents()) {
            return $this->finish($id, 'skipped', null, 'The switch is off');
        }
        if (Clock::parse((string) $row['created_at']) < $this->app->clock()->now() - self::MAX_AGE) {
            return $this->finish($id, 'skipped', null, 'Older than 24 hours');
        }
        $order = $this->app->service('orders')->find((int) $row['order_id']);
        $mark = $this->marks->find((int) $row['order_id']);
        $need = (string) $row['destination'] === 'meta' ? 'm' : 's';
        if ($order === null || $mark === null || strpos((string) $mark['consent'], $need) === false) {
            return $this->finish($id, 'skipped', null, 'No consent');
        }
        $request = (string) $row['destination'] === 'meta' ? $this->metaRequest($row, $order, $mark) : $this->ga4Request($row, $order, $mark);
        if ($request === null) {
            return $this->finish($id, 'skipped', null, 'Not configured');
        }
        $response = $this->app->http()->send($request);
        $excerpt = substr(trim($response->body), 0, 400);
        if ($response->ok()) {
            return $this->finish($id, 'sent', $response->status, $excerpt);
        }
        if ($response->status >= 500 || $response->status === 429) {
            throw new HttpException('Server events: the service answered ' . $response->status);
        }
        $this->finish($id, 'failed', $response->status, $excerpt);
        throw new PermanentFailure('Server events: the service answered ' . $response->status);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $order
     * @param array<string,mixed> $mark
     */
    private function ga4Request(array $row, array $order, array $mark): ?HttpRequest
    {
        $secret = $this->app->secret('datalayer.ga4_api_secret');
        if ($this->config->ga4() === '' || $secret === '') {
            return null;
        }
        if ((string) $row['kind'] === 'refund') {
            $refund = $this->refundOf($row);
            $params = $refund !== null ? $this->builder->refund($order, $refund)['ecommerce'] : [];
            $name = 'refund';
        } else {
            $params = $this->builder->orderEcommerce($order);
            $name = 'purchase';
        }
        // Sin la cookie «_ga» (el visitante no tenía GA) se usa un identificador estable derivado del pedido, no uno al azar.
        $client = isset($mark['ga_client_id']) && (string) $mark['ga_client_id'] !== '' ? (string) $mark['ga_client_id'] : substr(sha1((string) $mark['event_id']), 0, 10) . '.' . Clock::parse((string) $mark['created_at']);
        $url = 'https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode($this->config->ga4()) . '&api_secret=' . rawurlencode($secret);

        return HttpRequest::postJson($url, ['client_id' => $client, 'events' => [['name' => $name, 'params' => $params]]], [], 15.0);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $order
     * @param array<string,mixed> $mark
     */
    private function metaRequest(array $row, array $order, array $mark): ?HttpRequest
    {
        $token = $this->app->secret('datalayer.meta_token');
        if ($this->config->metaPixel() === '' || $token === '') {
            return null;
        }
        $billing = isset($order['billing']) && is_array($order['billing']) ? $order['billing'] : [];
        $user = [];
        $em = UserData::emailHash((string) $order['email']);
        $ph = UserData::phoneHash(isset($billing['phone']) ? (string) $billing['phone'] : '');
        if ($em !== '') {
            $user['em'] = [$em];
        }
        if ($ph !== '') {
            $user['ph'] = [$ph];
        }
        foreach (['fbp', 'fbc'] as $key) {
            if (isset($mark[$key]) && (string) $mark[$key] !== '') {
                $user[$key] = (string) $mark[$key];
            }
        }
        $custom = ['order_id' => (string) $order['number'], 'currency' => (string) $order['currency']];
        if ((string) $row['kind'] === 'refund') {
            $refund = $this->refundOf($row);
            $custom['value'] = EventBuilder::num($refund !== null ? (int) $refund['amount'] : 0);
            $name = 'Refund';
        } else {
            $ecommerce = $this->builder->orderEcommerce($order);
            $contents = [];
            $count = 0;
            foreach ($ecommerce['items'] as $item) {
                $contents[] = ['id' => $item['item_id'], 'quantity' => $item['quantity'], 'item_price' => $item['price']];
                $count += (int) $item['quantity'];
            }
            $custom += ['value' => $ecommerce['value'], 'content_type' => 'product', 'content_ids' => array_column($contents, 'id'), 'contents' => $contents, 'num_items' => $count];
            $name = 'Purchase';
        }
        $body = ['data' => [[
            'event_name' => $name, 'event_time' => $this->app->clock()->now(), 'event_id' => (string) $row['event_id'], 'action_source' => 'website',
            'event_source_url' => $this->app->site()->url() . '/', 'user_data' => $user, 'custom_data' => $custom,
        ]]];
        if ($this->config->metaTestCode() !== '') {
            $body['test_event_code'] = $this->config->metaTestCode();
        }
        $url = 'https://graph.facebook.com/' . $this->config->metaVersion() . '/' . rawurlencode($this->config->metaPixel()) . '/events?access_token=' . rawurlencode($token);

        return HttpRequest::postJson($url, $body, [], 15.0);
    }

    /**
     * La devolución de un evento «refund» (su identificador de reembolso está en «ref»).
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>|null
     */
    private function refundOf(array $row): ?array
    {
        return $this->app->db()->row('SELECT id, amount FROM {order_refunds} WHERE id = ? AND order_id = ?', [(int) $row['ref'], (int) $row['order_id']]);
    }

    private function finish(int $id, string $status, ?int $http, string $note): string
    {
        $this->app->db()->update('datalayer_events', [
            'status' => $status, 'http_status' => $http, 'response' => $note !== '' ? substr($note, 0, 500) : null,
            'sent_at' => $status === 'sent' ? $this->app->clock()->sql() : null,
        ], ['id' => $id]);

        return $status;
    }
}

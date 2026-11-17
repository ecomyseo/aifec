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

use Aifec\Http\HttpRequest;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Security\Random;

/**
 * Avisos al ERP (webhooks): una suscripción por conexión, cada envío firmado y reintentado, y pausa tras cinco fallos seguidos.
 *
 * REGLA BLOQUEANTE: un aviso sale solo por algo que ocurre DESDE que la suscripción existe. Nada recorre pedidos ni clientes
 * antiguos: los avisos nacen de los ganchos (order.paid…) en el instante en que ocurre el hecho, y since_at (la hora en que se creó
 * o se reactivó la suscripción) es el suelo. Con el ajuste erp.outbound apagado (así nace) no sale nada: los avisos se apartan
 * («held») y se ven en el panel. Los apartados o pendientes con más de 24 horas no se envían solos: caducan («expired») y solo se
 * reenvían a mano.
 *
 * Firma: cabecera X-Aifec-Timestamp (segundos Unix) y X-Aifec-Signature = «sha256=» + HMAC-SHA256 de «<marca>.<cuerpo>» con el
 * secreto de la conexión.
 */
final class Webhooks
{
    /** @var array<string,string> evento => para qué sirve */
    public const EVENTS = [
        'order.created' => 'An order is placed',
        'order.paid' => 'An order is paid',
        'order.status_changed' => 'The status of an order changes',
        'order.refunded' => 'Money is refunded from an order',
        'product.stock_changed' => 'The stock of a product changes',
        'customer.created' => 'A customer creates an account',
        'invoice.issued' => 'An invoice or a credit note is issued',
    ];

    /** Segundos de espera tras cada intento fallido: 1 min, 5 min, 30 min, 2 h, 12 h. */
    public const LADDER = [60, 300, 1800, 7200, 43200];

    /** Intentos de un aviso: el primero y uno por cada espera de la escalera. */
    public const MAX_ATTEMPTS = 6;

    /** Fallos seguidos (sin ningún envío bueno entre medias) que pausan la suscripción. */
    public const PAUSE_AFTER = 5;

    /** Segundos que un aviso puede esperar y salir solo. */
    public const WINDOW = 86400;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function outbound(): bool
    {
        return $this->app->settings()->bool('erp.outbound', false);
    }

    /** @return array<string,mixed>|null */
    public function find(int $connectionId): ?array
    {
        $row = $this->app->db()->find('erp_webhooks', ['connection_id' => $connectionId]);

        return $row === null ? null : $this->decorate($row);
    }

    /** Por qué una dirección no vale, o null si vale: https, sin usuario, y sin apuntar a la propia máquina ni a una red interna. */
    public function urlProblem(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || strtolower((string) $parts['scheme']) !== 'https' || strlen($url) > 255) {
            return 'The address must start with https://';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The address cannot carry a user or a password';
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === 'localhost' || substr($host, -6) === '.local' || substr($host, -9) === '.internal') {
            return 'The address cannot point to this machine or to an internal network';
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'The address cannot point to this machine or to an internal network';
        }

        return null;
    }

    /**
     * Crea o cambia la suscripción de una conexión. Al crearla (y al encenderla de nuevo) el cursor se siembra en «ahora».
     *
     * @param string[] $events
     *
     * @return array{id:int,secret:?string,created:bool} secret = el secreto en claro, SOLO al crearla
     *
     * @throws \InvalidArgumentException
     */
    public function save(int $connectionId, string $url, array $events, bool $active): array
    {
        $problem = $this->urlProblem(trim($url));
        if ($problem !== null) {
            throw new \InvalidArgumentException($problem);
        }
        $clean = [];
        foreach ($events as $event) {
            if (!is_string($event) || !isset(self::EVENTS[$event])) {
                throw new \InvalidArgumentException('Unknown event');
            }
            $clean[$event] = true;
        }
        if ($clean === []) {
            throw new \InvalidArgumentException('Choose at least one event');
        }
        $now = $this->app->clock()->sql();
        $json = (string) json_encode(array_keys($clean));
        $existing = $this->find($connectionId);
        if ($existing === null) {
            $id = $this->app->db()->insert('erp_webhooks', [
                'connection_id' => $connectionId, 'url' => trim($url), 'events' => $json, 'active' => $active ? 1 : 0, 'paused' => 0,
                'failures' => 0, 'since_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);

            return ['id' => $id, 'secret' => $this->newSecret($connectionId), 'created' => true];
        }
        $set = ['url' => trim($url), 'events' => $json, 'active' => $active ? 1 : 0, 'updated_at' => $now];
        if ($active && !$existing['active']) {
            $set += ['since_at' => $now, 'paused' => 0, 'paused_reason' => null, 'failures' => 0];
        }
        $this->app->db()->update('erp_webhooks', $set, ['id' => (int) $existing['id']]);

        return ['id' => (int) $existing['id'], 'secret' => null, 'created' => false];
    }

    /** Un secreto nuevo (el anterior deja de valer). Se devuelve en claro una sola vez. */
    public function newSecret(int $connectionId): string
    {
        $secret = 'whsec_' . Random::token(24);
        $this->app->setSecret('erp.whsec.' . $connectionId, $secret);

        return $secret;
    }

    /** Vuelve a encender una suscripción pausada: cursor en «ahora», fallos a cero; lo apartado de hace más de 24 h caduca. */
    public function reactivate(int $webhookId): bool
    {
        $db = $this->app->db();
        $row = $db->find('erp_webhooks', ['id' => $webhookId]);
        if ($row === null) {
            return false;
        }
        $now = $this->app->clock()->sql();
        $db->update('erp_webhooks', ['active' => 1, 'paused' => 0, 'paused_reason' => null, 'failures' => 0, 'since_at' => $now, 'updated_at' => $now], ['id' => $webhookId]);
        $db->run("UPDATE {erp_deliveries} SET status = 'expired', updated_at = ? WHERE webhook_id = ? AND status = 'held' AND created_at < ?", [
            $now, $webhookId, $this->app->clock()->sql($this->app->clock()->now() - self::WINDOW),
        ]);

        return true;
    }

    /**
     * ¿Hay alguna suscripción activa que quiera este evento? (Barato: así un hecho sin suscriptores no monta el aviso.)
     */
    public function hasSubscribers(string $event): bool
    {
        $rows = $this->app->db()->select('SELECT w.events FROM {erp_webhooks} w JOIN {erp_connections} c ON c.id = w.connection_id WHERE w.active = 1 AND w.paused = 0 AND c.active = 1');
        foreach ($rows as $row) {
            $list = json_decode((string) $row['events'], true);
            if (is_array($list) && in_array($event, $list, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un hecho acaba de ocurrir: un aviso por cada suscripción que lo quiere. Los datos se montan una sola vez y solo si hay alguien.
     *
     * @param callable    $data       () => array con el contenido del aviso
     * @param string|null $occurredAt cuándo ocurrió lo que se avisa («Y-m-d H:i:s» UTC); null = ahora. Un hecho anterior a la creación de la
     *                                suscripción (since_at) no se avisa nunca: ese es el suelo que impide que un aviso salga por algo antiguo
     *
     * @return int cuántos avisos se han creado
     */
    public function emit(string $event, callable $data, ?string $occurredAt = null): int
    {
        if ($this->app->silenced() || !isset(self::EVENTS[$event]) || !$this->hasSubscribers($event)) {
            return 0;
        }
        $db = $this->app->db();
        $now = $this->app->clock()->sql();
        $outbound = $this->outbound();
        $payload = null;
        $created = 0;
        $rows = $db->select('SELECT w.* FROM {erp_webhooks} w JOIN {erp_connections} c ON c.id = w.connection_id WHERE w.active = 1 AND w.paused = 0 AND c.active = 1 AND w.since_at <= ?', [$now]);
        foreach ($rows as $row) {
            $events = json_decode((string) $row['events'], true);
            if (!is_array($events) || !in_array($event, $events, true)) {
                continue;
            }
            if ($occurredAt !== null && $occurredAt !== '' && $occurredAt < (string) $row['since_at']) {
                continue;
            }
            if ($payload === null) {
                $eventId = 'evt_' . Random::token(12);
                $payload = (string) json_encode(['id' => $eventId, 'event' => $event, 'created_at' => Present::iso($now), 'data' => $data()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
            $id = $db->insert('erp_deliveries', [
                'webhook_id' => (int) $row['id'], 'connection_id' => (int) $row['connection_id'], 'event' => $event, 'event_id' => $eventId,
                'payload' => $payload, 'status' => $outbound ? 'pending' : 'held', 'attempts' => 0, 'next_attempt_at' => $outbound ? $now : null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            ++$created;
            if ($outbound) {
                $this->queue($id, 0);
            }
        }

        return $created;
    }

    /**
     * Un intento de envío. Devuelve: sent, retry, failed, paused, held, expired, busy o missing.
     */
    public function attempt(int $id, bool $manual = false): string
    {
        $db = $this->app->db();
        $delivery = $db->find('erp_deliveries', ['id' => $id]);
        if ($delivery === null) {
            return 'missing';
        }
        $isTest = (string) $delivery['event'] === 'webhook.test';
        $hook = $db->find('erp_webhooks', ['id' => (int) $delivery['webhook_id']]);
        $connection = $db->find('erp_connections', ['id' => (int) $delivery['connection_id']]);
        $now = $this->app->clock()->now();
        $stamp = $this->app->clock()->sql($now);
        if (!$this->outbound() || $hook === null || $connection === null || (int) $connection['active'] !== 1 || (int) $hook['active'] !== 1 || ((int) $hook['paused'] === 1 && !$isTest)) {
            if ((string) $delivery['status'] === 'pending') {
                $db->update('erp_deliveries', ['status' => 'held', 'next_attempt_at' => null, 'updated_at' => $stamp], ['id' => $id]);
            }

            return 'held';
        }
        if (!$manual && !$isTest && Clock::parse((string) $delivery['created_at']) + self::WINDOW < $now) {
            $db->run("UPDATE {erp_deliveries} SET status = 'expired', updated_at = ? WHERE id = ? AND status = 'pending'", [$stamp, $id]);

            return 'expired';
        }
        if ($db->run("UPDATE {erp_deliveries} SET status = 'sending', updated_at = ? WHERE id = ? AND status = 'pending'", [$stamp, $id]) !== 1) {
            return 'busy';
        }

        $body = (string) $delivery['payload'];
        $secret = $this->app->secret('erp.whsec.' . (int) $connection['id']);
        $headers = [
            'Content-Type' => 'application/json', 'X-Aifec-Event' => (string) $delivery['event'], 'X-Aifec-Delivery' => (string) $id,
            'X-Aifec-Timestamp' => (string) $now, 'X-Aifec-Signature' => 'sha256=' . hash_hmac('sha256', $now . '.' . $body, $secret),
        ];
        $code = null;
        $error = '';
        try {
            $response = $this->app->http()->send(new HttpRequest('POST', (string) $hook['url'], $headers, $body, 10.0));
            $code = $response->status;
            $good = $response->ok();
            if (!$good) {
                $error = 'The server answered ' . $code;
            }
        } catch (\Throwable $e) {
            $good = false;
            $error = mb_substr($e->getMessage(), 0, 200, 'UTF-8');
        }
        $attempts = (int) $delivery['attempts'] + 1;
        if ($good) {
            $db->update('erp_deliveries', ['status' => 'sent', 'attempts' => $attempts, 'response_code' => $code, 'error' => null, 'sent_at' => $stamp, 'next_attempt_at' => null, 'updated_at' => $stamp], ['id' => $id]);
            if (!$isTest) {
                $db->update('erp_webhooks', ['failures' => 0, 'updated_at' => $stamp], ['id' => (int) $hook['id']]);
            }

            return 'sent';
        }
        $base = ['attempts' => $attempts, 'response_code' => $code, 'error' => $error, 'updated_at' => $stamp];
        if ($isTest) {
            $db->update('erp_deliveries', $base + ['status' => 'failed', 'next_attempt_at' => null], ['id' => $id]);

            return 'failed';
        }
        $failures = (int) $hook['failures'] + 1;
        $db->update('erp_webhooks', ['failures' => $failures, 'updated_at' => $stamp], ['id' => (int) $hook['id']]);
        if ($failures >= self::PAUSE_AFTER) {
            $reason = 'It failed ' . $failures . ' times in a row. Last error: ' . $error;
            $db->update('erp_webhooks', ['paused' => 1, 'paused_reason' => mb_substr($reason, 0, 255, 'UTF-8')], ['id' => (int) $hook['id']]);
            $db->update('erp_deliveries', $base + ['status' => 'failed', 'next_attempt_at' => null], ['id' => $id]);
                $this->notifyPaused($hook, $connection, $reason);

            return 'paused';
        }
        if ($attempts >= self::MAX_ATTEMPTS) {
            $db->update('erp_deliveries', $base + ['status' => 'failed', 'next_attempt_at' => null], ['id' => $id]);

            return 'failed';
        }
        $wait = self::LADDER[min($attempts, count(self::LADDER)) - 1];
        $db->update('erp_deliveries', $base + ['status' => 'pending', 'next_attempt_at' => $this->app->clock()->sql($now + $wait)], ['id' => $id]);
        $this->queue($id, $wait);

        return 'retry';
    }

    /**
     * La pasada periódica (cada minuto): caduca lo viejo, suelta lo apartado cuando ya se puede y envía lo que le toca.
     *
     * @return array{sent:int,failed:int,expired:int,released:int}
     */
    public function sweep(int $limit = 50): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'expired' => 0, 'released' => 0];
        if (!$this->outbound()) {
            return $out;
        }
        $db = $this->app->db();
        $now = $this->app->clock()->now();
        $stamp = $this->app->clock()->sql($now);
        $cut = $this->app->clock()->sql($now - self::WINDOW);
        $out['expired'] = $db->run("UPDATE {erp_deliveries} SET status = 'expired', updated_at = ? WHERE status IN ('held', 'pending') AND created_at < ? AND event <> 'webhook.test'", [$stamp, $cut]);
        $held = $db->column("SELECT d.id FROM {erp_deliveries} d JOIN {erp_webhooks} w ON w.id = d.webhook_id WHERE d.status = 'held' AND w.active = 1 AND w.paused = 0 ORDER BY d.id LIMIT ?", [$limit]);
        foreach ($held as $id) {
            if ($db->run("UPDATE {erp_deliveries} SET status = 'pending', next_attempt_at = ?, updated_at = ? WHERE id = ? AND status = 'held'", [$stamp, $stamp, (int) $id]) === 1) {
                ++$out['released'];
            }
        }
        // Lo que se quedó «sending» sin nadie detrás (el proceso murió) vuelve a la cola a los 10 minutos.
        $db->run("UPDATE {erp_deliveries} SET status = 'pending', updated_at = ? WHERE status = 'sending' AND updated_at < ?", [$stamp, $this->app->clock()->sql($now - 600)]);
        $due = $db->column("SELECT id FROM {erp_deliveries} WHERE status = 'pending' AND next_attempt_at <= ? ORDER BY id LIMIT ?", [$stamp, $limit]);
        foreach ($due as $id) {
            $result = $this->attempt((int) $id);
            if ($result === 'sent') {
                ++$out['sent'];
            } elseif ($result === 'failed' || $result === 'paused') {
                ++$out['failed'];
            } elseif ($result === 'expired') {
                ++$out['expired'];
            }
        }

        return $out;
    }

    /**
     * «Probar»: manda un aviso de prueba a la dirección de la suscripción, ahora y una sola vez (no cuenta para la pausa).
     *
     * @return array{ok:bool,status:?int,error:string}
     */
    public function test(int $webhookId): array
    {
        $hook = $this->app->db()->find('erp_webhooks', ['id' => $webhookId]);
        if ($hook === null) {
            return ['ok' => false, 'status' => null, 'error' => 'There is no such subscription'];
        }
        if (!$this->outbound()) {
            return ['ok' => false, 'status' => null, 'error' => 'Outgoing messages are switched off in the settings'];
        }
        $now = $this->app->clock()->sql();
        $id = $this->app->db()->insert('erp_deliveries', [
            'webhook_id' => $webhookId, 'connection_id' => (int) $hook['connection_id'], 'event' => 'webhook.test', 'event_id' => 'evt_' . Random::token(12),
            'payload' => (string) json_encode(['id' => 'evt_test', 'event' => 'webhook.test', 'created_at' => Present::iso($now), 'data' => ['message' => 'This is a test']]),
            'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->attempt($id, true);
        $row = $this->app->db()->find('erp_deliveries', ['id' => $id]);

        return ['ok' => $row !== null && (string) $row['status'] === 'sent', 'status' => $row !== null && $row['response_code'] !== null ? (int) $row['response_code'] : null,
            'error' => $row !== null && $row['error'] !== null ? (string) $row['error'] : ''];
    }

    /**
     * «Reenviar»: vuelve a mandar un aviso ya hecho (aunque tenga más de 24 horas), ahora y a mano.
     *
     * @return string sent, retry, failed, paused, held o missing; 'blocked' si los avisos están apagados o la suscripción pausada
     */
    public function resend(int $deliveryId): string
    {
        $db = $this->app->db();
        $delivery = $db->find('erp_deliveries', ['id' => $deliveryId]);
        if ($delivery === null) {
            return 'missing';
        }
        $hook = $db->find('erp_webhooks', ['id' => (int) $delivery['webhook_id']]);
        if (!$this->outbound() || $hook === null || (int) $hook['active'] !== 1 || (int) $hook['paused'] === 1) {
            return 'blocked';
        }
        $now = $this->app->clock()->sql();
        $changed = $db->run("UPDATE {erp_deliveries} SET status = 'pending', attempts = 0, next_attempt_at = ?, error = NULL, updated_at = ? WHERE id = ? AND status IN ('sent', 'failed', 'expired', 'held')", [$now, $now, $deliveryId]);

        return $changed === 1 ? $this->attempt($deliveryId, true) : 'busy';
    }

    /**
     * @return array<int,array<string,mixed>> los últimos avisos (de una conexión o de todas), el más nuevo primero
     */
    public function recent(?int $connectionId = null, string $status = '', int $limit = 50): array
    {
        $sql = 'SELECT d.*, c.name AS connection_name FROM {erp_deliveries} d LEFT JOIN {erp_connections} c ON c.id = d.connection_id WHERE 1 = 1';
        $args = [];
        if ($connectionId !== null) {
            $sql .= ' AND d.connection_id = ?';
            $args[] = $connectionId;
        }
        if ($status !== '') {
            $sql .= ' AND d.status = ?';
            $args[] = $status;
        }
        $args[] = $limit;

        return $this->app->db()->select($sql . ' ORDER BY d.id DESC LIMIT ?', $args);
    }

    // ------------------------------------------------------------------ piezas

    private function queue(int $deliveryId, int $delay): void
    {
        if (!$this->app->has('jobs')) {
            return;
        }
        $this->app->service('jobs')->push('erp.deliver', ['id' => $deliveryId], ['unique' => 'erp.deliver:' . $deliveryId, 'delay' => $delay, 'max_attempts' => 1]);
    }

    /**
     * @param array<string,mixed> $hook
     * @param array<string,mixed> $connection
     */
    private function notifyPaused(array $hook, array $connection, string $reason): void
    {
        $to = trim($this->app->settings()->str('store.email'));
        if ($to === '' || !$this->app->has('mailer')) {
            return;
        }
        try {
            $this->app->service('mailer')->queue('erp_webhook_paused', $to, [
                'connection' => (string) $connection['name'], 'url' => (string) $hook['url'], 'reason' => $reason,
                'panel_url' => $this->app->url()->origin() . $this->app->url()->plain('/admin/erp/connections/' . (int) $connection['id']),
            ], ['locale' => $this->app->defaultLang(), 'related' => 'erp:' . (int) $connection['id']]);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'erp.webhook_paused_mail');
        }
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function decorate(array $row): array
    {
        $events = json_decode((string) $row['events'], true);
        $row['events'] = is_array($events) ? array_values(array_filter($events, 'is_string')) : [];
        $row['active'] = (int) $row['active'] === 1;
        $row['paused'] = (int) $row['paused'] === 1;
        $row['failures'] = (int) $row['failures'];

        return $row;
    }
}

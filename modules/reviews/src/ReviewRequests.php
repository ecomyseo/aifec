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
 * La petición de opinión por correo.
 *
 * Al completarse un pedido (acción order.status) se anota UNA fila por producto con su fecha de envío prevista
 * (completado + reviews.ask_days días). La tarea «reviews.ask» manda las que tocan: nunca recorre pedidos, solo mira las filas
 * anotadas, y solo las de las últimas 24 horas (una fila que se pasó de largo, por ejemplo con el sitio parado, caduca y no sale
 * jamás). Un módulo recién instalado en una tienda con historial no manda nada de lo anterior: no hay filas.
 *
 * El enlace del correo lleva una ficha de un solo uso por producto. La ficha en claro sale de la clave del sitio y del pedido
 * (no se guarda; en la tabla solo queda su resumen), igual que la de ver un pedido sin cuenta.
 */
final class ReviewRequests
{
    /** Segundos que una fila puede esperar pasada su fecha antes de caducar. */
    public const WINDOW = 86400;

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** La ficha en claro del enlace de un producto de un pedido. */
    public static function token(App $app, int $orderId, int $productId): string
    {
        return substr(hash_hmac('sha256', 'review-request:' . $orderId . ':' . $productId, $app->site()->secret()), 0, 40);
    }

    /**
     * Anota las peticiones de un pedido que acaba de completarse. Devuelve cuántas filas nuevas. No anota nada si las peticiones
     * están apagadas, si el pedido no está cobrado o no tiene un correo válido.
     *
     * @param array<string,mixed> $order el pedido completo (Orders::find)
     */
    public function schedule(array $order): int
    {
        $settings = $this->app->settings();
        if (!$settings->bool('reviews.ask', true)) {
            return 0;
        }
        if (!in_array((string) $order['payment_status'], ['paid', 'partially_refunded'], true)) {
            return 0;
        }
        $email = strtolower(trim((string) $order['email']));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 0;
        }
        $billing = isset($order['billing']) && is_array($order['billing']) ? $order['billing'] : [];
        $name = isset($billing['first_name']) ? trim((string) $billing['first_name']) : '';
        $days = max(1, min(60, $settings->int('reviews.ask_days', 7)));
        $now = $this->app->clock()->now();
        $created = 0;
        $seen = [];
        foreach (isset($order['items']) && is_array($order['items']) ? $order['items'] : [] as $item) {
            $productId = (int) $item['product_id'];
            if ((string) $item['kind'] !== 'product' || $productId < 1 || isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;
            $created += $this->app->db()->insertIgnore('review_requests', [
                'order_id' => (int) $order['id'], 'product_id' => $productId, 'email' => $email,
                'name' => $name !== '' ? mb_substr($name, 0, 80, 'UTF-8') : null, 'lang' => (string) $order['lang'],
                'token' => hash('sha256', self::token($this->app, (int) $order['id'], $productId)), 'status' => 'pending',
                'send_at' => $this->app->clock()->sql($now + $days * 86400), 'created_at' => $this->app->clock()->sql($now),
            ]) ? 1 : 0;
        }

        return $created;
    }

    /**
     * La petición que corresponde a una ficha en claro, si todavía vale (anotada o enviada, sin gastar), o null.
     *
     * @return array<string,mixed>|null
     */
    public function open(string $token): ?array
    {
        if ($token === '' || strlen($token) > 80) {
            return null;
        }
        return $this->app->db()->row("SELECT * FROM {review_requests} WHERE token = ? AND status IN ('pending', 'sent')", [hash('sha256', $token)]);
    }

    /** Gasta la ficha: una sola sentencia condicionada, así dos envíos a la vez no valoran dos veces. */
    public function consume(int $requestId, int $reviewId): bool
    {
        return $this->app->db()->run(
            "UPDATE {review_requests} SET status = 'used', used_at = ?, review_id = ? WHERE id = ? AND status IN ('pending', 'sent')",
            [$this->app->clock()->sql(), $reviewId, $requestId]
        ) === 1;
    }

    /**
     * Las filas que tocan ahora: pendientes con la fecha de envío ya pasada y de hace menos de 24 horas. Las pendientes más
     * antiguas se marcan caducadas antes de mirar (por eso esta función escribe).
     *
     * @return array<int,array<string,mixed>>
     */
    public function due(): array
    {
        $db = $this->app->db();
        $now = $this->app->clock()->now();
        $oldest = $this->app->clock()->sql($now - self::WINDOW);
        $db->run("UPDATE {review_requests} SET status = 'expired' WHERE status = 'pending' AND send_at <= ?", [$oldest]);

        return $db->select(
            "SELECT * FROM {review_requests} WHERE status = 'pending' AND send_at <= ? AND send_at > ? ORDER BY order_id, id",
            [$this->app->clock()->sql($now), $oldest]
        );
    }

    /**
     * Manda las peticiones que tocan, un correo por pedido con un enlace por producto. Devuelve cuántos correos se han puesto en
     * la cola. El modo del correo manda: con el correo retenido el mensaje se guarda retenido y no sale.
     *
     * @return array{emails:int,links:int}
     */
    public function sendDue(): array
    {
        $out = ['emails' => 0, 'links' => 0];
        if (!$this->app->settings()->bool('reviews.ask', true) || !$this->app->has('mailer') || $this->app->silenced()) {
            return $out;
        }
        $db = $this->app->db();
        $byOrder = [];
        foreach ($this->due() as $row) {
            $byOrder[(int) $row['order_id']][] = $row;
        }
        foreach ($byOrder as $orderId => $rows) {
            $links = [];
            $claimed = [];
            foreach ($rows as $row) {
                $productId = (int) $row['product_id'];
                if ($db->value('SELECT id FROM {reviews} WHERE product_id = ? AND email = ?', [$productId, (string) $row['email']]) !== null) {
                    $db->update('review_requests', ['status' => 'used', 'used_at' => $this->app->clock()->sql()], ['id' => (int) $row['id'], 'status' => 'pending']);
                    continue;
                }
                $product = $this->app->service('catalog')->product($productId);
                if ($product === null) {
                    $db->update('review_requests', ['status' => 'expired'], ['id' => (int) $row['id'], 'status' => 'pending']);
                    continue;
                }
                $changed = $db->update('review_requests', ['status' => 'sent', 'sent_at' => $this->app->clock()->sql()], ['id' => (int) $row['id'], 'status' => 'pending']);
                if ($changed !== 1) {
                    continue;
                }
                $claimed[] = (int) $row['id'];
                $links[] = ['name' => (string) $product['name'], 'url' => $this->link(self::token($this->app, $orderId, $productId))];
            }
            if ($links === []) {
                continue;
            }
            $first = $rows[0];
            $number = '';
            if ($this->app->has('orders')) {
                $order = $this->app->service('orders')->find($orderId);
                $number = $order !== null ? (string) $order['number'] : '';
            }
            $queued = 0;
            try {
                $queued = (int) $this->app->service('mailer')->queue('review_request', (string) $first['email'], [
                    'name' => (string) $first['name'] !== '' ? (string) $first['name'] : (string) $first['email'],
                    'number' => $number,
                    'products' => $links,
                ], ['locale' => (string) $first['lang'], 'to_name' => (string) $first['name'], 'related' => 'order:' . $orderId]);
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'reviews.ask');
            }
            if ($queued < 1) {
                // No salió: las filas vuelven a estar pendientes para la próxima pasada (mientras duren sus 24 horas).
                foreach ($claimed as $id) {
                    $db->update('review_requests', ['status' => 'pending', 'sent_at' => null], ['id' => $id, 'status' => 'sent']);
                }
                continue;
            }
            ++$out['emails'];
            $out['links'] += count($links);
        }

        return $out;
    }

    /** La dirección completa del enlace de un producto. */
    private function link(string $token): string
    {
        $url = $this->app->url();

        return $url->origin() . $url->route('reviews.request', ['token' => $token]);
    }
}

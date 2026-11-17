<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Response;
use Aifec\Security\Random;

/**
 * Descargas de lo digital comprado (tabla order_downloads).
 *
 * Al pagar el pedido se concede una descarga por cada fichero de cada línea digital. La ficha del enlace es aleatoria
 * (48 letras y cifras) y solo se guarda su resumen SHA-256: quien lee la base de datos no puede construir un enlace.
 * La ficha en claro se entrega una vez (el correo de confirmación) y, si se pierde, renew() emite otra.
 *
 * Límites (ajustes): orders.download_limit descargas por fichero y orders.download_days días de validez; cero = sin
 * límite. serve() cuenta la descarga con una sola sentencia condicionada: dos peticiones a la vez no se saltan el tope.
 */
final class Downloads
{
    /** El filtro sellado con el que un plugin (capacidad «orders») decide si un fichero se entrega. */
    public const GATE = 'order.download.allowed';

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Concede las descargas de un pedido pagado. Lo ya concedido no se repite.
     *
     * @param array<string,mixed> $order el pedido completo (Orders::find)
     *
     * @return array<int,array{id:int,name:string,url:string}> lo concedido ahora, con el enlace en claro
     */
    public function grant(array $order): array
    {
        $db = $this->app->db();
        $clock = $this->app->clock();
        $settings = $this->app->settings();
        $limit = max(0, $settings->int('orders.download_limit', 0));
        $days = max(0, $settings->int('orders.download_days', 0));
        $expires = $days > 0 ? $clock->sql($clock->now() + $days * 86400) : null;
        $granted = [];
        foreach (isset($order['items']) && is_array($order['items']) ? $order['items'] : [] as $item) {
            if ((string) $item['kind'] !== 'product' || empty($item['is_digital']) || (int) $item['product_id'] < 1) {
                continue;
            }
            $files = $db->select(
                'SELECT id, name FROM {product_files} WHERE product_id = ? AND (variant_id IS NULL OR variant_id = ?) ORDER BY position, id',
                [(int) $item['product_id'], (int) $item['variant_id']]
            );
            foreach ($files as $file) {
                if ($db->value('SELECT id FROM {order_downloads} WHERE order_item_id = ? AND file_id = ?', [(int) $item['id'], (int) $file['id']]) !== null) {
                    continue;
                }
                $token = Random::token(24);
                $id = $db->insert('order_downloads', [
                    'order_id' => (int) $order['id'],
                    'order_item_id' => (int) $item['id'],
                    'file_id' => (int) $file['id'],
                    'token' => hash('sha256', $token),
                    'downloads' => 0,
                    'max_downloads' => $limit,
                    'expires_at' => $expires,
                    'created_at' => $clock->sql(),
                ]);
                $granted[] = ['id' => $id, 'name' => (string) $file['name'], 'url' => $this->url($token)];
            }
        }

        return $granted;
    }

    /**
     * Las descargas de un pedido para la zona de cliente. No lleva enlaces (solo se guarda su resumen): para dar uno
     * nuevo, renew().
     *
     * Si un plugin frena una descarga (filtro sellado «order.download.allowed»), sale con available = false, blocked = true y su
     * motivo en «reason».
     *
     * @param array<string,mixed>|null $order el pedido completo, si quien llama ya lo tiene (solo se lee si hay plugins que decidan)
     *
     * @return array<int,array<string,mixed>> id, name, item, downloads, max_downloads, remaining (null = sin tope),
     *                                        expires_at (o null), expired, available, blocked, reason, last_at
     */
    public function forOrder(int $orderId, ?array $order = null): array
    {
        $db = $this->app->db();
        $paymentStatus = (string) $db->value('SELECT payment_status FROM {orders} WHERE id = ?', [$orderId]);
        $paid = in_array($paymentStatus, ['paid', 'partially_refunded'], true);
        $now = $this->app->clock()->now();
        $rows = $db->select(
            'SELECT d.id, d.order_item_id, d.file_id, d.downloads, d.max_downloads, d.expires_at, d.last_at, f.name AS file_name, i.name AS item_name '
            . 'FROM {order_downloads} d JOIN {product_files} f ON f.id = d.file_id JOIN {order_items} i ON i.id = d.order_item_id '
            . 'WHERE d.order_id = ? ORDER BY d.id',
            [$orderId]
        );
        $out = [];
        foreach ($rows as $row) {
            $max = (int) $row['max_downloads'];
            $remaining = $max > 0 ? max(0, $max - (int) $row['downloads']) : null;
            $expiresAt = $row['expires_at'] !== null && (string) $row['expires_at'] !== '' ? (string) $row['expires_at'] : null;
            $expired = $expiresAt !== null && Clock::parse($expiresAt) <= $now;
            $open = $paid && !$expired && ($remaining === null || $remaining > 0);
            $verdict = ['allowed' => true, 'reason' => ''];
            if ($open && $this->app->hooks()->has(self::GATE)) {
                if ($order === null) {
                    $order = $this->app->service('orders')->find($orderId);
                }
                $verdict = $order !== null ? $this->gate($order, (int) $row['order_item_id'], (int) $row['file_id']) : $verdict;
            }
            $out[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['file_name'],
                'item' => (string) $row['item_name'],
                'downloads' => (int) $row['downloads'],
                'max_downloads' => $max,
                'remaining' => $remaining,
                'expires_at' => $expiresAt,
                'expired' => $expired,
                'available' => $open && $verdict['allowed'],
                'blocked' => !$verdict['allowed'],
                'reason' => $verdict['allowed'] ? '' : $verdict['reason'],
                'last_at' => $row['last_at'] !== null ? (string) $row['last_at'] : null,
            ];
        }

        return $out;
    }

    /**
     * ¿Puede entregarse este fichero de este pedido? Lo decide, si quiere, un plugin con la capacidad «orders» concedida, con el
     * filtro sellado «order.download.allowed»: recibe (bool $allowed, array $order, array $line, array $file) y devuelve
     * ['allowed' => bool, 'reason' => string]. Sin plugins que decidan, siempre sí, sin leer nada más.
     *
     * $line es la línea del pedido (la de Orders::find) y $file el fichero (id, name, product_id, variant_id). Si dos plugins
     * deciden, el segundo recibe en $allowed el array del primero. Un plugin que lanza no frena nada (como todos los ganchos).
     *
     * @param array<string,mixed> $order
     *
     * @return array{allowed:bool,reason:string}
     */
    public function gate(array $order, int $orderItemId, int $fileId): array
    {
        $open = ['allowed' => true, 'reason' => ''];
        if (!$this->app->hooks()->has(self::GATE)) {
            return $open;
        }
        $line = [];
        foreach (isset($order['items']) && is_array($order['items']) ? $order['items'] : [] as $item) {
            if (is_array($item) && isset($item['id']) && (int) $item['id'] === $orderItemId) {
                $line = $item;
            }
        }
        $row = $this->app->db()->row('SELECT id, name, product_id, variant_id FROM {product_files} WHERE id = ?', [$fileId]);
        $file = $row !== null ? ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'product_id' => (int) $row['product_id'], 'variant_id' => $row['variant_id'] !== null ? (int) $row['variant_id'] : 0] : ['id' => $fileId];

        return self::verdict($this->app->hooks()->apply(self::GATE, true, $order, $line, $file));
    }

    /**
     * El veredicto de los plugins como array. Un «no» tiene que ser explícito (false, o allowed = false): lo que no se entiende
     * deja pasar, como un plugin que no decide.
     *
     * @param mixed $result
     *
     * @return array{allowed:bool,reason:string}
     */
    public static function verdict($result): array
    {
        if (is_array($result)) {
            $allowed = !array_key_exists('allowed', $result) || (bool) $result['allowed'];
            $reason = isset($result['reason']) && is_scalar($result['reason']) ? trim((string) preg_replace('/[\x00-\x20\x7f]+/', ' ', (string) $result['reason'])) : '';
        } else {
            $allowed = $result !== false;
            $reason = '';
        }
        if ($allowed) {
            return ['allowed' => true, 'reason' => ''];
        }

        return ['allowed' => false, 'reason' => $reason !== '' ? mb_substr($reason, 0, 300, 'UTF-8') : t('This download is not available yet.')];
    }

    /**
     * Para el correo: lo que frena la descarga con ese número, o ['allowed' => true] si nada la frena.
     *
     * @param array<string,mixed> $order
     *
     * @return array{allowed:bool,reason:string}
     */
    public function verdictOf(array $order, int $downloadId): array
    {
        if (!$this->app->hooks()->has(self::GATE)) {
            return ['allowed' => true, 'reason' => ''];
        }
        $row = $this->app->db()->row('SELECT order_item_id, file_id FROM {order_downloads} WHERE id = ? AND order_id = ?', [$downloadId, (int) $order['id']]);

        return $row !== null ? $this->gate($order, (int) $row['order_item_id'], (int) $row['file_id']) : ['allowed' => true, 'reason' => ''];
    }

    /**
     * Emite un enlace nuevo para una descarga de ese pedido (el anterior deja de valer). Null si no existe.
     */
    public function renew(int $downloadId, int $orderId): ?string
    {
        $token = Random::token(24);
        $changed = $this->app->db()->update('order_downloads', ['token' => hash('sha256', $token)], ['id' => $downloadId, 'order_id' => $orderId]);

        return $changed === 1 ? $this->url($token) : null;
    }

    /** La dirección completa de una descarga a partir de su ficha en claro. */
    public function url(string $token): string
    {
        $url = $this->app->url();

        return $url->origin() . $url->route('order.download', ['token' => $token]);
    }

    /**
     * Entrega el fichero de una ficha en claro y cuenta la descarga.
     *
     * @throws HttpError 404 si la ficha no existe, el fichero ya no está o no hay servicio de ficheros; 403 si el pedido no
     *                   está pagado, el enlace caducó o se agotaron las descargas
     */
    public function serve(string $token): Response
    {
        $token = strtolower(trim($token));
        if (preg_match('/^[a-f0-9]{48}$/D', $token) !== 1 || !$this->app->has('files')) {
            throw HttpError::notFound();
        }
        $db = $this->app->db();
        $row = $db->row(
            'SELECT d.id, d.order_id, d.order_item_id, d.file_id, d.expires_at, o.payment_status, f.path AS file_path, f.name AS file_name '
            . 'FROM {order_downloads} d JOIN {orders} o ON o.id = d.order_id JOIN {product_files} f ON f.id = d.file_id WHERE d.token = ?',
            [hash('sha256', $token)]
        );
        if ($row === null) {
            throw HttpError::notFound();
        }
        if (!in_array((string) $row['payment_status'], ['paid', 'partially_refunded'], true)) {
            throw HttpError::forbidden('The order is not paid');
        }
        // La puerta de los plugins (p. ej. la renuncia al desistimiento de un contenido digital): también la dirección directa,
        // que es la que lleva el correo, pasa por ella. Una descarga frenada no se cuenta.
        if ($this->app->hooks()->has(self::GATE)) {
            $order = $this->app->service('orders')->find((int) $row['order_id']);
            $verdict = $order !== null ? $this->gate($order, (int) $row['order_item_id'], (int) $row['file_id']) : ['allowed' => true, 'reason' => ''];
            if (!$verdict['allowed']) {
                throw HttpError::forbidden($verdict['reason']);
            }
        }
        $files = $this->app->service('files');
        if ($files->path((string) $row['file_path']) === null) {
            throw HttpError::notFound();
        }
        $now = $this->app->clock()->sql();
        $counted = $db->run(
            'UPDATE {order_downloads} SET downloads = downloads + 1, last_at = ? WHERE id = ? AND (max_downloads = 0 OR downloads < max_downloads) AND (expires_at IS NULL OR expires_at > ?)',
            [$now, (int) $row['id'], $now]
        );
        if ($counted !== 1) {
            $expired = $row['expires_at'] !== null && (string) $row['expires_at'] !== '' && (string) $row['expires_at'] <= $now;

            throw HttpError::forbidden($expired ? 'This download link has expired' : 'The download limit has been reached');
        }

        return $files->response((string) $row['file_path'], (string) $row['file_name']);
    }
}

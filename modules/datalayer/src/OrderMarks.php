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

use Aifec\Kernel\App;
use Aifec\Modules\Consent\ConsentService;

/**
 * La marca de cada pedido hecho con el módulo encendido (tabla datalayer_orders).
 *
 *  - record(): al hacer el pedido, con la petición del visitante delante, se apunta qué decidió sobre las cookies y sus
 *    identificadores de medición. Es lo único que permite medir la compra desde el servidor respetando su decisión.
 *  - claimBrowser(): la primera vez que se enseña la página del pedido con la compra ya válida, devuelve la marca; las siguientes
 *    veces (recargas) devuelve null. Es una sentencia condicionada: dos visitas a la vez no pueden ser las dos «la primera».
 *
 * Los pedidos de antes de encender el módulo no tienen marca, y sin marca no se mide nada: el módulo nunca recorre pedidos antiguos.
 */
final class OrderMarks
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Apunta la marca de un pedido recién hecho. Si ya existe, no hace nada. Devuelve true si la creó.
     *
     * @param array<string,mixed> $order
     */
    public function record(array $order): bool
    {
        if ($this->app->silenced() || !isset($order['id'], $order['number'])) {
            return false;
        }
        $db = $this->app->db();
        if ($db->row('SELECT id FROM {datalayer_orders} WHERE order_id = ?', [(int) $order['id']]) !== null) {
            return false;
        }
        $request = $this->app->request();
        $letters = '';
        if ($this->app->has('consent')) {
            /** @var ConsentService $consent */
            $consent = $this->app->service('consent');
            foreach (['statistics' => 's', 'marketing' => 'm'] as $category => $letter) {
                if ($consent->allows($category, $request)) {
                    $letters .= $letter;
                }
            }
        }
        $attribution = isset($order['attribution']) && is_array($order['attribution']) ? $order['attribution'] : [];
        $assistant = (string) $order['source'] === 'assistant' || (isset($attribution['channel']) && (string) $attribution['channel'] === 'assistant');
        if (!$assistant && strpos($letters, 's') !== false && $this->app->has('assistant')) {
            $conversations = $this->app->service('assistant')->conversations();
            $token = (string) $request->cookie($conversations->cookieName(), '');
            $assistant = $token !== '' && $conversations->findByToken($token) !== null;
        }
        $source = isset($attribution['source']) && is_string($attribution['source']) ? substr(trim($attribution['source']), 0, 40) : '';
        $db->insert('datalayer_orders', [
            'order_id' => (int) $order['id'],
            'event_id' => 'purchase_' . substr((string) $order['number'], 0, 50),
            'consent' => $letters,
            'ga_client_id' => $letters !== '' && strpos($letters, 's') !== false ? $this->gaClientId((string) $request->cookie('_ga', '')) : null,
            'fbp' => strpos($letters, 'm') !== false ? $this->plain((string) $request->cookie('_fbp', ''), 80) : null,
            'fbc' => strpos($letters, 'm') !== false ? $this->plain((string) $request->cookie('_fbc', ''), 120) : null,
            'ai_source' => $source !== '' ? $source : null,
            'assistant' => $assistant ? 1 : 0,
            'created_at' => $this->app->clock()->sql(),
        ]);

        return true;
    }

    /**
     * ¿Está el pedido en un punto en que la compra cuenta? Pagado, o hecho con una forma de pago que se paga después (transferencia,
     * contra reembolso) y ya aceptado. No cuenta mientras espera a la pasarela, ni si falló, se canceló o se devolvió entero, ni
     * si es un pedido de pruebas.
     *
     * @param array<string,mixed> $order
     */
    public static function eligible(array $order): bool
    {
        if (!empty($order['test'])) {
            return false;
        }
        $status = (string) $order['status'];
        if (in_array($status, ['pending_payment', 'failed', 'cancelled', 'refunded'], true)) {
            return false;
        }

        return in_array((string) $order['payment_status'], ['paid', 'partially_refunded'], true) || in_array($status, ['on_hold', 'processing', 'shipped', 'completed'], true);
    }

    /**
     * La marca del pedido si esta es la primera vez que se enseña con la compra válida; null si no hay marca (pedido de antes del
     * módulo), si todavía no cuenta o si ya se empujó.
     *
     * @param array<string,mixed> $order
     *
     * @return array<string,mixed>|null
     */
    public function claimBrowser(array $order): ?array
    {
        if (!self::eligible($order)) {
            return null;
        }
        $db = $this->app->db();
        $mark = $db->row('SELECT * FROM {datalayer_orders} WHERE order_id = ?', [(int) $order['id']]);
        if ($mark === null) {
            return null;
        }
        $changed = $db->run('UPDATE {datalayer_orders} SET browser_at = ? WHERE order_id = ? AND browser_at IS NULL', [$this->app->clock()->sql(), (int) $order['id']]);

        return $changed === 1 ? $mark : null;
    }

    /**
     * La marca de un pedido, o null.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $orderId): ?array
    {
        return $this->app->db()->row('SELECT * FROM {datalayer_orders} WHERE order_id = ?', [$orderId]);
    }

    /** El identificador de cliente de GA4 de la cookie «_ga» («GA1.1.123456789.1700000000» → «123456789.1700000000»). */
    private function gaClientId(string $cookie): ?string
    {
        if (preg_match('/^GA\d+\.\d+\.(\d{4,12}\.\d{6,12})$/', $cookie, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function plain(string $value, int $max): ?string
    {
        return preg_match('/^[A-Za-z0-9._-]{1,' . $max . '}$/', $value) === 1 ? $value : null;
    }
}

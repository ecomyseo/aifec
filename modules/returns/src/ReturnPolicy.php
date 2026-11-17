<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Returns;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;

/**
 * Las reglas de una devolución: plazos, qué se puede devolver de un pedido y con qué motivos.
 *
 * El plazo cuenta desde la entrega (el momento en que el pedido se marcó «completed») o, si no hay fecha de entrega, desde el
 * envío más los días de tránsito del ajuste. El reloj es el de la aplicación (nunca la hora suelta del sistema).
 */
final class ReturnPolicy
{
    /** Código del motivo del desistimiento: no pide explicación y solo se ofrece dentro de su plazo. */
    public const WITHDRAWAL = 'withdrawal';

    /** Código del motivo que admite fotografías. */
    public const DEFECTIVE = 'defective';

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** Días de plazo de una devolución corriente (ajuste returns.days). */
    public function days(): int
    {
        return max(0, $this->app->settings()->int('returns.days', 30));
    }

    /** Días de plazo del desistimiento (ajuste returns.withdrawal_days). */
    public function withdrawalDays(): int
    {
        return max(0, $this->app->settings()->int('returns.withdrawal_days', 14));
    }

    /** Días que se suman al envío cuando el pedido no tiene fecha de entrega (ajuste returns.transit_days). */
    public function transitDays(): int
    {
        return max(0, $this->app->settings()->int('returns.transit_days', 3));
    }

    /**
     * Los motivos que se ofrecen: código => texto (en inglés si es de serie, tal cual lo escribió el comerciante si es suyo).
     * Salen del ajuste returns.reasons (una línea por motivo, «código|texto») o, si está vacío, los de serie. El motivo
     * «withdrawal» no se configura: lo pone la propia elegibilidad.
     *
     * @return array<string,string>
     */
    public function reasons(): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $this->app->settings()->str('returns.reasons')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $pos = strpos($line, '|');
            $code = $pos === false ? '' : strtolower(trim(substr($line, 0, $pos)));
            $label = trim($pos === false ? $line : substr($line, $pos + 1));
            if ($pos === false) {
                $code = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
            }
            if ($label === '' || preg_match('/^[a-z0-9_]{1,40}$/D', $code) !== 1 || $code === self::WITHDRAWAL) {
                continue;
            }
            $out[$code] = mb_substr($label, 0, 120, 'UTF-8');
        }
        if ($out === []) {
            $out = [
                'not_fit' => 'It does not fit or I do not like it',
                self::DEFECTIVE => 'Defective or damaged',
                'not_expected' => 'It is not what I expected',
                'late' => 'It arrived too late',
                'other' => 'Other reason',
            ];
        }

        return $out;
    }

    /**
     * El texto de un motivo ya traducido: el de serie (en inglés en el código) se traduce; el que escribió el comerciante, tal cual.
     */
    public function reasonLabel(string $code): string
    {
        if ($code === self::WITHDRAWAL) {
            return t('Withdrawal');
        }
        $reasons = $this->reasons();

        return isset($reasons[$code]) ? t($reasons[$code]) : $code;
    }

    /**
     * Qué se puede devolver de un pedido y hasta cuándo. Nunca lanza: un pedido que no existe da ok = false.
     *
     * @return array{ok:bool,code:string,message:string,order_id:int,number:string,start:?string,return_until:?string,withdrawal_until:?string,return_open:bool,withdrawal_open:bool,unshipped:bool,lines:array<int,array<string,mixed>>,open_return:?array<string,mixed>}
     *         code: ok, unknown_order, not_paid, cancelled, expired, nothing_left u open_return. Un pedido pagado sin enviar
     *         (unshipped) admite el desistimiento, sin fechas, y no la devolución.
     */
    public function eligibility(int $orderId): array
    {
        $out = [
            'ok' => false, 'code' => 'unknown_order', 'message' => t('We could not find that order.'), 'order_id' => $orderId, 'number' => '',
            'start' => null, 'return_until' => null, 'withdrawal_until' => null, 'return_open' => false, 'withdrawal_open' => false,
            'lines' => [], 'open_return' => null, 'unshipped' => false,
        ];
        $order = $orderId > 0 && $this->app->has('orders') ? $this->app->service('orders')->find($orderId) : null;
        if ($order === null) {
            return $out;
        }
        $out['order_id'] = (int) $order['id'];
        $out['number'] = (string) $order['number'];

        $status = (string) $order['status'];
        $payment = (string) $order['payment_status'];
        if (in_array($status, ['cancelled', 'refunded'], true) || $payment === 'refunded' || $payment === 'cancelled') {
            return $this->stop($out, 'cancelled', t('This order was cancelled or has already been refunded.'));
        }
        if (!in_array($payment, ['paid', 'partially_refunded'], true) || in_array($status, ['failed', 'pending_payment'], true)) {
            return $this->stop($out, 'not_paid', t('This order has not been paid yet.'));
        }

        // La referencia de los plazos: la entrega o, sin ella, el envío más el tránsito.
        $delivered = Clock::parse(isset($order['completed_at']) ? (string) $order['completed_at'] : null);
        $shipped = Clock::parse(isset($order['shipped_at']) ? (string) $order['shipped_at'] : null);
        $start = $delivered > 0 ? $delivered : ($shipped > 0 ? $shipped + $this->transitDays() * 86400 : 0);
        $clock = $this->app->clock();
        $now = $clock->now();
        if ($start < 1) {
            // Un pedido pagado y aún sin enviar: el derecho de desistimiento existe desde que se hizo el pedido y su plazo
            // todavía no ha empezado a correr (empieza con la entrega), así que se puede pedir ya. La devolución de
            // siempre, no: esa es cuando llegue. La solicitud queda para que la tienda la atienda (parar el envío o
            // recoger y reembolsar).
            $out['unshipped'] = true;
            $out['return_open'] = false;
            $out['withdrawal_open'] = true;
        } else {
            $returnUntil = $start + $this->days() * 86400;
            $withdrawalUntil = $start + $this->withdrawalDays() * 86400;
            $out['start'] = $clock->sql($start);
            $out['return_until'] = $clock->sql($returnUntil);
            $out['withdrawal_until'] = $clock->sql($withdrawalUntil);
            $out['return_open'] = $now <= $returnUntil;
            $out['withdrawal_open'] = $now <= $withdrawalUntil;
        }

        $out['lines'] = $this->lines($order, $out['return_open'], $out['withdrawal_open']);
        $open = $this->app->db()->row("SELECT id, number, status FROM {returns} WHERE order_id = ? AND status IN ('requested', 'approved', 'received') ORDER BY id DESC", [(int) $order['id']]);
        $out['open_return'] = $open === null ? null : ['id' => (int) $open['id'], 'number' => (string) $open['number'], 'status' => (string) $open['status']];

        if (!$out['return_open'] && !$out['withdrawal_open']) {
            return $this->stop($out, 'expired', t('The period to return this order has ended.'));
        }
        $selectable = 0;
        foreach ($out['lines'] as $line) {
            $selectable += !empty($line['selectable']) ? 1 : 0;
        }
        if ($selectable < 1) {
            // Con el porqué (14/11/2026: «No queda nada por devolver» sobre un pedido recién completado, sin más, y la clienta
            // no sabía si era un fallo).
            return $this->stop($out, 'nothing_left', $this->nothingLeftReason($out['lines'], $out['return_open']));
        }
        if ($out['open_return'] !== null) {
            return $this->stop($out, 'open_return', t('This order already has a return in progress.'));
        }
        $out['ok'] = true;
        $out['code'] = 'ok';
        $out['message'] = '';

        return $out;
    }

    /**
     * Por qué no hay nada que devolver: solo descargas o servicios, todo devuelto ya, o productos excluidos del desistimiento
     * con la devolución ya cerrada.
     *
     * @param array<int,array<string,mixed>> $lines
     */
    private function nothingLeftReason(array $lines, bool $returnOpen): string
    {
        if ($lines === []) {
            return t('This order only has digital products or services: they are not sent back by post. If something is wrong with them, write to us and we will sort it out.');
        }
        $available = 0;
        foreach ($lines as $line) {
            $available += (int) $line['available'];
        }
        if ($available < 1) {
            return t('Everything in this order has already been returned or refunded.');
        }
        if (!$returnOpen) {
            return t('The period to return this order has ended, and its products are excluded from the right of withdrawal (personalised, perishable or sealed for hygiene).');
        }

        return t('There is nothing left to return in this order.');
    }

    /**
     * @param array<string,mixed> $out
     *
     * @return array<string,mixed>
     */
    private function stop(array $out, string $code, string $message): array
    {
        $out['ok'] = false;
        $out['code'] = $code;
        $out['message'] = $message;

        return $out;
    }

    /**
     * Las líneas de producto físicas del pedido con lo que se puede devolver de cada una.
     *
     * @param array<string,mixed> $order
     *
     * @return array<int,array<string,mixed>>
     */
    private function lines(array $order, bool $returnOpen, bool $withdrawalOpen): array
    {
        $lines = [];
        foreach (isset($order['items']) && is_array($order['items']) ? $order['items'] : [] as $item) {
            if ((string) $item['kind'] !== 'product' || !empty($item['is_digital'])) {
                continue;
            }
            $available = max(0, (int) $item['qty'] - (int) $item['refunded_qty']);
            $excluded = $this->excluded($item);
            $lines[] = [
                'id' => (int) $item['id'],
                'name' => (string) $item['name'],
                'variant_label' => (string) ($item['variant_label'] !== null ? $item['variant_label'] : ''),
                'sku' => (string) ($item['sku'] !== null ? $item['sku'] : ''),
                'product_id' => $item['product_id'] !== null ? (int) $item['product_id'] : null,
                'variant_id' => $item['variant_id'] !== null ? (int) $item['variant_id'] : null,
                'qty' => (int) $item['qty'],
                'returned' => (int) $item['refunded_qty'],
                'available' => $available,
                'withdrawal_excluded' => $excluded,
                'excluded_reason' => $excluded ? t('This product is excluded from the right of withdrawal (personalised, perishable or sealed for hygiene).') : '',
                'return_ok' => $available > 0 && $returnOpen,
                'withdrawal_ok' => $available > 0 && $withdrawalOpen && !$excluded,
                'selectable' => $available > 0 && ($returnOpen || ($withdrawalOpen && !$excluded)),
            ];
        }

        return $lines;
    }

    /**
     * ¿Está esta línea excluida del desistimiento por la ley? Por la marca «no_withdrawal» del producto en el catálogo (dato
     * extra del producto) o porque el producto está en una de las categorías excluidas de los ajustes.
     *
     * @param array<string,mixed> $item
     */
    private function excluded(array $item): bool
    {
        if ($item['product_id'] === null || !$this->app->has('catalog')) {
            return false;
        }
        $product = $this->app->service('catalog')->product((int) $item['product_id'], null, true);
        if ($product === null) {
            return false;
        }
        $extra = isset($product['extra']) && is_array($product['extra']) ? $product['extra'] : [];
        if (isset($extra['no_withdrawal']) && is_array($extra['no_withdrawal']) && in_array($extra['no_withdrawal']['value'], [true, 1, '1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        $blocked = [];
        foreach ($this->app->settings()->arr('returns.excluded_categories') as $id) {
            if (is_scalar($id) && (int) $id > 0) {
                $blocked[(int) $id] = true;
            }
        }
        foreach (isset($product['categories']) && is_array($product['categories']) ? $product['categories'] : [] as $category) {
            if (isset($category['id']) && isset($blocked[(int) $category['id']])) {
                return true;
            }
        }

        return false;
    }
}

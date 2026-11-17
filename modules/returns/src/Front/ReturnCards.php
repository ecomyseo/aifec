<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Returns\Front;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Modules\Returns\ReturnLabels;
use Aifec\Modules\Returns\Returns;

/**
 * Las tarjetas «Devoluciones» de la tienda: la de la página del pedido (hueco «order.thanks») con el estado de sus devoluciones y el
 * botón de pedir una, y la de la zona de cliente (hueco «account.dashboard») con sus últimas devoluciones y los pedidos de los que
 * todavía puede pedir una. Son privadas: nada de esto va en una página que se guarde en la caché.
 */
final class ReturnCards
{
    /**
     * @param array<string,mixed> $order el pedido (Orders::findBySecret)
     */
    public static function forOrder(App $app, array $order): string
    {
        $returns = self::returns($app);
        $secret = $app->service('orders')->viewTokenOf((string) $order['number']);
        $eligibility = $returns->eligibility((int) $order['id']);
        $rows = self::rows($app, $returns->forOrder((int) $order['id']));
        $url = $secret !== '' ? route('returns.order', ['number' => (string) $order['number'], 'secret' => $secret]) : '';
        $canAsk = !empty($eligibility['ok']) && $url !== '';
        // Un pedido pagado del que no se puede pedir nada dice por qué (antes la tarjeta desaparecía y no había forma de saberlo).
        $notice = !$canAsk && in_array((string) $eligibility['code'], ['nothing_left', 'expired', 'open_return'], true) ? (string) $eligibility['message'] : '';
        if ($rows === [] && !$canAsk && $notice === '') {
            return '';
        }

        return $app->view()->render('returns::front/card', ['rows' => $rows, 'url' => $canAsk || $rows !== [] ? $url : '', 'canAsk' => $canAsk, 'unshipped' => !empty($eligibility['unshipped']), 'orders' => [], 'notice' => $notice]);
    }

    /**
     * @param array<string,mixed> $customer el cliente que ha entrado
     */
    public static function forCustomer(App $app, array $customer): string
    {
        $returns = self::returns($app);
        $db = $app->db();
        $rows = [];
        foreach ($db->select('SELECT id FROM {returns} WHERE customer_id = ? ORDER BY id DESC LIMIT 5', [(int) $customer['id']]) as $row) {
            $return = $returns->find((int) $row['id']);
            if ($return !== null) {
                $rows[] = $return;
            }
        }
        $orders = [];
        // Los pagados y sin cancelar: uno sin enviar también admite el desistimiento (la política dice qué se puede pedir).
        $sql = "SELECT id, number FROM {orders} WHERE customer_id = ? AND payment_status IN ('paid', 'partially_refunded') AND status NOT IN ('cancelled', 'refunded', 'failed', 'pending_payment') ORDER BY placed_at DESC, id DESC LIMIT 5";
        foreach ($db->select($sql, [(int) $customer['id']]) as $order) {
            $eligibility = $returns->eligibility((int) $order['id']);
            if (!empty($eligibility['ok'])) {
                $orders[] = ['number' => (string) $order['number'], 'url' => route('returns.account', ['number' => (string) $order['number']])];
            }
        }
        $list = self::rows($app, $rows);
        if ($list === [] && $orders === []) {
            return '';
        }

        return $app->view()->render('returns::front/card', ['rows' => $list, 'url' => '', 'canAsk' => false, 'unshipped' => false, 'orders' => $orders]);
    }

    /**
     * @param array<int,array<string,mixed>> $returns
     *
     * @return array<int,array<string,string>>
     */
    private static function rows(App $app, array $returns): array
    {
        $out = [];
        foreach ($returns as $return) {
            $code = (string) $return['status'];
            $kind = ReturnLabels::kind($code);
            $time = Clock::parse((string) $return['requested_at']);
            $out[] = [
                'number' => (string) $return['number'],
                'type' => ReturnLabels::type((string) $return['kind']),
                'status' => ReturnLabels::status($code),
                'class' => $kind === 'ok' ? 'af-badge af-badge-new' : ($kind === 'bad' ? 'af-badge af-badge-out' : 'af-badge'),
                'text' => ReturnLabels::customerText($code),
                'date' => $time > 0 ? $app->i18n()->date($time, 'medium', $app->site()->timezone()) : '',
            ];
        }

        return $out;
    }

    private static function returns(App $app): Returns
    {
        /** @var Returns $returns */
        $returns = $app->service('returns');

        return $returns;
    }
}

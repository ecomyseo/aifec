<?php
/**
 * AIFEC — complementos del catálogo y reposición solicitada por el cliente.
 * @license Ver LICENSE.md
 */
declare(strict_types=1);

namespace Aifec\Modules\Assistant;

use Aifec\Kernel\App;

final class PurchaseSuggestions
{
    public static function repeatRequest(string $message): bool
    {
        return preg_match('/\b(?:volver a comprar|repetir (?:mi |el |un )?pedido|que (?:me toca|puedo|debo) reponer|comprar de nuevo|buy again|reorder|repeat my order|what should i replenish)\b/', RuleBrain::norm($message)) === 1;
    }

    public static function repeatOrder(App $app): Answer
    {
        $answer = new Answer();
        $account = $app->has('customers') ? $app->service('customers')->current() : null;
        if (!$account || !empty($account['guest'])) {
            $answer->text = t('Sign in to see items you can buy again.');
            return $answer;
        }
        $answer->actions[] = ['type' => 'private_turn'];
        $answer->text = t('None of your purchased items are due for replenishment.');
        // La última compra válida de cada artículo manda, aunque una compra anterior ya esté vencida.
        $rows = $app->db()->select(
            'SELECT p.id, p.replenishment_days, MAX(o.placed_at) AS last_purchase'
            . ' FROM {orders} o JOIN {order_items} i ON i.order_id = o.id JOIN {products} p ON p.id = i.product_id'
            . " WHERE o.customer_id = ? AND o.payment_status = 'paid' AND o.test = 0 AND o.status NOT IN ('cancelled','refunded','failed')"
            . " AND i.kind = 'product' AND i.qty > i.refunded_qty AND p.replenishment_days > 0 AND p.status = 'published'"
            . ' GROUP BY p.id, p.replenishment_days ORDER BY last_purchase DESC, p.id',
            [(int) $account['id']]
        );
        $due = [];
        foreach ($rows as $row) {
            $threshold = $app->clock()->sql($app->clock()->now() - (int) $row['replenishment_days'] * 86400);
            if ((string) $row['last_purchase'] <= $threshold) {
                $due[] = (int) $row['id'];
            }
        }
        $shop = new ShopTools($app);
        foreach (array_chunk($due, 24) as $ids) {
            foreach ($shop->cardsFor($ids) as $item) {
                if (!empty($item['available'])) {
                    $answer->addCard($item);
                }
                if (count($answer->cards) >= 3) {
                    break 2;
                }
            }
        }
        if ($answer->cards !== []) {
            $answer->text = t('You bought these items before. Their replenishment interval has passed. Would you like to buy any of them again?');
        }
        return $answer;
    }

    /** Un complemento comprable por alta de producto. Los vínculos los elige el comerciante. */
    public static function afterAdd(App $app, Answer $answer, array &$state): void
    {
        if (!$answer->cartAdded || !$app->settings()->bool('assistant.cross_sell', false) || count($answer->cards) >= Answer::MAX_CARDS) {
            return;
        }
        $already = $state['cross_sell_shown'] ?? [];
        $inCart = array_map('intval', array_column($app->service('cart')->summary()['lines'], 'product_id'));
        foreach ($answer->toolLog as $action) {
            $parent = (int) ($action['input']['product_id'] ?? 0);
            if ($action['name'] !== 'cart_add' || empty($action['ok']) || !$parent || in_array($parent, $already, true)) {
                continue;
            }
            $rows = $app->db()->select("SELECT related_id FROM {product_relations} WHERE product_id = ? AND kind = 'cross' ORDER BY position, related_id", [$parent]);
            $ids = array_diff(array_column($rows, 'related_id'), $inCart, array_column($answer->cards, 'id'), [$parent]);
            foreach ((new ShopTools($app))->cardsFor($ids) as $item) {
                if (empty($item['available'])) {
                    continue;
                }
                $answer->addCard($item);
                $answer->text .= "\n" . t('The shop recommends {name} as a companion to the item you added.', ['name' => $item['name']]);
                $state['cross_sell_shown'] = array_merge($already, [$parent]);
                return;
            }
        }
    }
}

<?php
/** AIFEC — conversaciones iniciadas en los últimos siete días y sus pedidos pagados. */
declare(strict_types=1);
namespace Aifec\Modules\Assistant;

use Aifec\Kernel\App;

final class CommerceReport
{
    public static function week(App $app): array
    {
        $since = $app->clock()->sql($app->clock()->now() - 7 * 86400);
        $db = $app->db();
        $row = $db->row('SELECT COUNT(*) AS total, COUNT(vote) AS votes, SUM(CASE WHEN vote = 1 THEN 1 ELSE 0 END) AS positive, AVG(ai_cost_micro) AS average_cost, SUM(ai_unpriced) AS unpriced FROM {conversations} WHERE started_at >= ?', [$since]);
        $paid = " FROM {assistant_orders} a JOIN {conversations} c ON c.id = a.conversation_id JOIN {orders} o ON o.id = a.order_id WHERE c.started_at >= ? AND o.test = 0 AND o.payment_status IN ('paid','partially_refunded') AND o.status NOT IN ('cancelled','failed','refunded')";
        $row['converted'] = $app->has('orders') ? (int) $db->value('SELECT COUNT(DISTINCT c.id)' . $paid, [$since]) : 0;
        $row['revenue'] = [];
        if ($app->has('orders')) {
            foreach ($db->select('SELECT o.currency, SUM(o.total - o.refunded_total) AS amount' . $paid . ' GROUP BY o.currency ORDER BY o.currency', [$since]) as $sum) {
                $row['revenue'][] = $app->i18n()->money((int) $sum['amount'], $sum['currency']);
            }
        }
        $row['cost'] = $row['average_cost'] === null ? t('No measurements yet') : \Aifec\Modules\Ai\Usage::amount($app, (int) round((float) $row['average_cost']));
        if ($row['unpriced'] > 0) { $row['cost'] = t('Some provider prices are missing'); }
        return $row;
    }
}

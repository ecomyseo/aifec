<?php
/** AIFEC — seguimiento y fechas de los pedidos de la cuenta que tiene la sesión. */
declare(strict_types=1);

namespace Aifec\Modules\Assistant;

use Aifec\Kernel\App;

final class AccountOrders
{
    public static function answer(App $app, string $message): ?Answer
    {
        $n = RuleBrain::norm($message);
        if (!preg_match('/^(?:ver |muestrame |ensename |donde esta |estado de |show |show me |where is |track )?(?:mis pedidos|mi ultimo pedido|mi pedido|my orders|my latest order|my order)(?:$| (?:del?|entre|desde|de esta|de la|esta|ayer|hoy|from|between|last|this|today|yesterday)\b)/', $n)) {
            return null;
        }
        $account = $app->has('customers') ? $app->service('customers')->current() : null;
        if (!$account || !empty($account['guest']) || !$app->has('orders')) { return null; }
        $answer = new Answer();
        $answer->actions[] = ['type' => 'private_turn'];
        $zone = new \DateTimeZone($app->site()->timezone());
        $today = (new \DateTimeImmutable('@' . $app->clock()->now()))->setTimezone($zone)->setTime(0, 0);
        $from = $until = null;
        if (preg_match_all('/\b(\d{4}-\d{2}-\d{2})\b/', $message, $dates)) {
            $parsed = [];
            foreach ($dates[1] as $date) {
                $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
                if (!$day || $day->format('Y-m-d') !== $date) { break; }
                $parsed[] = $day;
            }
            if (count($parsed) !== count($dates[1]) || count($parsed) > 2 || end($parsed) < $parsed[0]) {
                $answer->text = t('Write the dates as YYYY-MM-DD in chronological order.');
                return $answer;
            }
            $from = $parsed[0];
            $until = end($parsed)->modify('+1 day');
        } elseif (preg_match('/\b(?:hoy|today|ayer|yesterday)\b/', $n, $m)) {
            $from = in_array($m[0], ['ayer', 'yesterday'], true) ? $today->modify('-1 day') : $today;
            $until = $from->modify('+1 day');
        } elseif (preg_match('/\b(?:semana|week|mes|month)\b/', $n, $m)) {
            $week = in_array($m[0], ['semana', 'week'], true);
            $from = $today->modify($week ? 'monday this week' : 'first day of this month');
            $period = $week ? 'week' : 'month';
            if (preg_match('/\b(?:pasad[oa]|last)\b/', $n)) { $from = $from->modify('-1 ' . $period); }
            $until = $from->modify('+1 ' . $period);
        } elseif (preg_match('/\b(?:dias|days|entre|between|desde|from|del)\b/', $n)) {
            $answer->text = t('Which dates should I check? You can write both as YYYY-MM-DD.');
            return $answer;
        }
        $sql = 'SELECT number, email FROM {orders} WHERE customer_id = ? AND test = 0';
        $params = [(int) $account['id']];
        if ($from !== null) {
            $sql .= ' AND placed_at >= ? AND placed_at < ?';
            $params[] = $app->clock()->sql($from->getTimestamp());
            $params[] = $app->clock()->sql($until->getTimestamp());
        }
        $limit = preg_match('/\b(?:mis pedidos|my orders)\b/', $n) ? 5 : 1;
        $rows = $app->db()->select($sql . ' ORDER BY placed_at DESC, id DESC LIMIT ' . $limit, $params);
        $shop = new ShopTools($app);
        $lines = [];
        foreach ($rows as $row) {
            $order = $shop->orderStatus($row, []);
            if (isset($order['error'])) { continue; }
            $lines[] = $order['number'] . ' · ' . $order['placed_on'] . ' · ' . $order['status_label'];
            if (!empty($order['carrier'])) { $lines[] = t('Carrier: {name}', ['name' => $order['carrier']]); }
            if (!empty($order['tracking_code'])) { $lines[] = t('Tracking code: {code}', ['code' => $order['tracking_code']]); }
        }
        $answer->text = $lines ? implode("\n", $lines) : t('I cannot see any orders for your account for those dates.');
        return $answer;
    }
}

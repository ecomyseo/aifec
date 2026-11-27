<?php
/** AIFEC — propuestas revisables a partir de búsquedas y compras. Nunca modifica sinónimos. */
declare(strict_types=1);
namespace Aifec\Modules\Assistant;

use Aifec\Kernel\App;

final class SearchEvidence
{
    public static function capture(App $app, int $messageId, string $message, array $tools, string $lang): void
    {
        if (!$tools || !$app->has('search') || !$app->has('consent') || !$app->service('consent')->allows('statistics')) { return; }
        if (SensitiveQuestion::matches($message) || OrderPrestep::hasData($message) || Masker::forStorage($message) !== $message) { return; }
        $words = explode(' ', RuleBrain::norm($message));
        foreach ($tools as $tool) {
            $term = RuleBrain::norm((string) ($tool['input']['query'] ?? ''));
            if ($tool['name'] === 'search_products' && in_array($term, $words, true) && preg_match('/^[a-z]{5,28}$/D', $term)) {
                $app->db()->update('conversation_messages', ['learning_term' => $term, 'learning_lang' => $lang], ['id' => $messageId, 'role' => 'user']);
                return;
            }
        }
    }

    public static function suggestions(App $app): array
    {
        if (!$app->has('orders') || !$app->has('search')) { return []; }
        $rows = $app->db()->select("SELECT DISTINCT o.id, m.learning_term, m.learning_lang, i.name FROM {assistant_orders} a
            JOIN {orders} o ON o.id = a.order_id JOIN {order_items} i ON i.order_id = o.id
            JOIN {conversation_messages} m ON m.conversation_id = a.conversation_id
            WHERE m.learning_term IS NOT NULL AND m.created_at >= ? AND m.created_at <= o.placed_at
            AND o.test = 0 AND o.payment_status IN ('paid','partially_refunded') AND o.status NOT IN ('cancelled','failed','refunded')
            AND i.kind = 'product' AND i.qty > i.refunded_qty ORDER BY o.id DESC LIMIT 400",
            [$app->clock()->sql($app->clock()->now() - 7 * 86400)]);
        $groups = [];
        foreach ($rows as $row) {
            $source = $row['learning_term'];
            $words = array_unique(explode(' ', RuleBrain::norm($row['name'])));
            if (in_array($source, $words, true)) { continue; }
            $close = array_values(array_filter($words, static function ($word) use ($source): bool {
                return strlen($word) >= 5 && levenshtein($source, $word) === 1;
            }));
            if (count($close) !== 1) { continue; }
            $key = $row['learning_lang'] . ':' . $source . ':' . $close[0];
            $groups[$key]['source'] = $source;
            $groups[$key]['terms'] = $close[0];
            $groups[$key]['lang'] = $row['learning_lang'];
            $groups[$key]['orders'][$row['id']] = true;
        }
        foreach ($groups as &$group) {
            $group['count'] = count($group['orders']);
            unset($group['orders']);
        }
        unset($group);
        usort($groups, static function ($a, $b): int { return $b['count'] <=> $a['count'] ?: strcmp($a['source'], $b['source']); });
        return array_slice($groups, 0, 20);
    }
}

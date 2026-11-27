<?php
/**
 * AIFEC — muestra rebajas del catálogo y cupones que el comercio ha autorizado.
 * @author Ecom Experts
 * @license Ver LICENSE.md
 */
declare(strict_types=1);

namespace Aifec\Modules\Assistant;

use Aifec\Kernel\App;

final class MerchantOffers
{
    public static function requested(string $message): bool
    {
        $normal = RuleBrain::norm($message);
        if (preg_match('/\b(?:devolver|devolucion|return|refund|aplica|aplicar|apply|quita|remove)\b/', $normal) === 1) {
            return false;
        }
        return preg_match('/\b(?:black friday|cyber monday|ofertas?|rebajas|promociones|cupon(?:es)?|codigo (?:de |promocional)|hay (?:algun |un )?codigo|promo code|coupon|special offers|on sale|any discounts|any deals)\b/', RuleBrain::norm($message)) === 1;
    }

    public static function answer(App $app): Answer
    {
        $answer = new Answer();
        $listing = $app->service('catalog')->listing(['lang' => $app->lang(), 'on_sale' => true, 'per_page' => Answer::MAX_CARDS]);
        $ids = array_column($listing['items'] ?? [], 'id');
        $shop = new ShopTools($app);
        foreach ($shop->cardsFor($ids) as $item) {
            if ((int) ($item['compare_price_cents'] ?? 0) > $item['price_cents']) {
                $answer->addCard($item);
            }
        }
        $lines = [$answer->cards ? t('These products are currently reduced.') : t('There are no reduced products to show right now.')];
        $codes = self::visibleCodes($app);
        if ($codes !== []) {
            $lines[] = t('Available coupon codes: {codes}. Conditions are checked in the cart.', ['codes' => implode(', ', $codes)]);
        }
        $answer->text = implode("\n", $lines);
        return $answer;
    }

    private static function visibleCodes(App $app): array
    {
        if (!$app->has('coupons')) {
            return [];
        }
        $customer = $app->has('customers') ? $app->service('customers')->current() : null;
        $id = (int) ($customer['id'] ?? 0);
        $allowed = preg_split('/[\s,;]+/', strtoupper($app->settings()->str('assistant.public_coupons')), 51, PREG_SPLIT_NO_EMPTY) ?: [];
        $allowed = array_values(array_unique(array_slice($allowed, 0, 50)));
        $clauses = [];
        $params = [];
        if ($allowed !== []) {
            $clauses[] = '(customer_id IS NULL AND code IN (' . implode(',', array_fill(0, count($allowed), '?')) . '))';
            $params = $allowed;
        }
        if ($id > 0) {
            $clauses[] = 'customer_id = ?';
            $params[] = $id;
        }
        if ($clauses === []) {
            return [];
        }
        $now = $app->clock()->sql();
        $rows = $app->db()->select('SELECT code FROM {coupons} WHERE active = 1 AND (starts_at IS NULL OR starts_at <= ?)'
            . ' AND (ends_at IS NULL OR ends_at >= ?) AND (max_uses = 0 OR uses < max_uses) AND ('
            . implode(' OR ', $clauses) . ') ORDER BY code LIMIT 10', array_merge([$now, $now], $params));
        return array_column($rows, 'code');
    }
}

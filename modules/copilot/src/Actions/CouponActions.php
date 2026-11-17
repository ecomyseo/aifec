<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot\Actions;

use Aifec\Kernel\App;
use Aifec\Modules\Copilot\Fmt;
use Aifec\Modules\Copilot\Params;
use Aifec\Modules\Copilot\Reader;

/**
 * Crear un cupón: código, porcentaje o importe, caducidad y número de usos. Permiso «coupons.edit».
 *
 * Deshacer no borra el cupón: lo desactiva mientras nadie lo haya usado ni tocado.
 */
final class CouponActions
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public static function definitions(App $app): array
    {
        return [[
            'id' => 'coupons.create', 'module' => 'coupons', 'permission' => 'coupons.edit',
            'label' => 'Create a coupon',
            'description' => 'Creates a discount coupon with a code and either a percentage or a fixed amount, with an optional last day and a maximum number of uses.',
            'params' => [
                ['name' => 'code', 'type' => 'string', 'required' => true, 'description' => 'The coupon code (letters, numbers, dash and underscore)'],
                ['name' => 'percent', 'type' => 'percent', 'description' => 'Percentage discount, between 0.01 and 100'],
                ['name' => 'amount', 'type' => 'money', 'description' => 'Fixed discount amount'],
                ['name' => 'ends', 'type' => 'date', 'description' => 'Last day it can be used'],
                ['name' => 'uses', 'type' => 'int', 'description' => 'Maximum number of uses in total'],
            ],
            'one_of' => [['percent', 'amount']],
            'preview' => [self::class, 'preview'], 'apply' => [self::class, 'apply'], 'undo' => [self::class, 'undo'],
            'understand' => [self::class, 'read'],
            'examples' => ['Create the coupon SUMMER10 with 10 % off until 2026-12-31'],
        ]];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function read(App $app, string $raw, string $norm): ?array
    {
        if (!Reader::has($norm, 'cupon|cupones|coupon|coupons|codigo descuento|codigo de descuento|discount code')
            || !Reader::has($norm, 'crea|crear|creame|genera|generar|nuevo|nueva|haz|hazme|anade|anadir|create|make|new|generate|add')) {
            return null;
        }
        $args = [];
        $fold = Reader::fold($raw);
        $code = null;
        if (preg_match_all('/(?<![A-Za-z0-9_-])([A-Z0-9][A-Z0-9_-]{2,39})(?![A-Za-z0-9_-])/', $raw, $all) > 0) {
            foreach ($all[1] as $candidate) {
                if (preg_match('/[A-Z]/', $candidate) === 1 && preg_match('/^\d{1,2}$/', $candidate) !== 1) {
                    $code = $candidate;
                    break;
                }
            }
        }
        if ($code === null) {
            $code = Reader::capture($raw, $fold, '/(?:codigo|code|llamado|llamada|named|called)\s+["“«]?([A-Za-z0-9][A-Za-z0-9_-]{2,39})/i');
        }
        if ($code !== null && $code !== '') {
            $args['code'] = $code;
        }
        list($basis, $work) = Reader::takePercent($norm);
        if ($basis !== null) {
            $args['percent'] = abs($basis);
        } else {
            list($money) = Reader::takeMoney($work);
            if ($money !== null) {
                $args['amount'] = abs($money);
            }
        }
        $date = null;
        if (Reader::has($norm, 'hasta|until|expira|expires|caduca|vence|valid until|ends|termina|before|antes')) {
            $date = Reader::date($raw);
        }
        if ($date !== null) {
            $args['ends'] = $date;
        }
        if (preg_match('/(\d+)\s*(?:usos|uso|uses|use|veces|times)/', $norm, $m) === 1) {
            $args['uses'] = (int) $m[1];
        } elseif (preg_match('/(?:limite|limit|maximo|max)\s*(?:de\s*)?(\d+)/', $norm, $m) === 1) {
            $args['uses'] = (int) $m[1];
        }

        return ['args' => $args, 'score' => 85 + (isset($args['code']) ? 5 : 0)];
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    private static function plan(App $app, array $args): array
    {
        $out = ['errors' => [], 'warnings' => [], 'code' => '', 'ends_at' => null];
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) $args['code']));
        $out['code'] = $code;
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/', $code) !== 1) {
            $out['errors'][] = t('The code has to have 3 to 40 letters or numbers (dash and underscore allowed).');
        } elseif ($app->db()->value('SELECT id FROM {coupons} WHERE code = ?', [$code]) !== null) {
            $out['errors'][] = t('There is already a coupon with the code {code}.', ['code' => $code]);
        }
        $percent = isset($args['percent']);
        if ($percent === isset($args['amount'])) {
            $out['errors'][] = t('Give either a percentage or an amount.');
        } elseif ($percent && ((int) $args['percent'] < 1 || (int) $args['percent'] > 10000)) {
            $out['errors'][] = t('The percentage has to be between 0.01 and 100.');
        } elseif (!$percent && (int) $args['amount'] <= 0) {
            $out['errors'][] = t('The amount has to be above zero.');
        }
        if (isset($args['uses']) && (int) $args['uses'] < 1) {
            $out['errors'][] = t('The uses have to be at least 1.');
        }
        if (isset($args['ends'])) {
            $timezone = $app->site()->timezone();
            try {
                $end = new \DateTimeImmutable((string) $args['ends'] . ' 23:59:59', new \DateTimeZone($timezone));
                if ($end->getTimestamp() < $app->clock()->now()) {
                    $out['errors'][] = t('That last day has already passed.');
                }
                $out['ends_at'] = $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                $out['errors'][] = t('The date is not valid.');
            }
        } else {
            $out['warnings'][] = t('The coupon does not expire.');
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function preview(App $app, array $args): array
    {
        $plan = self::plan($app, $args);
        $discount = isset($args['percent']) ? Params::toForm('percent', (int) $args['percent']) . ' %' : Fmt::money($app, (int) $args['amount']);
        $changes = [
            Fmt::change(t('Code'), '—', $plan['code']),
            Fmt::change(t('Discount'), '—', $discount),
            Fmt::change(t('Last day'), '—', isset($args['ends']) ? (string) $args['ends'] : t('No limit')),
            Fmt::change(t('Maximum uses'), '—', isset($args['uses']) ? (string) (int) $args['uses'] : t('No limit')),
        ];

        return [
            'summary' => t('Create the coupon {code} ({discount})', ['code' => $plan['code'], 'discount' => $discount]),
            'changes' => $changes, 'count' => 1, 'warnings' => $plan['warnings'], 'errors' => $plan['errors'],
        ];
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function apply(App $app, array $args): array
    {
        $plan = self::plan($app, $args);
        if ($plan['errors'] !== []) {
            return ['ok' => false, 'message' => (string) $plan['errors'][0], 'undo' => null];
        }
        $now = $app->clock()->sql();
        $percent = isset($args['percent']);
        $id = $app->db()->insert('coupons', [
            'code' => $plan['code'], 'kind' => $percent ? 'percent' : 'fixed',
            'percent' => $percent ? (int) $args['percent'] : 0, 'amount' => $percent ? 0 : (int) $args['amount'],
            'ends_at' => $plan['ends_at'], 'max_uses' => isset($args['uses']) ? (int) $args['uses'] : 0,
            'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $app->invalidate(['coupons']);

        return ['ok' => true, 'message' => t('Coupon {code} created.', ['code' => $plan['code']]), 'undo' => ['id' => $id, 'updated_at' => $now]];
    }

    /**
     * @param array<string,mixed> $undo
     *
     * @return array<string,mixed>
     */
    public static function undo(App $app, array $undo): array
    {
        $row = $app->db()->row('SELECT active, uses, updated_at FROM {coupons} WHERE id = ?', [(int) $undo['id']]);
        if ($row === null || (int) $row['active'] !== 1 || (int) $row['uses'] > 0 || (string) $row['updated_at'] !== (string) $undo['updated_at']) {
            return ['ok' => false, 'message' => t('The coupon has been used or changed since. It is not undone so that nothing else is lost.')];
        }
        $app->db()->update('coupons', ['active' => 0, 'updated_at' => $app->clock()->sql()], ['id' => (int) $undo['id'], 'active' => 1]);
        $app->invalidate(['coupons']);

        return ['ok' => true, 'message' => t('The coupon was deactivated.')];
    }
}

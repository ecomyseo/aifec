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
use Aifec\Modules\Copilot\Finder;
use Aifec\Modules\Copilot\Fmt;
use Aifec\Modules\Copilot\Phrases;
use Aifec\Modules\Copilot\Reader;

/**
 * Las acciones de envío: el precio de una tarifa de un transportista y el importe a partir del cual el envío sale gratis.
 * Permiso «shipping.edit». Un precio nunca queda en cero ni en negativo (para un envío gratis está el importe de envío gratis).
 */
final class ShippingActions
{
    /** Un precio no pasa de esto (la columna es un entero de 32 bits). */
    private const PRICE_MAX = 2000000000;

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function definitions(App $app): array
    {
        return [
            [
                'id' => 'shipping.rate', 'module' => 'shipping', 'permission' => 'shipping.edit',
                'label' => 'Change the price of a shipping rate',
                'description' => 'Changes the price of one rate of a carrier. If the carrier has several rates, the zone or the rate has to be chosen.',
                'params' => [
                    ['name' => 'carrier', 'type' => 'string', 'required' => true, 'description' => 'Carrier name or code'],
                    ['name' => 'zone', 'type' => 'string', 'description' => 'Shipping zone name'],
                    ['name' => 'rate', 'type' => 'string', 'description' => 'A rate chosen from the options the copilot offers'],
                    ['name' => 'price', 'type' => 'money', 'required' => true, 'description' => 'The new shipping price'],
                ],
                'preview' => [self::class, 'previewRate'], 'apply' => [self::class, 'applyRate'], 'undo' => [self::class, 'undoRate'],
                'understand' => [self::class, 'readRate'],
                'examples' => ['Set the GLS rate to 6.90'],
            ],
            [
                'id' => 'shipping.free_from', 'module' => 'shipping', 'permission' => 'shipping.edit',
                'label' => 'Set the free shipping amount',
                'description' => 'Sets the amount of items from which a carrier ships for free. An amount of 0 turns the free shipping off for that carrier.',
                'params' => [
                    ['name' => 'carrier', 'type' => 'string', 'required' => true, 'description' => 'Carrier name or code'],
                    ['name' => 'amount', 'type' => 'money', 'required' => true, 'description' => 'Free from this amount of items (0 = never free)'],
                ],
                'preview' => [self::class, 'previewFree'], 'apply' => [self::class, 'applyFree'], 'undo' => [self::class, 'undoFree'],
                'understand' => [self::class, 'readFree'],
                'examples' => ['Free shipping for orders over 50 € with GLS'],
            ],
        ];
    }

    // ------------------------------------------------------------------ entender

    /**
     * @return array<string,mixed>|null
     */
    public static function readRate(App $app, string $raw, string $norm): ?array
    {
        if (!Reader::has($norm, 'tarifa|tarifas|rate|rates|gastos de envio|coste de envio|costes de envio|precio de envio|precio del envio|shipping price|shipping cost|shipping rate|delivery price')
            || Reader::has($norm, 'gratis|free|gratuito')) {
            return null;
        }
        $work = $norm;
        list($money, $work) = Reader::takeAmountAfter($work);
        if ($money === null) {
            list($money, $work) = Reader::takeMoney($work);
        }
        $args = [];
        $carriers = Finder::carriersIn($app, $norm);
        if (count($carriers) === 1) {
            $args['carrier'] = (string) $carriers[0]['name'];
        }
        $zones = Finder::zonesIn($app, $norm);
        if (count($zones) === 1) {
            $args['zone'] = (string) $zones[0]['name'];
        }
        if ($money !== null) {
            $args['price'] = abs($money);
        }

        return ['args' => $args, 'score' => 80 + (isset($args['carrier']) ? 5 : 0) + (isset($args['price']) ? 5 : 0)];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function readFree(App $app, string $raw, string $norm): ?array
    {
        if (!Reader::has($norm, 'gratis|free|gratuito|gratuitos') || !Reader::has($norm, 'envio|envios|portes|shipping|delivery|transporte')
            || Reader::has($norm, Phrases::STOCK)) {
            return null;
        }
        $args = [];
        $off = Reader::has($norm, 'quita|quitar|elimina|eliminar|desactiva|desactivar|sin|no mas|remove|disable|turn off|stop|ya no');
        list($money) = Reader::takeMoney($norm);
        if ($money === null) {
            list($money) = Reader::takeAmountAfter($norm);
        }
        if ($money === null && preg_match('/(?:desde|a partir de|por encima de|superior a|mas de|over|above|from|minimum|minimo|de)\s+(\d[\d.,]*)/', $norm, $m) === 1) {
            $parsed = \Aifec\Validation\Validator::toCents(trim($m[1], '.,'));
            $money = $parsed;
        }
        if ($off && $money === null) {
            $args['amount'] = 0;
        } elseif ($money !== null) {
            $args['amount'] = abs($money);
        }
        $carriers = Finder::carriersIn($app, $norm);
        if (count($carriers) === 1) {
            $args['carrier'] = (string) $carriers[0]['name'];
        } elseif ($carriers === []) {
            $all = Finder::carriers($app, '');
            if (count($all) === 1) {
                $args['carrier'] = (string) $all[0]['name'];
            }
        }

        return ['args' => $args, 'score' => 82 + (isset($args['amount']) ? 5 : 0)];
    }

    // ------------------------------------------------------------------ una tarifa

    /**
     * @param array<string,mixed> $row zone, basis, min, max, price
     */
    private static function rateLabel(App $app, array $row): string
    {
        $zone = isset($row['zone']) && $row['zone'] !== null ? t((string) $row['zone']) : t('Any zone');
        if ((string) $row['basis'] === 'price') {
            $from = Fmt::money($app, (int) $row['min']);
            $to = (int) $row['max'] > 0 ? Fmt::money($app, (int) $row['max']) : t('and more');
        } else {
            $from = (string) (int) $row['min'] . ' g';
            $to = (int) $row['max'] > 0 ? (string) (int) $row['max'] . ' g' : t('and more');
        }

        return $zone . ' · ' . $from . ' – ' . $to . ' · ' . Fmt::money($app, (int) $row['price']);
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    private static function planRate(App $app, array $args): array
    {
        $out = ['errors' => [], 'choices' => [], 'item' => null, 'summary' => ''];
        $carriers = Finder::carriers($app, (string) $args['carrier']);
        if ($carriers === []) {
            $out['errors'][] = t('No carrier matches «{text}».', ['text' => (string) $args['carrier']]);

            return $out;
        }
        if (count($carriers) > 1) {
            $out['errors'][] = t('Several carriers match «{text}». Choose one.', ['text' => (string) $args['carrier']]);
            foreach ($carriers as $carrier) {
                $out['choices'][] = ['param' => 'carrier', 'value' => '#' . $carrier['id'], 'label' => (string) $carrier['name']];
            }

            return $out;
        }
        $carrier = $carriers[0];
        $rows = $app->db()->select(
            'SELECT r.id, r.zone_id, r.basis, r.min, r.max, r.price, z.name AS zone FROM {carrier_rates} r LEFT JOIN {shipping_zones} z ON z.id = r.zone_id WHERE r.carrier_id = ? ORDER BY r.zone_id, r.basis, r.min, r.id',
            [(int) $carrier['id']]
        );
        if (isset($args['rate']) && preg_match('/^#(\d+)$/', (string) $args['rate'], $m) === 1) {
            $rows = array_values(array_filter($rows, static function (array $row) use ($m): bool {
                return (int) $row['id'] === (int) $m[1];
            }));
        }
        if (isset($args['zone'])) {
            $wanted = Reader::norm((string) $args['zone']);
            $rows = array_values(array_filter($rows, static function (array $row) use ($wanted): bool {
                $name = (string) $row['zone'];

                return $wanted === '' || Reader::norm($name) === $wanted || Reader::norm(t($name)) === $wanted
                    || strpos(Reader::norm($name), $wanted) !== false || strpos(Reader::norm(t($name)), $wanted) !== false;
            }));
        }
        if ($rows === []) {
            $out['errors'][] = t('{name} has no rate that matches.', ['name' => (string) $carrier['name']]);

            return $out;
        }
        if (count($rows) > 1) {
            $out['errors'][] = t('{name} has several rates. Choose the one to change.', ['name' => (string) $carrier['name']]);
            foreach (array_slice($rows, 0, 12) as $row) {
                $out['choices'][] = ['param' => 'rate', 'value' => '#' . $row['id'], 'label' => self::rateLabel($app, $row)];
            }

            return $out;
        }
        $row = $rows[0];
        $price = (int) $args['price'];
        if ($price <= 0) {
            $out['errors'][] = t('The shipping price has to stay above zero. For free shipping, set the free shipping amount.');
        } elseif ($price > self::PRICE_MAX) {
            $out['errors'][] = t('The price is too high.');
        } elseif ($price === (int) $row['price']) {
            $out['errors'][] = t('Nothing to change: the rate is already that.');
        }
        $out['item'] = ['id' => (int) $row['id'], 'before' => (int) $row['price'], 'after' => $price, 'name' => (string) $carrier['name'] . ' · ' . self::rateLabel($app, $row)];
        $out['summary'] = t('Set a rate of {name} to {price}', ['name' => (string) $carrier['name'], 'price' => Fmt::money($app, $price)]);

        return $out;
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function previewRate(App $app, array $args): array
    {
        $plan = self::planRate($app, $args);
        $changes = [];
        if ($plan['item'] !== null) {
            $changes[] = Fmt::change($plan['item']['name'], Fmt::money($app, $plan['item']['before']), Fmt::money($app, $plan['item']['after']));
        }

        return [
            'summary' => $plan['summary'] !== '' ? $plan['summary'] : t('Change the price of a shipping rate'),
            'changes' => $changes, 'count' => $changes === [] ? 0 : 1, 'warnings' => [], 'errors' => $plan['errors'], 'choices' => $plan['choices'],
        ];
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function applyRate(App $app, array $args): array
    {
        $plan = self::planRate($app, $args);
        if ($plan['errors'] !== [] || $plan['choices'] !== [] || $plan['item'] === null) {
            return ['ok' => false, 'message' => $plan['errors'] !== [] ? (string) $plan['errors'][0] : t('Nothing to change.'), 'undo' => null];
        }
        $item = $plan['item'];
        if ($app->db()->update('carrier_rates', ['price' => $item['after']], ['id' => $item['id'], 'price' => $item['before']]) !== 1) {
            return ['ok' => false, 'message' => t('The rate changed while applying. Nothing was changed.'), 'undo' => null];
        }
        $app->invalidate(['shipping']);

        return ['ok' => true, 'message' => t('The rate is now {price}.', ['price' => Fmt::money($app, $item['after'])]), 'undo' => ['id' => $item['id'], 'before' => $item['before'], 'after' => $item['after']]];
    }

    /**
     * @param array<string,mixed> $undo
     *
     * @return array<string,mixed>
     */
    public static function undoRate(App $app, array $undo): array
    {
        $changed = $app->db()->update('carrier_rates', ['price' => (int) $undo['before']], ['id' => (int) $undo['id'], 'price' => (int) $undo['after']]);
        if ($changed !== 1) {
            return ['ok' => false, 'message' => t('The rate has changed since. It is not undone so that nothing else is lost.')];
        }
        $app->invalidate(['shipping']);

        return ['ok' => true, 'message' => t('The rate is back to {price}.', ['price' => Fmt::money($app, (int) $undo['before'])])];
    }

    // ------------------------------------------------------------------ envío gratis

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    private static function planFree(App $app, array $args): array
    {
        $out = ['errors' => [], 'choices' => [], 'item' => null, 'summary' => ''];
        $carriers = Finder::carriers($app, (string) $args['carrier']);
        if ($carriers === []) {
            $out['errors'][] = t('No carrier matches «{text}».', ['text' => (string) $args['carrier']]);

            return $out;
        }
        if (count($carriers) > 1) {
            $out['errors'][] = t('Several carriers match «{text}». Choose one.', ['text' => (string) $args['carrier']]);
            foreach ($carriers as $carrier) {
                $out['choices'][] = ['param' => 'carrier', 'value' => '#' . $carrier['id'], 'label' => (string) $carrier['name']];
            }

            return $out;
        }
        $carrier = $carriers[0];
        $amount = (int) $args['amount'];
        if ($amount < 0 || $amount > self::PRICE_MAX) {
            $out['errors'][] = t('The amount is not valid.');
        }
        $before = $carrier['free_from'] !== null && $carrier['free_from'] !== '' ? (int) $carrier['free_from'] : null;
        $after = $amount > 0 ? $amount : null;
        if ($before === $after && $out['errors'] === []) {
            $out['errors'][] = t('Nothing to change: it is already set like that.');
        }
        $out['item'] = ['id' => (int) $carrier['id'], 'name' => (string) $carrier['name'], 'before' => $before, 'after' => $after];
        $out['summary'] = $after === null
            ? t('Turn the free shipping off for {name}', ['name' => (string) $carrier['name']])
            : t('Free shipping with {name} from {amount}', ['name' => (string) $carrier['name'], 'amount' => Fmt::money($app, $after)]);

        return $out;
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function previewFree(App $app, array $args): array
    {
        $plan = self::planFree($app, $args);
        $changes = [];
        if ($plan['item'] !== null) {
            $item = $plan['item'];
            $changes[] = Fmt::change(t('Free shipping from') . ' · ' . $item['name'], $item['before'] === null ? t('Never') : Fmt::money($app, $item['before']), $item['after'] === null ? t('Never') : Fmt::money($app, $item['after']));
        }

        return [
            'summary' => $plan['summary'] !== '' ? $plan['summary'] : t('Set the free shipping amount'),
            'changes' => $changes, 'count' => $changes === [] ? 0 : 1, 'warnings' => [], 'errors' => $plan['errors'], 'choices' => $plan['choices'],
        ];
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function applyFree(App $app, array $args): array
    {
        $plan = self::planFree($app, $args);
        if ($plan['errors'] !== [] || $plan['choices'] !== [] || $plan['item'] === null) {
            return ['ok' => false, 'message' => $plan['errors'] !== [] ? (string) $plan['errors'][0] : t('Nothing to change.'), 'undo' => null];
        }
        $item = $plan['item'];
        $changed = $app->db()->update('carriers', ['free_from' => $item['after'], 'updated_at' => $app->clock()->sql()], ['id' => $item['id'], 'free_from' => $item['before']]);
        if ($changed !== 1) {
            return ['ok' => false, 'message' => t('The carrier changed while applying. Nothing was changed.'), 'undo' => null];
        }
        $app->invalidate(['shipping']);

        return ['ok' => true, 'message' => t('The free shipping amount of {name} is updated.', ['name' => $item['name']]), 'undo' => ['id' => $item['id'], 'before' => $item['before'], 'after' => $item['after']]];
    }

    /**
     * @param array<string,mixed> $undo
     *
     * @return array<string,mixed>
     */
    public static function undoFree(App $app, array $undo): array
    {
        $changed = $app->db()->update('carriers', ['free_from' => $undo['before'], 'updated_at' => $app->clock()->sql()], ['id' => (int) $undo['id'], 'free_from' => $undo['after']]);
        if ($changed !== 1) {
            return ['ok' => false, 'message' => t('The carrier has changed since. It is not undone so that nothing else is lost.')];
        }
        $app->invalidate(['shipping']);

        return ['ok' => true, 'message' => t('The free shipping amount is back as it was.')];
    }
}

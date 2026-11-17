<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Writers;

use Aifec\Commerce\Money;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\WriteContext;

/**
 * Los transportistas del origen con sus tarifas por zona y tramo.
 *
 * Manejador: «rates» (tabla de tarifas), «pickup» (recogida: el precio va en sus ajustes) y, para «gratis», el mismo «rates» con una
 * tarifa a 0 en cada zona. El precio de cada tarifa se escribe como los precios de aquí (con o sin impuestos). Aquí no existe una
 * «tarifa de gestión»: se suma a cada tarifa y se dice. Los tramos de importe y «gratis desde» se copian tal cual (son umbrales).
 * Un transportista de un tipo que aquí no existe entra inactivo, con una nota.
 */
final class CarriersWriter extends WriterBase
{
    public function type(): string
    {
        return 'carriers';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $found = $this->resolve('carriers', $record, $ctx, function (int $localId) use ($db): ?array {
            return $db->find('carriers', ['id' => $localId]);
        }, function () use ($record, $id): ?array {
            $params = [];
            $row = $this->db()->row('SELECT * FROM {carriers} WHERE ' . $this->sameText('name', (string) $record['name'], $params), $params);

            return $row !== null && !$this->claimed('carriers', (int) $row['id'], $id) ? $row : null;
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }
        $kind = (string) $record['kind'];
        $supported = in_array($kind, ['rates', 'pickup', 'free'], true);
        if (!$supported) {
            $ctx->note('carriers', $id, 'note', 'This kind of carrier does not exist here: it was brought switched off');
        }
        $classId = $this->taxClass($record, $ctx, $id);
        $includes = isset($record['price_includes_tax']) && is_bool($record['price_includes_tax']) ? $record['price_includes_tax'] : (bool) $ctx->option('prices_include_tax_here', true);
        $fee = isset($record['handling_fee']) && is_int($record['handling_fee']) ? max(0, $record['handling_fee']) : 0;
        if ($fee > 0 && $kind !== 'free') {
            $this->noteOnce($ctx, 'fee', 'There is no handling fee here: it was added to the price of each rate');
        }
        $feeMicros = isset($record['handling_fee_micros']) ? $this->sureMicros($record['handling_fee_micros'], $fee) : null;
        $rows = $this->rateRows($record, $kind, $includes, $classId, $fee, $feeMicros, $ctx, $id);

        $now = $this->now();
        $delay = $this->cut($this->mainText(isset($record['delay']) && is_array($record['delay']) ? $record['delay'] : [], $ctx), 191);
        $data = [
            'name' => $this->cut((string) $record['name'], 120), 'delay' => $delay !== '' ? $delay : null,
            'handler' => $kind === 'pickup' ? 'pickup' : 'rates', 'tax_class_id' => $classId,
            'free_from' => isset($record['free_from']) && is_int($record['free_from']) && $kind !== 'free' ? max(0, $record['free_from']) : null,
            'max_weight' => isset($record['max_weight']) && is_int($record['max_weight']) ? max(0, $record['max_weight']) : 0,
            'min_days' => isset($record['min_days']) && is_int($record['min_days']) ? $record['min_days'] : null,
            'max_days' => isset($record['max_days']) && is_int($record['max_days']) ? $record['max_days'] : null,
            'tracking_url' => $this->tracking(isset($record['tracking_url']) && is_string($record['tracking_url']) ? $record['tracking_url'] : ''),
            'active' => $supported && !(isset($record['active']) && $record['active'] === false) ? 1 : 0,
            'position' => isset($record['position']) ? (int) $record['position'] : 0, 'updated_at' => $now,
        ];
        $data['settings'] = $kind === 'pickup' ? json_encode(['price' => $this->pickupPrice($rows), 'address' => ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        if (isset($record['logo']) && is_array($record['logo'])) {
            $media = $ctx->image($record['logo'], 'carriers', $id);
            if ($media !== null) {
                $data['media_id'] = $media;
            }
        }
        if ($row === null) {
            $codes = array_map('strval', $db->column('SELECT code FROM {carriers}'));
            $data['code'] = $this->code((string) $record['name'], 'carrier', 40, $codes);
            $data['created_at'] = $now;
            $localId = $db->insert('carriers', $data);
        } else {
            $localId = (int) $row['id'];
            $db->update('carriers', $data, ['id' => $localId]);
            $db->delete('carrier_rates', ['carrier_id' => $localId]);
        }
        if ($kind !== 'pickup') {
            foreach ($rows as $rate) {
                $db->insert('carrier_rates', ['carrier_id' => $localId] + $rate);
            }
        }
        $ctx->remember('carriers', $id, $localId, Neutral::hash($record));

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    /**
     * La clase de impuesto del transportista: la pareja guardada o, si no hay, la equivalencia. Sin clase = la de los ajustes de impuestos.
     *
     * @param array<string,mixed> $record
     */
    private function taxClass(array $record, WriteContext $ctx, string $id): ?int
    {
        if (!isset($record['tax_class_id']) || !is_string($record['tax_class_id']) || $record['tax_class_id'] === '') {
            return null;
        }
        $local = $ctx->localId('tax_classes', $record['tax_class_id']);
        if ($local === null) {
            $code = $ctx->equivalent('tax_class', $record['tax_class_id']);
            $found = $code !== null ? $this->db()->value('SELECT id FROM {tax_classes} WHERE code = ?', [$code]) : null;
            $local = $found !== null ? (int) $found : null;
        }
        if ($local === null) {
            $ctx->note('carriers', $id, 'note', 'The tax class of this carrier was not brought: it uses the one in the tax settings');
        }

        return $local;
    }

    /**
     * Las filas de carrier_rates (sin el transportista), con los precios ya como se escriben aquí.
     *
     * @param array<string,mixed> $record
     *
     * @return array<int,array{zone_id:int,basis:string,min:int,max:int,price:int}>
     */
    private function rateRows(array $record, string $kind, bool $includes, ?int $classId, int $fee, ?int $feeMicros, WriteContext $ctx, string $id): array
    {
        $out = [];
        $missing = false;
        $rates = isset($record['rates']) && is_array($record['rates']) ? $record['rates'] : [];
        if ($kind === 'free') {
            $zones = [];
            foreach (isset($record['zone_ids']) && is_array($record['zone_ids']) ? $record['zone_ids'] : [] as $zoneSource) {
                $zones[] = is_scalar($zoneSource) ? (string) $zoneSource : '';
            }
            foreach ($rates as $rate) {
                if (is_array($rate) && isset($rate['zone_id']) && is_scalar($rate['zone_id'])) {
                    $zones[] = (string) $rate['zone_id'];
                }
            }
            foreach (array_values(array_unique($zones)) as $zoneSource) {
                $zone = $zoneSource !== '' ? $ctx->localId('shipping_zones', $zoneSource) : null;
                if ($zone === null) {
                    $missing = true;
                    continue;
                }
                $out[] = ['zone_id' => $zone, 'basis' => 'weight', 'min' => 0, 'max' => 0, 'price' => 0];
            }
        } else {
            foreach ($rates as $rate) {
                if (!is_array($rate) || !isset($rate['zone_id']) || !is_scalar($rate['zone_id'])) {
                    continue;
                }
                $zone = $ctx->localId('shipping_zones', (string) $rate['zone_id']);
                if ($zone === null) {
                    $missing = true;
                    continue;
                }
                $basis = isset($rate['basis']) ? (string) $rate['basis'] : 'weight';
                $min = $basis === 'flat' ? 0 : (isset($rate['min']) && is_int($rate['min']) ? max(0, $rate['min']) : 0);
                $max = $basis === 'flat' ? 0 : (isset($rate['max']) && is_int($rate['max']) ? max(0, $rate['max']) : 0);
                $cents = isset($rate['price']) && is_int($rate['price']) ? max(0, $rate['price']) : 0;
                // Tarifa y recargo se suman en millonésimas antes de convertir (si alguno de los dos las trae).
                $rateMicros = isset($rate['price_micros']) ? $this->sureMicros($rate['price_micros'], $cents) : null;
                $total = $rateMicros !== null || $feeMicros !== null
                    ? ($rateMicros !== null ? $rateMicros : $cents * Money::SCALE) + ($feeMicros !== null ? $feeMicros : $fee * Money::SCALE) : null;
                $out[] = [
                    'zone_id' => $zone, 'basis' => $basis === 'subtotal' ? 'price' : 'weight', 'min' => $min, 'max' => $max,
                    'price' => $this->price($cents + $fee, $includes, $classId, $ctx, $total),
                ];
            }
            if ($kind === 'pickup' && $out === [] && $fee > 0) {
                $out[] = ['zone_id' => 0, 'basis' => 'weight', 'min' => 0, 'max' => 0, 'price' => $this->price($fee, $includes, $classId, $ctx, $feeMicros)];
            }
        }
        if ($missing) {
            $ctx->note('carriers', $id, 'note', 'Some rates point to a zone that was not brought and were left out');
        }

        return $out;
    }

    /**
     * El precio de recogida: el más bajo de sus tarifas.
     *
     * @param array<int,array{zone_id:int,basis:string,min:int,max:int,price:int}> $rows
     */
    private function pickupPrice(array $rows): int
    {
        $best = null;
        foreach ($rows as $row) {
            if ($best === null || $row['price'] < $best) {
                $best = $row['price'];
            }
        }

        return $best !== null ? $best : 0;
    }

    /** La dirección de seguimiento con «{code}» donde va el código (PrestaShop lo escribe con «@»). */
    private function tracking(string $url): string
    {
        $url = trim($url);
        if ($url !== '' && strpos($url, '{code}') === false) {
            $url = str_replace('@', '{code}', $url);
        }

        return $this->cut($url, 255);
    }
}

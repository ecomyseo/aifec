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

use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\WriteContext;
use Aifec\Modules\Tax\Postcodes;

/**
 * Las zonas de envío del origen con sus localizaciones (país, región y códigos postales).
 *
 * Una zona del origen con el mismo nombre que una de aquí se adopta (no se duplica). Las cuatro zonas de España que siembra la
 * instalación y que nadie ha tocado ni usado se APAGAN al traer la primera zona del origen: si no, una dirección de Canarias
 * caería en la de serie (sin tarifas) y no en la del origen, y el envío saldría distinto.
 */
final class ShippingZonesWriter extends WriterBase
{
    /** Zonas de serie: nombre y códigos postales de su única localización (país ES). */
    private const SEED = ['Peninsula' => '', 'Balearic Islands' => '07', 'Canary Islands' => '35,38', 'Ceuta and Melilla' => '51,52'];

    public function type(): string
    {
        return 'shipping_zones';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $found = $this->resolve('shipping_zones', $record, $ctx, function (int $localId) use ($db): ?array {
            return $db->find('shipping_zones', ['id' => $localId]);
        }, function () use ($record, $id): ?array {
            $params = [];
            $row = $this->db()->row('SELECT * FROM {shipping_zones} WHERE ' . $this->sameText('name', (string) $record['name'], $params), $params);

            return $row !== null && !$this->claimed('shipping_zones', (int) $row['id'], $id) ? $row : null;
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }
        $data = [
            'name' => $this->cut((string) $record['name'], 120), 'active' => isset($record['active']) && $record['active'] === false ? 0 : 1,
            'position' => isset($record['position']) ? (int) $record['position'] : 0,
        ];
        if ($row === null) {
            $localId = $db->insert('shipping_zones', $data);
        } else {
            $localId = (int) $row['id'];
            $db->update('shipping_zones', $data, ['id' => $localId]);
            $db->delete('zone_locations', ['zone_id' => $localId]);
        }
        foreach (isset($record['locations']) && is_array($record['locations']) ? $record['locations'] : [] as $location) {
            $this->location($localId, is_array($location) ? $location : [], $ctx, $id);
        }
        $ctx->remember('shipping_zones', $id, $localId, Neutral::hash($record));
        $this->switchOffSeeds($ctx, $id);

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    /**
     * @param array<string,mixed> $location
     */
    private function location(int $zoneId, array $location, WriteContext $ctx, string $sourceId): void
    {
        $country = isset($location['country']) && is_string($location['country']) ? strtoupper($location['country']) : '';
        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
            return;
        }
        $postcodes = Postcodes::normalize(isset($location['postcodes']) && is_string($location['postcodes']) ? $location['postcodes'] : '');
        if ($postcodes === null) {
            // Quitar los códigos postales ensancharía la zona: mejor dejar fuera esa localización y decirlo.
            $ctx->note('shipping_zones', $sourceId, 'note', 'A place of the zone has postcodes that are not valid and was left out');

            return;
        }
        $this->db()->insert('zone_locations', [
            'zone_id' => $zoneId, 'country' => $country,
            'region' => $this->cut($this->region($country, isset($location['region']) && is_string($location['region']) ? $location['region'] : ''), 16),
            'postcodes' => $postcodes,
        ]);
    }

    /**
     * Apaga las zonas de serie intactas, sin tarifas y que no vienen del origen.
     */
    private function switchOffSeeds(WriteContext $ctx, string $sourceId): void
    {
        $db = $this->db();
        foreach (self::SEED as $name => $postcodes) {
            $zone = $db->row('SELECT * FROM {shipping_zones} WHERE name = ? AND active = 1', [$name]);
            if ($zone === null) {
                continue;
            }
            $zoneId = (int) $zone['id'];
            if ((int) $db->value("SELECT COUNT(*) FROM {import_map} WHERE type = 'shipping_zones' AND local_id = ?", [$zoneId]) > 0
                || (int) $db->value('SELECT COUNT(*) FROM {carrier_rates} WHERE zone_id = ?', [$zoneId]) > 0) {
                continue;
            }
            $places = $db->select('SELECT country, region, postcodes FROM {zone_locations} WHERE zone_id = ?', [$zoneId]);
            if (count($places) !== 1 || (string) $places[0]['country'] !== 'ES' || (string) $places[0]['region'] !== '' || (string) $places[0]['postcodes'] !== $postcodes) {
                continue;
            }
            $db->update('shipping_zones', ['active' => 0], ['id' => $zoneId]);
            $ctx->note('shipping_zones', $sourceId, 'note', 'The default zone «' . $name . '» was switched off: the zones of the source are used instead');
        }
    }
}

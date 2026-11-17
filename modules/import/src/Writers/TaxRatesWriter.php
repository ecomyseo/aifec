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
 * Los tipos de impuesto del origen, por clase, país, región y código postal.
 *
 * Los tipos de una clase importada SUSTITUYEN a los que hubiera para ese país en esa clase cuando solo son los de serie (los que
 * sembró la instalación); los de una migración anterior se actualizan en su sitio. Si la clase ya tenía tipos puestos a mano,
 * el registro choca («conflict») y no se toca nada. Un tipo «compuesto» no existe aquí: entra como tipo normal y se apunta para revisarlo.
 */
final class TaxRatesWriter extends WriterBase
{
    /**
     * Los tipos que siembra la instalación (módulo de impuestos): clase, códigos postales, tipo, nombre y prioridad.
     *
     * @var array<int,array{0:string,1:string,2:int,3:string,4:int}>
     */
    private const SEED = [
        ['standard', '', 2100, 'IVA 21 %', 0], ['reduced', '', 1000, 'IVA 10 %', 0], ['super-reduced', '', 400, 'IVA 4 %', 0], ['zero', '', 0, 'IVA 0 %', 0],
        ['standard', '35,38', 700, 'IGIC 7 %', 10], ['reduced', '35,38', 300, 'IGIC 3 %', 10], ['super-reduced', '35,38', 0, 'IGIC 0 %', 10], ['zero', '35,38', 0, 'IGIC 0 %', 10],
        ['standard', '51,52', 0, 'Sin IVA (IPSI en destino)', 10], ['reduced', '51,52', 0, 'Sin IVA (IPSI en destino)', 10],
        ['super-reduced', '51,52', 0, 'Sin IVA (IPSI en destino)', 10], ['zero', '51,52', 0, 'Sin IVA (IPSI en destino)', 10],
    ];

    public function type(): string
    {
        return 'tax_rates';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $classId = $ctx->localId('tax_classes', (string) $record['class_id']);
        $class = $classId !== null ? $db->find('tax_classes', ['id' => $classId]) : null;
        if ($class === null) {
            return $this->result(self::SKIPPED, null, 'The tax class of this rate was not brought');
        }
        $postcodes = Postcodes::normalize(isset($record['postcodes']) && is_string($record['postcodes']) ? $record['postcodes'] : '');
        if ($postcodes === null) {
            return $this->result(self::SKIPPED, null, 'The list of postcodes is not valid');
        }
        $country = strtoupper((string) $record['country']);
        $region = $this->region($country, isset($record['region']) && is_string($record['region']) ? $record['region'] : '');

        $found = $this->resolve('tax_rates', $record, $ctx, function (int $localId) use ($db): ?array {
            return $db->find('tax_rates', ['id' => $localId]);
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        $replace = [];
        if ($row === null) {
            $replace = $this->inTheWay((int) $class['id'], (string) $class['code'], $country);
            if ($replace['manual'] !== [] && !(bool) $ctx->option('overwrite_local', false)) {
                return $this->result(self::CONFLICT, null, 'The class already has rates for this country that were set by hand');
            }
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }
        if (isset($record['compound']) && $record['compound'] === true) {
            $ctx->unmapped('review', 'Compound tax', 1, 'Imported as a normal tax: check by hand that the amount is right');
        }
        $name = $this->cut(isset($record['name']) && is_string($record['name']) ? $record['name'] : '', 60);
        $data = [
            'tax_class_id' => (int) $class['id'], 'country' => $country, 'region' => $this->cut($region, 16), 'postcodes' => $postcodes,
            'rate' => max(0, (int) $record['rate']), 'name' => $name !== '' ? $name : 'Tax', 'priority' => isset($record['priority']) ? (int) $record['priority'] : 0,
            'active' => isset($record['active']) && $record['active'] === false ? 0 : 1,
        ];
        if ($row === null) {
            // Los de serie siempre se sustituyen; los puestos a mano, solo si se pidió pisar lo editado aquí.
            foreach (array_merge($replace['delete'], $replace['manual']) as $oldId) {
                $db->delete('tax_rates', ['id' => $oldId]);
            }
            $localId = $db->insert('tax_rates', $data);
        } else {
            $localId = (int) $row['id'];
            $db->update('tax_rates', $data, ['id' => $localId]);
        }
        $ctx->remember('tax_rates', $id, $localId, Neutral::hash($record));
        $service = $this->app->service('tax');
        if (is_callable([$service, 'flush'])) {
            $service->flush();
        }

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    public function checksums(WriteContext $ctx): array
    {
        return ['tax_rates_count' => $this->mappedCount('tax_rates', 'tax_rates')];
    }

    /**
     * Qué hay ya en esa clase para ese país: lo que se sustituye (de serie o de una migración) y cuántos están puestos a mano.
     *
     * @return array{delete:int[],manual:int[]}
     */
    private function inTheWay(int $classId, string $classCode, string $country): array
    {
        $db = $this->db();
        $delete = [];
        $manual = [];
        foreach ($db->select('SELECT * FROM {tax_rates} WHERE tax_class_id = ? AND country = ?', [$classId, $country]) as $row) {
            $rowId = (int) $row['id'];
            $mapped = (int) $db->value("SELECT COUNT(*) FROM {import_map} WHERE type = 'tax_rates' AND local_id = ?", [$rowId]) > 0;
            if ($mapped) {
                continue; // una hermana de esta misma migración: se queda
            }
            if ($this->isSeed($classCode, $row)) {
                $delete[] = $rowId;
            } else {
                $manual[] = $rowId;
            }
        }

        return ['delete' => $delete, 'manual' => $manual];
    }

    /**
     * ¿Es una fila tal como la sembró la instalación?
     *
     * @param array<string,mixed> $row
     */
    private function isSeed(string $classCode, array $row): bool
    {
        foreach (self::SEED as $seed) {
            if ($seed[0] === $classCode && (string) $row['country'] === 'ES' && (string) $row['region'] === '' && (string) $row['postcodes'] === $seed[1]
                && (int) $row['rate'] === $seed[2] && (string) $row['name'] === $seed[3] && (int) $row['priority'] === $seed[4] && (int) $row['active'] === 1) {
                return true;
            }
        }

        return false;
    }
}

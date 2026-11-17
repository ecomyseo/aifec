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

/**
 * Los grupos de clientes del origen: nombre (en el idioma principal), descuento y exención de impuestos.
 *
 * El grupo por defecto del origen casa con el de aquí (no se crea otro ni se le cambia el nombre); un grupo que el comerciante
 * eligió en las equivalencias, o que se llama igual, también se adopta en vez de duplicarse.
 */
final class CustomerGroupsWriter extends WriterBase
{
    public function type(): string
    {
        return 'customer_groups';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $name = $this->cut($this->mainText(isset($record['name']) && is_array($record['name']) ? $record['name'] : [], $ctx), 80);
        $byName = false;
        $found = $this->resolve('customer_groups', $record, $ctx, function (int $localId) use ($db): ?array {
            return $db->find('customer_groups', ['id' => $localId]);
        }, function () use ($record, $ctx, $id, $name, &$byName): ?array {
            return $this->existing($record, $ctx, $id, $name, $byName);
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }
        $data = ['discount' => max(0, isset($record['discount']) ? (int) $record['discount'] : 0), 'tax_exempt' => isset($record['tax_exempt']) && $record['tax_exempt'] === true ? 1 : 0];
        if ($row === null) {
            $localId = $db->insert('customer_groups', $data + ['name' => $name !== '' ? $name : 'Group ' . $id, 'is_default' => 0]);
        } else {
            $localId = (int) $row['id'];
            // El grupo por defecto de aquí y el que eligió el comerciante conservan su nombre; el resto toma el del origen.
            if ($name !== '' && ($byName || !$found['natural'])) {
                $data['name'] = $name;
            }
            $db->update('customer_groups', $data, ['id' => $localId]);
        }
        $ctx->remember('customer_groups', $id, $localId, Neutral::hash($record));

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    /**
     * El grupo de aquí que ya es este: el que eligió el comerciante, el que se llama igual o, si es el por defecto del origen, el de aquí.
     *
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>|null
     */
    private function existing(array $record, WriteContext $ctx, string $id, string $name, bool &$byName): ?array
    {
        $db = $this->db();
        $chosen = $ctx->equivalent('customer_group', $id);
        if ($chosen !== null && ctype_digit($chosen)) {
            $row = $db->find('customer_groups', ['id' => (int) $chosen]);
            if ($row !== null && !$this->claimed('customer_groups', (int) $row['id'], $id)) {
                return $row;
            }
        }
        if ($name !== '') {
            $params = [];
            $row = $db->row('SELECT * FROM {customer_groups} WHERE ' . $this->sameText('name', $name, $params) . ' ORDER BY id', $params);
            if ($row !== null && !$this->claimed('customer_groups', (int) $row['id'], $id)) {
                $byName = true;

                return $row;
            }
        }
        if (isset($record['is_default']) && $record['is_default'] === true) {
            $row = $db->row('SELECT * FROM {customer_groups} WHERE is_default = 1 ORDER BY id');
            if ($row !== null && !$this->claimed('customer_groups', (int) $row['id'], $id)) {
                return $row;
            }
        }

        return null;
    }
}

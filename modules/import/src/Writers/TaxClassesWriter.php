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
 * Las clases de impuesto del origen. Una clase del origen que ya existe aquí (por la equivalencia que eligió el comerciante, o por
 * tener el mismo código) NO se duplica: se apunta la pareja y, si casó por el código, se pone el nombre del origen.
 * La clase por defecto no se toca: la de aquí manda.
 */
final class TaxClassesWriter extends WriterBase
{
    public function type(): string
    {
        return 'tax_classes';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $found = $this->resolve('tax_classes', $record, $ctx, function (int $localId) use ($db): ?array {
            return $db->find('tax_classes', ['id' => $localId]);
        }, function () use ($record, $ctx, $id): ?array {
            return $this->existing($record, $ctx, $id);
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }
        $name = $this->cut((string) $record['name'], 80);
        $hash = Neutral::hash($record);
        if ($row === null) {
            $codes = array_map('strval', $db->column('SELECT code FROM {tax_classes}'));
            $position = (int) $db->value('SELECT MAX(position) FROM {tax_classes}') + 1;
            $localId = $db->insert('tax_classes', [
                'code' => $this->code((string) $record['code'], 'class', 32, $codes), 'name' => $name !== '' ? $name : 'Tax class',
                'is_default' => 0, 'position' => $position,
            ]);
        } else {
            $localId = (int) $row['id'];
            // Una clase equivalente elegida a mano conserva su nombre; una de mismo código toma el del origen.
            if (!$found['natural'] || $ctx->equivalent('tax_class', $id) === null) {
                if ($name !== '' && $name !== (string) $row['name']) {
                    $db->update('tax_classes', ['name' => $name], ['id' => $localId]);
                }
            }
        }
        $ctx->remember('tax_classes', $id, $localId, $hash);
        $this->flush();

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    /**
     * La clase de aquí que ya es esta: la que eligió el comerciante en el asistente o la que tiene el mismo código.
     *
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>|null
     */
    private function existing(array $record, WriteContext $ctx, string $id): ?array
    {
        $db = $this->db();
        $chosen = $ctx->equivalent('tax_class', $id);
        if ($chosen !== null) {
            $row = $db->row('SELECT * FROM {tax_classes} WHERE code = ?', [$chosen]);
            if ($row !== null && !$this->claimed('tax_classes', (int) $row['id'], $id)) {
                return $row;
            }
        }
        $params = [];
        $row = $db->row('SELECT * FROM {tax_classes} WHERE ' . $this->sameText('code', (string) $record['code'], $params), $params);

        return $row !== null && !$this->claimed('tax_classes', (int) $row['id'], $id) ? $row : null;
    }

    private function flush(): void
    {
        $service = $this->app->service('tax');
        if (is_callable([$service, 'flush'])) {
            $service->flush();
        }
    }
}

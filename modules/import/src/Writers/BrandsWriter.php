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

use Aifec\Modules\Catalog\Admin\TaxonomySlugs;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\WriteContext;

/**
 * Las marcas del origen. Una marca que ya existe aquí con el mismo nombre no se duplica. Se escribe como la guarda el panel:
 * su dirección amigable en el idioma principal y la caché del catálogo vaciada.
 */
final class BrandsWriter extends WriterBase
{
    public function type(): string
    {
        return 'brands';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $found = $this->resolve('brands', $record, $ctx, function (int $localId) use ($db): ?array {
            return $db->find('brands', ['id' => $localId]);
        }, function () use ($record, $id): ?array {
            $params = [];
            $row = $this->db()->row('SELECT * FROM {brands} WHERE ' . $this->sameText('name', (string) $record['name'], $params), $params);

            return $row !== null && !$this->claimed('brands', (int) $row['id'], $id) ? $row : null;
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($action === self::CONFLICT) {
            return $this->conflict((int) $row['id']);
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }
        $now = $this->now();
        $description = $this->html($this->mainText(isset($record['description']) && is_array($record['description']) ? $record['description'] : [], $ctx));
        $website = isset($record['website']) && is_string($record['website']) ? trim($record['website']) : '';
        $data = [
            'name' => trim((string) $record['name']), 'description' => $description !== '' ? $description : null, 'website' => $website !== '' ? $website : null,
            'active' => isset($record['active']) && $record['active'] === false ? 0 : 1, 'position' => isset($record['position']) ? (int) $record['position'] : 0,
            'updated_at' => $now,
        ];
        if (isset($record['logo']) && is_array($record['logo'])) {
            $media = $ctx->image($record['logo'], 'brands', $id);
            if ($media !== null) {
                $data['media_id'] = $media;
            }
        }
        if ($row === null) {
            $localId = $db->insert('brands', $data + ['created_at' => $now]);
        } else {
            $localId = (int) $row['id'];
            $db->update('brands', $data, ['id' => $localId]);
        }
        $this->address($localId, $record, $ctx);
        foreach (['meta_title', 'meta_description'] as $field) {
            if (isset($record[$field]) && is_array($record[$field]) && $this->mainText($record[$field], $ctx) !== '') {
                $ctx->unmapped('seo', 'Brand ' . $field, 1, 'Brands have no search engine texts here');
            }
        }
        $ctx->remember('brands', $id, $localId, Neutral::hash($record));
        $this->app->invalidate(['catalog']);

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    /**
     * La dirección amigable (en el idioma principal) y las antiguas.
     *
     * @param array<string,mixed> $record
     */
    private function address(int $localId, array $record, WriteContext $ctx): void
    {
        $main = $this->app->defaultLang();
        $slug = isset($record['slug']) && is_array($record['slug']) ? $this->mainText($record['slug'], $ctx) : '';
        $segment = TaxonomySlugs::make($slug !== '' ? $slug : (string) $record['name'], 'brand-' . $localId);
        $this->app->slugs()->set('brand', $localId, $main, TaxonomySlugs::prefix($this->app, 'brand') . '/' . $segment);
        $paths = [];
        foreach (isset($record['old_paths']) && is_array($record['old_paths']) ? $record['old_paths'] : [] as $path) {
            if (is_string($path)) {
                $paths[] = $path;
            }
        }
        $ctx->oldPaths('brand', $localId, $paths);
    }
}

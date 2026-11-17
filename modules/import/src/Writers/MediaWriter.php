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
 * La biblioteca de medios que no cuelga de un producto. La imagen se lleva a la biblioteca con WriteContext::image() (que no duplica la que
 * ya está) y sus direcciones antiguas se registran para que las que aparecen dentro de los textos sigan valiendo.
 */
final class MediaWriter extends WriterBase
{
    public function type(): string
    {
        return 'media';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $found = $this->resolve('media', $record, $ctx, function (int $localId) use ($db): ?array {
            return $db->find('media', ['id' => $localId]);
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }
        $image = [];
        foreach (['url', 'path', 'alt'] as $field) {
            if (isset($record[$field])) {
                $image[$field] = $record[$field];
            }
        }
        $localId = $ctx->image($image, 'media', $id);
        if ($localId === null) {
            return $this->result(self::SKIPPED, null, (bool) $ctx->option('import_images', true) ? 'The file could not be brought' : 'Images are switched off in this migration');
        }
        $alt = $this->mainText(isset($record['alt']) && is_array($record['alt']) ? $record['alt'] : [], $ctx);
        if ($alt !== '' && $this->exists('media', ['id' => $localId])) {
            $db->update('media', ['alt' => $this->cut($alt, 255)], ['id' => $localId]);
        }
        $paths = [];
        foreach (isset($record['old_paths']) && is_array($record['old_paths']) ? $record['old_paths'] : [] as $path) {
            if (is_string($path)) {
                $paths[] = $path;
            }
        }
        $ctx->oldPaths('media', $localId, $paths);
        $ctx->remember('media', $id, $localId, Neutral::hash($record));

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }
}

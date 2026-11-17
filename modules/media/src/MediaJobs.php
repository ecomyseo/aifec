<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Media;

use Aifec\Kernel\App;
use Aifec\Media\MediaException;
use Aifec\Modules\Jobs\PermanentFailure;

/**
 * Trabajos en segundo plano de la biblioteca de medios.
 */
final class MediaJobs
{
    /**
     * «media.regenerate» `['id' => n]`: rehace las variantes de una imagen. Si la imagen ya no existe o le falta el
     * fichero, repetirlo no lo arregla: fallo permanente.
     *
     * @param array<string,mixed> $payload
     */
    public static function regenerate(array $payload, App $app): string
    {
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        if ($id < 1) {
            throw new PermanentFailure('Missing image id');
        }
        /** @var Library $library */
        $library = $app->service('media');
        try {
            $library->regenerate($id);
        } catch (MediaException $e) {
            throw new PermanentFailure($e->getMessage());
        }

        return 'Image ' . $id . ' regenerated';
    }
}

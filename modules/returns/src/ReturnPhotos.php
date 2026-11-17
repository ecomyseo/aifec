<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Returns;

use Aifec\Kernel\App;
use Aifec\Media\MediaException;
use Aifec\Media\Mime;

/**
 * Las fotografías de un producto defectuoso: hasta 3, JPEG, PNG o WebP de hasta 5 MB cada una.
 *
 * Se guardan en la carpeta privada del sitio (servicio «files» del módulo de medios, zona «returns»), fuera de la raíz web. Solo se
 * sirven desde el panel, con permiso (Admin\ReturnsController::photo()): nunca tienen una dirección pública. El tipo se mira por el
 * contenido real del fichero, no por su nombre ni por lo que diga el navegador.
 */
final class ReturnPhotos
{
    public const MAX_FILES = 3;

    public const MAX_BYTES = 5242880;

    /** @var string[] */
    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** ¿Se pueden guardar ficheros privados (módulo de medios cargado)? */
    public function available(): bool
    {
        return $this->app->has('files');
    }

    /**
     * Comprueba las fotos que llegan del formulario sin guardar nada. Los huecos vacíos (campos sin fichero) se ignoran.
     *
     * @param array<int,array<string,mixed>> $files cada una: name, tmp_name, size, type
     *
     * @return array{files:array<int,array{name:string,tmp_name:string}>,error:string}
     */
    public function check(array $files): array
    {
        $clean = [];
        foreach ($files as $file) {
            $tmp = isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file['tmp_name'] : '';
            if ($tmp === '') {
                continue;
            }
            $clean[] = ['name' => isset($file['name']) ? (string) $file['name'] : 'photo', 'tmp_name' => $tmp];
        }
        if (count($clean) > self::MAX_FILES) {
            return ['files' => [], 'error' => t('You can attach 3 photos at most.')];
        }
        foreach ($clean as $file) {
            if (!is_file($file['tmp_name']) || !is_readable($file['tmp_name'])) {
                return ['files' => [], 'error' => t('A photo could not be read. Try again.')];
            }
            $bytes = filesize($file['tmp_name']);
            if ($bytes === false || $bytes > self::MAX_BYTES) {
                return ['files' => [], 'error' => t('Each photo can be 5 MB at most.')];
            }
            try {
                $mime = Mime::fromContent($file['tmp_name']);
            } catch (MediaException $e) {
                return ['files' => [], 'error' => t('A photo could not be read. Try again.')];
            }
            if (!in_array($mime, self::TYPES, true)) {
                return ['files' => [], 'error' => t('Only JPEG, PNG or WebP photos are accepted.')];
            }
        }

        return ['files' => $clean, 'error' => ''];
    }

    /**
     * Guarda las fotos ya comprobadas. Si una falla, borra las que ya había guardado y lanza.
     *
     * @param array<int,array{name:string,tmp_name:string}> $files
     *
     * @return array<int,array{path:string,name:string,mime:string,bytes:int}>
     *
     * @throws \RuntimeException si no se puede guardar alguna
     */
    public function store(array $files): array
    {
        $stored = [];
        try {
            foreach ($files as $file) {
                $one = $this->app->service('files')->store($file['tmp_name'], $file['name'], 'returns');
                $stored[] = ['path' => (string) $one['path'], 'name' => (string) $one['name'], 'mime' => (string) $one['mime'], 'bytes' => (int) $one['bytes']];
            }
        } catch (\Throwable $e) {
            $this->delete($stored);
            throw new \RuntimeException('The photos could not be saved', 0, $e);
        }

        return $stored;
    }

    /**
     * Borra del disco las fotos de una lista [{path,…}].
     *
     * @param array<int,array<string,mixed>> $photos
     */
    public function delete(array $photos): void
    {
        if (!$this->available()) {
            return;
        }
        foreach ($photos as $photo) {
            if (isset($photo['path']) && is_string($photo['path'])) {
                $this->app->service('files')->delete($photo['path']);
            }
        }
    }
}

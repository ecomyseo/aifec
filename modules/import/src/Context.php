<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

use Aifec\Kernel\App;

/**
 * Lo que un escritor tiene a mano durante una migración: las opciones, el casamiento de identificadores
 * (import_map), las equivalencias elegidas, las imágenes, las direcciones antiguas y el informe.
 *
 * En un simulacro no escribe nada salvo las líneas de import_log de ese simulacro (note): remember(), image() y
 * oldPaths() no hacen nada.
 */
final class Context implements WriteContext
{
    /** Valores por omisión de las opciones de una migración. */
    private const DEFAULTS = ['keep_urls' => true, 'overwrite_local' => false, 'import_images' => true];

    /** Papeles de una dirección antigua (addresses()): la buena, la misma con el prefijo del idioma principal, y la de un idioma sin versión. */
    private const MAIN = 'main';
    private const PREFIXED = 'prefixed';
    private const UNTRANSLATED = 'untranslated';

    /** Tipo con el que se apuntan en import_map las imágenes ya traídas (no es un tipo de migración). */
    private const IMAGE_TYPE = '_image';

    /** @var App */
    private $app;

    /** @var Runs */
    private $runs;

    /** @var array<string,mixed> */
    private $run;

    /** @var string[] datos de conexión que no pueden salir en ningún texto */
    private $secrets;

    /** @var string carpeta de ficheros del origen (solo en modo copia) */
    private $filesDir;

    /** @var string[] secretos del registro que se está escribiendo */
    private $recordSecrets = [];

    /** @var array<string,array{kind:string,name:string,count:int,note:string}> */
    private $unmapped = [];

    /**
     * @param array<string,mixed> $run     fila de import_runs ya descodificada
     * @param string[]            $secrets datos de conexión (Connections::secrets)
     */
    public function __construct(App $app, Runs $runs, array $run, array $secrets = [], string $filesDir = '')
    {
        $this->app = $app;
        $this->runs = $runs;
        $this->run = $run;
        $this->secrets = $secrets;
        $this->filesDir = $filesDir;
    }

    public function app(): App
    {
        return $this->app;
    }

    public function dryRun(): bool
    {
        return (int) $this->run['dry_run'] === 1;
    }

    public function source(): string
    {
        return (string) $this->run['source'];
    }

    /**
     * Secretos del registro que se va a escribir: lo que se apunte desde ahora los lleva tapados.
     *
     * @param string[] $secrets
     */
    public function guard(array $secrets): void
    {
        $this->recordSecrets = $secrets;
    }

    public function option(string $key, $default = null)
    {
        $options = isset($this->run['options']) && is_array($this->run['options']) ? $this->run['options'] : [];
        if (array_key_exists($key, $options)) {
            return $options[$key];
        }
        if (array_key_exists($key, self::DEFAULTS)) {
            return self::DEFAULTS[$key];
        }
        if ($key === 'prices_include_tax_here') {
            return $this->app->settings()->bool('tax.prices_include_tax', true);
        }

        return $default;
    }

    public function localId(string $type, string $sourceId): ?int
    {
        $mapped = $this->mapped($type, $sourceId);

        return $mapped === null ? null : $mapped['local_id'];
    }

    public function mapped(string $type, string $sourceId): ?array
    {
        $row = $this->app->db()->row(
            'SELECT local_id, source_hash, written_at FROM {import_map} WHERE source = ? AND source_key = ? AND type = ? AND source_id = ?',
            [$this->source(), (string) $this->run['source_key'], $type, $sourceId]
        );
        if ($row === null) {
            return null;
        }

        return ['local_id' => (int) $row['local_id'], 'source_hash' => (string) $row['source_hash'], 'written_at' => (string) $row['written_at']];
    }

    public function remember(string $type, string $sourceId, int $localId, string $sourceHash): void
    {
        if ($this->dryRun()) {
            return;
        }
        $this->app->db()->upsert('import_map', [
            'source' => $this->source(),
            'source_key' => (string) $this->run['source_key'],
            'type' => substr($type, 0, 30),
            'source_id' => substr($sourceId, 0, 64),
            'local_id' => $localId,
            'source_hash' => substr($sourceHash, 0, 40),
            'run_id' => (int) $this->run['id'],
            'written_at' => $this->app->clock()->sql(),
        ], ['source', 'source_key', 'type', 'source_id']);
    }

    public function equivalent(string $kind, string $sourceValue): ?string
    {
        $all = isset($this->run['equivalences']) && is_array($this->run['equivalences']) ? $this->run['equivalences'] : [];
        if (!isset($all[$kind]) || !is_array($all[$kind]) || !isset($all[$kind][$sourceValue])) {
            return null;
        }
        $value = $all[$kind][$sourceValue];

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function note(string $type, string $sourceId, string $result, string $reason): void
    {
        $secrets = array_merge($this->secrets, $this->recordSecrets);
        $this->runs->log((int) $this->run['id'], $type, $sourceId, $result, Redactor::scrub($reason, $secrets));
    }

    public function unmapped(string $kind, string $name, int $count = 1, string $note = ''): void
    {
        $key = $kind . "\n" . $name;
        if (!isset($this->unmapped[$key])) {
            $this->unmapped[$key] = ['kind' => $kind, 'name' => $name, 'count' => 0, 'note' => $note];
        }
        $this->unmapped[$key]['count'] += max(0, $count);
    }

    /**
     * Lo apuntado con unmapped() desde la última vez, y vuelta a empezar.
     *
     * @return array<int,array{kind:string,name:string,count:int,note:string}>
     */
    public function drainUnmapped(): array
    {
        $out = array_values($this->unmapped);
        $this->unmapped = [];

        return $out;
    }

    public function languages(): array
    {
        return $this->app->languages();
    }

    public function image(array $image, string $ownerType, string $ownerId): ?int
    {
        if ($this->dryRun() || !$this->option('import_images', true) || !$this->app->has('media')) {
            return null;
        }
        $url = isset($image['url']) && is_string($image['url']) ? trim($image['url']) : '';
        $path = isset($image['path']) && is_string($image['path']) ? trim($image['path']) : '';
        $options = ['alt' => $this->altText($image)];
        /** @var \Aifec\Modules\Media\Library $media */
        $media = $this->app->service('media');

        try {
            if ($path !== '') {
                $file = $this->insideFiles($path);
                if ($file === null) {
                    $this->note($ownerType, $ownerId, 'note', 'An image path is outside the files folder of the source and was not read');

                    return null;
                }
                $key = md5('file:' . $file);
                $known = $this->knownImage($key, $media);
                if ($known !== null) {
                    return $known;
                }
                $row = $media->store($file, basename($file), $options);
            } elseif ($url !== '') {
                $key = md5('url:' . $url);
                $known = $this->knownImage($key, $media);
                if ($known !== null) {
                    return $known;
                }
                $row = $media->importUrl($url, $options);
            } else {
                return null;
            }
        } catch (\Throwable $e) {
            $this->note($ownerType, $ownerId, 'note', 'An image could not be brought: ' . $e->getMessage());

            return null;
        }
        $id = (int) $row['id'];
        $this->remember(self::IMAGE_TYPE, $key, $id, $key);

        return $id > 0 ? $id : null;
    }

    public function oldPaths(string $entity, int $localId, array $paths, string $lang = ''): void
    {
        if ($this->dryRun() || $paths === []) {
            return;
        }
        $keep = (bool) $this->option('keep_urls', true);
        $first = [];
        foreach ($paths as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            foreach ($this->addresses($entity, $localId, $raw, $lang) as $item) {
                list($pathLang, $path, $role) = $item;
                if ($path === '' || strlen($path) > 191) {
                    continue;
                }
                if ($role === self::PREFIXED) {
                    // La misma dirección con el prefijo del idioma principal, que esta tienda no usa: aparte de la buena, apunta a ella.
                    if ($keep) {
                        $this->keepPath($entity, $localId, $pathLang, $path, false);
                    } else {
                        $this->redirectPath($entity, $localId, $pathLang, $path);
                    }
                } elseif ($role === self::UNTRANSLATED) {
                    $this->untranslatedPath($entity, $localId, $pathLang, $path);
                } else {
                    $this->mainPath($entity, $localId, $pathLang, $path, $keep, !isset($first[$pathLang]));
                    $first[$pathLang] = true;
                }
            }
        }
    }

    private function mainPath(string $entity, int $localId, string $lang, string $path, bool $keep, bool $canonical): void
    {
        if ($keep) {
            $this->keepPath($entity, $localId, $lang, $path, $canonical);
        } else {
            $this->redirectPath($entity, $localId, $lang, $path);
        }
    }

    /**
     * Las direcciones que hay que dejar funcionando por una dirección antigua: [idioma, ruta, papel], con la ruta sin barras de los extremos.
     *
     * - Sin prefijo de idioma (o con uno que el sitio no tiene): la ruta tal cual, en el idioma dado o el principal.
     * - Con el prefijo del idioma principal («/es/content/4-aviso-legal»): el idioma principal no lleva prefijo, así que el sitio no lo
     *   acepta. Se guarda la ruta sin él (la buena) Y la ruta con él (la que la gente tenía enlazada), que lleva a la misma página. Si quien
     *   llama ya dijo el idioma, la ruta se guarda entera y tal cual, como siempre.
     * - Con el prefijo de otro idioma del sitio («/en/…»): el sitio lo quita antes de buscar, así que se guarda sin él en ese idioma; y si lo
     *   que se migra no tiene versión en ese idioma, no se crea una dirección vacía: redirige al idioma principal.
     *
     * @return array<int,array{0:string,1:string,2:string}>
     */
    private function addresses(string $entity, int $localId, string $raw, string $lang): array
    {
        $cut = strcspn($raw, '?#');
        $path = trim((string) preg_replace('#/+#', '/', substr($raw, 0, $cut)), '/');
        $default = $this->app->defaultLang();
        $segments = explode('/', $path, 2);
        $head = $segments[0];
        $rest = isset($segments[1]) ? $segments[1] : '';
        if ($rest === '' || !in_array($head, $this->app->languages(), true)) {
            return [[$lang !== '' ? $lang : $default, $path, self::MAIN]];
        }
        if ($head === $default) {
            return $lang !== '' ? [[$lang, $path, self::MAIN]] : [[$default, $rest, self::MAIN], [$default, $path, self::PREFIXED]];
        }

        return [[$head, $rest, $this->app->slugs()->path($entity, $localId, $head) === null ? self::UNTRANSLATED : self::MAIN]];
    }

    /**
     * La dirección antigua de un contenido que no existe en el idioma de su prefijo: manda al mismo contenido en el idioma principal (con su
     * dirección completa, porque el sitio le pondría el prefijo del idioma de quien pide).
     */
    private function untranslatedPath(string $entity, int $localId, string $lang, string $path): void
    {
        $db = $this->app->db();
        $main = $this->app->defaultLang();
        if ($this->app->slugs()->path($entity, $localId, $main) === null) {
            $this->note($entity, (string) $localId, 'note', 'The old address «' . $path . '» has no new address to point to');

            return;
        }
        if ($db->row('SELECT id FROM {slugs} WHERE lang = ? AND path = ?', [$lang, $path]) !== null) {
            $this->note($entity, (string) $localId, 'note', 'The old address «' . $path . '» is already used by another page and was left alone');

            return;
        }
        $target = $this->app->url()->entityAbs($entity, $localId, $main);
        $existing = $db->row('SELECT id, target FROM {redirects} WHERE source = ?', [$path]);
        if ($existing !== null) {
            if ((string) $existing['target'] !== $target) {
                $this->note($entity, (string) $localId, 'note', 'The old address «' . $path . '» already redirects somewhere else and was left alone');
            }

            return;
        }
        $db->insert('redirects', [
            'source' => $path, 'target' => $target, 'code' => 301, 'hits' => 0, 'active' => 1, 'note' => 'Import', 'created_at' => $this->app->clock()->sql(),
        ]);
    }

    private function keepPath(string $entity, int $localId, string $lang, string $path, bool $canonical): void
    {
        $db = $this->app->db();
        $row = $db->row('SELECT type, entity_id FROM {slugs} WHERE lang = ? AND path = ?', [$lang, $path]);
        if ($row !== null && ((string) $row['type'] !== $entity || (int) $row['entity_id'] !== $localId)) {
            $this->note($entity, (string) $localId, 'note', 'The old address «' . $path . '» is already used by another page and was left alone');

            return;
        }
        if ($canonical) {
            $this->app->slugs()->set($entity, $localId, $lang, $path);

            return;
        }
        if ($row === null) {
            $db->insertIgnore('slugs', ['lang' => $lang, 'path' => $path, 'type' => $entity, 'entity_id' => $localId, 'canonical' => 0]);
        }
    }

    private function redirectPath(string $entity, int $localId, string $lang, string $path): void
    {
        $db = $this->app->db();
        $current = $this->app->slugs()->path($entity, $localId, $lang);
        if ($current === null) {
            $this->note($entity, (string) $localId, 'note', 'The old address «' . $path . '» has no new address to point to');

            return;
        }
        if ($current === $path) {
            return;
        }
        if ($db->row('SELECT id FROM {slugs} WHERE lang = ? AND path = ?', [$lang, $path]) !== null) {
            $this->note($entity, (string) $localId, 'note', 'The old address «' . $path . '» is already used by another page and was left alone');

            return;
        }
        $target = '/' . $current;
        $existing = $db->row('SELECT id, target FROM {redirects} WHERE source = ?', [$path]);
        if ($existing !== null) {
            if ((string) $existing['target'] !== $target) {
                $this->note($entity, (string) $localId, 'note', 'The old address «' . $path . '» already redirects somewhere else and was left alone');
            }

            return;
        }
        $db->insert('redirects', [
            'source' => $path,
            'target' => $target,
            'code' => 301,
            'hits' => 0,
            'active' => 1,
            'note' => 'Import',
            'created_at' => $this->app->clock()->sql(),
        ]);
    }

    /**
     * Texto alternativo de una imagen neutra: el del idioma principal o el primero que traiga.
     *
     * @param array<string,mixed> $image
     */
    private function altText(array $image): string
    {
        $alt = isset($image['alt']) && is_array($image['alt']) ? $image['alt'] : [];
        $main = $this->app->defaultLang();
        if (isset($alt[$main]) && is_string($alt[$main])) {
            return $alt[$main];
        }
        foreach ($alt as $text) {
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * El identificador de una imagen ya traída (y que sigue en la biblioteca), o null.
     */
    private function knownImage(string $key, object $media): ?int
    {
        $mapped = $this->mapped(self::IMAGE_TYPE, $key);
        if ($mapped === null) {
            return null;
        }
        /** @var \Aifec\Modules\Media\Library $media */
        return $media->get($mapped['local_id']) !== null ? $mapped['local_id'] : null;
    }

    /**
     * La ruta real de un fichero si está DENTRO de la carpeta de ficheros del origen; null si no.
     */
    private function insideFiles(string $path): ?string
    {
        if ($this->filesDir === '') {
            return null;
        }
        $root = realpath($this->filesDir);
        if ($root === false) {
            return null;
        }
        $full = preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path) === 1 ? $path : rtrim($this->filesDir, '/\\') . '/' . ltrim($path, '/\\');
        $real = realpath($full);
        if ($real === false || !is_file($real)) {
            return null;
        }
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $real = str_replace('\\', '/', $real);
        if (DIRECTORY_SEPARATOR === '\\') {
            $root = strtolower($root);
            $inside = strpos(strtolower($real), $root) === 0;
        } else {
            $inside = strpos($real, $root) === 0;
        }

        return $inside ? $real : null;
    }
}

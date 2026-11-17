<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Kernel;

/**
 * Caché en ficheros del sitio, con caducidad y con etiquetas.
 *
 * Cada entrada es un fichero PHP (lo acelera la OPcache). Una entrada puede llevar etiquetas
 * («product:12», «catalog»); al invalidar una etiqueta, todas las entradas que la llevan dejan de valer
 * sin recorrer el disco: cada etiqueta tiene un número de versión y la entrada guarda el que vio al escribirse.
 *
 * Solo admite valores que se puedan escribir como código PHP: escalares, null y arrays de ellos.
 */
final class Cache
{
    /** @var string */
    private $dir;

    /** @var Clock */
    private $clock;

    /** @var array<string,int>|null versiones de las etiquetas */
    private $tags;

    /** @var array<string,mixed> lo ya leído en esta petición */
    private $memory = [];

    public function __construct(string $dir, Clock $clock)
    {
        $this->dir = rtrim(str_replace('\\', '/', $dir), '/');
        $this->clock = $clock;
    }

    /**
     * @param mixed $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        if (array_key_exists($key, $this->memory)) {
            return $this->memory[$key];
        }
        $file = $this->file($key);
        if (!is_file($file)) {
            return $default;
        }
        $entry = @include $file;
        if (!is_array($entry) || !array_key_exists('v', $entry)) {
            return $default;
        }
        if ((int) $entry['e'] !== 0 && (int) $entry['e'] < $this->clock->now()) {
            return $default;
        }
        foreach ((array) $entry['t'] as $tag => $version) {
            if ($this->tagVersion((string) $tag) !== (int) $version) {
                return $default;
            }
        }
        $this->memory[$key] = $entry['v'];

        return $entry['v'];
    }

    /**
     * @param mixed    $value
     * @param int      $ttl   segundos; 0 = sin caducidad
     * @param string[] $tags
     */
    public function set(string $key, $value, int $ttl = 0, array $tags = []): void
    {
        $versions = [];
        foreach ($tags as $tag) {
            $versions[$tag] = $this->tagVersion($tag);
        }
        $entry = ['e' => $ttl > 0 ? $this->clock->now() + $ttl : 0, 't' => $versions, 'v' => $value];
        $this->write($this->file($key), "<?php\nreturn " . var_export($entry, true) . ";\n");
        $this->memory[$key] = $value;
    }

    /**
     * Devuelve lo guardado o, si no vale, lo calcula con la función y lo guarda.
     *
     * @param string[] $tags
     *
     * @return mixed
     */
    public function remember(string $key, int $ttl, callable $compute, array $tags = [])
    {
        $marker = "\0af-miss\0";
        $value = $this->get($key, $marker);
        if ($value !== $marker) {
            return $value;
        }
        $value = $compute();
        $this->set($key, $value, $ttl, $tags);

        return $value;
    }

    public function delete(string $key): void
    {
        unset($this->memory[$key]);
        $file = $this->file($key);
        if (is_file($file)) {
            @unlink($file);
            $this->invalidate($file);
        }
    }

    /**
     * Invalida todo lo que lleve alguna de estas etiquetas (también las páginas guardadas).
     *
     * @param string[] $tags
     */
    public function flushTags(array $tags): void
    {
        if ($tags === []) {
            return;
        }
        $this->loadTags();
        foreach ($tags as $tag) {
            $this->tags[$tag] = (isset($this->tags[$tag]) ? $this->tags[$tag] : 0) + 1;
        }
        $this->write($this->dir . '/tags.php', "<?php\nreturn " . var_export($this->tags, true) . ";\n");
        $this->memory = [];
    }

    /** Vacía la caché entera del sitio. */
    public function flush(): void
    {
        $this->memory = [];
        $this->tags = null;
        self::removeDir($this->dir, false);
    }

    public function tagVersion(string $tag): int
    {
        $this->loadTags();

        return isset($this->tags[$tag]) ? (int) $this->tags[$tag] : 0;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    private function loadTags(): void
    {
        if ($this->tags !== null) {
            return;
        }
        $file = $this->dir . '/tags.php';
        $tags = is_file($file) ? @include $file : [];
        $this->tags = is_array($tags) ? $tags : [];
    }

    private function file(string $key): string
    {
        $hash = md5($key);

        return $this->dir . '/data/' . substr($hash, 0, 2) . '/' . $hash . '.php';
    }

    private function write(string $file, string $code): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $code, LOCK_EX) === false) {
            return;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return;
        }
        $this->invalidate($file);
    }

    private function invalidate(string $file): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    /**
     * Escribe un fichero PHP que devuelve un valor (`return [...];`), de forma atómica: se escribe a un temporal y se
     * cambia de nombre, así quien lo lee a la vez ve el viejo o el nuevo, nunca uno a medias. OPcache lo deja en memoria.
     * Lo usan las cachés del arranque (boot.php, settings.php, lang-xx.php). Devuelve false si no se pudo escribir.
     *
     * @param mixed $value escalares, null y arrays de ellos
     */
    public static function export(string $file, $value): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, "<?php\nreturn " . var_export($value, true) . ";\n", LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        return true;
    }

    /**
     * Lee un fichero escrito con export(). Null si no existe o está dañado (a medias, con basura): quien lo pide lo rehace.
     *
     * @return mixed|null
     */
    public static function import(string $file)
    {
        if (!is_file($file)) {
            return null;
        }
        // Un fichero machacado con texto sin PHP se escribiría en la salida al incluirlo: se recoge y se tira.
        ob_start();
        try {
            $value = @include $file;
        } catch (\Throwable $e) {
            $value = null;
        } finally {
            ob_end_clean();
        }

        return $value === false ? null : $value;
    }

    public static function removeDir(string $dir, bool $removeSelf = true): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach (is_array($items) ? $items : [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeDir($path, true);
            } else {
                @unlink($path);
            }
        }
        if ($removeSelf) {
            @rmdir($dir);
        }
    }
}

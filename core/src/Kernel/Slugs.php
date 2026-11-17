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

use Aifec\Db\Db;

/**
 * Direcciones amigables. Cada cosa publicada (producto, categoría, página, entrada…) tiene su ruta
 * completa guardada por idioma: «producto/silla-ergo», «categoria/sillas/oficina», «acerca-de».
 *
 * La ruta la calcula quien guarda la entidad, según el esquema de direcciones configurado, y la
 * registra con set(). Si la ruta cambia, la anterior se conserva como «no canónica» y el sistema
 * redirige de la vieja a la nueva: ninguna dirección publicada deja de funcionar.
 */
final class Slugs
{
    /** @var Db */
    private $db;

    /** @var array<string,string|null> «tipo:id:idioma» => ruta canónica */
    private $paths = [];

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * ¿A qué corresponde esta ruta?
     *
     * @return array{type:string,entity_id:int,canonical:bool,path:string}|null «path» es la ruta canónica vigente
     */
    public function resolve(string $lang, string $path): ?array
    {
        $path = self::clean($path);
        if ($path === '') {
            return null;
        }
        $row = $this->db->row('SELECT type, entity_id, canonical FROM {slugs} WHERE lang = ? AND path = ?', [$lang, $path]);
        if ($row === null) {
            return null;
        }
        $canonical = (int) $row['canonical'] === 1;
        $current = $canonical ? $path : $this->path((string) $row['type'], (int) $row['entity_id'], $lang);
        if ($current === null) {
            return null;
        }

        return ['type' => (string) $row['type'], 'entity_id' => (int) $row['entity_id'], 'canonical' => $canonical, 'path' => $current];
    }

    /**
     * Registra la ruta canónica de una entidad en un idioma y devuelve la que queda de verdad
     * (si otra entidad ya la usa, se le añade «-2», «-3»…).
     */
    public function set(string $type, int $id, string $lang, string $path): string
    {
        $path = self::clean($path);
        if ($path === '') {
            throw new \InvalidArgumentException('Empty slug path');
        }
        $final = $path;
        $n = 1;
        while (true) {
            $row = $this->db->row('SELECT id, type, entity_id FROM {slugs} WHERE lang = ? AND path = ?', [$lang, $final]);
            if ($row === null || ((string) $row['type'] === $type && (int) $row['entity_id'] === $id)) {
                break;
            }
            ++$n;
            $final = self::suffix($path, $n);
        }
        $this->db->update('slugs', ['canonical' => 0], ['type' => $type, 'entity_id' => $id, 'lang' => $lang]);
        $this->db->upsert('slugs', ['lang' => $lang, 'path' => $final, 'type' => $type, 'entity_id' => $id, 'canonical' => 1], ['lang', 'path']);
        $this->paths[$type . ':' . $id . ':' . $lang] = $final;

        return $final;
    }

    /** Ruta canónica de una entidad, o null si no tiene. */
    public function path(string $type, int $id, string $lang): ?string
    {
        $key = $type . ':' . $id . ':' . $lang;
        if (!array_key_exists($key, $this->paths)) {
            $value = $this->db->value(
                'SELECT path FROM {slugs} WHERE type = ? AND entity_id = ? AND lang = ? AND canonical = 1',
                [$type, $id, $lang]
            );
            $this->paths[$key] = $value !== null ? (string) $value : null;
        }

        return $this->paths[$key];
    }

    /**
     * Lee de una vez las rutas de muchas entidades del mismo tipo (listados).
     *
     * @param int[] $ids
     */
    public function preload(string $type, array $ids, string $lang): void
    {
        $missing = [];
        foreach ($ids as $id) {
            if (!array_key_exists($type . ':' . (int) $id . ':' . $lang, $this->paths)) {
                $missing[] = (int) $id;
            }
        }
        if ($missing === []) {
            return;
        }
        foreach ($missing as $id) {
            $this->paths[$type . ':' . $id . ':' . $lang] = null;
        }
        $params = [$type, $lang];
        $in = $this->db->in($missing, $params);
        foreach ($this->db->select('SELECT entity_id, path FROM {slugs} WHERE type = ? AND lang = ? AND canonical = 1 AND entity_id IN (' . $in . ')', $params) as $row) {
            $this->paths[$type . ':' . (int) $row['entity_id'] . ':' . $lang] = (string) $row['path'];
        }
    }

    /** Quita todas las rutas de una entidad (al borrarla del todo). */
    public function remove(string $type, int $id): void
    {
        $this->db->delete('slugs', ['type' => $type, 'entity_id' => $id]);
        foreach (array_keys($this->paths) as $key) {
            if (strpos($key, $type . ':' . $id . ':') === 0) {
                unset($this->paths[$key]);
            }
        }
    }

    /** Ruta sin barras en los extremos, sin barras repetidas y sin espacios. */
    public static function clean(string $path): string
    {
        $path = trim((string) preg_replace('#/+#', '/', str_replace('\\', '/', trim($path))), '/');

        return strlen($path) > 191 ? substr($path, 0, 191) : $path;
    }

    private static function suffix(string $path, int $n): string
    {
        // El sufijo va antes de la extensión, si la hay: «12-silla.html» → «12-silla-2.html».
        if (preg_match('/^(.*)(\.[a-z0-9]{2,5})$/i', $path, $m) === 1) {
            return $m[1] . '-' . $n . $m[2];
        }

        return $path . '-' . $n;
    }
}

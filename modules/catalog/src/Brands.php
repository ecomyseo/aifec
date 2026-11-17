<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog;

use Aifec\Kernel\App;

/**
 * Marcas: la ficha de una y la lista de las activas. Una marca desactivada no sale en ningún sitio.
 */
final class Brands
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * ['id','name','description','website','image','url','url_abs']; null si no existe o está desactivada.
     *
     * @return array<string,mixed>|null
     */
    public function brand(int $id, string $lang): ?array
    {
        $row = $this->app->db()->row('SELECT * FROM {brands} WHERE id = ? AND active = 1', [$id]);

        return $row === null ? null : $this->shape($row, $this->image($row), $lang);
    }

    /**
     * Las marcas activas por orden (posición y nombre).
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(string $lang): array
    {
        $cache = $this->app->cache();
        $key = 'brands:' . $lang;
        $hit = $cache->get($key);
        if (is_array($hit)) {
            return $hit;
        }
        $rows = $this->app->db()->select('SELECT * FROM {brands} WHERE active = 1 ORDER BY position, name, id');
        $ids = [];
        $mediaIds = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
            if (isset($row['media_id']) && $row['media_id'] !== null && (int) $row['media_id'] > 0) {
                $mediaIds[(int) $row['media_id']] = true;
            }
        }
        $slugs = $this->app->slugs();
        foreach (array_values(array_unique([$lang, $this->app->defaultLang()])) as $l) {
            $slugs->preload('brand', $ids, $l);
        }
        $images = Images::many($this->app, array_keys($mediaIds));
        $out = [];
        foreach ($rows as $row) {
            $mediaId = isset($row['media_id']) && $row['media_id'] !== null ? (int) $row['media_id'] : 0;
            $out[] = $this->shape($row, isset($images[$mediaId]) ? $images[$mediaId] : null, $lang);
        }
        $cache->set($key, $out, 3600, ['catalog']);

        return $out;
    }

    /**
     * @param array<string,mixed>      $row
     * @param array<string,mixed>|null $image
     *
     * @return array<string,mixed>
     */
    private function shape(array $row, ?array $image, string $lang): array
    {
        $id = (int) $row['id'];

        return [
            'id' => $id,
            'name' => (string) $row['name'],
            'description' => isset($row['description']) ? (string) $row['description'] : '',
            'website' => isset($row['website']) && $row['website'] !== null ? (string) $row['website'] : '',
            'image' => $image,
            'url' => $this->app->url()->entity('brand', $id, $lang),
            'url_abs' => $this->app->url()->entityAbs('brand', $id, $lang),
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>|null
     */
    private function image(array $row): ?array
    {
        $mediaId = isset($row['media_id']) && $row['media_id'] !== null ? (int) $row['media_id'] : 0;
        if ($mediaId <= 0) {
            return null;
        }
        $images = Images::many($this->app, [$mediaId]);

        return isset($images[$mediaId]) ? $images[$mediaId] : null;
    }
}

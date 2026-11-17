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

use Aifec\Modules\Cms\Cms;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\WriteContext;
use Aifec\Text\UrlFilter;
use Aifec\Util\Str;

/**
 * Los menús del origen, por ubicación (main, footer, legal; cualquier otra se crea con su nombre). Se guardan con el propio servicio
 * de menús, que descarta lo que no es válido, y los elementos se enlazan a lo ya traído por su pareja (páginas, entradas,
 * categorías de la tienda, productos y categorías del blog). Lo que no case se queda como enlace con su dirección del origen si la
 * trae; sin ella no hay a dónde apuntar y se deja fuera con una nota. Aquí los menús llegan a dos niveles: lo más profundo se aplana
 * al segundo.
 *
 * Un menú de esa ubicación que el comerciante ya tenía con elementos no se pisa: choca. Los menús no llevan fecha de modificación, así
 * que para saber si se editaron aquí se guarda, al escribirlos, una huella de sus elementos en el ajuste «import.fp.menus.<id>».
 */
final class MenusWriter extends WriterBase
{
    /** Tipo del origen → tipo del servicio de menús y tipo de pareja de lo que enlaza. */
    private const TARGETS = [
        'page' => ['page', 'pages'], 'post' => ['post', 'posts'], 'category' => ['category', 'categories'], 'product' => ['product', 'products'],
        'post_category' => ['term', 'post_categories'],
    ];

    public function type(): string
    {
        return 'menus';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $code = $this->menuCode($record);
        $found = $this->resolve('menus', $record, $ctx, static function (int $localId) use ($db): ?array {
            return $db->find('menus', ['id' => $localId]);
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        $same = $db->row('SELECT id FROM {menus} WHERE code = ?', [$code]);
        if ($row === null && $same !== null) {
            $items = (int) $db->value('SELECT COUNT(*) FROM {menu_items} WHERE menu_id = ?', [(int) $same['id']]);
            if ($items > 0 || $this->claimed('menus', (int) $same['id'], $id)) {
                return $this->result(self::CONFLICT, (int) $same['id'], 'A menu for this location already exists here and was left as it is');
            }
            $row = $same;
            $action = self::UPDATED;
        }
        if ($action === self::CONFLICT || ($action === self::UPDATED && $row !== null && $this->mappedHere($id, $ctx) && $this->edited((int) $row['id'], (string) $row['code']) && !(bool) $ctx->option('overwrite_local', false))) {
            return $this->conflict((int) $row['id']);
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }

        $name = isset($record['name']) && is_string($record['name']) && trim($record['name']) !== '' ? trim($record['name']) : $code;
        $localId = $this->save($record, $code, $this->cut($name, 120), $ctx);
        $ctx->remember('menus', $id, $localId, Neutral::hash($record));

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    public function link(array $record, WriteContext $ctx): void
    {
        if ($ctx->dryRun()) {
            return;
        }
        $db = $this->db();
        $localId = $ctx->localId('menus', (string) $record['id']);
        $row = $localId !== null ? $db->find('menus', ['id' => $localId]) : null;
        if ($row === null || $this->edited((int) $row['id'], (string) $row['code'])) {
            return;
        }
        $this->quiet = true;

        try {
            $this->save($record, (string) $row['code'], (string) $row['name'], $ctx);
        } finally {
            $this->quiet = false;
        }
    }

    public function checksums(WriteContext $ctx): array
    {
        return ['menus_count' => $this->mappedCount('menus', 'menus')];
    }

    // ------------------------------------------------------------------ guardar

    /**
     * Guarda el menú entero con el servicio de menús y apunta su huella. Devuelve su identificador.
     *
     * @param array<string,mixed> $record
     */
    private function save(array $record, string $code, string $name, WriteContext $ctx): int
    {
        $cms = new Cms($this->app);
        $items = $this->flatten(isset($record['items']) && is_array($record['items']) ? $record['items'] : [], 0, $ctx);
        $cms->menus()->save($code, $name, $items);
        $menuId = (int) $this->db()->value('SELECT id FROM {menus} WHERE code = ?', [$code]);
        $this->app->settings()->set('import.fp.menus.' . $menuId, $this->fingerprint($code));

        return $menuId;
    }

    /**
     * El código de la ubicación: main, footer y legal tal cual; otra, con su nombre en minúsculas y sin signos.
     *
     * @param array<string,mixed> $record
     */
    private function menuCode(array $record): string
    {
        $location = isset($record['location']) && is_string($record['location']) ? trim($record['location']) : '';
        if (in_array($location, ['main', 'footer', 'legal'], true)) {
            return $location;
        }
        $slug = Str::slug($location, 40);

        return $slug !== '' ? $slug : 'menu-' . $this->cut((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $record['id'])), 30);
    }

    /**
     * Los elementos con la forma que pide el servicio de menús: tipo, referencia, dirección, nivel (0 o 1) y etiquetas por idioma.
     *
     * @param array<int|string,mixed> $items
     *
     * @return array<int,array<string,mixed>>
     */
    private function flatten(array $items, int $level, WriteContext $ctx): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['type']) || !is_string($item['type'])) {
                continue;
            }
            $resolved = $this->target($item, $ctx);
            if ($resolved === null) {
                $this->noteOnce($ctx, 'dropped', 'A menu item could not be linked to anything that was brought and has no address of its own: it was left out');
            } else {
                $labels = $this->texts(isset($item['label']) && is_array($item['label']) ? $item['label'] : [], $ctx);
                if ($labels === []) {
                    $labels = [$this->app->defaultLang() => $resolved['url'] !== null ? $this->cut($resolved['url'], 120) : 'Link'];
                }
                $out[] = ['type' => $resolved['type'], 'ref' => $resolved['ref'], 'url' => $resolved['url'], 'blank' => 0, 'level' => $level, 'labels' => $labels];
            }
            if (isset($item['children']) && is_array($item['children'])) {
                $out = array_merge($out, $this->flatten($item['children'], 1, $ctx));
                if ($level === 1) {
                    $this->noteOnce($ctx, 'flat', 'The menu has more than two levels here: the deepest items were moved up to the second level');
                }
            }
        }

        return $out;
    }

    /**
     * A qué apunta un elemento: lo traído (por su pareja), la dirección que traiga el elemento, o nada.
     *
     * @param array<string,mixed> $item
     *
     * @return array{type:string,ref:?int,url:?string}|null
     */
    private function target(array $item, WriteContext $ctx): ?array
    {
        $type = (string) $item['type'];
        $url = isset($item['url']) && is_string($item['url']) ? UrlFilter::safe($item['url']) : null;
        if ($type === 'home') {
            return ['type' => 'home', 'ref' => null, 'url' => null];
        }
        if ($type === 'url') {
            return $url !== null ? ['type' => 'url', 'ref' => null, 'url' => $url] : null;
        }
        $ref = isset($item['ref']) && is_scalar($item['ref']) ? (string) $item['ref'] : '';
        if (isset(self::TARGETS[$type]) && $ref !== '') {
            $local = $ctx->localId(self::TARGETS[$type][1], $ref);
            if ($local !== null) {
                return ['type' => self::TARGETS[$type][0], 'ref' => $local, 'url' => null];
            }
        } elseif ($type === 'brand' && $ref !== '') {
            $local = $ctx->localId('brands', $ref);
            $path = $local !== null ? $this->app->slugs()->path('brand', $local, $this->app->defaultLang()) : null;
            if ($path !== null) {
                return ['type' => 'url', 'ref' => null, 'url' => '/' . $path];
            }
        }
        if ($url !== null) {
            $this->noteOnce($ctx, 'as-link', 'A menu item refers to something that was not brought: it was kept as a link to its old address');

            return ['type' => 'url', 'ref' => null, 'url' => $url];
        }

        return null;
    }

    // ------------------------------------------------------------------ huella

    /**
     * Los elementos que hay ahora en el menú, resumidos.
     */
    private function fingerprint(string $code): string
    {
        $cms = new Cms($this->app);

        return sha1((string) json_encode($cms->menus()->editable($code), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** ¿Se tocó aquí después de traerlo? (su huella ya no es la guardada) */
    private function edited(int $localId, string $code): bool
    {
        $stored = $this->app->settings()->str('import.fp.menus.' . $localId, '');

        return $stored !== '' && $stored !== $this->fingerprint($code);
    }

    /** ¿Hay una pareja guardada de este menú? (un menú que ya existía y se adopta no tiene huella ni se puede haber tocado) */
    private function mappedHere(string $id, WriteContext $ctx): bool
    {
        return $ctx->mapped('menus', $id) !== null;
    }
}

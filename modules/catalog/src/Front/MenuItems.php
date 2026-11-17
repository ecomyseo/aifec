<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Front;

use Aifec\Kernel\App;
use Aifec\Modules\Catalog\Catalog;

/**
 * El menú principal de serie, por el filtro «menu.items»: si nadie ha aportado elementos a la ubicación «main» (el comerciante no
 * ha compuesto el suyo), las categorías de primer nivel que están «en el menú», con sus hijas. Si solo hay UNA de primer
 * nivel y tiene hijas en el menú, el menú son esas hijas (P100: «Calzado» solo no deja «Hombre» y «Mujer» a un clic).
 */
final class MenuItems
{
    /**
     * @param mixed $items    lo que ya hay: [['label', 'url', 'current', 'children']]
     * @param mixed $location «main», «footer», «legal»…
     * @param mixed $app
     *
     * @return array<int,array<string,mixed>>
     */
    public static function add($items, $location, $app): array
    {
        $items = is_array($items) ? array_values($items) : [];
        if ($location !== 'main' || $items !== [] || !($app instanceof App) || !$app->has('catalog')) {
            return $items;
        }
        // El menú ya montado, de la caché del sitio (una entrada por idioma, etiqueta «catalog», sin caducidad por tiempo): la
        // cabecera y el panel del móvil lo piden en cada página y casi nunca cambia. Lo de «dónde estás» se marca al leer.
        $built = $app->cache()->remember('catalog:menu:' . $app->lang(), 0, static function () use ($app): array {
            /** @var Catalog $catalog */
            $catalog = $app->service('catalog');
            $inMenu = [];
            foreach ($app->db()->select('SELECT id FROM {categories} WHERE active = 1 AND in_menu = 1') as $row) {
                $inMenu[(int) $row['id']] = true;
            }
            if ($inMenu === []) {
                return [];
            }
            $built = self::build($catalog->tree(), $inMenu);
            // Una tienda con UNA sola categoría principal («Calzado») enseña sus hijas: con ella sola, nada queda a un clic.
            if (count($built) === 1 && $built[0]['children'] !== []) {
                return $built[0]['children'];
            }

            return $built;
        }, ['catalog']);
        if (!is_array($built) || $built === []) {
            return $items;
        }

        return self::mark($built, rtrim($app->url()->to($app->request()->path), '/'));
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @param array<int,bool>                $inMenu
     *
     * @return array<int,array<string,mixed>>
     */
    private static function build(array $nodes, array $inMenu): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!isset($inMenu[(int) $node['id']])) {
                continue;
            }
            $out[] = [
                'label' => (string) $node['name'],
                'url' => (string) $node['url'],
                'current' => false,
                'children' => self::build(is_array($node['children']) ? $node['children'] : [], $inMenu),
            ];
        }

        return $out;
    }

    /**
     * Marca como «current» el elemento cuya dirección es la de la petición.
     *
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<int,array<string,mixed>>
     */
    private static function mark(array $items, string $here): array
    {
        foreach ($items as $i => $item) {
            $items[$i]['current'] = $here !== '' && rtrim((string) $item['url'], '/') === $here;
            $items[$i]['children'] = self::mark(isset($item['children']) && is_array($item['children']) ? $item['children'] : [], $here);
        }

        return $items;
    }
}

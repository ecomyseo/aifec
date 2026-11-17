<?php
/**
 * AIFEC — menú del panel: une lo que declara cada módulo en su admin.php y deja solo lo que la persona puede ver.
 *
 * Un módulo declara:
 *   'menu' => [
 *       ['group' => 'catalog', 'items' => [
 *           ['label' => 'Products', 'url' => '/admin/products', 'cap' => 'catalog.view', 'order' => 10],
 *           ['label' => 'Brands', 'resource' => 'brands', 'cap' => 'catalog.view', 'order' => 40],
 *       ]],
 *   ]
 * Los grupos de serie (home, sales, catalog, customers, content, marketing, ai, design, plugins, settings, system)
 * los declara el núcleo con su texto, icono y orden; un módulo puede declarar un grupo nuevo dando 'label', 'icon' y 'order'.
 * Un elemento apunta con 'url' (ruta del sitio), 'route' (nombre de ruta), 'resource' (recurso) o 'settings' (ajustes de un módulo).
 *
 * Pantallas hermanas en pestañas (opcional; quien no lo usa sale como siempre, una entrada por elemento):
 *   'tabs'       => 'taxes'   conjunto al que pertenece. El menú enseña UNA entrada por conjunto (la del primer elemento por
 *                             orden que la persona puede ver) y cada pantalla del conjunto pinta, bajo el título, una barra
 *                             de pestañas con todas las que esa persona puede ver. Cada pestaña es un enlace a su pantalla
 *                             de siempre: misma dirección y mismo permiso. El conjunto puede repartirse entre módulos.
 *   'tab'        => 'Classes' texto de su pestaña (por defecto, 'label').
 *   'menu_label' => 'Taxes'   texto de la entrada del menú: el nombre del conjunto (o el de un elemento suelto, si no es su
 *                             'label'). Con una sola pantalla visible del conjunto, la entrada lleva el 'label' de esa pantalla.
 *   'hidden'     => true      no sale en el menú ni en la barra (una pantalla a la que se llega con un botón): solo la
 *                             encuentra el buscador del panel.
 * 'label' es el nombre de siempre de la pantalla: con él la encuentra el buscador del panel (Ctrl + K).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Admin;

use Aifec\Kernel\App;

final class Menu
{
    /**
     * @return array<int,array<string,mixed>> grupos: id, label, icon, url (si no tiene hijos), open, active,
     *                                        items[label, path, url, order, active, tabs[label, path, url, active]]
     */
    public static function build(App $app, string $currentPath): array
    {
        $groups = self::groups($app);
        $screens = self::screens($app, $groups);

        // Entradas: un elemento suelto es una entrada; un conjunto, una sola (en el grupo de su primer elemento).
        $sets = [];
        $entries = [];
        foreach ($screens as $i => $screen) {
            if ($screen['set'] !== '') {
                $sets[$screen['set']][] = $i;
            } elseif (!$screen['hidden']) {
                $entries[$screen['group']][] = [
                    'label' => $screen['menu'] !== '' ? $screen['menu'] : $screen['label'],
                    'path' => $screen['path'],
                    'url' => $screen['url'],
                    'order' => $screen['order'],
                    'active' => false,
                    'paths' => [$screen['path']],
                    'tabs' => [],
                ];
            }
        }
        foreach ($sets as $members) {
            $visible = [];
            $paths = [];
            foreach ($members as $i) {
                $paths[] = $screens[$i]['path'];
                if (!$screens[$i]['hidden']) {
                    $visible[] = $screens[$i];
                }
            }
            if ($visible === []) {
                continue;
            }
            usort($visible, [self::class, 'compare']);
            $lead = $visible[0];
            $label = $lead['label'];
            $tabs = [];
            if (count($visible) > 1) {
                foreach ($visible as $member) {
                    if ($member['menu'] !== '') {
                        $label = $member['menu'];
                        break;
                    }
                }
                foreach ($visible as $member) {
                    $tabs[] = ['label' => $member['tab'], 'path' => $member['path'], 'url' => $member['url'], 'active' => false];
                }
            }
            $entries[$lead['group']][] = [
                'label' => $label,
                'path' => $lead['path'],
                'url' => $lead['url'],
                'order' => $lead['order'],
                'active' => false,
                'paths' => $paths,
                'tabs' => $tabs,
            ];
        }

        $auth = $app->auth();
        $out = [];
        foreach ($groups as $id => $group) {
            $items = isset($entries[$id]) ? $entries[$id] : [];
            usort($items, [self::class, 'compare']);
            $single = isset($group['url']) ? (string) $group['url'] : '';
            if ($items === [] && ($single === '' || (isset($group['cap']) && !$auth->can((string) $group['cap'])))) {
                continue;
            }
            $out[] = [
                'id' => $group['id'],
                'label' => t((string) $group['label']),
                'icon' => (string) $group['icon'],
                'order' => (int) $group['order'],
                'path' => $single,
                'url' => $single !== '' ? $app->url()->plain($single) : '',
                'open' => false,
                'active' => false,
                'items' => $items,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return $a['order'] - $b['order'];
        });

        // Se marca la entrada (y su pestaña) cuya ruta es el prefijo más largo de la dirección actual.
        $current = '/' . trim($currentPath, '/');
        $best = -1;
        $bestAt = null;
        foreach ($out as $g => $group) {
            $candidates = $group['items'] !== [] ? $group['items'] : [['paths' => [$group['path']]]];
            foreach ($candidates as $i => $item) {
                foreach ($item['paths'] as $raw) {
                    $path = rtrim((string) $raw, '/');
                    if ($path === '') {
                        continue;
                    }
                    $matches = $current === $path || ($path !== '/admin' && strpos($current . '/', $path . '/') === 0);
                    if ($matches && strlen($path) > $best) {
                        $best = strlen($path);
                        $bestAt = [$g, $group['items'] !== [] ? $i : null, $path];
                    }
                }
            }
        }
        if ($bestAt !== null) {
            $out[$bestAt[0]]['open'] = true;
            if ($bestAt[1] === null) {
                $out[$bestAt[0]]['active'] = true;
            } else {
                $out[$bestAt[0]]['items'][$bestAt[1]]['active'] = true;
                foreach ($out[$bestAt[0]]['items'][$bestAt[1]]['tabs'] as $t => $tab) {
                    $out[$bestAt[0]]['items'][$bestAt[1]]['tabs'][$t]['active'] = rtrim((string) $tab['path'], '/') === $bestAt[2];
                }
            }
        }

        return $out;
    }

    /**
     * La barra de pestañas de la pantalla actual: la de la entrada marcada, si agrupa dos pantallas o más que la persona
     * puede ver. Vacía si la pantalla no es de ningún conjunto.
     *
     * @param array<int,array<string,mixed>> $menu lo que devuelve build()
     *
     * @return array{label?:string,items?:array<int,array{label:string,path:string,url:string,active:bool}>}
     */
    public static function tabs(array $menu): array
    {
        foreach ($menu as $group) {
            foreach ($group['items'] as $item) {
                if ($item['active'] && isset($item['tabs']) && count($item['tabs']) > 1) {
                    return ['label' => (string) $item['label'], 'items' => $item['tabs']];
                }
            }
        }

        return [];
    }

    /**
     * Lista plana para el buscador de órdenes del panel (Ctrl + K): todas las pantallas que la persona puede ver, con su
     * nombre de siempre, también las que ahora son una pestaña o un botón. Si dos se llaman igual, se distinguen por su
     * pestaña.
     *
     * @return array<int,array{label:string,group:string,url:string}>
     */
    public static function flat(App $app): array
    {
        $groups = self::groups($app);
        $screens = self::screens($app, $groups);
        $count = [];
        foreach ($screens as $screen) {
            $count[$screen['label']] = isset($count[$screen['label']]) ? $count[$screen['label']] + 1 : 1;
        }
        $labels = [];
        foreach ($groups as $id => $group) {
            $labels[$id] = t((string) $group['label']);
        }

        $flat = [];
        foreach (self::build($app, '') as $group) {
            if ($group['items'] === [] && $group['url'] !== '') {
                $flat[] = ['label' => $group['label'], 'group' => '', 'url' => $group['url']];
            }
        }
        usort($screens, static function (array $a, array $b) use ($groups): int {
            $ga = (int) $groups[$a['group']]['order'];
            $gb = (int) $groups[$b['group']]['order'];

            return $ga === $gb ? self::compare($a, $b) : $ga - $gb;
        });
        foreach ($screens as $screen) {
            $label = $screen['label'];
            if ($count[$label] > 1 && $screen['tab'] !== $label) {
                $label .= ' (' . $screen['tab'] . ')';
            }
            $flat[] = ['label' => $label, 'group' => $labels[$screen['group']], 'url' => $screen['url']];
        }

        return $flat;
    }

    /**
     * Los grupos con los elementos que declaran los módulos, sin filtrar.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function groups(App $app): array
    {
        $groups = [];
        foreach ($app->adminDefinitions() as $definition) {
            foreach (isset($definition['menu']) && is_array($definition['menu']) ? $definition['menu'] : [] as $entry) {
                if (!is_array($entry) || !isset($entry['group'])) {
                    continue;
                }
                $id = (string) $entry['group'];
                if (!isset($groups[$id])) {
                    $groups[$id] = ['id' => $id, 'label' => $id, 'icon' => 'dot', 'order' => 500, 'items' => []];
                }
                foreach (['label', 'icon', 'order', 'url', 'cap'] as $key) {
                    if (isset($entry[$key])) {
                        $groups[$id][$key] = $entry[$key];
                    }
                }
                foreach (isset($entry['items']) && is_array($entry['items']) ? $entry['items'] : [] as $item) {
                    if (is_array($item) && isset($item['label'])) {
                        $groups[$id]['items'][] = $item;
                    }
                }
            }
        }

        return $groups;
    }

    /**
     * Las pantallas que la persona puede ver, ya traducidas: group, label, menu, tab, set, hidden, path, url, order.
     *
     * @param array<string,array<string,mixed>> $groups
     *
     * @return array<int,array{group:string,label:string,menu:string,tab:string,set:string,hidden:bool,path:string,url:string,order:int}>
     */
    private static function screens(App $app, array $groups): array
    {
        $auth = $app->auth();
        $out = [];
        foreach ($groups as $id => $group) {
            foreach ($group['items'] as $item) {
                if (isset($item['cap']) && !$auth->can((string) $item['cap'])) {
                    continue;
                }
                $path = self::path($app, $item);
                if ($path === '') {
                    continue;
                }
                $label = t((string) $item['label']);
                $out[] = [
                    'group' => (string) $id,
                    'label' => $label,
                    'menu' => isset($item['menu_label']) && is_string($item['menu_label']) && $item['menu_label'] !== '' ? t($item['menu_label']) : '',
                    'tab' => isset($item['tab']) && is_string($item['tab']) && $item['tab'] !== '' ? t($item['tab']) : $label,
                    'set' => isset($item['tabs']) && is_string($item['tabs']) ? $item['tabs'] : '',
                    'hidden' => !empty($item['hidden']),
                    'path' => $path,
                    'url' => $app->url()->plain($path),
                    'order' => isset($item['order']) ? (int) $item['order'] : 100,
                ];
            }
        }

        return $out;
    }

    /**
     * Por orden y, a igualdad, por nombre.
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private static function compare(array $a, array $b): int
    {
        return $a['order'] === $b['order'] ? strcmp((string) $a['label'], (string) $b['label']) : (int) $a['order'] - (int) $b['order'];
    }

    /**
     * @param array<string,mixed> $item
     */
    private static function path(App $app, array $item): string
    {
        if (isset($item['url'])) {
            return '/' . ltrim((string) $item['url'], '/');
        }
        if (isset($item['resource'])) {
            return '/admin/r/' . $item['resource'];
        }
        if (isset($item['settings'])) {
            return '/admin/settings/' . $item['settings'];
        }
        if (isset($item['route']) && $app->router()->has((string) $item['route'])) {
            return $app->router()->path((string) $item['route'], isset($item['params']) && is_array($item['params']) ? $item['params'] : []);
        }

        return '';
    }
}

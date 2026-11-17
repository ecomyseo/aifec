<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Util\Str;

/**
 * El buscador de órdenes del panel (Ctrl + K), ampliado por el filtro «admin.palette» sin tocar el núcleo:
 *
 *   «Hacer: …»   si el texto parece una orden que el copiloto entiende (y la persona puede hacer), la primera entrada abre el
 *                copiloto con la propuesta ya preparada;
 *   ajustes      por intención («iva», «envío gratis», «logo»…), con el campo señalado;
 *   datos        pedidos (número, correo o nombre), productos (nombre, referencia o código de barras), clientes (correo, nombre
 *                o teléfono) y cupones (código): como mucho cinco de cada grupo y solo lo que el permiso de la persona deja ver;
 *   ayuda        páginas del manual, si el módulo «docs» está activo.
 *
 * Si algo falla, se anota y el buscador sigue con lo que tenía: nunca rompe el Ctrl + K.
 */
final class Palette
{
    /** Resultados por grupo. */
    private const PER_GROUP = 5;

    /**
     * @param mixed $items lo que llevan los filtros anteriores
     *
     * @return array<int,array{label:string,group:string,url:string}>
     */
    public static function filter($items, string $q, App $app): array
    {
        $items = is_array($items) ? array_values($items) : [];
        $q = trim($q);
        if (mb_strlen($q, 'UTF-8') < 2 || $app->auth()->admin() === null) {
            return $items;
        }
        $q = mb_substr($q, 0, 160, 'UTF-8');
        // Lo que ya pusieron otros módulos (el catálogo, los pedidos, las páginas) no se repite.
        $seen = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['url'])) {
                $seen[(string) $item['url']] = true;
            }
        }
        $mine = [];
        foreach (['doItem', 'settingsItems', 'dataItems', 'helpItems'] as $part) {
            try {
                foreach (call_user_func([self::class, $part], $app, $q) as $item) {
                    if (!isset($seen[$item['url']])) {
                        $seen[$item['url']] = true;
                        $mine[] = $item;
                    }
                }
            } catch (\Throwable $e) {
                $app->log()->exception($e, 'copilot palette ' . $part);
            }
        }

        return array_merge($items, $mine);
    }

    /**
     * «Hacer: …»: abre el copiloto con la orden ya entendida.
     *
     * @return array<int,array{label:string,group:string,url:string}>
     */
    public static function doItem(App $app, string $q): array
    {
        if (!$app->auth()->can('copilot.use')) {
            return [];
        }
        /** @var Copilot $copilot */
        $copilot = $app->service('copilot');
        $actions = $copilot->actions();
        $actions->reset();
        foreach ((new Understand($app, $actions))->candidates($q) as $candidate) {
            $def = $actions->get($candidate['action']);
            if ($def !== null && !$def['readonly'] && $actions->allowed($def, $candidate['args'])) {
                return [['label' => t('Do: {text}', ['text' => Str::limit($q, 80)]), 'group' => t('Copilot'), 'url' => $app->url()->plain('/admin/copilot', ['q' => $q])]];
            }
        }

        return [];
    }

    /**
     * @return array<int,array{label:string,group:string,url:string}>
     */
    public static function settingsItems(App $app, string $q): array
    {
        return Intents::search($app, $q, self::PER_GROUP);
    }

    /**
     * Pedidos, productos, clientes y cupones.
     *
     * @return array<int,array{label:string,group:string,url:string}>
     */
    public static function dataItems(App $app, string $q): array
    {
        $out = [];
        $auth = $app->auth();
        $modules = $app->modules();
        $db = $app->db();
        $words = Finder::words($q);
        $money = static function (int $cents) use ($app): string {
            return Fmt::money($app, $cents);
        };

        if ($modules->isLoaded('orders') && $auth->can('orders.view')) {
            $rows = [];
            if (preg_match('/^#?\s*(\d[\d\-]*)$/', $q, $m) === 1) {
                $ref = ltrim($m[1], '0');
                foreach ($db->select('SELECT id, number, email, total FROM {orders} WHERE number LIKE ?' . Like::ESC . ' ORDER BY id DESC LIMIT 40', ['%' . Like::escape($m[1]) . '%']) as $row) {
                    $last = preg_match('/(\d+)$/', (string) $row['number'], $n) === 1 ? ltrim($n[1], '0') : '';
                    if ($last === $ref || strcasecmp((string) $row['number'], $m[1]) === 0) {
                        $rows[] = $row;
                    }
                }
            } elseif ($words !== []) {
                $join = $db->tableExists('customers') ? 'LEFT JOIN {customers} c ON c.id = o.customer_id' : '';
                $where = [];
                $params = [];
                foreach ($words as $word) {
                    $like = Like::contains($word);
                    $part = 'o.email LIKE ?' . Like::ESC . ' OR o.billing LIKE ?' . Like::ESC;
                    $params[] = $like;
                    $params[] = $like;
                    if ($join !== '') {
                        $part .= ' OR c.first_name LIKE ?' . Like::ESC . ' OR c.last_name LIKE ?' . Like::ESC;
                        $params[] = $like;
                        $params[] = $like;
                    }
                    $where[] = '(' . $part . ')';
                }
                $params[] = self::PER_GROUP;
                $rows = $db->select('SELECT o.id, o.number, o.email, o.total FROM {orders} o ' . $join . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY o.id DESC LIMIT ?', $params);
            }
            foreach (array_slice($rows, 0, self::PER_GROUP) as $row) {
                $out[] = [
                    'label' => t('Order {number}', ['number' => (string) $row['number']]) . ' · ' . (string) $row['email'] . ' · ' . $money((int) $row['total']),
                    'group' => t('Orders'), 'url' => $app->url()->plain('/admin/orders/' . (int) $row['id']),
                ];
            }
        }

        if ($modules->isLoaded('catalog') && $auth->can('catalog.view')) {
            $seen = [];
            foreach (Finder::products($app, $q, self::PER_GROUP) as $candidate) {
                if (isset($seen[$candidate['id']])) {
                    continue;
                }
                $seen[$candidate['id']] = true;
                $out[] = ['label' => $candidate['label'], 'group' => t('Products'), 'url' => Admin::resourceUrl($app, 'products', $candidate['id'])];
            }
        }

        if ($modules->isLoaded('customers') && $auth->can('customers.view') && $words !== []) {
            $where = [];
            $params = [];
            foreach ($words as $word) {
                $like = Like::contains($word);
                $where[] = '(email LIKE ?' . Like::ESC . ' OR first_name LIKE ?' . Like::ESC . ' OR last_name LIKE ?' . Like::ESC . ' OR phone LIKE ?' . Like::ESC . ')';
                array_push($params, $like, $like, $like, $like);
            }
            $params[] = self::PER_GROUP;
            foreach ($db->select('SELECT id, email, first_name, last_name FROM {customers} WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ?', $params) as $row) {
                $name = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
                $out[] = ['label' => ($name !== '' ? $name . ' · ' : '') . (string) $row['email'], 'group' => t('Customers'), 'url' => Admin::resourceUrl($app, 'customers', (int) $row['id'])];
            }
        }

        if ($modules->isLoaded('coupons') && $auth->can('coupons.view')) {
            foreach ($db->select('SELECT id, code FROM {coupons} WHERE code LIKE ?' . Like::ESC . ' ORDER BY id DESC LIMIT ?', [Like::contains(strtoupper($q)), self::PER_GROUP]) as $row) {
                $out[] = ['label' => t('Coupon {code}', ['code' => (string) $row['code']]), 'group' => t('Coupons'), 'url' => Admin::resourceUrl($app, 'coupons', (int) $row['id'])];
            }
        }

        return $out;
    }

    /**
     * Páginas del manual (solo si el módulo «docs» está activo y tiene documentación).
     *
     * @return array<int,array{label:string,group:string,url:string}>
     */
    public static function helpItems(App $app, string $q): array
    {
        if (!$app->modules()->isLoaded('docs') || !$app->has('docs')) {
            return [];
        }
        $docs = $app->service('docs');
        $class = '\\Aifec\\Modules\\Docs\\Search';
        if (!is_object($docs) || !class_exists($class) || $docs->library()->isEmpty()) {
            return [];
        }
        $out = [];
        foreach ((new $class($docs))->run($q, 3) as $hit) {
            $out[] = ['label' => (string) $hit['page']['title'], 'group' => t('Help'), 'url' => $app->url()->to('/docs/' . $hit['page']['path'])];
        }

        return $out;
    }
}

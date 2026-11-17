<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Cms;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Pantallas de los menús: la lista de ubicaciones del tema y, por cada una, el editor (elementos ordenables y anidables en dos
 * niveles, con su etiqueta por idioma, que se guardan todos de una vez) y el buscador del editor.
 */
final class MenuController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    private function menus(): Menus
    {
        /** @var Cms $cms */
        $cms = $this->app->service('cms');

        return $cms->menus();
    }

    /**
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('content.view');
        $menus = $this->menus();
        $counts = [];
        foreach ($this->app->db()->select('SELECT m.code AS code, COUNT(i.id) AS n FROM {menus} m LEFT JOIN {menu_items} i ON i.menu_id = m.id GROUP BY m.code') as $row) {
            $counts[(string) $row['code']] = (int) $row['n'];
        }
        $locations = [];
        foreach ($menus->locations() as $code => $name) {
            $locations[] = ['code' => $code, 'name' => $name, 'count' => isset($counts[$code]) ? $counts[$code] : 0, 'url' => $this->app->url()->plain('/admin/cms/menus/' . $code)];
        }

        return Admin::page($this->app, 'cms::admin/menus', ['locations' => $locations], [
            'title' => t('Menus'),
            'active' => '/admin/cms/menus',
            'help' => Admin::helpText('The theme decides where each menu appears. Choose one to change its links.'),
        ]);
    }

    /**
     * @param array<string,string> $params code
     */
    public function edit(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('content.view');
        $menus = $this->menus();
        $locations = $menus->locations();
        $code = isset($params['code']) ? (string) $params['code'] : '';
        if (!isset($locations[$code])) {
            throw HttpError::notFound();
        }
        $items = [];
        foreach ($menus->editable($code) as $item) {
            $item['text'] = $menus->describe($item);
            $items[] = $item;
        }
        $kinds = [
            'home' => t('Home page'), 'page' => t('Page'), 'post' => t('Post'), 'term' => t('Blog category or tag'), 'url' => t('Link'),
        ];
        if ($this->app->has('catalog')) {
            $kinds['category'] = t('Product category');
            $kinds['product'] = t('Product');
        }

        return Admin::page($this->app, 'cms::admin/menu', [
            'code' => $code,
            'name' => $locations[$code],
            'items' => $items,
            'languages' => $this->app->languages(),
            'defaultLang' => $this->app->defaultLang(),
            'kinds' => $kinds,
            'canEdit' => $this->app->auth()->can('content.edit'),
            'saveUrl' => $this->app->url()->plain('/admin/cms/menus/' . $code),
            'searchUrl' => $this->app->url()->plain('/admin/cms/menus/search'),
            'script' => $this->app->url()->asset('m', 'cms', 'menus.js'),
        ], [
            'title' => $locations[$code],
            'crumbs' => [[t('Menus'), $this->app->url()->plain('/admin/cms/menus')]],
            'active' => '/admin/cms/menus',
        ]);
    }

    /**
     * Guarda el menú entero de una vez.
     *
     * @param array<string,string> $params code
     */
    public function save(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('content.edit');
        $menus = $this->menus();
        $locations = $menus->locations();
        $code = isset($params['code']) ? (string) $params['code'] : '';
        if (!isset($locations[$code])) {
            throw HttpError::notFound();
        }
        $items = $request->arr('items');
        $kept = $menus->save($code, $locations[$code], $items);
        Admin::audit($this->app, 'update', 'menus', $code, tn('{n} item', '{n} items', $kept));
        Admin::flash($this->app, 'ok', t('Saved'));

        return Response::redirect($this->app->url()->plain('/admin/cms/menus/' . $code));
    }

    /**
     * Candidatos para añadir al menú: ?type=page&q=…
     *
     * @param array<string,string> $params
     */
    public function search(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('content.view');
        $type = $request->str('type');
        if (!in_array($type, Menus::TYPES, true)) {
            throw HttpError::badRequest('Unknown type');
        }

        return Response::json(['items' => $this->menus()->search($type, $request->str('q'))]);
    }
}

<?php
/**
 * AIFEC — la portada. No tiene contenido propio: la componen los módulos con el filtro «home.sections»
 * (la página de portada del gestor de contenidos, los destacados del catálogo, las últimas entradas…).
 *
 * Una sección: ['id' => 'catalog.featured', 'order' => 20, 'html' => '<section>…</section>']. El tema decide cómo
 * envolverlas; el orden lo ponen los módulos y lo puede cambiar el comerciante (ajuste «home.order»).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Front;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

final class HomeController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * El icono del sitio para quien lo pide por su dirección de siempre (/favicon.ico): el elegido en los ajustes o el de serie.
     *
     * @param array<string,string> $params
     */
    public function favicon(Request $request, array $params): Response
    {
        $url = Page::iconUrl($this->app);
        if (strpos($url, 'data:') !== 0) {
            return Response::redirect($url, 302)->header('Cache-Control', 'public, max-age=86400');
        }

        return (new Response(Page::defaultIcon($this->app), 200, ['Content-Type' => 'image/svg+xml; charset=utf-8']))->header('Cache-Control', 'public, max-age=86400');
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $app = $this->app;
        $sections = $app->hooks()->apply('home.sections', [], $app);
        $clean = [];
        foreach (is_array($sections) ? $sections : [] as $section) {
            if (is_array($section) && isset($section['id'], $section['html']) && trim((string) $section['html']) !== '') {
                $clean[(string) $section['id']] = ['id' => (string) $section['id'], 'order' => isset($section['order']) ? (int) $section['order'] : 100, 'html' => (string) $section['html']];
            }
        }
        // El comerciante puede fijar el orden (y ocultar secciones) desde el panel.
        $order = $app->settings()->arr('home.order');
        if ($order !== []) {
            $position = 0;
            foreach ($order as $id) {
                if (is_string($id) && isset($clean[$id])) {
                    $clean[$id]['order'] = $position++;
                }
            }
            foreach ($app->settings()->arr('home.hidden') as $id) {
                unset($clean[(string) $id]);
            }
        }
        $list = array_values($clean);
        usort($list, static function (array $a, array $b): int {
            return $a['order'] <=> $b['order'];
        });

        $page = [
            'type' => 'home',
            'title' => $app->settings()->str('seo.home_title', ''),
            'title_full' => $app->settings()->str('seo.home_title', '') !== '',
            'description' => $app->settings()->str('seo.home_description', ''),
        ];
        if ($page['title'] === '') {
            unset($page['title'], $page['title_full']);
        }
        if (trim($page['description']) === '') {
            // Sin descripción escrita en los ajustes de SEO, una frase con el nombre de la tienda: una portada sin descripción es lo
            // primero que marca cualquier auditoría (la visita de SEO del 13/11/2026).
            $page['description'] = t('{shop}: online shop. Browse the catalogue, ask the assistant and buy with home delivery.', ['shop' => $app->settings()->str('store.name', 'AIFEC')]);
        }

        return $app->render('home', ['sections' => $list, 'page' => $page])->cacheable(300, ['home', 'catalog', 'cms', 'settings']);
    }
}

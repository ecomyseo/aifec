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
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Catalog\Catalog;
use Aifec\Modules\Catalog\Texts;
use Aifec\Util\Str;

/**
 * Los listados de la tienda: categoría, marca y búsqueda. Los tres usan la misma plantilla («catalog::front/listing») y lo que
 * trae la dirección: orden, página, filtros por atributo, precio mínimo y máximo, existencias y ofertas (ver ListingQuery).
 *
 * Qué se indexa: la primera página y las siguientes sin filtros ni orden distinto (cada una con su canónica). Una vista filtrada
 * u ordenada de otra manera lleva «noindex, follow» y su canónica apunta a la dirección sin filtros; la búsqueda no se indexa.
 */
final class ListingController
{
    /** Segundos de la caché de página de una categoría y de una marca. */
    private const TTL = 300;

    /** Largo máximo de lo que se busca. */
    private const MAX_SEARCH = 80;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    private function catalog(): Catalog
    {
        /** @var Catalog $catalog */
        $catalog = $this->app->service('catalog');

        return $catalog;
    }

    /**
     * @param array<string,mixed> $params id
     */
    public function category(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $category = $this->catalog()->category($id);
        if ($category === null) {
            throw HttpError::notFound();
        }
        $crumbs = [];
        foreach ($category['crumbs'] as $crumb) {
            $crumbs[] = ['name' => (string) $crumb['name'], 'url' => (string) $crumb['url']];
        }
        if ($crumbs === []) {
            $crumbs[] = ['name' => (string) $category['name'], 'url' => ''];
        }
        $crumbs[count($crumbs) - 1]['url'] = '';

        $response = $this->listing($request, [
            'kind' => 'category',
            'base' => (string) $category['url'],
            'heading' => (string) $category['name'],
            'description' => (string) $category['description'],
            'title' => (string) $category['seo']['title'],
            'meta_description' => (string) $category['seo']['description'],
            'crumbs' => Support::crumbs($this->app, $crumbs),
            'children' => $category['children'],
            'image' => $category['image'],
            'entity' => ['type' => 'category', 'id' => $id],
            'catalog_query' => ['category_id' => $id],
        ]);

        return $response->cacheable(self::TTL, Support::pageTags('category', $id));
    }

    /**
     * @param array<string,mixed> $params id
     */
    public function brand(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $brand = $this->catalog()->brand($id);
        if ($brand === null) {
            throw HttpError::notFound();
        }
        $name = (string) $brand['name'];
        $description = (string) $brand['description'];
        $plain = trim(Texts::plain($description));

        $response = $this->listing($request, [
            'kind' => 'brand',
            'base' => (string) $brand['url'],
            'heading' => $name,
            'description' => $description,
            'title' => $name,
            'meta_description' => $plain !== '' ? Str::limit($plain, 154) : t('Products from {brand}', ['brand' => $name]),
            'crumbs' => Support::crumbs($this->app, [['name' => $name, 'url' => '']]),
            'children' => [],
            'image' => $brand['image'],
            'entity' => ['type' => 'brand', 'id' => $id],
            'catalog_query' => ['brand_id' => $id],
        ]);

        return $response->cacheable(self::TTL, Support::pageTags('brand', $id));
    }

    /**
     * GET /buscar?q=…: sin palabra, el formulario; con palabra, el listado. Nunca se guarda en la caché de página ni se indexa.
     *
     * @param array<string,mixed> $params
     */
    public function search(Request $request, array $params): Response
    {
        $app = $this->app;
        $q = trim($request->str('q'));
        $q = function_exists('mb_substr') ? mb_substr($q, 0, self::MAX_SEARCH) : substr($q, 0, self::MAX_SEARCH);
        $base = route('search');
        $crumbs = Support::crumbs($app, [['name' => t('Search'), 'url' => '']]);
        if ($q === '') {
            return $app->render('catalog::front/search', [
                'page' => [
                    'type' => 'search',
                    'title' => t('Search'),
                    'robots' => 'noindex, follow',
                    'canonical' => $app->url()->origin() . $base,
                    'breadcrumbs' => $crumbs,
                ],
                'crumbs' => $crumbs,
                'action' => $base,
                'max' => self::MAX_SEARCH,
            ]);
        }

        return $this->listing($request, [
            'kind' => 'search',
            'base' => $base,
            'heading' => t('Results for {q}', ['q' => $q]),
            'description' => '',
            'title' => t('Results for {q}', ['q' => $q]),
            'meta_description' => '',
            'crumbs' => $crumbs,
            'children' => [],
            'image' => null,
            'entity' => null,
            'catalog_query' => [],
            'q' => $q,
        ]);
    }

    /**
     * Lo común de los tres: lee la dirección, pide el listado y pinta la plantilla.
     *
     * @param array<string,mixed> $ctx kind, base, heading, description, title, meta_description, crumbs, children, image, entity,
     *                                 catalog_query y, en la búsqueda, q
     */
    private function listing(Request $request, array $ctx): Response
    {
        $app = $this->app;
        $query = ListingQuery::fromRequest($request);
        $query->q = isset($ctx['q']) ? (string) $ctx['q'] : '';
        $base = (string) $ctx['base'];

        // Lo que fija la propia página (la categoría, la marca) manda sobre lo que pida la dirección.
        $result = $this->catalog()->listing($ctx['catalog_query'] + $query->toCatalog());
        // Lo que el catálogo ha dado por bueno: un orden desconocido o una página de más se quedan en lo que sale de verdad.
        $query->sort = (string) $result['sort'];
        $asked = isset($request->query['page']) && is_scalar($request->query['page']) ? (int) $request->query['page'] : 1;
        $query->page = (int) $result['page'];
        if ($asked > $query->page && $ctx['kind'] !== 'search') {
            // Una página que no existe («?page=99») no repite la última con un 200: lleva a la última de verdad (la visita de SEO del
            // 13/11/2026 vio un duplicado sin fin).
            return Response::redirect($query->url($base, $query->page, $query->isFiltered()), 301);
        }

        $facets = [];
        foreach ($result['facets'] as $facet) {
            if ($ctx['kind'] === 'brand' && $facet['code'] === 'brand') {
                continue;
            }
            $facets[] = $facet;
        }

        $filtered = $query->isFiltered();
        $title = Support::pageTitle((string) $ctx['title'], $query->page);
        $description = trim((string) $ctx['meta_description']);
        if ($description === '' && $ctx['kind'] !== 'search') {
            // Sin descripción escrita: la de la propia categoría o marca, en texto y recortada; si tampoco hay, una frase con su nombre.
            $plain = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $ctx['description']), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $description = $plain !== ''
                ? \Aifec\Util\Str::limit($plain, 155)
                : t('{name}: {count} products at {shop}. Compare prices and options and buy online.', ['name' => (string) $ctx['title'], 'count' => (int) $result['total'], 'shop' => $app->settings()->str('store.name', 'AIFEC')]);
        }
        $robots = $filtered || $ctx['kind'] === 'search' ? 'noindex, follow' : '';
        $canonical = $app->url()->origin() . $query->url($base, $query->page, $filtered);

        $page = [
            'type' => $ctx['kind'],
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'robots' => $robots,
            'breadcrumbs' => $ctx['crumbs'],
            'image' => is_array($ctx['image']) && isset($ctx['image']['url']) ? Support::absolute($app, (string) $ctx['image']['url']) : '',
        ];
        if ($ctx['entity'] !== null) {
            $page['entity'] = $ctx['entity'];
        }

        $url = static function (int $n) use ($query, $base): string {
            return $query->url($base, $n);
        };

        return $app->render('catalog::front/listing', [
            'page' => $page,
            'crumbs' => $ctx['crumbs'],
            'kind' => $ctx['kind'],
            'heading' => $ctx['heading'],
            'description' => Support::html((string) $ctx['description']),
            'children' => is_array($ctx['children']) ? $ctx['children'] : [],
            'result' => $result,
            'facets' => $facets,
            'query' => $query,
            'base' => $base,
            'clear_url' => $query->url($base, 1, true),
            'url' => $url,
        ]);
    }
}

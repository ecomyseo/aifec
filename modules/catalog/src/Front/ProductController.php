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

use Aifec\Front\StoreInfo;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Catalog\Catalog;

/**
 * La ficha de producto de la tienda y el paso que elige la variante cuando el navegador no tiene JavaScript.
 *
 * Todo sale de catalog->product(): aquí solo se compone la página. Un borrador, o un producto aún sin publicar, no existe para el
 * público (404). La respuesta se guarda en la caché de página diez minutos con la etiqueta del producto, la de cada relacionado
 * y «catalog»; el catálogo la vacía al guardar.
 */
final class ProductController
{
    /** Segundos que se guarda la ficha en la caché de página. */
    private const TTL = 600;

    /** Productos relacionados que se enseñan («También te puede interesar»). */
    private const RELATED = 4;

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
    public function show(Request $request, array $params): Response
    {
        $app = $this->app;
        $id = (int) $params['id'];
        $catalog = $this->catalog();
        $product = $catalog->product($id);
        if ($product === null) {
            throw HttpError::notFound();
        }

        // Migas: portada › categorías de la principal › producto.
        $tail = [];
        if (isset($product['main_category']['id'])) {
            $category = $catalog->category((int) $product['main_category']['id']);
            foreach ($category !== null ? $category['crumbs'] : [] as $crumb) {
                $tail[] = ['name' => (string) $crumb['name'], 'url' => (string) $crumb['url']];
            }
        }
        $tail[] = ['name' => (string) $product['name'], 'url' => ''];
        $crumbs = Support::crumbs($app, $tail);

        $meta = [
            'type' => 'product',
            'entity' => ['type' => 'product', 'id' => $id],
            'title' => (string) $product['seo']['title'],
            'description' => (string) $product['seo']['description'],
            'canonical' => (string) $product['url_abs'],
            'image' => isset($product['image']['url']) ? Support::absolute($app, (string) $product['image']['url']) : '',
            'breadcrumbs' => $crumbs,
        ];

        // «También te puede interesar» (ver Related) y las líneas de confianza de debajo del botón (ver StoreInfo).
        $related = Related::cards($app, $product, self::RELATED);
        $relatedIds = [];
        foreach ($related as $card) {
            $relatedIds[] = (int) $card['id'];
        }

        // Con variantes, la ficha sale con la primera que se puede comprar elegida (precio, existencias y referencia son los suyos).
        // Quien llega desde la tarjeta de un listado con una combinación ya elegida («?variante=12») la encuentra elegida aquí.
        $initial = $product['has_variants'] ? Variants::initial($product) : null;
        $asked = $product['has_variants'] ? Variants::byId($product, $request->int('variante')) : null;
        if ($asked !== null) {
            $initial = $asked;
        }
        $shown = $initial !== null ? $initial : $product;
        $stock = is_array($shown['availability']) ? $shown['availability'] : ['status' => 'in_stock', 'label' => '', 'delivery_days' => null];

        $faqs = [];
        foreach (is_array($product['faqs']) ? $product['faqs'] : [] as $faq) {
            if (is_array($faq) && isset($faq['question']) && trim((string) $faq['question']) !== '') {
                $faqs[] = ['question' => (string) $faq['question'], 'answer' => Support::html(isset($faq['answer']) ? (string) $faq['answer'] : '')];
            }
        }

        // Las de la ficha (con «shipping»: un cambio de zonas, transportistas o tarifas renueva la línea de envío) son las mismas
        // que las de su versión en texto: salen de Support::pageTags.
        $tags = Support::pageTags('product', $id);
        foreach ($relatedIds as $relatedId) {
            $tags[] = 'product:' . $relatedId;
        }

        return $app->render('catalog::front/product', [
            'page' => $meta,
            'product' => $product,
            'crumbs' => $crumbs,
            'related' => $related,
            'trust' => StoreInfo::data($app, true)['trust'],
            // Sin el módulo de pedidos no hay carrito: la tienda es un catálogo y no lleva botón de compra. Tampoco lo lleva un
            // producto que un plugin ha dejado como «no se puede comprar» (disponibilidad con purchasable = false); el carrito y
            // la caja lo comprueban además en el servidor.
            'can_buy' => $app->router()->has('cart.add') && (!isset($product['availability']['purchasable']) || !empty($product['availability']['purchasable'])),
            'initial' => $initial,
            'price' => $shown['price'],
            'sku' => isset($shown['sku']) && $shown['sku'] !== null && $shown['sku'] !== '' ? (string) $shown['sku'] : (string) $product['sku'],
            'stock' => $stock,
            'stock_class' => Support::availabilityClass((string) $stock['status']),
            'delivery' => Support::delivery(isset($stock['delivery_days']) ? $stock['delivery_days'] : null),
            'sold_out' => Variants::soldOut($shown),
            'summary_html' => Support::html((string) $product['summary']),
            'description_html' => Support::html((string) $product['description']),
            'faqs' => $faqs,
            'variants_data' => $product['has_variants'] ? Variants::data($product) : null,
            'choose_action' => $app->router()->has('catalog.choose') ? route('catalog.choose') : '',
        ])->cacheable(self::TTL, array_values(array_unique($tags)));
    }

    /**
     * POST: la compra desde una ficha con variantes en un navegador sin JavaScript. Llegan las opciones elegidas («opt[color]»)
     * y no el identificador de la variante: aquí se resuelve y se añade al carrito como lo haría el carrito.
     *
     * @param array<string,mixed> $params
     */
    public function choose(Request $request, array $params): Response
    {
        $app = $this->app;
        $product = $this->catalog()->product($request->int('product_id'));
        if ($product === null) {
            throw HttpError::notFound();
        }
        $back = (string) $product['url'];
        if (!$app->has('cart') || !$app->router()->has('cart')) {
            return Response::redirect($back);
        }
        $variantId = Variants::resolve($product, $request->arr('opt'));
        $session = $app->session('customer');
        if ($variantId === null) {
            // El aviso se ve en la página del carrito (la ficha está en la caché de página y no puede llevar avisos).
            $session->flash('cart_error', t('Choose an option first'));

            return Response::redirect(route('cart'));
        }
        $result = $app->service('cart')->add((int) $product['id'], $variantId, $request->int('qty', 1), []);
        if (empty($result['ok'])) {
            $error = isset($result['error']) ? (string) $result['error'] : '';
            if ($error !== '') {
                $session->flash('cart_error', $error);
            }
        }

        return Response::redirect(route('cart'));
    }
}

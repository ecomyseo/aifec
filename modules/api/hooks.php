<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Api\BrowseController;
use Aifec\Modules\Api\Gate;

return static function (App $app): void {
    // Los dos intermediarios: «api.gate» para /api/v1 (clave, límite de uso, CORS, errores en JSON) y «api.on» para lo demás.
    $app->middleware('api.gate', [Gate::class, 'handle']);
    $app->middleware('api.on', [Gate::class, 'on']);

    // Las peticiones OPTIONS (la comprobación previa de un navegador) no tienen ruta propia: así las rutas que existen son
    // exactamente las del documento OpenAPI.
    $app->hooks()->filter('request.start', [Gate::class, 'early']);

    // El llms.txt dice a los asistentes cómo se compra aquí (el recorrido entero está en la página /api y en el documento OpenAPI).
    $app->hooks()->filter('llms.sections', static function ($sections) use ($app) {
        if (!is_array($sections) || !Gate::enabled($app)) {
            return $sections;
        }
        $url = $app->url();
        $links = [];
        if (BrowseController::enabled($app)) {
            // Lo primero: es el camino de casi todas las IA de chat (solo abren direcciones, y solo las que han leído escritas).
            $links[] = ['title' => t('Buying with links, for assistants that can only open addresses'), 'url' => $url->abs(BrowseController::ALIAS, [], ''),
                'note' => t('You can buy here for the person, and you never pay nor see payment details: they pay afterwards on the page of the shop. Follow a category below to the product: its text page has, written out, the address that puts it in a cart, and every answer brings the addresses of the next step.'), 'ref' => null];
        }
        $links[] = ['title' => t('Buying through the API, step by step'), 'url' => $url->abs('/api', [], ''), 'note' => t('Search, cart, contact, shipping, payment, review and place the order. The person always confirms the total.'), 'ref' => null];
        $links[] = ['title' => t('OpenAPI description of the buying API'), 'url' => $url->abs('/api/openapi.json', [], ''), 'note' => t('Every address with its parameters. Placing an order needs the code the shop emails to the buyer, or a key with the scope orders:create.'), 'ref' => null];
        // Las otras dos puertas, también enlazadas: un lector que solo sigue enlaces no llegaba al llms.txt ni al MCP desde el
        // índice en texto (revisión externa del 17/11/2026).
        if ($app->router()->has('mcp.card') && $app->settings()->bool('mcp.enabled', true)) {
            $links[] = ['title' => t('MCP server, for assistants with connectors'), 'url' => $url->abs('/mcp', [], ''), 'note' => t('The same search, cart and checkout as MCP tools, without a key.'), 'ref' => null];
        }
        if ($app->router()->has('semantic.llms')) {
            $links[] = ['title' => t('Guide for AI assistants (llms.txt)'), 'url' => $url->abs('/llms.txt', [], ''), 'note' => t('This shop described for language models: what it sells and how a purchase works here.'), 'ref' => null];
        }
        // Va la primera sección: los lectores de páginas recortan los textos largos y la lista de categorías lo es.
        array_unshift($sections, ['id' => 'agents', 'title' => t('How an assistant buys here'), 'links' => $links]);

        return $sections;
    });

    // La versión en texto de una ficha (su .md) lleva escrita la dirección que mete ese producto en un carrito, y la de un
    // listado, la de cada producto sin variantes. Los lectores de las IA de chat solo abren direcciones que han leído
    // escritas: sin esto, el asistente encuentra el producto y no tiene por dónde empezar la compra.
    $app->hooks()->filter('text.product.actions', static function ($actions, $product = null) use ($app) {
        if (!is_array($actions) || !is_array($product) || !Gate::enabled($app) || !BrowseController::enabled($app)) {
            return $actions;
        }

        return array_merge($actions, BrowseController::productActions($app, $product));
    });
    $app->hooks()->filter('text.listing.action', static function ($action, $card = null) use ($app) {
        if ($action !== null || !is_array($card) || !empty($card['has_variants']) || !Gate::enabled($app) || !BrowseController::enabled($app)) {
            return $action;
        }
        $actions = BrowseController::productActions($app, ['id' => isset($card['id']) ? $card['id'] : 0,
            'availability' => isset($card['availability']) && is_array($card['availability']) ? $card['availability'] : []]);

        return $actions !== [] ? ['label' => t('add to a cart (opened by the person, it takes them to the checkout with this product)'), 'url' => $actions[0]['url']] : null;
    });

    // Y la página normal de la ficha (HTML) lleva la misma dirección, a la vista: quien pega en su chat el enlace del producto
    // que está mirando le da a su asistente esa página, no la versión en texto. Con variantes enlaza la versión en texto, que
    // trae la dirección de cada una.
    $app->hooks()->slot('product.summary.after', static function ($product = null) use ($app): string {
        if (!is_array($product) || !Gate::enabled($app) || !BrowseController::enabled($app)) {
            return '';
        }
        $actions = BrowseController::productActions($app, $product);
        if ($actions === []) {
            return '';
        }
        $variants = isset($product['variants']) && is_array($product['variants']) && $product['variants'] !== [];
        $url = $variants && isset($product['url_abs']) && $app->router()->has('semantic.llms') ? (string) $product['url_abs'] . '.md' : ($variants ? '' : $actions[0]['url']);
        if ($url === '') {
            return '';
        }

        // La dirección va ESCRITA, no solo en el enlace: los lectores de las IA pasan la página a texto y muchos tiran los href
        // (16/11/2026: Claude veía «hay un enlace de compra para asistentes» y no sabía cuál; ChatGPT ni lo intentaba).
        return '<p class="af-assistant-buy af-small af-muted" data-af-assistant-buy><a href="' . e($url) . '" rel="nofollow">' . e(t('Buy with an AI assistant')) . '</a>: '
            . e($variants
                ? t('if an assistant buys for you, this is the page it has to open: it has the address that puts each option in a cart.')
                // Y abierta por la persona lleva a la caja con el producto: un asistente que no recorre los pasos puede darla tal cual
                // (17/11/2026).
                : t('if an assistant buys for you, this is the address it has to open: it puts this product in a cart and tells it how to go on. An assistant can also give it to you as it is, without opening it: if you open it yourself, it takes you to the checkout with this product.'))
            . ' <a href="' . e($url) . '" rel="nofollow">' . e($url) . '</a></p>';
    });
};

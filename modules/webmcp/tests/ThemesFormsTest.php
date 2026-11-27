<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * WebMCP en todos los temas de serie con plantillas propias: portada, listado, ficha, carrito, búsqueda y entrada de cliente llevan
 * marcados los formularios que hacen algo útil (buscar, añadir al carrito, entrar, escribir), sin nombres repetidos en una página y
 * con el mismo HTML para todo el mundo. Lo pidió la auditoría de Lighthouse sobre gmartos.es (23/11/2026): «los formularios no
 * llevan toolname».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Webmcp\Webmcp;

require_once __DIR__ . '/helpers.php';

const WEBMCP_THEMES = ['gmartos', 'atelier', 'cafe', 'lookbook', 'metrica', 'taller', 'despensa', 'aifec'];

/**
 * Los formularios de la página que son de los que WebMCP conoce (añadir al carrito, buscar, entrar) y no llevan «toolname».
 *
 * @return string[]
 */
function webmcpUnmarkedForms(string $html): array
{
    $dom = new DOMDocument();
    $before = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($before);
    $out = [];
    foreach ((new DOMXPath($dom))->query('//form') ?: [] as $form) {
        /** @var DOMElement $form */
        $known = $form->hasAttribute('data-af-cart-add') || $form->getAttribute('role') === 'search'
            || preg_match('#/mi-cuenta/entrar$#', $form->getAttribute('action')) === 1;
        if ($known && !$form->hasAttribute('toolname')) {
            $out[] = '<form class="' . $form->getAttribute('class') . '" action="' . $form->getAttribute('action') . '">';
        }
    }

    return $out;
}

/**
 * Todo lo que WebMCP escribe en una página (toolname, tooldescription y toolparamdescription), en orden: lo que no puede cambiar de
 * una persona a otra.
 *
 * @return string[]
 */
function webmcpToolAttrs(string $html): array
{
    preg_match_all('/\b(?:toolname|tooldescription|toolparamdescription)="[^"]*"/', $html, $m);

    return $m[0];
}

/**
 * Una tienda con un tema de serie, productos con y sin variantes, y un carrito con una línea.
 *
 * @return array{0:\Aifec\Kernel\App,1:TestClient,2:array<string,int>}
 */
function webmcpThemeShop(string $theme): array
{
    $app = webmcpApp(['theme.active' => $theme]);
    aifecAttribute($app, 'talla', 'Talla', ['s' => 'S', 'm' => 'M']);
    $ids = assistantShop($app);
    $ids['camiseta'] = aifecProduct($app, [
        'name' => 'Camiseta', 'slug' => 'camiseta', 'price' => 1500, 'stock' => 5,
        'variants' => [
            ['sku' => 'CAM-S', 'stock' => 3, 'values' => ['talla' => 's']],
            ['sku' => 'CAM-M', 'stock' => 2, 'values' => ['talla' => 'm']],
        ],
    ]);
    $web = new TestClient($app);
    $web->postJson('/webmcp/call/cart_add', ['product_id' => $ids['silla_roja'], 'qty' => 1]);

    return [$app, $web, $ids];
}

test('cada tema de serie marca en portada, listado, ficha, carrito, búsqueda y entrada los formularios que hacen algo, sin repetir nombres', function (): void {
    $withCards = [];
    foreach (WEBMCP_THEMES as $theme) {
        [$app, $web, $ids] = webmcpThemeShop($theme);
        $product = $app->url()->entity('product', $ids['silla_roja']);
        $variants = $app->url()->entity('product', $ids['camiseta']);
        $pages = ['/', '/categoria/sillas', $product, $variants, '/carrito', '/buscar?q=silla', '/buscar?q=zzzzzz', '/mi-cuenta/entrar'];
        foreach ($pages as $path) {
            $r = $web->get($path);
            same(200, $r->status, $theme . ' ' . $path);
            same([], webmcpFormProblems($r->body), $theme . ' ' . $path . ': formularios marcados bien hechos');
            same([], webmcpUnmarkedForms($r->body), $theme . ' ' . $path . ': ningún formulario conocido sin marca');
        }

        // La ficha: el formulario de añadir se llama add_to_cart, una sola vez, con sus cuatro campos descritos.
        $html = $web->get($product)->body;
        same(1, substr_count($html, 'toolname="add_to_cart"'), $theme . ': la ficha tiene su add_to_cart');
        foreach (['product_id', 'variant_id'] as $field) {
            check(preg_match('/name="' . $field . '"[^>]*toolparamdescription="/', $html) === 1, $theme . ': ' . $field . ' descrito');
        }
        $options = $web->get($variants)->body;
        check(preg_match('/name="opt\[[a-z0-9_]+\]"[^>]*toolparamdescription="/', $options) === 1, $theme . ': las opciones de una ficha con variantes, descritas');

        // El listado: cada tarjeta que se puede comprar de golpe es su propia herramienta, con el número del producto en el nombre.
        $list = $web->get('/categoria/sillas')->body;
        preg_match_all('/<form\b[^>]*data-af-cart-add[^>]*>/', $list, $forms);
        foreach ($forms[0] as $form) {
            check(preg_match('/toolname="add_to_cart_[0-9]+"/', $form) === 1, $theme . ': cada tarjeta lleva add_to_cart_<id>: ' . $form);
        }
        $withCards[$theme] = count($forms[0]);

        // El carrito.
        $cart = $web->get('/carrito')->body;
        foreach (['update_cart_line_1', 'apply_coupon', 'calculate_shipping'] as $name) {
            contains('toolname="' . $name . '"', $cart, $theme . ': el carrito marca ' . $name);
        }
        // La entrada de cliente.
        $login = $web->get('/mi-cuenta/entrar')->body;
        contains('toolname="sign_in"', $login, $theme . ': la entrada de cliente es sign_in');
        foreach (['email', 'password'] as $field) {
            check(preg_match('/name="' . $field . '"[^>]*toolparamdescription="/', $login) === 1 || preg_match('/toolparamdescription="[^"]*"[^>]*name="' . $field . '"/', $login) === 1, $theme . ': ' . $field . ' descrito');
        }
    }
    check(count(array_filter($withCards)) >= 5, 'la mayoría de los temas trae tarjetas con formulario de añadir y todas van marcadas: ' . json_encode($withCards));
});

test('el HTML marcado es el mismo para dos visitantes distintos: nada personal y las páginas siguen siendo cacheables', function (): void {
    foreach (WEBMCP_THEMES as $theme) {
        [$app, , $ids] = webmcpThemeShop($theme);
        foreach (['/', '/categoria/sillas', $app->url()->entity('product', $ids['silla_roja']), '/carrito'] as $path) {
            $one = (new TestClient($app))->get($path);
            $two = (new TestClient($app))->get($path);
            check(webmcpToolAttrs($one->body) !== [] || $path === '/carrito' || $path === '/', $theme . ' ' . $path . ': trae marcas');
            same(webmcpToolAttrs($one->body), webmcpToolAttrs($two->body), $theme . ' ' . $path . ': las marcas no dependen de la persona');
        }
        $product = $app->url()->entity('product', $ids['silla_roja']);
        $a = (new TestClient($app))->get($product);
        $b = (new TestClient($app))->get($product);
        same(600, $a->cacheTtl, $theme . ': la ficha se sigue guardando en la caché de página');
        same($a->body, $b->body, $theme . ': el HTML de la ficha es el mismo para todo el mundo');
    }
});

test('los nombres de herramienta no se arrastran de una página a la siguiente de la misma aplicación', function (): void {
    [$app, $web, $ids] = webmcpThemeShop('base');
    $product = $app->url()->entity('product', $ids['silla_roja']);
    $first = webmcpToolAttrs($web->get($product)->body);
    $second = webmcpToolAttrs($web->get($product)->body);
    same($first, $second, 'dos peticiones seguidas dan las mismas marcas (sin «_2» de la anterior)');
    notContains('toolname="add_to_cart_2"', $web->get($product)->body);
});

test('si un mismo nombre se pide dos veces en una página, el segundo lleva «_2»', function (): void {
    $app = webmcpApp();
    /** @var Webmcp $webmcp */
    $webmcp = $app->service('webmcp');
    $webmcp->reset();
    contains('toolname="search_products"', $webmcp->formAttributes('search_products'));
    contains('toolname="search_products_2"', $webmcp->formAttributes('search_products'));
    contains('toolname="search_products_3"', $webmcp->formAttributes('search_products'));
    $webmcp->reset();
    contains('toolname="search_products"', $webmcp->formAttributes('search_products'), 'tras reset() vuelve a empezar');
    // La tarjeta de un listado dice de qué producto es.
    contains('«Silla roja»', $webmcp->formAttributes('add_to_cart', ['n' => 7, 'name' => 'Silla roja']));
});

test('el formulario de contacto de serie se marca como send_contact_message con sus campos reales, y los que crea la tienda no', function (): void {
    $app = webmcpApp([], ['cms']);
    $app->boot();
    /** @var \Aifec\Modules\Cms\Forms $forms */
    $forms = new \Aifec\Modules\Cms\Forms($app);
    $html = $forms->block(['form_id' => 'contact']);
    contains('toolname="send_contact_message"', $html);
    foreach (['f[name]', 'f[email]', 'f[message]', 'f[consent]', 'website', '_mark'] as $field) {
        check(preg_match('/name="' . preg_quote($field, '/') . '"[^>]*toolparamdescription="/', $html) === 1, 'el campo ' . $field . ' lleva su descripción');
    }
    same([], webmcpFormProblems($html), 'el formulario de contacto está bien marcado');
    // Un formulario que crea la tienda tiene otros campos: no se le pone una herramienta que no es.
    $app->db()->insert('forms', ['code' => 'presupuesto', 'name' => 'Presupuesto', 'fields' => json_encode([['key' => 'obra', 'type' => 'text', 'label' => 'Obra', 'required' => 1, 'options' => []]]),
        'settings' => json_encode(['notify_to' => '', 'thanks' => '']), 'created_at' => $app->clock()->sql(), 'updated_at' => $app->clock()->sql()]);
    notContains('toolname', (new \Aifec\Modules\Cms\Forms($app))->block(['form_id' => 'presupuesto']));
});

test('el panel dice en qué plantillas está cada formulario, y los temas de serie salen en la lista', function (): void {
    $app = webmcpApp();
    /** @var Webmcp $webmcp */
    $webmcp = $app->service('webmcp');
    $placement = $webmcp->placement();
    foreach (WEBMCP_THEMES as $theme) {
        $mine = array_filter($placement['add_to_cart'], static function (string $file) use ($theme): bool {
            return strpos($file, 'themes/' . $theme . '/') === 0;
        });
        if ($theme !== 'aifec') {
            check($mine !== [], $theme . ' marca add_to_cart en alguna plantilla propia');
        }
    }
    check(in_array('themes/gmartos/templates/home.php', $placement['search_products'], true), 'el buscador de la portada de gmartos');
    check(in_array('themes/base/templates/partials/drawers.php', $placement['search_products'], true), 'el panel de búsqueda del tema base');
    check(in_array('modules/customers/views/front/login.php', $placement['sign_in'], true), 'sign_in en la entrada de cliente');
    check(in_array('modules/cms/src/Forms.php', $placement['send_contact_message'], true), 'send_contact_message en el formulario del módulo de contenido');
    same([], $placement['subscribe_newsletter'], 'AIFEC no tiene formulario de boletín: no hay nada que marcar (no se inventa)');

    $web = adminClient($app);
    $page = $web->get('/admin/webmcp');
    same(200, $page->status);
    contains('themes/gmartos/templates/home.php', $page->body, 'el panel enseña las plantillas, no solo cuántas son');
    contains('modules/customers/views/front/login.php', $page->body);
    check(in_array('modules/reviews/views/front/_form.php', $placement['submit_review'], true), 'submit_review en el formulario de la opinión');
    check(in_array('modules/assistant/views/front/widget.php', $placement['ask_shop_assistant'], true), 'ask_shop_assistant en el widget del asistente');
});

/**
 * Los formularios de una página sin «toolname» que la tienda deja fuera a propósito (id del formulario => motivo). El panel de filtros del
 * listado solo cambia lo que se ve en pantalla (marca y desmarca criterios y recarga): un agente busca con search_products y con la API,
 * y una herramienta con veinte casillas dependientes del listado confunde más que ayuda.
 */
const WEBMCP_EXCLUDED_FORMS = [
    'af-filters-form' => 'filtros del listado',
    'af-assistant-budget-preference' => 'solo el cliente puede decidir que se guarde un presupuesto en su cuenta',
    'af-assistant-personal-rating' => 'la valoración es la experiencia personal del cliente',
];

test('la opinión de la ficha es submit_review y el asistente es ask_shop_assistant, con sus campos reales y sin nombres repetidos en ningún tema', function (): void {
    foreach (WEBMCP_THEMES as $theme) {
        $app = webmcpApp(['theme.active' => $theme], ['reviews']);
        $ids = assistantShop($app);
        $web = new TestClient($app);
        $product = $app->url()->entity('product', $ids['silla_roja']);
        foreach (['/', '/categoria/sillas', $product] as $path) {
            $r = $web->get($path);
            same(200, $r->status, $theme . ' ' . $path);
            same([], webmcpFormProblems($r->body), $theme . ' ' . $path . ': formularios marcados bien hechos y sin nombres repetidos');
            $dom = new DOMDocument();
            $before = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8"?>' . $r->body);
            libxml_clear_errors();
            libxml_use_internal_errors($before);
            foreach ((new DOMXPath($dom))->query('//form') ?: [] as $form) {
                /** @var DOMElement $form */
                check($form->hasAttribute('toolname') || isset(WEBMCP_EXCLUDED_FORMS[$form->getAttribute('id')]),
                    $theme . ' ' . $path . ': un formulario sin toolname ni exclusión documentada: <form class="' . $form->getAttribute('class') . '" action="' . $form->getAttribute('action') . '">');
            }
            same(1, substr_count($r->body, 'toolname="ask_shop_assistant"'), $theme . ' ' . $path . ': el asistente es una herramienta');
            check(preg_match('/name="message"[^>]*toolparamdescription="/', $r->body) === 1, $theme . ' ' . $path . ': message descrito');
        }
        $html = $web->get($product)->body;
        same(1, substr_count($html, 'toolname="submit_review"'), $theme . ': la ficha tiene su submit_review, una sola vez');
        contains('PÚBLICA', $html, $theme . ': la descripción dice que la opinión es pública');
        foreach (['rating', 'name', 'email', 'body', 'website'] as $field) {
            check(preg_match('/name="' . $field . '"[^>]*toolparamdescription="/', $html) === 1, $theme . ': ' . $field . ' de la opinión descrito');
        }
        same($html, (new TestClient($app))->get($product)->body, $theme . ': la ficha con opinión sigue siendo igual para todos');
    }
});

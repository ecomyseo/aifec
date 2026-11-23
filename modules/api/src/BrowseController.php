<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Api;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Orders\Front\OrderPageController;

/**
 * Comprar con enlaces: la misma compra de la API para los asistentes que solo saben LEER páginas (peticiones GET).
 *
 * Muchos asistentes de chat no pueden mandar un POST ni una cabecera: solo abren direcciones. Aquí cada paso de la compra es
 * una dirección GET bajo /api/v1/go/…, con la ficha del carrito en el parámetro «cart», y cada respuesta trae en «next» las
 * direcciones ya montadas del paso siguiente: el asistente solo tiene que abrirlas.
 *
 *   start → add → customer → shipping-options → shipping → payment-options → payment → review → place
 *
 * No hay lógica propia: cada paso llama a las mismas herramientas y al mismo motor que la API (Checkout). Lo que protege el
 * pedido es lo de siempre: la ficha de confirmación de la revisión y el código que la tienda manda al comprador (BuyerCode).
 * Sin ese código, abrir «place» no hace nada, lo abra un asistente, un rastreador o una caché.
 *
 * Como un GET lo puede repetir cualquiera, los pasos se pueden repetir sin daño: añadir un producto que ya está no lo dobla,
 * revisar dos veces no manda dos códigos y hacer el pedido dos veces hace uno solo. Las respuestas no se guardan en caché
 * ni se indexan.
 */
final class BrowseController
{
    /** Los pasos que son una herramienta de la caja tal cual, con los parámetros que admite cada uno. */
    private const TOOLS = [
        'status' => ['checkout_status', []],
        'customer' => ['checkout_set_customer', ['text', 'note', 'email', 'first_name', 'last_name', 'company', 'vat_id', 'phone', 'line1', 'line2', 'postcode', 'city', 'region', 'country']],
        'shipping-options' => ['checkout_shipping_options', []],
        'shipping' => ['checkout_set_shipping', ['carrier_id']],
        'payment-options' => ['checkout_payment_options', []],
        'payment' => ['checkout_set_payment', ['method']],
    ];

    private const INTEGERS = ['product_id', 'variant_id', 'qty', 'carrier_id'];

    /**
     * Los valores que cada paso admite como trozos de la ruta, en orden, detrás del carrito: /buy-with-links/<paso>/<carrito>/<a>/<b>.
     * Así una dirección no lleva «&»: hay lectores de páginas que solo abren una dirección si es idéntica, letra a letra, a la
     * del HTML que leyeron, y en el HTML «&» se escribe «&amp;».
     */
    private const PATH = ['add' => ['product_id', 'qty'], 'shipping' => ['carrier_id'], 'payment' => ['method'], 'place' => ['confirm_token', 'buyer_code'],
        'code' => ['confirm_token', 'digits']];

    /** Los dígitos del código que la tienda manda al comprador. */
    private const CODE_DIGITS = 6;

    /**
     * Cuántos dígitos se teclean en cada página del teclado de enlaces: uno, es decir, diez direcciones por página. De dos
     * en dos eran cien direcciones (unos 17 KB) y el lector de Gemini cortaba la página en la número 36: no veía el resto y
     * se negaba a abrir una dirección que no había leído (medido el 16/11/2026).
     */
    private const CODE_STEP = 1;

    /** La misma compra con enlaces fuera de /api/v1 (ver Routes). */
    public const ALIAS = '/buy-with-links';

    /** Segundos tras los que un carrito ya pedido se da por una dirección de otra conversación (ver step()). */
    private const OLD_CART = 1200;

    // Cada paso es el estado de UN carrito: ninguna caché compartida puede guardarlo (17/11/2026: el lector de Claude
    // devolvía «una copia guardada» de /buy-with-links/new/129 con el carrito de otra conversación, ya pedido). «private»
    // lo prohíbe a las cachés compartidas; «no-cache», que nadie la sirva sin preguntar antes.
    private const NO_SHARED_CACHE = 'private, no-cache, max-age=0, must-revalidate';

    /** Lo que dice la lista de formas de pago al asistente: elegirla no es pagar. */
    private const PAYMENT_CHOICE = 'Choosing the payment method only records it in this cart: nothing is charged and no payment details are needed, '
        . 'so the assistant can choose it by opening the address of the option the person wants. The person pays after the order is placed, on the page of the shop.';

    /** La caja para la persona: la salida de quien no puede (o no quiere) mandar los datos del comprador por el chat. */
    private const HANDOFF = 'The checkout for the person: this same cart, already filled, at the shop. Give them this address as it is if they prefer to finish there, or if you cannot get their details to the shop: '
        . 'they type their name and address, choose delivery and payment and pay. This address works for any buyer, with or without tools. '
        . 'It is the ONLY address that opens this cart in their browser: the checkout page of the shop on its own opens their own cart, which is not this one, so never send them to it instead';

    /**
     * Lo que la tienda dice después de meter algo en el carrito: los tres caminos, según lo que pueda hacer el asistente. El
     * segundo es para los lectores que solo abren direcciones que han leído (casi todas las IA de chat): no pueden abrir la
     * dirección con los datos del comprador, pero sí ESCRIBIRLA en su respuesta para que la abra la persona.
     */
    /** Lo que se le dice al asistente al añadir: {FORM} y {RESUME} son las dos direcciones ya escritas (afterAdd()). */
    // Las dos direcciones que hacen falta van LO PRIMERO y escritas: el lector de Claude resume las páginas largas y perdía las de
    // «next» («mi lector solo devuelve resúmenes y no me da las URLs de los pasos siguientes», 17/11/2026).
    // Y escrito como INFORMACIÓN de cómo sigue la compra, no como órdenes: Claude ignoraba «las instrucciones para un asistente
    // que traen algunas páginas» (17/11/2026), que es lo que hace con lo que parece una orden metida en una web.
    private const AFTER_ADD = 'The product is in the cart. How the purchase goes on: the person types who receives it (name, address, phone and email) in this form of the shop: {FORM} . '
        . 'Once they have, this page of the shop shows what is still missing and the next steps (delivery, payment, the review and the order, which the person confirms with a code the shop emails them): {STATUS} . '
        . 'The carrier needs the phone. In the same form the person can write a note for the shop: a size or a colour the product does not offer as an option. '
        . 'The way that works with any tool is that form: a page of the shop where the person types those details, and the shop keeps them in this cart. '
        . 'An assistant that can open addresses it completes can instead send the person\'s own words in the "Save who buys" address of "next". '
        . 'To finish at the shop instead, this address opens this same cart, already filled, at the checkout: {RESUME} . '
        . 'None of this needs an app, a tool or a permission. Paying is always done by the person, on the payment page of the shop (card, PayPal…): '
        . 'no assistant ever sees or types payment details, and nothing is charged before the person pays there. What is in the cart and its price are in this answer.';

    /**
     * Lo mismo para el lector de una IA de chat, con las DOS formas de seguir: dar a la persona la caja con este carrito (la
     * que vale con cualquier IA) o, si la IA puede abrir varias direcciones seguidas, preparar el pedido. Antes se le obligaba
     * a la segunda y las que no recorren varios pasos se quedaban sin nada que dar (17/11/2026).
     */
    private const AFTER_ADD_READER = 'The product is in the cart. There are two ways to go on, and both are right: '
        . '1) The quickest, which works with any assistant: give the person this address, written as it is. It opens this same cart at the checkout of the shop, '
        . 'where they type their name, address, phone and email, choose delivery and payment, and pay: {RESUME} . '
        . '2) If you can open several addresses one after another, you can also prepare the order: the person types who receives it in this form of the shop: {FORM} ; '
        . 'once they have, this page shows what is still missing and the next steps (delivery, payment, the review and the order, which the person confirms with a code the shop emails them): {STATUS} . '
        . 'The carrier needs the phone, and in the form the person can write a note for the shop (a size or a colour the product does not offer as an option). '
        . 'None of this needs an app, a tool or a permission. Paying is always done by the person, on the payment page of the shop: '
        . 'no assistant ever sees or types payment details, and nothing is charged before the person pays there. What is in the cart and its price are in this answer.';

    /** Lo que se le dice al asistente en una tienda de demostración o con los pagos en modo de prueba. */
    private const DEMO_NOTE = 'This is a demo shop: its payments are in test mode and nothing is ever charged. Test orders here are expected: '
        . 'it is how its developer and the people trying the demo see how buying with an assistant works, so going on to the cart or to the order is fine.';

    /** @var App */
    private $app;

    /** @var bool|null si la tienda es de demostración o cobra solo en modo de prueba (se mira una vez por petición) */
    private $demoShop = null;

    /** @var bool la respuesta va en JSON (lo pide el programa); si no, como página de texto */
    private $json = false;

    /** @var bool el JSON se pidió con ?format=json: las direcciones siguientes lo llevan también */
    private $jsonParam = false;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** ¿Está encendida la compra con enlaces? */
    public static function enabled(App $app): bool
    {
        return $app->settings()->bool('api.links', true);
    }

    /**
     * La dirección, escrita entera, que mete un producto en un carrito nuevo. Es la que se escribe en la versión en texto de
     * las fichas y de los listados: un asistente que solo abre direcciones que ha leído (no monta ninguna) la abre tal cual.
     */
    public static function addUrl(App $app, int $productId, int $variantId = 0): string
    {
        // Como ruta limpia, sin «?»: el lector de Claude perdía a veces los parámetros y abría «/buy-with-links/add» a secas
        // («mi herramienta pierde los parámetros del enlace que mete el producto en el carrito», 17/11/2026).
        return $app->url()->abs(self::ALIAS . '/new/' . $productId . ($variantId > 0 ? '/' . $variantId : ''), [], '');
    }

    /**
     * «/buy-with-links/new/{producto}[/{opción}]»: lo mismo que «add?product_id=…&qty=1», sin parámetros.
     *
     * @param array<string,string> $params
     */
    public function addNew(Request $request, array $params): Response
    {
        $request->query['product_id'] = (string) $params['a'];
        if (isset($params['b']) && (int) $params['b'] > 0) {
            $request->query['variant_id'] = (string) $params['b'];
        }
        if (!isset($request->query['qty'])) {
            $request->query['qty'] = '1';
        }

        return $this->go($request, ['step' => 'add']);
    }

    /**
     * El carrito en pocas líneas: nombre, cantidad y total de cada una, y el total.
     *
     * @return array{lines:array<int,array<string,mixed>>,count:int,total:string,total_cents:int}|null null si el carrito no está disponible
     */
    private function cartSummary(): ?array
    {
        $view = Agent::tool($this->app, 'cart_view');
        if (!is_array($view) || isset($view['error'])) {
            return null;
        }
        $lines = [];
        foreach (isset($view['lines']) && is_array($view['lines']) ? $view['lines'] : [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $name = (string) (isset($line['name']) ? $line['name'] : '');
            $variant = (string) (isset($line['variant']) ? $line['variant'] : '');
            $lines[] = ['name' => $variant !== '' ? $name . ' (' . $variant . ')' : $name, 'quantity' => (int) (isset($line['qty']) ? $line['qty'] : 0),
                'total' => (string) (isset($line['total']) ? $line['total'] : ''), 'total_cents' => (int) (isset($line['total_cents']) ? $line['total_cents'] : 0)];
        }

        return ['lines' => $lines, 'count' => (int) (isset($view['count']) ? $view['count'] : 0), 'total' => (string) (isset($view['total']) ? $view['total'] : ''),
            'total_cents' => (int) (isset($view['total_cents']) ? $view['total_cents'] : 0)];
    }

    /** ¿Se puede comprar ahora mismo? (lo que dice el catálogo: se vende, enseña su precio y no está agotado). */
    private static function buyable(array $availability): bool
    {
        return !(isset($availability['purchasable']) && empty($availability['purchasable']))
            && !(isset($availability['show_price']) && empty($availability['show_price']))
            && !(isset($availability['status']) && (string) $availability['status'] === 'out_of_stock');
    }

    /**
     * Lo que un asistente puede hacer con un producto, para su versión en texto: la dirección que lo añade a un carrito (una
     * por variante, si las tiene). Sin nada si no se puede comprar ahora.
     *
     * @param array<string,mixed> $product el producto como lo da el catálogo
     *
     * @return array<int,array{label:string,url:string,note:string}>
     */
    public static function productActions(App $app, array $product): array
    {
        $id = isset($product['id']) ? (int) $product['id'] : 0;
        if ($id < 1 || !self::buyable(isset($product['availability']) && is_array($product['availability']) ? $product['availability'] : [])) {
            return [];
        }
        // «Leer» y «no compra ni cobra»: Claude veía la dirección y decía «aquí solo puedo leer páginas, no puedo hacer el
        // pedido» (17/11/2026); abrirla es leer una página, y lo que la persona compra lo confirma ella al final con un código.
        $note = t('reading this page only prepares a new cart with 1 unit: it buys and charges nothing, the order is only placed at the end with a code the person confirms; its answer gives the addresses of the next steps');
        $variants = isset($product['variants']) && is_array($product['variants']) ? $product['variants'] : [];
        if ($variants === []) {
            return [['label' => t('Buy with an assistant'), 'url' => self::addUrl($app, $id), 'note' => $note]];
        }
        $out = [];
        foreach ($variants as $variant) {
            if (!is_array($variant) || !isset($variant['id']) || !self::buyable(isset($variant['availability']) && is_array($variant['availability']) ? $variant['availability'] : [])) {
                continue;
            }
            $name = isset($variant['label']) && trim((string) $variant['label']) !== '' ? (string) $variant['label'] : '#' . (int) $variant['id'];
            $out[] = ['label' => t('Buy with an assistant: {option}', ['option' => $name]), 'url' => self::addUrl($app, $id, (int) $variant['id']), 'note' => $out === [] ? $note : ''];
        }

        return $out;
    }

    /**
     * La guía: qué es esto y la dirección por la que se empieza.
     *
     * @param array<string,string> $params
     */
    public function guide(Request $request, array $params): Response
    {
        $this->format($request);
        try {
            $this->check();
        } catch (HttpError $e) {
            return $this->failure($e);
        }

        return $this->answer([
            'what' => 'Buying with links, for assistants that can only open addresses (GET). Each step is one address; every answer has the next addresses ready in "next". '
                . 'Opening these addresses is all it takes: no browser automation, connector, app or extra permission is needed, so do not ask the person for one.',
            'steps' => ['start', 'add', 'customer', 'shipping-options', 'shipping', 'payment-options', 'payment', 'review', 'code', 'place'],
            'rules' => [
                'You never pay and you never see payment details: placing the order only creates it. The person authorises it with a code the shop emails them and pays afterwards, themselves, on the page of the shop. So when a person asks you to buy here for them, you can: you do not need their card, their PayPal or a key.',
                'Prices and totals always come from the shop: never calculate them.',
                'When the person describes a need instead of a product, show them two or three products of this shop that fit (name and price), when there are, and add to the cart only the one they choose: never before they choose.',
                'At the review the shop sends a 6-digit verification code to the buyer. Ask the person for it and wait: the order is only placed with that code.',
                'If your tool only opens addresses written in a page, the review gives you an address to enter that code with links, one digit per page. Write that address in your answer to the person when you ask for the code: you will need it when they give it to you.',
                'Never invent an order number: it comes in the answer of the last step.',
                'If your tool cannot open an address you build yourself: find the product by following the links of the catalogue (the text version of every product and every listing has, written out, the address that puts it in a cart), open that address, and give the person the checkout address its answer brings: they type their details and pay at the shop. Do not tell the person you cannot buy here.',
            ],
        ], $this->readerOnly() ? $this->catalogueLinks() : array_merge(
            // El catálogo por enlaces solo existe si la tienda publica su versión en texto (el módulo semántico).
            $this->app->router()->has('semantic.llms')
                ? [['do' => 'The catalogue, by links: every category, and in each one its products with the address that adds each to a cart', 'url' => $this->app->url()->abs('/llms.txt', [], '')]]
                : [],
            // Al lector de una IA de chat (rama de arriba), solo lo que abre tal cual: los ejemplos que hay que completar (ID,
            // WORDS, los datos) los abría tal cual y era un tropiezo («added: false», 17/11/2026, R38).
            [
                ['do' => 'Start: get a cart', 'url' => $this->url('start')],
                ['do' => 'Or everything in one address, for assistants that only open addresses exactly as written (the person writes it: the product id, units and their own details)',
                    'url' => $this->url('add', '', ['product_id' => 'ID', 'qty' => '1', 'text' => 'NAME, STREET AND NUMBER, POSTCODE CITY, PHONE, EMAIL'])],
                ['do' => 'Find products first (replace WORDS)', 'url' => $this->app->url()->abs(Routes::PREFIX . '/search', [], '') . '?q=WORDS'],
            ]
        ));
    }

    /**
     * Un paso de la compra.
     *
     * @param array<string,string> $params
     */
    public function go(Request $request, array $params): Response
    {
        $this->format($request);
        try {
            return $this->step($request, $params);
        } catch (HttpError $e) {
            return $this->failure($e);
        }
    }

    /**
     * @param array<string,string> $params
     */
    private function step(Request $request, array $params): Response
    {
        $this->check();
        $step = isset($params['step']) ? (string) $params['step'] : '';
        if (\Aifec\Kernel\BrowserCommerce::blocked($this->app, $request)) {
            $product = $params['a'] ?? $request->get('product_id');
            if ($step === 'add' && !$this->json && is_scalar($product) && (int) $product > 0 && \Aifec\Kernel\BrowserCommerce::navigation($request)) {
                $item = $this->app->service('catalog')->product((int) $product);
                if ($item !== null && !empty($item['url_abs'])) {
                    $response = Response::redirect((string) $item['url_abs']);
                    $response->headers['Cache-Control'] = 'no-store, private';
                    return $response;
                }
            }
            $response = $this->json ? Response::json(['error' => 'browser_blocked'], 403)
                : Response::text(t('This browser cannot modify carts through assistant links.'), 403);
            $response->headers['Cache-Control'] = 'no-store, private';
            return $response;
        }
        if ($step === 'start') {
            if ($this->readerOnly()) {
                // Al lector de una IA de chat, sin carrito vacío ni la plantilla «ID» que no puede completar: el catálogo con
                // la dirección de cada producto ya escrita, que crea su carrito (17/11/2026, R67: «la URL de add venía como
                // plantilla {cart}/ID/1» y Claude no pudo añadir el queso).
                return $this->answer([], $this->catalogueLinks(), 'To buy, open the address of the product in the catalogue below: each product has its own, already written, and it puts that product in a new cart.');
            }
            $raw = Carts::create($this->app, CartController::SOURCE);
            Carts::bind($this->app, $raw);

            return $this->answer(['cart' => $raw, 'contents' => Agent::tool($this->app, 'cart_view')], [
                ['do' => 'Add a product (replace ID with the product id and the last number with the units)', 'url' => $this->url('add', $raw, ['product_id' => 'ID', 'qty' => '1'])],
            ], 'This is your cart. Keep the value of "cart": every address of the next steps carries it.');
        }

        $raw = strtolower(trim((string) (is_scalar($request->get('cart')) ? $request->get('cart') : '')));
        $inPath = isset($params['cart']) && $raw === '';
        if ($inPath) {
            $raw = strtolower((string) $params['cart']);
        }
        if ($step === 'add' && !$this->hasProduct($request, $params)) {
            // «add» sin producto, o con el ejemplo «ID» tal cual: lo abre el lector que solo abre direcciones escritas (17/11/2026,
            // Claude-User), que no puede completarlas. No es un error: su camino es el catálogo por enlaces.
            return $this->findFirst($raw);
        }
        if ($raw === '' && $step === 'add') {
            // «add» sin carrito lo crea: así la persona puede escribir UNA sola dirección (producto y, si quiere, sus datos) y
            // el asistente que solo abre direcciones literales ya no tiene que montar ninguna.
            $raw = Carts::create($this->app, CartController::SOURCE);
        }
        if ($raw === '') {
            throw new HttpError(401, 'Add the cart to the address as ?cart=… (' . $this->url('start') . ' gives you one)');
        }
        $placed = $this->placed($raw);
        if ($placed !== null && $this->app->clock()->now() - (int) strtotime((string) $placed['placed_at'] . ' UTC') > self::OLD_CART) {
            // Un carrito que se hizo pedido hace horas es una dirección de OTRA conversación (17/11/2026: Claude, con memoria,
            // reabría carritos de pruebas de la víspera y daba aquel pedido como el que la persona hacía ahora, aunque la
            // respuesta lo advertía). No se da como pedido: se dice que es viejo y se ofrece volver a comprar lo mismo.
            $row = Carts::find($this->app, $raw);
            $again = $this->buyAgain($row !== null ? (int) $row['order_id'] : 0);
            if ($this->personBrowser($request)) {
                // La abre la PERSONA (su asistente le dio, de memoria, la dirección de un carrito viejo): lo que pedía ahora, o lo
                // mismo de aquel pedido, en un carrito nuevo y a la caja (17/11/2026: le salía la página para asistentes).
                $wanted = $step === 'add' && $this->hasProduct($request, $params) ? self::addUrl($this->app, (int) (isset($params['a']) ? $params['a'] : $request->get('product_id')))
                    : (isset($again[0]['url']) && strpos((string) $again[0]['url'], self::ALIAS . '/new/') !== false ? (string) $again[0]['url'] : '');
                if ($wanted !== '') {
                    $go = Response::redirect($wanted);
                    $go->headers['Cache-Control'] = 'no-store, private';

                    return $go;
                }
            }

            return $this->answer(['old_cart' => true, 'ordered_at' => (string) $placed['placed_at'] . ' UTC'], $again,
                'This is an OLD cart, from another conversation: it became an order on ' . (string) $placed['placed_at'] . ' UTC. It is not what the person is buying now, and that order is not theirs. '
                . 'Each address below puts that product in a NEW cart (reading it buys and charges nothing); its answer has the next steps.');
        }
        if ($placed !== null && $this->personBrowser($request)) {
            // La PERSONA abre una dirección de un carrito que ya es un pedido (su asistente se la dio de otra conversación,
            // 17/11/2026: le salía la página para asistentes con un pedido que no era el suyo). Si es la de añadir, va a comprar
            // eso en un carrito nuevo; si no, ve qué pedido es y puede volver a comprar lo mismo.
            if ($step === 'add' && $this->hasProduct($request, $params)) {
                $go = Response::redirect(self::addUrl($this->app, (int) (isset($params['a']) ? $params['a'] : $request->get('product_id'))));
                $go->headers['Cache-Control'] = 'no-store, private';

                return $go;
            }
            $row = Carts::find($this->app, $raw);

            return $this->placedForPerson($placed, $this->buyAgain($row !== null ? (int) $row['order_id'] : 0));
        }
        if ($placed !== null) {
            // Un GET lo repite cualquiera (la herramienta del asistente, una caché): si este carrito ya es un pedido, la
            // respuesta es ese pedido, las veces que se abra. Así quien perdió la primera respuesta no se queda sin número.
            // Y con su fecha: el asistente que recuerda una dirección de otra conversación (17/11/2026, Claude con memoria) daba
            // ese pedido viejo como el que la persona estaba haciendo ahora.
            return $this->answer($placed, [], 'This cart has already been ordered, at ' . (string) $placed['placed_at'] . ' (shop time): this is that order. '
                . 'If that is the order the person is asking about, give them its number and its order page; do not place it again. '
                . 'If the person is buying now, this is NOT their new order: an address from another conversation is an old cart. Start a new one from the address of the product (it creates a new cart).');
        }
        Carts::openToken($this->app, $raw);
        $input = $this->input($request);
        if ($inPath) {
            // Los valores que vienen como trozos de la ruta valen lo mismo que sus parámetros.
            foreach (isset(self::PATH[$step]) ? self::PATH[$step] : [] as $i => $name) {
                $segment = $i === 0 ? 'a' : 'b';
                if (!isset($params[$segment])) {
                    continue;
                }
                if (in_array($name, self::INTEGERS, true)) {
                    if (preg_match('/^\d{1,9}$/D', (string) $params[$segment]) !== 1) {
                        throw HttpError::badRequest('The value ' . $name . ' must be a whole number');
                    }
                    $input[$name] = (int) $params[$segment];
                } else {
                    $input[$name] = (string) $params[$segment];
                }
            }
            if ($step === 'place') {
                // En la ruta limpia no hay sitio para la declaración: la hace quien abre la dirección con el código de la persona.
                $input['buyer_confirmed'] = 'true';
            }
        }

        if ($step === 'cart') {
            // Con lo que falta de quién compra: Claude miraba el carrito tras guardar la persona sus datos y, como el carrito no
            // los enseñaba, concluía que no estaban (17/11/2026, R42).
            $view = Agent::tool($this->app, 'cart_view');
            $status = Agent::tool($this->app, 'checkout_status');
            $view['missing_fields'] = isset($status['missing_fields']) && is_array($status['missing_fields']) ? $status['missing_fields'] : [];

            return $this->answer($view, $this->afterCart($raw), $view['missing_fields'] === [] ? 'The details of who buys are saved in this cart. The next steps (delivery, payment, the review) start at "What is still missing".' : '');
        }
        if ($step === 'add') {
            // Una dirección la puede abrir dos veces cualquiera (el asistente, una caché): «añadir» deja en el carrito la
            // cantidad pedida, no la suma. Si el producto ya está, se pone esa cantidad en su línea.
            $product = isset($input['product_id']) ? (int) $input['product_id'] : 0;
            $variant = isset($input['variant_id']) ? (int) $input['variant_id'] : 0;
            $view = Agent::tool($this->app, 'cart_view');
            foreach (isset($view['lines']) && is_array($view['lines']) ? $view['lines'] : [] as $line) {
                if (is_array($line) && (int) $line['product_id'] === $product && ((int) $line['variant_id'] === $variant || !isset($input['variant_id']))) {
                    $result = Agent::tool($this->app, 'cart_update', ['key' => (string) $line['key'], 'qty' => isset($input['qty']) ? (int) $input['qty'] : 1]);
                    Carts::keep($this->app, $raw, CartController::SOURCE);

                    if ($this->personBrowser($request)) {
                        return $this->toCheckout($raw);
                    }

                    return $this->answer(['cart' => $raw] + $result, $this->afterCart($raw), $this->afterAdd($raw), $this->cartForPerson($raw));
                }
            }
            $result = Agent::tool($this->app, 'cart_add', array_intersect_key($input, ['product_id' => 1, 'variant_id' => 1, 'qty' => 1]));
            $who = array_intersect_key(self::optionsToNote($input), array_flip(self::TOOLS['customer'][1]));
            if (isset($who['text']) && self::isTemplate((string) $who['text'])) {
                // La dirección «todo en una» abierta con el ejemplo tal cual: se añade el producto y los datos se piden aparte.
                unset($who['text']);
            }
            if ($who !== []) {
                // La misma dirección trae los datos del comprador: se guardan y lo siguiente ya es elegir el envío.
                $saved = Agent::tool($this->app, 'checkout_set_customer', $who);

                // Si la abre la PERSONA (su asistente le escribió la dirección con sus datos, 17/11/2026), lo primero que lee es
                // para ella: que ya está y, si su asistente no sigue, la caja con su carrito.
                $complete = !empty($result['ok']) && empty($saved['errors']) && empty($saved['missing_fields']);

                return $this->answer(['cart' => $raw, 'added' => $result, 'customer' => $saved], $this->afterTool('customer', $raw, $saved), '',
                    $complete
                        ? t('Your details are saved. Go back to your assistant and tell it to continue.') . $this->finishHere($raw)
                        : t('Some of your details are missing or not valid. Go back to your assistant and tell it what this page says.'));
            }

            if (!empty($result['ok']) && $this->personBrowser($request)) {
                // Lo abre la PERSONA en su navegador (su asistente le dio el enlace): a la caja con el producto, sin la página
                // para asistentes en inglés y con JSON (16/11/2026: tenía que buscar el enlace del carrito dentro).
                return $this->toCheckout($raw);
            }

            return $this->answer(['cart' => $raw] + $result, $this->afterCart($raw), $this->afterAdd($raw), $this->cartForPerson($raw));
        }
        if ($step === 'customer' && !$this->json && array_intersect_key($input, array_flip(self::TOOLS['customer'][1])) === []) {
            // La PERSONA abre la dirección de los datos tal cual, sin datos: le sale un formulario que los guarda en ESTE carrito.
            // Así el asistente que no puede abrir direcciones que monta él solo tiene que darle un enlace ya escrito (16/11/2026:
            // sin la app TinyFish, ChatGPT no sabía guardar los datos y decía que la tienda no le dejaba comprar).
            if (\Aifec\Kernel\VisitLog::bot($request->userAgent()) !== null) {
                // Pero si la abre el lector del ASISTENTE, el formulario no es para él (17/11/2026: Claude lo veía, decía «no
                // puedo enviar formularios» y mandaba a la persona a terminar sola en la caja). Se le dice qué hacer con él.
                return $this->answer(['cart' => $raw, 'form_for_the_person' => $this->url('customer', $raw)], $this->afterCart($raw),
                    'This address is a form for the PERSON who buys, not for an assistant: nobody but them fills it in. They open it and type their name, address, phone and email, and the shop keeps them in this cart: '
                    . $this->url('customer', $raw) . ' . Once they have, this page shows what is still missing and the next steps (delivery, payment, the review and the order with the code the shop emails them): '
                    . $this->statusUrl($raw) . ' .');
            }

            return $this->detailsForm($raw);
        }
        if ($step === 'customer' && isset($input['text']) && self::isTemplate((string) $input['text'])) {
            // El ejemplo abierto TAL CUAL: es lo que hace el lector que solo abre direcciones escritas (16/11/2026, Claude-User:
            // «NAME, STREET AND NUMBER…» y un 422 «no he encontrado ningún dato personal»). No es un error: es la señal de que
            // ese asistente no puede completar direcciones, y su camino es el formulario para la persona.
            // Sin la caja entre las siguientes: con ella delante, Claude mandaba a la persona a terminar sola en vez de darle el
            // formulario y seguir él hasta el pedido (17/11/2026, R29-R31). La caja sigue en la respuesta de añadir.
            $formOnly = [
                ['do' => 'The form for the person: they type who receives it, and the shop keeps it in this cart', 'url' => $this->url('customer', $raw)],
                ['do' => 'What is still missing, and the next steps, once the person has filled the form', 'url' => $this->statusUrl($raw)],
                ['do' => 'See the cart', 'url' => $this->url('cart', $raw)],
            ];

            return $this->answer(['cart' => $raw, 'saved' => false], $formOnly,
                'The example address was opened as it is, so no details were saved, which is fine. '
                . 'The details of who buys (name, address, phone, email, and a note for a size or colour) are typed by the person in this form of the shop, which keeps them in this cart: '
                . $this->url('customer', $raw) . ' . Once they have, this page shows what is still missing and the next steps (delivery, payment, the review and the order with the code the shop emails them): '
                . $this->statusUrl($raw) . ' .');
        }
        if ($step === 'customer') {
            $input = self::optionsToNote($input);
        }
        if (isset(self::TOOLS[$step])) {
            $result = Agent::tool($this->app, self::TOOLS[$step][0], array_intersect_key($input, array_flip(self::TOOLS[$step][1])));
            if ($step === 'customer') {
                // Esta dirección la abre a veces la PERSONA (se la escribe su asistente, que no puede abrirla): la página le
                // dice, en el idioma de la tienda, que ya está y que vuelva al chat. Al asistente, por dónde sigue.
                $complete = empty($result['errors']) && empty($result['missing_fields']);

                return $this->answer($result, $this->afterTool($step, $raw, $result),
                    $complete
                        ? 'The buyer details are saved. Go on with "See the delivery options".'
                        : 'Some details are missing or not valid (see "errors" and "missing_fields"). Ask the person for them and save them again, with the same address.',
                    $complete
                        ? t('Your details are saved. Go back to your assistant and tell it to continue.') . $this->finishHere($raw)
                        : t('Some of your details are missing or not valid. Go back to your assistant and tell it what this page says.'));
            }

            // Mientras falte quién compra, cada paso lo dice lo primero, con el formulario para la persona ya escrito: ChatGPT
            // sin herramientas elegía el pago y, al no poder guardar los datos él, decía que la tienda no respondía (16/11/2026).
            $missing = $step === 'status' ? $result : Agent::tool($this->app, 'checkout_status');
            $who = isset($missing['missing_fields']) && is_array($missing['missing_fields']) ? $missing['missing_fields'] : [];
            if ($step === 'status' && is_array($result)) {
                // La nota del pedido (la talla, el color) a la vista: el asistente no podía confirmar que la de la persona había
                // llegado (17/11/2026, R38: «no puedo confirmar que la nota "talla 43" quedó guardada»).
                $note = $this->cartNote($raw);
                if ($note !== '') {
                    $result['note'] = $note;
                }
            }

            $say = $who !== [] ? $this->whoIsMissing($raw) : '';
            if ($step === 'payment-options') {
                // Elegir la forma de pago solo la apunta en el carrito: Claude creyó que elegirla era pagar y le dio a la persona
                // la dirección de elegirla, que su lector no podía abrir; sin pedido (17/11/2026, R50).
                $say = trim($say . ' ' . self::PAYMENT_CHOICE);
            }

            if (($step === 'payment-options' || $step === 'shipping-options') && is_array($result)) {
                // Lo que se va a pagar, a la vista en el propio paso: el asistente no tiene que pedir el carrito aparte para decírselo a la persona.
                $summary = $this->cartSummary();
                if ($summary !== null) {
                    $result['cart_summary'] = $summary;
                }
            }

            return $this->answer($result, $this->afterTool($step, $raw, $result), $say);
        }
        if ($step === 'review') {
            $key = $request->attr('api.key');
            $review = Checkout::review($this->app, is_array($key) ? $key : null);
            $next = [];
            if (!empty($review['confirm_token'])) {
                if (!$this->readerOnly()) {
                    $next[] = ['do' => 'Place the order: replace CODE with the 6-digit code the person received',
                        'url' => $this->url('place', $raw, ['confirm_token' => (string) $review['confirm_token'], 'buyer_confirmed' => 'true', 'buyer_code' => 'CODE'])];
                }
                // Al lector de una IA de chat, solo este: el de «CODE» lo completaba y su herramienta lo rechazaba, y la persona
                // tenía que abrirlo (17/11/2026, R40). Con este abre una página por cifra, ya escritas todas.
                $next[] = ['do' => 'To enter the code with links: this page has one address per digit; open the first digit of the code, and on each page the address of the next one. '
                    . 'Writing this address in the answer to the person now, when asking for the code, keeps it at hand when they give it',
                    'url' => $this->url('code', $raw, ['confirm_token' => (string) $review['confirm_token']])];
            } elseif (empty($review['ready'])) {
                $next = $this->afterTool('status', $raw, []);
            }
            if (!$this->readerOnly()) {
                $next[] = ['do' => 'Or let the person finish on the web', 'url' => Carts::resumeUrl($this->app, $raw)];
            }

            return $this->answer($review, $next, isset($review['next']) ? (string) $review['next'] : '');
        }
        if ($step === 'code') {
            return $this->keypad($raw, $input);
        }
        if ($step === 'place') {
            $key = $request->attr('api.key');
            if (isset($input['buyer_confirmed'])) {
                $input['buyer_confirmed'] = in_array(strtolower((string) $input['buyer_confirmed']), ['1', 'true', 'yes'], true);
            }
            $order = Checkout::place($this->app, is_array($key) ? $key : null, $input, CartController::SOURCE);
            if ($this->personBrowser($request)) {
                // La abre la PERSONA (su asistente le escribió la dirección con el código, 17/11/2026): va a pagar, o a la página
                // de su pedido, en vez de a la página para asistentes (Claude decía después «no puedo ver el enlace de pago»).
                $go = isset($order['payment']['pay_url']) && is_string($order['payment']['pay_url']) && $order['payment']['pay_url'] !== ''
                    ? $order['payment']['pay_url'] : (isset($order['order_page']) && is_string($order['order_page']) ? $order['order_page'] : '');
                if ($go !== '') {
                    $to = Response::redirect($go);
                    $to->headers['Cache-Control'] = 'no-store, private';
                    $to->headers['Referrer-Policy'] = 'no-referrer';

                    return $to;
                }
            }

            return $this->answer($order, [], 'The order is placed. Give the person the order number and the order page, and tell them how to pay ("payment"). If "payment" has a pay_url, the person must open it to pay: give it to them as it is.');
        }

        throw new HttpError(404, 'Unknown step. The steps are: start, add, cart, customer, shipping-options, shipping, payment-options, payment, status, review, code, place');
    }

    /**
     * El código del comprador, tecleado con enlaces: cada página trae diez direcciones, una por cada dígito (de 0 a 9), y las
     * del sexto dígito ya son las del pedido. Es para los lectores que solo abren una dirección si la han leído escrita en una
     * página (no montan ninguna): así ninguna dirección la tiene que construir el asistente ni pegar la persona. Las páginas
     * son pequeñas a propósito (ver CODE_STEP): un lector que recorta lo que lee las ve enteras. No comprueba ni cambia nada:
     * el código se sigue comprobando al hacer el pedido, con sus intentos contados.
     *
     * @param array<string,mixed> $input
     */
    private function keypad(string $raw, array $input): Response
    {
        $token = isset($input['confirm_token']) && is_scalar($input['confirm_token']) ? (string) $input['confirm_token'] : '';
        $digits = isset($input['digits']) && is_scalar($input['digits']) ? (string) $input['digits'] : '';
        if ($token === '' || preg_match('/^[A-Za-z0-9_.-]{8,80}$/D', $token) !== 1 || preg_match('/^\d{0,' . (self::CODE_DIGITS - self::CODE_STEP) . '}$/D', $digits) !== 1) {
            throw HttpError::badRequest('This address is not complete. Open the review again and start from the address it gives to enter the code');
        }
        $position = strlen($digits) + 1;
        $last = strlen($digits) + self::CODE_STEP >= self::CODE_DIGITS;
        $next = [];
        for ($digit = 0; $digit <= 9; $digit++) {
            $typed = $digits . $digit;
            $next[] = $last
                ? ['do' => 'Digit ' . $position . ' is ' . $digit . ': place the order with the code ' . $typed,
                    'url' => $this->url('place', $raw, ['confirm_token' => $token, 'buyer_confirmed' => 'true', 'buyer_code' => $typed])]
                : ['do' => 'Digit ' . $position . ' is ' . $digit, 'url' => $this->url('code', $raw, ['confirm_token' => $token, 'digits' => $typed])];
        }

        return $this->answer(['typed' => $digits, 'digits_left' => self::CODE_DIGITS - strlen($digits)], $next,
            'Enter the code the person gave you, one digit per page: open the address of digit ' . $position . ' of ' . self::CODE_DIGITS
            . '. Only the digits the person told you: do not guess any. Nothing is placed until the last digit. '
            . 'If you cannot open more addresses in this turn, tell the person how many digits you have entered and continue from this page in the next one.');
    }

    /**
     * El pedido en que se convirtió este carrito, o null si sigue abierto (o no existe).
     *
     * @return array<string,mixed>|null
     */
    private function placed(string $raw): ?array
    {
        $row = Carts::find($this->app, $raw);
        if ($row === null || $row['order_id'] === null || (int) $row['order_id'] < 1) {
            return null;
        }
        $order = $this->app->db()->row('SELECT number, total, currency, status, payment_status, payment_method, placed_at FROM {orders} WHERE id = ?', [(int) $row['order_id']]);
        if ($order === null) {
            return null;
        }
        // La ficha EN CLARO de la página del pedido: en la tabla solo está su resumen (17/11/2026: el enlace se montaba con el
        // resumen y daba un 404 siempre; Claude dio a dos clientes un pedido «sin enlace de pago»).
        $token = $this->app->has('orders') ? $this->app->service('orders')->viewTokenOf((string) $order['number']) : '';
        $page = '';
        try {
            $page = $token !== '' ? Present::abs($this->app, route('order.thanks', ['number' => (string) $order['number'], 'secret' => $token])) : '';
        } catch (\Throwable $e) {
            $page = '';
        }

        $placed = [
            'already_placed' => true,
            'number' => (string) $order['number'],
            'total' => (int) $order['total'],
            'total_formatted' => $this->app->i18n()->money((int) $order['total'], (string) $order['currency']),
            'currency' => (string) $order['currency'],
            'status' => (string) $order['status'],
            'payment_method' => (string) $order['payment_method'],
            'placed_at' => (string) $order['placed_at'],
            'order_page' => $page,
        ];
        if ($page !== '' && OrderPageController::payable($this->app, $order)) {
            // Sigue sin cobrar y se paga en una pasarela: la dirección que la persona abre para pagar.
            $placed['pay_url'] = OrderPageController::payUrl($this->app, $page);
        }

        return $placed;
    }

    /** La entrada por la que ha llegado esta petición: /api/v1/go o, fuera de /api/, /buy-with-links. Las siguientes van por la misma. */
    private function base(): string
    {
        return strpos((string) $this->app->request()->path, self::ALIAS) !== false ? self::ALIAS : Routes::PREFIX . '/go';
    }

    private function check(): void
    {
        if (!self::enabled($this->app)) {
            throw new HttpError(404, 'Not found');
        }
    }

    /**
     * Los parámetros de la dirección como los espera la herramienta: sin la ficha, y los números, como números.
     *
     * @return array<string,mixed>
     */
    private function input(Request $request): array
    {
        $input = [];
        foreach ($request->query as $name => $value) {
            if ($name === 'cart' || $name === 'format' || !is_string($name) || !is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if (in_array($name, self::INTEGERS, true)) {
                if (preg_match('/^\d{1,9}$/D', $value) !== 1) {
                    throw HttpError::badRequest('The parameter ' . $name . ' must be a whole number');
                }
                $input[$name] = (int) $value;
            } else {
                $input[$name] = $value;
            }
        }

        return $input;
    }

    /**
     * Lo que se le dice a una PERSONA que abre ella misma una dirección de «añadir» (la que va escrita en la ficha del
     * producto es para su asistente, pero cualquiera puede pulsarla): dónde tiene su carrito, en el idioma de la tienda.
     */
    /** Lo que se dice en cada paso mientras falten los datos de quien compra, con el formulario para la persona ya escrito. */
    private function whoIsMissing(string $raw): string
    {
        $resume = $this->app->has('cart') && $this->app->router()->has('checkout') ? Carts::resumeUrl($this->app, $raw) : $this->url('status', $raw);

        return 'The shop still needs who buys (name, address, email and phone). You do not need any tool or permission for it: write this address in your answer, exactly as it is, and ask the person to open it: '
            . $this->url('customer', $raw) . ' . It is a form; what they type is saved in this cart. When they tell you it is done, open "See what is still missing" and go on to the review and the order with their code. '
            . 'Or, if they prefer to finish at the shop, give them this address, which opens this same cart at the checkout: ' . $resume . ' . This address works for any buyer, with or without tools.';
    }

    /** Lo que se le dice al asistente al añadir, con las dos direcciones escritas: la IA solo abre o copia lo que ha leído. */
    private function afterAdd(string $raw): string
    {
        $resume = $this->app->has('cart') && $this->app->router()->has('checkout') ? Carts::resumeUrl($this->app, $raw) : $this->url('status', $raw);

        // Al lector que no completa direcciones (Claude-User, ChatGPT-User, el de Gemini), las dos formas de seguir, sin la
        // dirección que hay que completar (ver afterCart()).
        $text = strtr($this->readerOnly() ? self::AFTER_ADD_READER : self::AFTER_ADD,
            ['{FORM}' => $this->url('customer', $raw), '{STATUS}' => $this->statusUrl($raw), '{RESUME}' => $resume]);

        return $this->demoShop() ? $text . ' ' . self::DEMO_NOTE : $text;
    }

    /**
     * Si la tienda es de demostración o todas sus formas de pago en línea están en modo de prueba. Se le dice así al
     * asistente: Gemini y Claude no seguían porque «recordaban» que era una tienda de pruebas y no sabían si podían (17/11/2026).
     */
    private function demoShop(): bool
    {
        if ($this->demoShop !== null) {
            return $this->demoShop;
        }
        $this->demoShop = $this->app->site()->isDemo();
        if (!$this->demoShop) {
            $options = Agent::tool($this->app, 'checkout_payment_options');
            $online = 0;
            $test = 0;
            foreach (isset($options['options']) && is_array($options['options']) ? $options['options'] : [] as $option) {
                $kind = is_array($option) && isset($option['kind']) ? (string) $option['kind'] : '';
                if ($kind === '' || $kind === 'offline') {
                    continue;
                }
                $online++;
                $test += $kind === 'test' ? 1 : 0;
            }
            $this->demoShop = $online > 0 && $online === $test;
        }

        return $this->demoShop;
    }

    /**
     * La dirección de «lo que falta». Al lector de una IA de chat, con un trozo al azar al final, nueva en cada respuesta: su
     * herramienta guarda copia de lo que ya abrió y, al volver a mirar el estado después de que la persona rellenara el
     * formulario, le daba la página vieja, sin datos (17/11/2026, R42: «sigo viendo el carrito vacío de datos»).
     */
    private function statusUrl(string $raw): string
    {
        return $this->readerOnly() ? $this->url('status', $raw) . '/' . bin2hex(random_bytes(3)) : $this->url('status', $raw);
    }

    /** La nota que lleva el carrito para el pedido (la talla o el color que escribió la persona), o ''. */
    private function cartNote(string $raw): string
    {
        $json = (string) $this->app->db()->value('SELECT checkout FROM {carts} WHERE token = ?', [hash('sha256', $raw)]);
        $checkout = $json !== '' ? json_decode($json, true) : null;

        return is_array($checkout) && isset($checkout['note']) && is_string($checkout['note']) ? trim($checkout['note']) : '';
    }

    /** ¿Lo pide el lector de una IA de chat, que solo abre direcciones escritas tal cual (no la API ni un programa)? */
    private function readerOnly(): bool
    {
        $bot = \Aifec\Kernel\VisitLog::bot($this->app->request()->userAgent());

        return !$this->json && $bot !== null && $bot[2] === 'ai_user';
    }

    /**
     * Para la persona que acaba de guardar sus datos: si su asistente no sigue (16/11/2026: ChatGPT añadió el producto, le dio
     * el formulario y después dijo que la tienda no le devolvía el carrito), puede terminar ella en la caja, que ya los tiene.
     */
    private function finishHere(string $raw): string
    {
        if (!$this->app->has('cart') || !$this->app->router()->has('checkout') || !$this->app->router()->has('api.cart.resume')) {
            return '';
        }

        return ' ' . t('If your assistant cannot go on, finish yourself at the checkout, with your cart and your details: {url}', ['url' => Carts::resumeUrl($this->app, $raw)]);
    }

    private function cartForPerson(string $raw): string
    {
        if (!$this->app->has('cart') || !$this->app->router()->has('checkout')) {
            return '';
        }
        if (\Aifec\Kernel\VisitLog::bot($this->app->request()->userAgent()) !== null) {
            // Al lector de un asistente no: era lo primero que leía, y daba a la persona la caja para que terminara sola en vez
            // del formulario de los datos (17/11/2026, Claude).
            return '';
        }

        return t('This page is for AI assistants. Your cart is ready at the checkout of the shop: {url}', ['url' => Carts::resumeUrl($this->app, $raw)]);
    }

    /**
     * Lo que sigue a un carrito con algo dentro.
     *
     * @return array<int,array{do:string,url:string}>
     */
    private function afterCart(string $raw): array
    {
        $next = [
            ['do' => 'Save who buys: ask the person for their name, address, email and a phone (the carrier needs it for the delivery) and put their own words in text (URL-encoded), in place of the example. A detail the product does not offer as an option (a size, a colour) goes in the parameter note of the same address (URL-encoded). '
                . 'If your tool refuses to open the address once you have completed it, do not give up and do not ask for another tool: write the completed address in your answer as a link, ask the person to open it (it saves their details and tells them so), and then go on from the "what is still missing" address',
                'url' => $this->url('customer', $raw, ['text' => 'NAME, STREET AND NUMBER, POSTCODE CITY, PHONE, EMAIL'])],
            // Para el asistente que no puede abrir direcciones que completa él: un enlace ya escrito que abre un formulario
            // para la persona. Ella escribe sus datos, se guardan en este carrito y el asistente sigue y hace el pedido.
            ['do' => 'If you cannot open addresses you complete yourself: write THIS address, as it is, in your answer and ask the person to open it. It is a form where they write their name, address, phone and email; '
                . 'the shop saves them in this cart. When they tell you it is done, open "What is still missing" and go on to the delivery, the payment and the order: you still place it with their code',
                'url' => $this->url('customer', $raw)],
        ];
        if ($this->readerOnly()) {
            // El lector de una IA de chat (Claude-User, ChatGPT-User, el de Gemini) no abre direcciones que completa él: el
            // ejemplo de los datos solo le servía para abrirlo tal cual, recibir «saved: false» y dar un tropiezo antes del
            // formulario (17/11/2026, todas las pruebas con Claude). Se le da directamente el formulario para la persona.
            array_shift($next);
        }
        if ($this->app->has('cart') && $this->app->router()->has('checkout')) {
            // Los lectores de casi todas las IA de chat solo abren direcciones que han leído escritas: no pueden montar la de
            // los datos del comprador. Para ellos el camino es este: la persona abre el carrito en la caja de la tienda y lo
            // termina allí; el asistente mira después el estado y le da el número de pedido.
            if ($this->readerOnly()) {
                // Al lector de una IA de chat, la caja LO PRIMERO, como la forma de seguir que vale con cualquier asistente: dar a
                // la persona su carrito en la caja es un buen final, y no se la obliga a recorrer los pasos del pedido (17/11/2026).
                array_unshift($next, ['do' => self::HANDOFF, 'url' => Carts::resumeUrl($this->app, $raw)]);
            } else {
                $next[] = ['do' => self::HANDOFF, 'url' => Carts::resumeUrl($this->app, $raw)];
            }
            // La caja se abre con la forma de pago que el asistente haya elegido antes: si la persona ya dijo «pago con
            // PayPal», no tiene que buscarla (venía marcado contra reembolso y había que cambiarlo, 16/11/2026).
            $next[] = ['do' => 'If the person already told you how they want to pay, choose it here BEFORE you give them the checkout address: the checkout will open with that payment selected',
                'url' => $this->url('payment-options', $raw)];
            $next[] = ['do' => 'What is still missing. Open it when the person tells you they have opened the address with their details (it gives you the next steps), or that they have finished at the shop (it gives you their order number)',
                'url' => $this->statusUrl($raw)];
        }
        if (!$this->readerOnly()) {
            // Al lector de una IA de chat no: la plantilla «ID» no la puede completar; tiene las direcciones de addMore() ya escritas.
            $next[] = ['do' => 'Add another product', 'url' => $this->url('add', $raw, ['product_id' => 'ID', 'qty' => '1'])];
        }
        $next = array_merge($next, $this->addMore($raw));
        $next[] = ['do' => 'See the cart', 'url' => $this->url('cart', $raw)];

        return $next;
    }

    /**
     * Para el asistente que solo abre direcciones escritas: la que añade a ESTE carrito cada producto de la misma categoría que
     * lo que ya lleva, ya completa (16/11/2026: Claude ofreció colorante y mechas junto al kit de velas y no pudo meterlos en el
     * mismo carrito: «add/CARRITO/ID/1» lleva un marcador y su lector no abre direcciones que completa él).
     *
     * @return array<int,array{do:string,url:string}>
     */
    private function addMore(string $raw): array
    {
        if (!$this->app->has('catalog')) {
            return [];
        }
        $catalog = $this->app->service('catalog');
        $view = Agent::tool($this->app, 'cart_view');
        $inCart = [];
        $category = 0;
        foreach (isset($view['lines']) && is_array($view['lines']) ? $view['lines'] : [] as $line) {
            if (!is_array($line) || !isset($line['product_id'])) {
                continue;
            }
            $inCart[(int) $line['product_id']] = true;
            if ($category === 0) {
                $product = $catalog->product((int) $line['product_id']);
                $category = is_array($product) && isset($product['main_category']['id']) ? (int) $product['main_category']['id'] : 0;
            }
        }
        if ($category < 1) {
            return [];
        }
        try {
            $listing = $catalog->listing(['category_id' => $category, 'in_stock' => true, 'per_page' => 12]);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach (isset($listing['items']) && is_array($listing['items']) ? $listing['items'] : [] as $card) {
            if (count($out) >= 8) {
                break;
            }
            if (!is_array($card) || !isset($card['id'], $card['name']) || isset($inCart[(int) $card['id']]) || !empty($card['has_variants'])
                || !self::buyable(isset($card['availability']) && is_array($card['availability']) ? $card['availability'] : [])) {
                continue;
            }
            $price = isset($card['price']['amount']) && empty($card['price']['hidden'])
                ? ' (' . $this->app->i18n()->money((int) $card['price']['amount'], isset($card['price']['currency']) ? (string) $card['price']['currency'] : null) . ')' : '';
            $out[] = ['do' => 'Add to this same cart: ' . (string) $card['name'] . $price, 'url' => $this->url('add', $raw, ['product_id' => (string) (int) $card['id'], 'qty' => '1'])];
        }

        return $out;
    }

    /**
     * ¿Trae «add» un producto de verdad? Sin él, o con el ejemplo «ID» tal cual, es el lector que no puede completar direcciones.
     *
     * @param array<string,string> $params
     */
    private function hasProduct(Request $request, array $params): bool
    {
        $value = isset($params['a']) ? $params['a'] : $request->get('product_id');

        return is_scalar($value) && preg_match('/^\d{1,9}$/D', trim((string) $value)) === 1 && (int) $value > 0;
    }

    /**
     * Para quien abre «add» sin producto: el catálogo por enlaces, ya escritos. En la página de texto de cada categoría, cada
     * producto lleva escrita la dirección que lo mete en un carrito.
     */
    private function findFirst(string $raw): Response
    {
        $next = $this->catalogueLinks();
        if ($raw !== '') {
            $next[] = ['do' => 'See the cart', 'url' => $this->url('cart', $raw)];
        }

        return $this->answer(['added' => false], $next,
            'No product was given (or only the example "ID"), which is fine: the products are reached by links. '
            . 'Each category below lists its products, and next to each product is written the address that puts it in a new cart (reading it buys and charges nothing); its answer has the next steps.');
    }

    /**
     * El catálogo por enlaces, ya escritos: el llms.txt (si la tienda publica la versión en texto) y cada categoría, cuya página
     * lleva escrita junto a cada producto la dirección que lo mete en un carrito nuevo.
     *
     * @return array<int,array{do:string,url:string}>
     */
    private function catalogueLinks(): array
    {
        $text = $this->app->router()->has('semantic.llms');
        $next = [];
        if ($text) {
            $next[] = ['do' => 'The catalogue, by links: every category, and in each one its products with the address that adds each to a cart, already written',
                'url' => $this->app->url()->abs('/llms.txt', [], '')];
        }
        if ($this->app->has('catalog')) {
            $pages = [];
            foreach ($this->app->service('catalog')->tree() as $category) {
                if (!is_array($category)) {
                    continue;
                }
                $pages[] = $category;
                foreach (isset($category['children']) && is_array($category['children']) ? $category['children'] : [] as $child) {
                    $pages[] = $child;
                }
            }
            foreach (array_slice($pages, 0, 40) as $category) {
                if (!is_array($category) || !isset($category['name'], $category['url'])) {
                    continue;
                }
                $url = rtrim(Present::abs($this->app, (string) $category['url']), '/');
                $next[] = ['do' => 'Category ' . (string) $category['name'] . ': its products, each with the address that puts it in a cart', 'url' => $text ? $url . '.md' : $url];
            }
        }

        return $next;
    }

    /**
     * Las direcciones que vuelven a meter en un carrito NUEVO lo que llevaba un pedido viejo (lo que se sigue pudiendo comprar).
     *
     * @return array<int,array{do:string,url:string}>
     */
    private function buyAgain(int $orderId): array
    {
        $out = [];
        $rows = $orderId > 0 ? $this->app->db()->select("SELECT DISTINCT product_id, variant_id, name FROM {order_items} WHERE order_id = ? AND kind = 'product' AND product_id IS NOT NULL", [$orderId]) : [];
        foreach (is_array($rows) ? $rows : [] as $line) {
            // Con un trozo al azar: nunca una dirección que el lector del asistente tenga ya guardada (17/11/2026, caché de Claude).
            $out[] = ['do' => 'Buy it now, in a new cart: ' . (string) $line['name'],
                'url' => $this->app->url()->abs(self::ALIAS . '/new/' . (int) $line['product_id'] . '/' . (int) $line['variant_id'] . '/' . bin2hex(random_bytes(4)), [], '')];
        }
        if ($this->app->router()->has('semantic.llms')) {
            $out[] = ['do' => 'Or find something else in the catalogue, by links', 'url' => $this->app->url()->abs('/llms.txt', [], '')];
        }

        return $out;
    }

    /**
     * La talla o el color escritos entre los datos («…, aifec@…, Adidas Stabil 16 TALLA 39») van a la nota del pedido si no hay
     * nota: el lector de los datos solo saca los de la persona, y la talla se perdía (17/11/2026, Claude: «la talla no queda
     * en el pedido»).
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    private static function optionsToNote(array $input): array
    {
        if (!isset($input['text']) || !is_string($input['text']) || (isset($input['note']) && trim((string) $input['note']) !== '')) {
            return $input;
        }
        if (preg_match_all('/\b(?:talla|tallas|size|n[uú]mero de pie|color|colou?r)\s*[:=\-]?\s*[\p{L}\d][\p{L}\d.,\/]{0,11}/iu', $input['text'], $m) > 0) {
            $input['note'] = implode(', ', array_map('trim', $m[0]));
        }

        return $input;
    }

    /**
     * Para la persona que abre un carrito que ya es un pedido: qué pedido es, su página, y volver a comprar lo mismo.
     *
     * @param array<string,mixed>                     $placed
     * @param array<int,array{do:string,url:string}> $again
     */
    private function placedForPerson(array $placed, array $again): Response
    {
        $h = static function (string $text): string {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $html = '<!doctype html><html lang="' . $h($this->app->lang()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . $h(t('This cart is already an order')) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:2rem auto;padding:0 1rem;line-height:1.5}a{display:inline-block;margin:.4rem 0}</style></head><body>'
            . '<h1>' . $h(t('This cart is already an order')) . '</h1>'
            . '<p>' . $h(t('It became the order {number}. If that is not the purchase you are making now, buy it again: it goes to a new cart.', ['number' => (string) $placed['number']])) . '</p>';
        if (isset($placed['order_page']) && (string) $placed['order_page'] !== '') {
            $html .= '<p><a href="' . $h((string) $placed['order_page']) . '">' . $h(t('See the order {number}', ['number' => (string) $placed['number']])) . '</a></p>';
        }
        foreach ($again as $link) {
            if (strpos((string) $link['url'], self::ALIAS . '/new/') !== false) {
                $html .= '<p><a href="' . $h((string) $link['url']) . '">' . $h(t('Buy it again: {name}', ['name' => (string) preg_replace('/^Buy it now, in a new cart: /', '', (string) $link['do'])])) . '</a></p>';
            }
        }
        $html .= '</body></html>';

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer']);
    }

    /** ¿Es el ejemplo de la dirección de los datos tal cual («NAME, STREET AND NUMBER…»), sin los datos de nadie? */
    private static function isTemplate(string $text): bool
    {
        return stripos($text, 'STREET AND NUMBER') !== false || stripos($text, 'POSTCODE CITY') !== false;
    }

    /**
     * Lo que sigue a un paso de la caja, con las opciones que ha devuelto ya puestas en sus direcciones.
     *
     * @param array<string,mixed> $result
     *
     * @return array<int,array{do:string,url:string}>
     */
    private function afterTool(string $step, string $raw, array $result): array
    {
        $options = isset($result['options']) && is_array($result['options']) ? $result['options'] : [];
        $next = [];
        if ($step === 'shipping-options') {
            foreach (array_slice($options, 0, 12) as $option) {
                if (is_array($option) && isset($option['id'])) {
                    $label = (isset($option['name']) ? (string) $option['name'] : 'delivery') . (isset($option['price_formatted']) ? ' (' . (string) $option['price_formatted'] . ')' : '');
                    $next[] = ['do' => 'Choose this delivery: ' . $label, 'url' => $this->url('shipping', $raw, ['carrier_id' => (string) $option['id']])];
                }
            }

            return $next;
        }
        if ($step === 'payment-options') {
            foreach (array_slice($options, 0, 12) as $option) {
                if (is_array($option) && isset($option['id'])) {
                    $next[] = ['do' => 'Choose this payment method (it is only recorded in the cart; nothing is charged): ' . (isset($option['name']) ? (string) $option['name'] : (string) $option['id']), 'url' => $this->url('payment', $raw, ['method' => (string) $option['id']])];
                }
            }

            return $next;
        }
        if ($step === 'customer' || $step === 'status') {
            $next[] = ['do' => 'See the delivery options', 'url' => $this->url('shipping-options', $raw)];
        }
        if ($step === 'shipping' || $step === 'status') {
            $next[] = ['do' => 'See the payment options', 'url' => $this->url('payment-options', $raw)];
        }
        if ($step === 'payment' || $step === 'status') {
            $next[] = ['do' => 'Review the order: the exact total, and the shop sends the verification code to the buyer', 'url' => $this->url('review', $raw)];
        }
        if ($step !== 'status') {
            $next[] = ['do' => 'See what is still missing', 'url' => $this->statusUrl($raw)];
        }

        return $next;
    }

    /**
     * La dirección de un paso, con su dominio. Los valores de ejemplo (ID, CODE…) van tal cual para que se vean.
     *
     * @param array<string,string> $query
     */
    private function url(string $step, string $raw = '', array $query = []): string
    {
        if ($raw !== '' && $this->base() === self::ALIAS && !$this->jsonParam) {
            // Por la entrada de fuera de /api/ las direcciones van como rutas limpias, sin «&» (ver PATH).
            $path = self::ALIAS . '/' . $step . '/' . $raw;
            foreach (isset(self::PATH[$step]) ? self::PATH[$step] : [] as $name) {
                if (!isset($query[$name])) {
                    break;
                }
                $path .= '/' . rawurlencode($query[$name]);
                unset($query[$name]);
            }
            unset($query['buyer_confirmed']);
            $rest = [];
            foreach ($query as $name => $value) {
                $rest[] = $name . '=' . str_replace('%2C', ',', rawurlencode($value));
            }

            return $this->app->url()->abs($path, [], '') . ($rest !== [] ? '?' . implode('&', $rest) : '');
        }
        $pairs = [];
        foreach (($raw !== '' ? ['cart' => $raw] : []) + $query + ($this->jsonParam ? ['format' => 'json'] : []) as $name => $value) {
            $pairs[] = $name . '=' . str_replace('%2C', ',', rawurlencode($value));
        }

        return $this->app->url()->abs($this->base() . '/' . $step, [], '') . ($pairs !== [] ? '?' . implode('&', $pairs) : '');
    }

    /**
     * @param array<string,mixed>                    $data
     * @param array<int,array{do:string,url:string}> $next
     */
    private function answer(array $data, array $next, string $say = '', string $person = ''): Response
    {
        $payload = ['data' => Present::amounts($data), 'next' => $next];
        if ($say !== '') {
            $payload['say'] = $say;
        }
        if ($person !== '') {
            // Para la persona, si es ella quien ha abierto la dirección: en el idioma de la tienda y lo primero de la página.
            $payload['for_the_person'] = $person;
        }
        if ($this->json) {
            return $this->sealed(Response::json($payload));
        }
        $lines = ['# Buying with links', ''];
        if ($person !== '') {
            $lines[] = '**' . t('If you are the person who is buying') . ':** ' . $person;
            $lines[] = '';
        }
        if ($say !== '') {
            $lines[] = '**How it goes on:** ' . $say;
            $lines[] = '';
        }
        $lines[] = '## Answer of the shop';
        $lines[] = '';
        $lines[] = '```json';
        $lines[] = (string) json_encode($payload['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lines[] = '```';
        if ($next !== []) {
            $lines[] = '';
            $lines[] = '## Next addresses (open one)';
            $lines[] = '';
            foreach ($next as $link) {
                $lines[] = '- ' . $link['do'] . ': ' . $link['url'];
            }
        }

        return $this->page(implode("\n", $lines) . "\n");
    }

    /**
     * El formulario de los datos del comprador, para la persona: nombre, dirección, teléfono (lo exige el transporte) y correo.
     * Se envía por GET a la misma dirección, que los guarda en este carrito y le dice que vuelva a su asistente.
     */
    private function detailsForm(string $raw): Response
    {
        $h = static function (string $text): string {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $fields = [
            'first_name' => [t('First name'), 'given-name', 'text'],
            'last_name' => [t('Last name'), 'family-name', 'text'],
            'line1' => [t('Street and number'), 'address-line1', 'text'],
            'postcode' => [t('Postcode'), 'postal-code', 'text'],
            'city' => [t('City'), 'address-level2', 'text'],
            'phone' => [t('Phone'), 'tel', 'tel'],
            'email' => [t('Email'), 'email', 'email'],
        ];
        // Por /api/ el carrito va en la dirección como ?cart=…: se manda con el formulario, en campos ocultos.
        $parts = explode('?', $this->url('customer', $raw), 2);
        $action = $parts[0];
        $hidden = [];
        parse_str(isset($parts[1]) ? $parts[1] : '', $hidden);
        $hidden['country'] = $this->app->has('shipping') ? (string) $this->app->service('shipping')->defaultCountry() : 'ES';
        $html = '<!doctype html><html lang="' . $h($this->app->lang()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . $h(t('Your details for the order')) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:2rem auto;padding:0 1rem;line-height:1.5}label{display:block;margin-top:.8rem;font-weight:600}'
            . 'input{width:100%;padding:.6rem;font-size:1rem;box-sizing:border-box}button{margin-top:1.2rem;padding:.8rem 1.2rem;font-size:1rem}</style></head><body>'
            . '<h1>' . $h(t('Your details for the order')) . '</h1>'
            . '<p>' . $h(t('Your assistant has prepared your cart. Write who receives it: the shop saves it in that cart and you go back to your assistant to finish.')) . '</p>'
            . '<form method="get" action="' . $h($action) . '">';
        foreach ($fields as $name => $info) {
            $html .= '<label for="af-' . $h($name) . '">' . $h($info[0]) . '</label>'
                . '<input id="af-' . $h($name) . '" name="' . $h($name) . '" type="' . $h($info[2]) . '" autocomplete="' . $h($info[1]) . '" required>';
        }
        // Lo que la ficha no ofrece como opción (la talla, el color) lo escribe la persona aquí: va en la nota del pedido. Su
        // asistente no podía mandarlo (17/11/2026: Claude no pidió unas zapatillas porque «no deja elegir talla»).
        $html .= '<label for="af-note">' . $h(t('Note for the shop (optional): size, colour…')) . '</label>'
            . '<input id="af-note" name="note" type="text" maxlength="500" autocomplete="off">';
        foreach ($hidden as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $html .= '<input type="hidden" name="' . $h($name) . '" value="' . $h((string) $value) . '">';
            }
        }
        $html .= '<button type="submit">' . $h(t('Save my details')) . '</button></form></body></html>';

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /** ¿La abre una persona con su navegador (no el lector de una IA ni un programa)? Entonces va a la caja, no a la página de pasos. */
    private function personBrowser(Request $request): bool
    {
        $agent = $request->userAgent();

        return !$this->json && stripos($agent, 'Mozilla/') === 0 && \Aifec\Kernel\VisitLog::bot($agent) === null
            && stripos((string) $request->header('accept', ''), 'text/html') !== false
            && $this->app->has('cart') && $this->app->router()->has('checkout') && $this->app->router()->has('api.cart.resume');
    }

    /** A la caja de la tienda con este carrito (la dirección de retomar pone la cookie y lleva a la caja). */
    private function toCheckout(string $raw): Response
    {
        $response = Response::redirect(Carts::resumeUrl($this->app, $raw));
        $response->headers['Cache-Control'] = 'no-store, private';
        $response->headers['Referrer-Policy'] = 'no-referrer';

        return $response;
    }

    /** Qué formato quiere quien llama: JSON si lo pide (?format=json o la cabecera Accept), y si no una página de texto. */
    private function format(Request $request): void
    {
        $param = $request->get('format');
        $this->jsonParam = is_string($param) && strtolower($param) === 'json';
        $this->json = $this->jsonParam || stripos((string) $request->header('accept', ''), 'application/json') !== false;
    }

    /**
     * Un fallo. En JSON sigue su camino (el estado HTTP y el error de la API). Como página va con 200 y el motivo escrito:
     * un lector de páginas no enseña el cuerpo de un 4xx, y el asistente se quedaría sin saber qué ha pasado.
     */
    private function failure(HttpError $e): Response
    {
        if ($this->json) {
            throw $e;
        }

        return $this->page("# Buying with links\n\n**This step did not work (status " . $e->status() . ").** " . $e->getMessage() . "\n\nNothing was placed. Read the message, correct the address and open it again.\n\n- Start again: "
            . $this->url('start') . "\n- How it works: " . $this->app->url()->abs($this->base(), [], '') . "\n");
    }

    private function page(string $markdown): Response
    {
        if ($this->base() === self::ALIAS) {
            // Fuera de /api/ la página es HTML, con enlaces de verdad: es lo único que abren algunos lectores de páginas.
            return new Response($this->html($markdown), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => self::NO_SHARED_CACHE,
                'X-Robots-Tag' => 'noindex',
            ]);
        }
        // Las mismas cabeceras que las fichas .md de la tienda, que los lectores de páginas de los asistentes sí abren
        // (15/11/2026: el de Google recibía un 200 y lo descartaba con «no-store, private» y «nofollow»). Se pide que se
        // vuelva a validar siempre, que es lo que hace falta: cada paso cambia lo que dirá el siguiente.
        // Y como texto plano, igual que ellas: el lector de Gemini no abre «text/markdown».
        return new Response($markdown, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => self::NO_SHARED_CACHE,
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * La página de texto como HTML sencillo: títulos, párrafos, el bloque de la respuesta y la lista de direcciones, cada
     * una con su enlace. Solo entiende lo que escribe esta clase (no es un conversor de Markdown).
     */
    private function html(string $markdown): string
    {
        $link = static function (string $text): string {
            $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safe = (string) preg_replace('#https?://[^\s<]+#', '<a href="$0">$0</a>', $safe);

            return (string) preg_replace('#\*\*(.+?)\*\*#', '<strong>$1</strong>', $safe);
        };
        $out = [];
        $code = false;
        $list = false;
        foreach (explode("\n", $markdown) as $line) {
            if (strpos($line, '```') === 0) {
                $out[] = $code ? '</pre>' : '<pre>';
                $code = !$code;
                continue;
            }
            if ($code) {
                $out[] = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }
            $item = strpos($line, '- ') === 0;
            if ($item !== $list) {
                $out[] = $item ? '<ul>' : '</ul>';
                $list = $item;
            }
            if ($item) {
                $out[] = '<li>' . $link(substr($line, 2)) . '</li>';
            } elseif (strpos($line, '## ') === 0) {
                $out[] = '<h2>' . $link(substr($line, 3)) . '</h2>';
            } elseif (strpos($line, '# ') === 0) {
                $out[] = '<h1>' . $link(substr($line, 2)) . '</h1>';
            } elseif (trim($line) !== '') {
                $out[] = '<p>' . $link($line) . '</p>';
            }
        }
        if ($list) {
            $out[] = '</ul>';
        }

        return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>Buying with links</title>\n</head>\n<body>\n<main>\n"
            . implode("\n", $out) . "\n</main>\n</body>\n</html>\n";
    }

    /** El JSON ni se guarda en caché ni se indexa ni deja rastro en el Referer. */
    private function sealed(Response $response): Response
    {
        $response->headers['Cache-Control'] = 'no-store, private';
        $response->headers['X-Robots-Tag'] = 'noindex, nofollow';
        $response->headers['Referrer-Policy'] = 'no-referrer';

        return $response;
    }
}

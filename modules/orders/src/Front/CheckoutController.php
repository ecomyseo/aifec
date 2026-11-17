<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Front;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Orders\Cart;
use Aifec\Modules\Orders\Checkout\CheckoutSession;

/**
 * La caja: una sola página con contacto, entrega, envío y pago (GET /finalizar-compra), su actualización con y sin
 * JavaScript (POST …/update), «rellénalo de una vez» (POST …/understand) y la creación del pedido (POST …/place).
 *
 * La página no decide nada: guarda lo escrito y hace el pedido con el motor único (CheckoutSession, servicio «checkout»),
 * el mismo que usan el asistente y la API. Aquí solo se lee el formulario y se pinta.
 *
 * Reglas que no se saltan:
 *  - Todo importe sale de Cart::summary() (que usa Totals): aquí no se suma nada.
 *  - El botón de pagar y el resumen enseñan la misma cifra, y el formulario lleva el total que se enseñó
 *    (expected_total): si al guardar lo escrito el total es otro, no se crea el pedido, se vuelve a pintar la caja con
 *    el aviso y el cliente lo confirma con el total nuevo delante.
 *  - Una pulsación doble no crea dos pedidos: con el carrito ya convertido, la segunda lleva a la página del pedido.
 *  - Las respuestas no se guardan en ninguna caché.
 */
final class CheckoutController
{
    /** Segundos durante los que un segundo envío se toma por una pulsación doble. */
    private const DOUBLE_SUBMIT_SECONDS = 600;

    /** Claves de error que tienen su campo en el formulario; el resto se enseña arriba. */
    private const FIELD_ERRORS = ['email', 'address', 'carrier', 'payment', 'accept_terms'];

    /** Largo máximo del texto de «rellénalo de una vez». */
    private const UNDERSTAND_MAX = 2000;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ GET: la caja

    /**
     * @param array<string,string> $params
     */
    public function page(Request $request, array $params): Response
    {
        $gate = $this->gate(false);
        if ($gate !== null) {
            return $gate;
        }
        $this->engine()->prepare();
        $summary = $this->cart()->summary();
        $state = $this->engine()->draft();
        $state['carrier_id'] = $summary['carrier_id'] !== null ? (int) $summary['carrier_id'] : 0;
        $state['payment_method'] = $summary['payment_method'] !== null ? (string) $summary['payment_method'] : '';

        $messages = [];
        $errors = [];
        $flash = $this->app->session('customer')->pullFlash();
        if (isset($flash['checkout_error']) && is_string($flash['checkout_error']) && $flash['checkout_error'] !== '') {
            $messages[] = ['bad', $flash['checkout_error']];
        }
        if (isset($flash['checkout_update']) && is_array($flash['checkout_update']) && isset($flash['checkout_update']['state']) && is_array($flash['checkout_update']['state'])) {
            // Viene de «Actualizar» sin JavaScript: lo que se escribió (con sus casillas) manda sobre el borrador.
            $state = array_replace($state, $flash['checkout_update']['state']);
            $state['carrier_id'] = $summary['carrier_id'] !== null ? (int) $summary['carrier_id'] : 0;
            $state['payment_method'] = $summary['payment_method'] !== null ? (string) $summary['payment_method'] : '';
            $errors = isset($flash['checkout_update']['errors']) && is_array($flash['checkout_update']['errors']) ? $flash['checkout_update']['errors'] : [];
            $messages[] = ['info', t('The prices have been updated.')];
        }

        return $this->render($summary, $state, $errors, $messages, 200);
    }

    // ------------------------------------------------------------------ POST: actualizar

    /**
     * Guarda lo escrito y vuelve a calcular. Sin JavaScript contesta la página entera; con JavaScript, los fragmentos de
     * envío, pago y resumen, el total y los errores de los campos
     * ({ok, html: {shipping, payment, summary}, total, total_formatted, button, errors}).
     *
     * @param array<string,string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $json = $request->wantsJson();
        $gate = $this->gate($json);
        if ($gate !== null) {
            return $gate;
        }
        $state = CheckoutState::fromRequest($request);
        $result = $this->engine()->fill($this->fieldsOf($state));
        $this->engine()->prepare();
        $summary = $this->cart()->summary();
        $state['carrier_id'] = $summary['carrier_id'] !== null ? (int) $summary['carrier_id'] : 0;
        $state['payment_method'] = $summary['payment_method'] !== null ? (string) $summary['payment_method'] : '';
        $c = $this->context($summary, $state, $result['errors'], []);

        if ($json) {
            return Response::json([
                'ok' => true,
                'html' => $this->fragments($c),
                'total' => (int) $c['expected_total'],
                'total_formatted' => (string) $c['view']['total_formatted'],
                'button' => (string) $c['button'],
                'errors' => $result['errors'],
            ]);
        }

        // Sin JavaScript: se vuelve a la dirección de la caja (no se queda «/finalizar-compra/update» en el navegador, que al
        // recargar volvería a enviar el formulario). Lo escrito, las casillas y los avisos de cada campo viajan en la sesión
        // para que la caja salga igual que si se hubiera pintado aquí.
        $this->app->session('customer')->flash('checkout_update', ['state' => $state, 'errors' => $result['errors']]);

        return $this->redirect(route('checkout'), 303);
    }

    // ------------------------------------------------------------------ POST: rellénalo de una vez

    /**
     * Entiende un texto escrito de cualquier manera y devuelve los campos que saca, lo dudoso y lo que falta. No guarda
     * nada: lo guarda la propia caja cuando el cliente confirma cada campo.
     *
     * @param array<string,string> $params
     */
    public function understand(Request $request, array $params): Response
    {
        $gate = $this->gate(true);
        if ($gate !== null) {
            return $gate;
        }
        $text = mb_substr($request->str('text'), 0, self::UNDERSTAND_MAX, 'UTF-8');
        $read = $this->engine()->understand($text);

        return Response::json(['ok' => true, 'fields' => (object) $read['fields'], 'doubts' => (object) $read['doubts'], 'missing' => $read['missing']]);
    }

    // ------------------------------------------------------------------ POST: hacer el pedido

    /**
     * @param array<string,string> $params
     */
    public function place(Request $request, array $params): Response
    {
        $app = $this->app;
        $session = $app->session('customer');
        $summary = $this->cart()->summary();
        if (!$this->hasLines($summary)) {
            // Una pulsación doble: el primer envío ya convirtió el carrito en pedido.
            $last = $session->get('last_order');
            if (is_array($last) && isset($last['number'], $last['secret'], $last['at']) && $app->clock()->now() - (int) $last['at'] <= self::DOUBLE_SUBMIT_SECONDS) {
                return $this->redirect($this->orderUrl((string) $last['number'], (string) $last['secret']), 303);
            }

            return $this->redirect(route('cart'), 303);
        }
        $gate = $this->gate(false);
        if ($gate !== null) {
            return $gate;
        }

        $engine = $this->engine();
        $state = CheckoutState::fromRequest($request);
        $fill = $engine->fill($this->fieldsOf($state));
        $summary = $this->cart()->summary();

        // El total que se enseñó tiene que ser el que se va a cobrar.
        $expected = $request->input('expected_total');
        if ($expected !== null && is_numeric($expected) && (int) $expected !== (int) $summary['totals']['total']) {
            $state['carrier_id'] = $summary['carrier_id'] !== null ? (int) $summary['carrier_id'] : 0;
            $state['payment_method'] = $summary['payment_method'] !== null ? (string) $summary['payment_method'] : '';

            return $this->render($summary, $state, [], [['warn', t('The total has changed. Check it and place the order again.')]], 200);
        }

        $review = $engine->review();
        $errors = $fill['errors'] + $review['errors'];
        if ($fill['errors'] === []) {
            $options = ['source' => 'web', 'ip' => $request->ip, 'accept_terms' => $state['accept_terms'], 'marketing' => $state['marketing'], 'note' => $state['note']];
            $attribution = $session->get('attribution');
            if (is_array($attribution) && $attribution !== [] && strlen((string) json_encode($attribution)) <= 4000) {
                $options['attribution'] = $attribution;
            }
            $result = $engine->place((string) $review['confirm_token'], $options);
            if (!empty($result['ok'])) {
                return $this->afterPlace($result);
            }
            if (!empty($result['reopened'])) {
                // El cobro no pudo empezar: el pedido queda fallido y el carrito vuelve a estar libre para reintentar.
                $session->flash('checkout_error', (string) $result['error']);

                return $this->redirect(route('checkout'), 303);
            }
            $errors = $result['errors'];
        }

        $summary = $this->cart()->summary();
        $messages = [];
        $field = [];
        foreach ($errors as $key => $message) {
            if (in_array($key, self::FIELD_ERRORS, true) || strpos($key, 'shipping.') === 0 || strpos($key, 'billing.') === 0) {
                $field[(string) $message] = true;
            } else {
                $messages[] = ['bad', $message];
            }
        }
        if ($field !== []) {
            // El aviso de arriba dice también QUÉ falla (14/11/2026: «Revisa los campos marcados» a secas cuando el problema era que no
            // se envía a Baleares, que no es ningún campo que se pueda corregir escribiendo).
            $what = array_slice(array_keys($field), 0, 3);
            array_unshift($messages, ['bad', t('Check the fields marked below.') . ' ' . implode(' ', $what)]);
        }
        $state['carrier_id'] = $summary['carrier_id'] !== null ? (int) $summary['carrier_id'] : 0;
        $state['payment_method'] = $summary['payment_method'] !== null ? (string) $summary['payment_method'] : '';

        return $this->render($summary, $state, $errors, $messages, 422);
    }

    /**
     * Lo que sigue a un pedido bien creado, según lo que pida la forma de pago.
     *
     * @param array<string,mixed> $result lo que devuelve CheckoutSession::place()
     */
    private function afterPlace(array $result): Response
    {
        $app = $this->app;
        $number = (string) $result['number'];
        $secret = (string) $result['secret'];
        $app->session('customer')->set('last_order', ['number' => $number, 'secret' => $secret, 'at' => $app->clock()->now()]);
        $pay = is_array($result['payment']) ? $result['payment'] : [];
        $page = ['title' => t('Redirecting to the payment'), 'type' => 'checkout'];

        if (isset($pay['type']) && $pay['type'] === 'redirect' && isset($pay['url']) && self::safeUrl((string) $pay['url'])) {
            if (isset($pay['method']) && $pay['method'] === 'post') {
                $fields = [];
                foreach (is_array($pay['fields']) ? $pay['fields'] : [] as $name => $value) {
                    $fields[(string) $name] = (string) $value;
                }
                $response = $app->render('orders::front/pay-redirect', ['page' => $page, 'action' => (string) $pay['url'], 'fields' => $fields, 'number' => $number]);
                $response->headers['Cache-Control'] = 'no-store, private';

                return $response;
            }

            return $this->redirect((string) $pay['url'], 303);
        }

        return $this->redirect($this->orderUrl($number, $secret), 303);
    }

    // ------------------------------------------------------------------ piezas

    /**
     * La caja no se puede abrir con el carrito vacío ni, si no se admiten invitados, sin haber entrado.
     */
    private function gate(bool $json): ?Response
    {
        $summary = $this->cart()->summary();
        if (!$this->hasLines($summary)) {
            return $json ? Response::json(['ok' => false, 'redirect' => route('cart')], 409) : $this->redirect(route('cart'), $this->app->request()->isPost() ? 303 : 302);
        }
        if (!$this->app->settings()->bool('orders.guest_checkout', true) && $this->customer() === null) {
            $login = route('account.login', ['next' => route('checkout')]);

            return $json ? Response::json(['ok' => false, 'redirect' => $login], 401) : $this->redirect($login, $this->app->request()->isPost() ? 303 : 302);
        }

        return null;
    }

    /**
     * Lo escrito en el formulario, en la forma que espera el motor.
     *
     * @param array<string,mixed> $state
     *
     * @return array<string,mixed>
     */
    private function fieldsOf(array $state): array
    {
        return [
            'email' => (string) $state['email'],
            'address_id' => (int) $state['address_id'],
            'shipping' => $state['shipping'],
            'billing_other' => !empty($state['billing_other']),
            'billing' => $state['billing'],
            'note' => (string) $state['note'],
            'carrier_id' => (int) $state['carrier_id'],
            'payment_method' => (string) $state['payment_method'],
        ];
    }

    /**
     * La caja entera.
     *
     * @param array<string,mixed>          $summary
     * @param array<string,mixed>          $state
     * @param array<string,string>         $errors
     * @param array<int,array<int,string>> $messages
     */
    private function render(array $summary, array $state, array $errors, array $messages, int $status): Response
    {
        $c = $this->context($summary, $state, $errors, $messages);
        $page = ['title' => t('Checkout'), 'type' => 'checkout'];
        $response = $this->app->render('orders::front/checkout', ['page' => $page, 'c' => $c], $status);
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }

    /**
     * Todo lo que necesitan las plantillas de la caja y sus fragmentos. Los envíos, las formas de pago y las líneas salen
     * del motor; el resto es presentación.
     *
     * @param array<string,mixed>          $summary
     * @param array<string,mixed>          $state
     * @param array<string,string>         $errors
     * @param array<int,array<int,string>> $messages
     *
     * @return array<string,mixed>
     */
    private function context(array $summary, array $state, array $errors, array $messages): array
    {
        $app = $this->app;
        $customer = $this->customer();
        $addresses = $this->engine()->savedAddresses();
        $needs = !empty($summary['needs_shipping']);

        $countries = Destinations::countries($app, $needs);
        foreach (['shipping', 'billing'] as $group) {
            if ($state[$group]['country'] === '' || !isset($countries[$state[$group]['country']])) {
                $state[$group]['country'] = (string) key($countries);
            }
        }

        $engineState = $this->engine()->state();
        $delivery = CheckoutState::delivery($state, $addresses, $needs);
        // Antes de escribir el código postal el envío que suma el resumen es una estimación: se llama así, y el bloque de
        // envío dice lo mismo que el resumen (un solo mensaje).
        $view = TotalsView::forCart($app, $summary, true, $needs && $delivery['postcode'] === '');
        $total = (int) $summary['totals']['total'];
        $kind = '';
        foreach ($engineState['payment']['options'] as $option) {
            if ($option['selected']) {
                $kind = (string) $option['kind'];
            }
        }

        return [
            'summary' => $summary,
            'state' => $state,
            'errors' => $errors,
            'messages' => $messages,
            'customer' => $customer,
            'addresses' => $addresses,
            'needs_shipping' => $needs,
            'shipping_ready' => !$needs || $delivery['postcode'] !== '',
            'delivery_label' => trim($delivery['postcode'] . ' ' . $delivery['city'] . ' (' . $delivery['country'] . ')'),
            'delivery_saved' => $needs && CheckoutState::savedAddress($state, $addresses) !== null,
            'countries' => $countries,
            'provinces' => Destinations::provinces(),
            'carriers' => $engineState['shipping']['options'],
            'methods' => $engineState['payment']['options'],
            'view' => $view,
            'lines' => $engineState['lines'],
            'expected_total' => $total,
            'button' => $this->buttonLabel($total, $view['total_formatted'], $kind),
            'blocked' => $summary['problems'] !== [],
            'problems' => $summary['problems'],
            'require_vat' => $app->settings()->bool('orders.require_vat_id', false),
            'guest_allowed' => $app->settings()->bool('orders.guest_checkout', true),
            'terms_url' => $this->termsUrl(),
            'urls' => [
                'place' => route('checkout.place'),
                'update' => route('checkout.update'),
                'understand' => route('checkout.understand'),
                'cart' => route('cart'),
                'login' => route('account.login', ['next' => route('checkout')]),
                'logout' => route('account.logout'),
            ],
        ];
    }

    /**
     * Los tres trozos de la caja que cambian con el envío, la forma de pago y los precios.
     *
     * @param array<string,mixed> $c
     *
     * @return array{shipping:string,payment:string,summary:string}
     */
    private function fragments(array $c): array
    {
        $view = $this->app->view();

        return [
            'shipping' => !empty($c['needs_shipping']) ? $view->render('orders::front/checkout-shipping', ['c' => $c]) : '',
            'payment' => $view->render('orders::front/checkout-payment', ['c' => $c]),
            'summary' => $view->render('orders::front/checkout-summary', ['c' => $c]),
        ];
    }

    /**
     * «Pagar 27,47 €» o, si no hay nada que pagar ahora o se paga fuera de la web, «Hacer el pedido». El importe es el
     * del resumen.
     */
    private function buttonLabel(int $total, string $formatted, string $kind): string
    {
        if ($total > 0 && $kind !== 'offline') {
            return t('Pay {total}', ['total' => $formatted]);
        }

        return t('Place order');
    }

    /** El enlace a la página de condiciones, si hay una elegida y existe. */
    private function termsUrl(): string
    {
        $id = $this->app->settings()->int('orders.terms_page_id', 0);
        if ($id < 1) {
            return '';
        }
        $slugs = $this->app->slugs();
        if ($slugs->path('page', $id, $this->app->lang()) === null && $slugs->path('page', $id, $this->app->defaultLang()) === null) {
            return '';
        }

        return $this->app->url()->entity('page', $id);
    }

    private function orderUrl(string $number, string $secret): string
    {
        return route('order.thanks', ['number' => $number, 'secret' => $secret]);
    }

    /** Una dirección a la que se puede llevar al cliente: web http(s) o un camino de este mismo sitio. */
    private static function safeUrl(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1 || preg_match('#^/(?![/\\\\])#', $url) === 1;
    }

    private function redirect(string $url, int $status): Response
    {
        return Response::redirect($url, $status);
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function hasLines(array $summary): bool
    {
        return $summary['id'] !== null && (int) $summary['count'] > 0 && $summary['lines'] !== [];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function customer(): ?array
    {
        return $this->engine()->customer();
    }

    /** @return Cart */
    private function cart()
    {
        return $this->app->service('cart');
    }

    private function engine(): CheckoutSession
    {
        return $this->app->service('checkout');
    }
}

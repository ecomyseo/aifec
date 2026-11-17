<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Admin;

use Aifec\Admin\Admin;
use Aifec\Admin\Gate;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Orders\Front\Destinations;
use Aifec\Modules\Orders\Orders;

/**
 * «Pedido nuevo»: el pedido por teléfono, por WhatsApp o en el mostrador, hecho desde el panel con el camino de compra de la tienda.
 *
 * Una sola pantalla (GET /admin/orders/new) con siete pasos: cliente, productos, entrega, envío, cupón, pago y confirmar. Cada paso
 * es un formulario que se puede enviar sin JavaScript (POST /admin/orders/new/<paso> y vuelta a la pantalla); con JavaScript, el
 * mismo envío vuelve en JSON con la columna de los pasos ya pintada y el resumen se pide a POST /admin/orders/new/quote, que el
 * servidor calcula entero. Los dos buscadores (GET /admin/orders/new/find/customers|products) devuelven como mucho 10 clientes o 10
 * productos. «Repetir» (POST /admin/orders/{id}/repeat) copia un pedido en el borrador.
 *
 * Permisos: orders.create para todo (403 sin él); orders.price_override para «Precio de esta venta», que hay que tener POR SU
 * NOMBRE (o «*»): «orders.*» no lo incluye, así que de serie solo lo tiene el administrador.
 */
final class ManualOrderController
{
    public const CAP = 'orders.create';
    public const CAP_PRICE = 'orders.price_override';

    /** Los pasos que se envían a POST /admin/orders/new/<paso>, con el apartado de la pantalla al que se vuelve. */
    public const STEPS = [
        'customer' => 'mo-customer', 'customer-new' => 'mo-customer',
        'line' => 'mo-products', 'qty' => 'mo-products', 'remove' => 'mo-products', 'price' => 'mo-products', 'price-clear' => 'mo-products',
        'address' => 'mo-delivery', 'address-new' => 'mo-delivery',
        'shipping' => 'mo-shipping',
        'coupon' => 'mo-coupon', 'coupon-remove' => 'mo-coupon',
        'payment' => 'mo-payment',
        'reset' => 'mo-customer',
    ];

    /** Las tres cabeceras anti-caché del resumen (el intermediario del panel deja solo «no-store, private»). */
    private const NO_CACHE = ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'Pragma' => 'no-cache', 'Expires' => '0'];

    private const FLASH_FORM = 'manual_order_form';
    private const FLASH_CHANGES = 'manual_order_changes';

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** ¿Puede poner un precio distinto al de catálogo? Solo con el permiso por su nombre o con «*». */
    public static function canOverride(App $app): bool
    {
        if (!$app->auth()->can(self::CAP)) {
            return false;
        }
        $caps = $app->auth()->caps();

        return in_array('*', $caps, true) || in_array(self::CAP_PRICE, $caps, true);
    }

    // ------------------------------------------------------------------ la pantalla

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap(self::CAP);
        $draft = new ManualOrderDraft($app);
        try {
            $data = $this->screen($draft, $request, $this->pull(self::FLASH_FORM), $this->pull(self::FLASH_CHANGES));
            $draft->save();
        } finally {
            $draft->release();
        }

        return Admin::page($app, 'orders::admin/manual', ['v' => $data], [
            'title' => t('New order'),
            'crumbs' => [[t('Orders'), $app->url()->plain('/admin/orders')]],
            'active' => '/admin/orders',
            'help' => Admin::helpText("For the order that arrives by phone, by WhatsApp or at the counter. Prices, taxes, shipping, coupons and stock are the ones of the shop.\nWith «Already paid» the order is created paid: write how it was paid. With a payment method that gives instructions, the order waits for the money as in the shop.\nThe price of this sale needs its own permission, and the reason is kept in the order and in the activity log."),
        ]);
    }

    /** Un paso: POST /admin/orders/new/<paso>. @param array<string,string> $params */
    public function step(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap(self::CAP);
        $step = isset($params['step']) ? (string) $params['step'] : '';
        if (!isset(self::STEPS[$step])) {
            throw HttpError::notFound();
        }
        if (in_array($step, ['price', 'price-clear'], true) && !self::canOverride($app)) {
            throw HttpError::forbidden('Missing permission: ' . self::CAP_PRICE);
        }
        $draft = new ManualOrderDraft($app);
        $html = '';
        try {
            $result = $this->run($draft, $step, $request);
            $form = [];
            if (empty($result['ok'])) {
                $form = ['step' => $step, 'values' => $this->posted($request), 'errors' => $result['errors'], 'existing' => isset($result['existing']) ? $result['existing'] : null];
            }
            if ($request->wantsJson()) {
                $html = $app->view()->render('orders::admin/manual-steps', ['v' => $this->screen($draft, $request, $form, [])]);
            }
            $draft->save();
        } finally {
            $draft->release();
        }
        $message = !empty($result['ok']) ? (string) $result['message'] : (string) $result['error'];
        if ($request->wantsJson()) {
            return Response::json(['ok' => !empty($result['ok']), 'message' => $message, 'errors' => $result['errors'], 'html' => $html, 'focus' => self::STEPS[$step]]);
        }
        if ($message !== '') {
            Admin::flash($app, !empty($result['ok']) ? 'ok' : 'bad', $message);
        }
        if ($form !== []) {
            $app->session('admin')->flash(self::FLASH_FORM, $form);
        }

        return Response::redirect($app->url()->plain('/admin/orders/new') . '#' . self::STEPS[$step], 303);
    }

    /**
     * El resumen en JSON (POST /admin/orders/new/quote). Va fuera del grupo del panel para poder mandar las tres cabeceras
     * anti-caché: aquí mismo se pasa por el intermediario «admin» (sesión, origen y ficha) y siempre se contesta en JSON.
     *
     * @param array<string,string> $params
     */
    public function quote(Request $request, array $params): Response
    {
        $request->headers['accept'] = 'application/json';
        $response = Gate::admin($request, $this->app, function (Request $r): Response {
            $app = $this->app;
            if (!$app->auth()->can(self::CAP)) {
                return Response::json(['ok' => false, 'error' => t('You cannot create orders.')], 403);
            }
            $draft = new ManualOrderDraft($app);
            try {
                $quote = ManualOrderQuote::of($draft);
                $draft->save();
            } finally {
                $draft->release();
            }

            return Response::json(['ok' => true] + $quote);
        });
        foreach (self::NO_CACHE as $name => $value) {
            $response->headers[$name] = $value;
        }

        return $response;
    }

    /** Los buscadores: GET /admin/orders/new/find/customers|products?q=… @param array<string,string> $params */
    public function find(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap(self::CAP);
        $q = mb_substr($request->str('q'), 0, 80, 'UTF-8');
        $results = [];
        if ((string) $params['what'] === 'customers') {
            foreach (ManualOrderSearch::customers($app, $q) as $row) {
                $results[] = ['id' => $row['id'], 'label' => $row['name'] . ' · ' . $row['email'] . ($row['phone'] !== '' ? ' · ' . $row['phone'] : ''), 'disabled' => false];
            }
        } else {
            foreach (ManualOrderSearch::products($app, $q) as $row) {
                $results[] = [
                    'product_id' => $row['product_id'], 'variant_id' => $row['variant_id'], 'label' => self::productLabel($row),
                    'disabled' => !$row['available'], 'reason' => $row['reason'],
                ];
            }
        }

        return Response::json(['ok' => true, 'q' => $q, 'results' => $results, 'status' => self::countText(count($results))]);
    }

    /** Crear el pedido: POST /admin/orders/new/place. @param array<string,string> $params */
    public function place(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap(self::CAP);
        $draft = new ManualOrderDraft($app);
        $html = '';
        try {
            if ($draft->hasPrices() && !self::canOverride($app)) {
                throw HttpError::forbidden('Missing permission: ' . self::CAP_PRICE);
            }
            $result = (new ManualOrderPlacer($app))->place($draft, $request->str('confirm'), $request->str('note'), $request->bool('notify'));
            if (empty($result['ok']) && $request->wantsJson()) {
                $html = $app->view()->render('orders::admin/manual-steps', ['v' => $this->screen($draft, $request, [], $result['changes'])]);
            }
            $draft->save();
        } finally {
            $draft->release();
        }
        if (!empty($result['ok'])) {
            $url = $app->url()->plain('/admin/orders/' . (int) $result['order_id']);
            $message = $result['already'] ? t('This order was already created: here it is.') : t('Order {number} created.', ['number' => (string) $result['number']]);
            Admin::flash($app, $result['already'] ? 'info' : 'ok', $message);

            return $request->wantsJson() ? Response::json(['ok' => true, 'message' => $message, 'redirect' => $url]) : Response::redirect($url, 303);
        }
        if ((int) $result['order_id'] > 0) {
            // El pedido existe (fallido): se enseña.
            $url = $app->url()->plain('/admin/orders/' . (int) $result['order_id']);
            Admin::flash($app, 'bad', (string) $result['error']);

            return $request->wantsJson() ? Response::json(['ok' => false, 'message' => (string) $result['error'], 'redirect' => $url]) : Response::redirect($url, 303);
        }
        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'message' => (string) $result['error'], 'errors' => [], 'html' => $html, 'focus' => 'mo-confirm']);
        }
        Admin::flash($app, 'bad', (string) $result['error']);
        if ($result['changes'] !== []) {
            $app->session('admin')->flash(self::FLASH_CHANGES, $result['changes']);
        }

        return Response::redirect($app->url()->plain('/admin/orders/new') . '#mo-confirm', 303);
    }

    /** «Repetir» un pedido: POST /admin/orders/{id}/repeat. @param array<string,string> $params */
    public function repeat(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap(self::CAP);
        $orders = $app->service('orders');
        $order = $orders instanceof Orders ? $orders->find((int) $params['id']) : null;
        if ($order === null) {
            throw HttpError::notFound();
        }
        $draft = new ManualOrderDraft($app);
        try {
            $warnings = $draft->repeat($order);
            $draft->save();
        } finally {
            $draft->release();
        }
        Admin::flash($app, 'ok', t('Order {number} copied into a new order: check it and confirm.', ['number' => (string) $order['number']]));
        foreach ($warnings as $warning) {
            Admin::flash($app, 'warn', $warning);
        }

        return Response::redirect($app->url()->plain('/admin/orders/new'), 303);
    }

    // ------------------------------------------------------------------ piezas

    /**
     * @return array<string,mixed>
     */
    private function run(ManualOrderDraft $draft, string $step, Request $request): array
    {
        switch ($step) {
            case 'customer':
                return $draft->chooseCustomer($request->int('customer_id'));
            case 'customer-new':
                return $draft->newCustomer($request->arr('c'));
            case 'line':
                return $draft->addLine($request->int('product_id'), $request->int('variant_id'), $request->int('qty', 1));
            case 'qty':
                return $draft->setQty($request->str('key'), $request->int('qty'));
            case 'remove':
                return $draft->removeLine($request->str('key'));
            case 'price':
                return $draft->setPrice($request->str('key'), $request->str('price'), $request->str('reason'));
            case 'price-clear':
                return $draft->clearPrice($request->str('key'));
            case 'address':
                return $draft->chooseAddress($request->int('address_id'));
            case 'address-new':
                return $draft->newAddress($request->arr('a'));
            case 'shipping':
                return $draft->setCarrier($request->int('carrier_id'));
            case 'coupon':
                return $draft->applyCoupon($request->str('code'));
            case 'coupon-remove':
                return $draft->removeCoupon($request->str('code'));
            case 'payment':
                return $draft->setPayment($request->str('mode'), $request->str('paid_with'));
            default:
                $draft->reset();

                return ['ok' => true, 'error' => '', 'errors' => [], 'message' => t('Started again from an empty order.')];
        }
    }

    /**
     * Todo lo que pinta la pantalla.
     *
     * @param array<string,mixed>                      $form    lo enviado en un paso que falló (para volver a rellenarlo)
     * @param array<int,array{key:string,text:string}> $changes lo que cambió entre el resumen enseñado y la confirmación
     *
     * @return array<string,mixed>
     */
    private function screen(ManualOrderDraft $draft, Request $request, array $form, array $changes): array
    {
        $app = $this->app;
        $quote = ManualOrderQuote::of($draft);
        $state = $draft->state();
        $summary = $draft->summary();
        $base = '/admin/orders/new';
        $urls = ['show' => $app->url()->plain($base), 'quote' => $app->url()->plain($base . '/quote'), 'place' => $app->url()->plain($base . '/place'),
            'customers' => $app->url()->plain($base . '/find/customers'), 'products' => $app->url()->plain($base . '/find/products')];
        foreach (array_keys(self::STEPS) as $step) {
            $urls[$step] = $app->url()->plain($base . '/' . $step);
        }
        $shipping = [];
        $pickup = null;
        foreach ($summary['shipping_options'] as $option) {
            $row = [
                'id' => (int) $option['carrier_id'], 'name' => (string) $option['name'], 'delay' => isset($option['delay']) ? (string) $option['delay'] : '',
                'price' => !empty($option['free']) || (int) $option['price'] === 0 ? t('Free') : money((int) $option['price'], (string) $summary['currency']),
                'pickup' => isset($option['needs_address']) && $option['needs_address'] === false,
                'selected' => $summary['carrier_id'] !== null && (int) $summary['carrier_id'] === (int) $option['carrier_id'],
            ];
            $shipping[] = $row;
            if ($row['pickup'] && $pickup === null) {
                $pickup = $row;
            }
        }
        $methods = [];
        foreach ($draft->offlineMethods() as $method) {
            $methods[] = $method + ['fee_formatted' => $method['fee'] > 0 ? money($method['fee'], (string) $summary['currency']) : '', 'selected' => (string) $state['pay'] === 'method:' . $method['code']];
        }
        $cq = mb_substr($request->str('cq'), 0, 80, 'UTF-8');
        $pq = mb_substr($request->str('pq'), 0, 80, 'UTF-8');
        $products = [];
        if ($pq !== '') {
            foreach (ManualOrderSearch::products($app, $pq) as $row) {
                $products[] = $row + ['label' => self::productLabel($row)];
            }
        }
        $changed = [];
        foreach ($changes as $change) {
            if ($change['key'] !== '') {
                $changed[$change['key']] = true;
            }
        }

        return [
            'urls' => $urls,
            'quote' => $quote,
            'state' => $state,
            'customer' => $draft->customer(),
            'addresses' => $draft->addresses(),
            'address_id' => $draft->address() !== null ? (int) $state['address_id'] : 0,
            'shipping' => $shipping,
            'pickup' => $pickup,
            'coupons' => $quote['coupons'],
            'coupons_on' => $app->has('coupons'),
            'methods' => $methods,
            'link_reason' => ManualOrderDraft::linkUnavailable(),
            'can_price' => self::canOverride($app),
            'cq' => $cq,
            'pq' => $pq,
            'customers_found' => $cq !== '' ? ManualOrderSearch::customers($app, $cq) : [],
            'products_found' => $products,
            'searched' => ['customers' => $cq !== '' && ManualOrderSearch::words($cq) !== [], 'products' => $pq !== '' && ManualOrderSearch::words($pq) !== []],
            'form' => $form,
            'changes' => $changes,
            'changed' => $changed,
            'countries' => Destinations::countries($app, true),
        ];
    }

    /**
     * Lo enviado en un paso, solo texto y solo los grupos de los formularios largos (para volver a rellenarlos tras un error).
     *
     * @return array<string,mixed>
     */
    private function posted(Request $request): array
    {
        $out = [];
        foreach (['c', 'a'] as $group) {
            foreach ($request->arr($group) as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $out[$group][$key] = mb_substr((string) $value, 0, 200, 'UTF-8');
                }
            }
        }
        foreach (['code', 'paid_with', 'price', 'reason', 'key'] as $key) {
            $out[$key] = mb_substr($request->str($key), 0, 200, 'UTF-8');
        }

        return $out;
    }

    /**
     * Lee y borra un aviso de un solo uso de la sesión del panel.
     *
     * @return array<mixed>
     */
    private function pull(string $key): array
    {
        $session = $this->app->session('admin');
        $flash = $session->get('_flash', []);
        if (!is_array($flash) || !isset($flash[$key]) || !is_array($flash[$key])) {
            return [];
        }
        $value = $flash[$key];
        unset($flash[$key]);
        $session->set('_flash', $flash);

        return $value;
    }

    /**
     * «Camiseta — M · 20,00 € · 5 en existencias» o «… · Sin existencias».
     *
     * @param array<string,mixed> $row
     */
    public static function productLabel(array $row): string
    {
        $parts = [(string) $row['name'] . ((string) $row['variant'] !== '' ? ' — ' . (string) $row['variant'] : '')];
        if ((string) $row['sku'] !== '') {
            $parts[] = (string) $row['sku'];
        }
        $parts[] = (string) $row['price_formatted'];
        if (!$row['available']) {
            $parts[] = (string) $row['reason'];
        } elseif ($row['stock'] !== null) {
            $parts[] = tn('{n} in stock', '{n} in stock', (int) $row['stock']);
        }

        return implode(' · ', $parts);
    }

    private static function countText(int $n): string
    {
        return $n === 0 ? t('Nothing found.') : tn('{n} result', '{n} results', $n);
    }
}

<?php
/**
 * AIFEC — la página del pago simulado: GET /<urls.payment>/simulado/{number}/{secret} con dos botones, y el POST que los recibe.
 *
 * La ficha del pedido (secret) es la que manda: sin ella, o de un pedido que no es de esta forma de pago, el cliente recibe
 * un 404. Un pedido que ya no está pendiente no se toca otra vez: se le lleva a la página de gracias. Un mismo envío repetido
 * cobra una sola vez (la referencia es siempre la misma y markPaid solo cuenta la primera).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\PaymentsSimulated;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Security\Csrf;

final class SimulatedController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params number y secret
     */
    public function show(Request $request, array $params): Response
    {
        $order = $this->order($params);
        if (!$this->pending($order)) {
            return $this->leave($order, (string) $params['secret']);
        }
        $page = ['title' => t('Simulated payment'), 'type' => 'checkout', 'robots' => 'noindex, nofollow', 'title_full' => false];
        $response = $this->app->render('payments-simulated::front/pay', [
            'page' => $page,
            'number' => (string) $order['number'],
            'total' => (int) $order['total'],
            'currency' => (string) $order['currency'],
            'action' => $this->app->url()->route('payment.simulated.decide', ['number' => (string) $order['number'], 'secret' => (string) $params['secret']]),
            'token' => Csrf::issue($this->app->session('customer')->csrfSecret(), 'front', $this->app->clock()->now()),
        ]);
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }

    /**
     * POST: outcome = paid (el pago sale bien) o failed (sale mal).
     *
     * @param array<string,string> $params number y secret
     */
    public function decide(Request $request, array $params): Response
    {
        $order = $this->order($params);
        $outcome = $request->str('outcome');
        if (!in_array($outcome, ['paid', 'failed'], true)) {
            throw HttpError::badRequest();
        }
        if ($this->pending($order)) {
            $orders = $this->app->service('orders');
            if ($outcome === 'paid') {
                $orders->markPaid((int) $order['id'], SimulatedPayment::CODE, SimulatedPayment::reference((string) $order['number']), (int) $order['total'], [], true);
            } else {
                $orders->paymentFailed((int) $order['id'], SimulatedPayment::CODE, 'The simulated payment was declined');
            }
        }

        return $this->leave($order, (string) $params['secret']);
    }

    /**
     * El pedido de la dirección, o 404 si la ficha no es la suya o no se paga con esta forma de pago.
     *
     * @param array<string,string> $params
     *
     * @return array<string,mixed>
     */
    private function order(array $params): array
    {
        $number = isset($params['number']) ? (string) $params['number'] : '';
        $secret = isset($params['secret']) ? (string) $params['secret'] : '';
        $order = $number !== '' && $secret !== '' ? $this->app->service('orders')->findBySecret($number, $secret) : null;
        if ($order === null || (string) $order['payment_method'] !== SimulatedPayment::CODE) {
            throw HttpError::notFound();
        }

        return $order;
    }

    /**
     * @param array<string,mixed> $order
     */
    private function pending(array $order): bool
    {
        return (string) $order['payment_status'] === 'pending' && (string) $order['status'] === 'pending_payment';
    }

    /**
     * La página de gracias del pedido si existe; si no, la portada.
     *
     * @param array<string,mixed> $order
     */
    private function leave(array $order, string $secret): Response
    {
        if ($this->app->router()->has('order.thanks')) {
            return Response::redirect($this->app->url()->route('order.thanks', ['number' => (string) $order['number'], 'secret' => $secret]));
        }

        return Response::redirect($this->app->url()->to('/'));
    }
}

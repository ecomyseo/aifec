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
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * «Ajustes › Formas de pago» (/admin/payments): la tabla de las formas de pago, el orden de la caja y encender un módulo de
 * pago apagado. Ver PaymentsAdmin. Permiso: el de los ajustes de ventas (orders.edit); encender, además, plugins.edit.
 */
final class PaymentsController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('orders.edit');
        $overview = PaymentsAdmin::overview($app);
        $context = $overview['context'];

        return Admin::page($app, 'orders::admin/payments', $overview + [
            'normal' => t('A normal order: {amount} to {country}, with shipping.', [
                'amount' => money((int) $context['total'], (string) $context['currency']),
                'country' => (string) $context['country'],
            ]),
        ], [
            'title' => t('Payment methods'),
            'crumbs' => [[t('Settings'), $app->url()->plain('/admin/settings')]],
            'active' => '/admin/payments',
            'help' => Admin::helpText("The list is the order in which the customer sees the payment methods in the checkout. Move them with the arrows.\nEach payment method keeps its keys and options in its own screen: use Configure.\nOffered means that a normal order of the shop would see it today. A payment method can be on and still not be offered: missing keys, another currency or another country.\nA dash means that the payment method does not say it."),
        ]);
    }

    /** @param array<string,string> $params */
    public function order(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('orders.edit');
        if (PaymentsAdmin::move($app, $request->str('move'))) {
            Admin::flash($app, 'ok', t('Order of the payment methods saved'));
        }

        return Response::redirect($app->url()->plain('/admin/payments'));
    }

    /** @param array<string,string> $params */
    public function enable(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('orders.edit');
        $app->auth()->requireCap('plugins.edit');
        $id = isset($params['id']) ? (string) $params['id'] : '';
        if (!$app->has('plugins') || !PaymentsAdmin::isOffPaymentModule($app, $id)) {
            throw HttpError::notFound();
        }
        $service = $app->service('plugins');
        $result = is_object($service) && method_exists($service, 'enable') ? $service->enable($id) : ['ok' => false, 'message' => ''];
        $ok = is_array($result) && !empty($result['ok']);
        if ($ok) {
            Admin::audit($app, 'plugins.enable', 'module', $id, 'Enable');
        }
        Admin::flash($app, $ok ? 'ok' : 'bad', is_array($result) && isset($result['message']) && (string) $result['message'] !== '' ? (string) $result['message'] : t('The module could not be turned on'));

        return Response::redirect($app->url()->plain('/admin/payments'));
    }
}

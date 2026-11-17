<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Subscriptions;

use Aifec\Admin\Admin;
use Aifec\Admin\Table;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\RateLimiter;
use Aifec\Kernel\Response;
use Aifec\Modules\Customers\Front\Account;
use Aifec\Security\Csrf;

final class SubscriptionsController
{
    private App $app;
    private Subscriptions $service;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->service = $app->service('subscriptions');
    }

    public function plans(Request $request, array $params): Response
    {
        return $this->front('plans', t('Monthly services'), ['plans' => $this->service->available() ? $this->service->plans() : []]);
    }

    public function account(Request $request, array $params): Response
    {
        $me = $this->me();
        return $this->front('account', t('My subscriptions'), ['subscriptions' => $this->service->forCustomer((int) $me['id']), 'customerPayments' => $this->service->customerPayments((int) $me['id'])]);
    }

    public function checkout(Request $request, array $params): Response
    {
        $me = $this->me();
        if (!$this->service->available()) {
            throw HttpError::notFound();
        }
        $plan = $this->service->plan((int) $params['id']);
        if ($plan === null || empty($plan['active'])) {
            throw HttpError::notFound();
        }
        $addresses = $this->app->service('customers')->addresses((int) $me['id']);
        $addressId = $request->int('address_id', isset($addresses[0]) ? (int) $addresses[0]['id'] : 0);
        $quote = $addressId > 0 ? $this->service->quote((int) $plan['id'], (int) $me['id'], $addressId) : null;
        $session = $this->app->session('customer');
        $key = 'subscriptions.nonce.' . (int) $plan['id'];
        $nonce = $session->get($key);
        $fingerprint = hash('sha256', (string) json_encode($quote));
        if (!is_string($nonce) || preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1 || $session->get('subscriptions.used.' . (int) $plan['id'], false)
            || $session->get('subscriptions.quote.' . (int) $plan['id'], '') !== $fingerprint) {
            $nonce = bin2hex(random_bytes(16));
            $session->set($key, $nonce);
            $session->set('subscriptions.used.' . (int) $plan['id'], false);
            $session->set('subscriptions.quote.' . (int) $plan['id'], $fingerprint);
        }
        return $this->front('checkout', t('Confirm the monthly service'), ['plan' => $plan, 'addresses' => $addresses, 'addressId' => $addressId, 'quote' => $quote, 'nonce' => $nonce, 'error' => '']);
    }

    public function start(Request $request, array $params): Response
    {
        $me = $this->me();
        $this->requireFrontToken($request);
        $limiter = new RateLimiter($this->app->db(), $this->app->clock());
        if (!$limiter->hit('subscription.customer.' . (int) $me['id'], 10, 600) || !$limiter->hit('subscription.ip.' . $request->ip, 40, 600)) {
            return $this->app->errorResponse(429, t('Too many subscription attempts. Wait a few minutes before trying again.'));
        }
        $nonce = $request->str('nonce');
        if (!hash_equals((string) $this->app->session('customer')->get('subscriptions.nonce.' . (int) $params['id'], ''), $nonce) || $nonce === '') {
            throw HttpError::forbidden();
        }
        try {
            $sub = $this->service->start((int) $params['id'], (int) $me['id'], $request->int('address_id'), $request->bool('consent'), $request->int('amount'), $nonce);
            if (!in_array($sub['status'], ['starting', 'setup_failed'], true)) {
                $this->app->session('customer')->set('subscriptions.used.' . (int) $params['id'], true);
            }
            if ($sub['status'] === 'pending' && !empty($sub['approval_url'])) {
                return Response::redirect((string) $sub['approval_url'], 303)->header('Cache-Control', 'no-store, private');
            }
            if ($sub['status'] === 'starting') {
                return $this->app->errorResponse(409, t('The subscription is being prepared. Wait a moment and open My subscriptions.'));
            }
            (new Account($this->app))->flash('ok', t('The monthly subscription has been created.'));
            return Response::redirect($this->app->url()->route('subscriptions.account'), 303);
        } catch (\InvalidArgumentException $e) {
            return $this->app->errorResponse(422, t('The subscription could not be confirmed. Review the plan, billing address and monthly amount, and accept the recurring payment.'));
        } catch (\RuntimeException $e) {
            return $this->app->errorResponse(503, t('The payment provider is not available. No subscription has been confirmed. Try again with the same form.'));
        }
    }

    public function back(Request $request, array $params): Response
    {
        $me = $this->me();
        $sub = $this->service->byPublic((string) $params['id'], (int) $me['id']);
        if ($sub === null) {
            throw HttpError::notFound();
        }
        // La vuelta no equivale a un cobro: solo consulta al proveedor; el aviso firmado registra la cuota.
        $this->service->refresh((int) $sub['id']);
        return Response::redirect($this->app->url()->route('subscriptions.account'), 303)->header('Cache-Control', 'no-store, private');
    }

    public function cancel(Request $request, array $params): Response
    {
        $me = $this->me();
        $this->requireFrontToken($request);
        $id = (int) $params['id'];
        if ($this->service->find($id, (int) $me['id']) === null) {
            throw HttpError::notFound();
        }
        $this->service->change($id, 'cancel', (int) $me['id']);
        (new Account($this->app))->flash('ok', t('The subscription has been cancelled. There will be no new monthly payments.'));
        return Response::redirect($this->app->url()->route('subscriptions.account'), 303)->header('Cache-Control', 'no-store, private');
    }

    public function notify(Request $request, array $params): Response
    {
        try {
            $ok = $this->service->notify((string) $params['environment'], $request->headers, $request->body);
            return Response::json(['ok' => $ok], $ok ? 200 : 400)->header('Cache-Control', 'no-store');
        } catch (\InvalidArgumentException $e) {
            return Response::json(['ok' => false], 400);
        } catch (\RuntimeException $e) {
            return Response::json(['ok' => false], 503);
        }
    }

    public function admin(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('subscriptions.view');
        $table = new Table($app, ['from' => '{subscriptions}', 'select' => '*', 'key' => 'id', 'search' => ['public_id', 'provider_id'], 'sort' => ['id', 'desc'], 'per_page' => 25,
            'row_url' => static function (array $row) use ($app): string { return $app->url()->route('admin.subscriptions.show', ['id' => (int) $row['id']]); },
            'columns' => ['public_id' => ['label' => 'Subscription', 'link' => true], 'customer_id' => ['label' => 'Customer'],
                'status' => ['label' => 'Status', 'render' => static function (array $row): string { return e(Labels::status((string) $row['status'])); }],
                'next_payment_at' => ['label' => 'Next payment', 'type' => 'datetime'], 'failures' => ['label' => 'Failed payments']]]);
        return Admin::page($app, 'subscriptions::admin/list', ['table' => $table->render($request)], ['title' => t('Subscriptions'), 'active' => '/admin/subscriptions']);
    }

    public function show(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('subscriptions.view');
        $sub = $this->service->find((int) $params['id']);
        if ($sub === null) {
            throw HttpError::notFound();
        }
        return Admin::page($this->app, 'subscriptions::admin/show', ['sub' => $sub, 'snapshot' => Subscriptions::snapshot($sub), 'payments' => $this->service->payments((int) $sub['id']), 'token' => Admin::token($this->app)], ['title' => t('Subscription') . ' ' . $sub['public_id'], 'active' => '/admin/subscriptions']);
    }

    public function action(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('subscriptions.edit');
        $id = (int) $params['id'];
        if (in_array($params['action'], ['paid', 'failed'], true)) {
            $this->service->simulate($id, (string) $params['action'], true, $request->str('due'));
        } else {
            $this->service->change($id, (string) $params['action']);
        }
        Admin::audit($this->app, 'subscription.' . $params['action'], 'subscriptions', (string) $id);
        return Response::redirect($this->app->url()->route('admin.subscriptions.show', ['id' => $id]), 303);
    }

    private function me(): array
    {
        $me = $this->app->service('customers')->current();
        if ($me === null) {
            throw HttpError::forbidden();
        }
        return $me;
    }

    /** En un mandato de pago exigimos la ficha, incluso en un envío de la propia web. */
    private function requireFrontToken(Request $request): void
    {
        $token = $request->input('_token');
        if (!is_string($token) || $token === '' || !Csrf::check($this->app->session('customer')->csrfSecret(), 'front', $token, $this->app->clock()->now(), 86400)) {
            throw HttpError::forbidden('The subscription form expired');
        }
    }

    private function front(string $view, string $title, array $data): Response
    {
        $account = new Account($this->app);
        $data += ['page' => ['title' => $title, 'type' => 'account', 'robots' => 'noindex, nofollow'], 'token' => $account->token(), 'service' => $this->service, 'app' => $this->app];
        return $this->app->render('subscriptions::front/' . $view, $data)->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}

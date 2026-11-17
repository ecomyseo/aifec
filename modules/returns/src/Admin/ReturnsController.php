<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Returns\Admin;

use Aifec\Admin\Admin;
use Aifec\Admin\Table;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Orders\Admin\OrdersAdmin;
use Aifec\Modules\Returns\ReturnLabels;
use Aifec\Modules\Returns\Returns;

/**
 * Las devoluciones en el panel: el listado (con filtro por estado y búsqueda por número, pedido o correo), la ficha con todo en una
 * pantalla (líneas, fotos, motivo, historial y acciones) y las acciones: aceptar, rechazar, marcar recibida y devolver el dinero.
 *
 * No escribe en las tablas por su cuenta: todo pasa por el servicio «returns», que es quien llama a Orders::refund(). Ver la lista
 * y las fotos pide «returns.view»; cualquier acción, «returns.manage».
 */
final class ReturnsController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    private function returns(): Returns
    {
        /** @var Returns $returns */
        $returns = $this->app->service('returns');

        return $returns;
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('returns.view');
        $table = new Table($app, [
            'from' => '{returns} r INNER JOIN {orders} o ON o.id = r.order_id',
            'select' => 'r.id, r.number, r.kind, r.status, r.reason, r.email, r.currency, r.refunded_amount, r.requested_at, o.number AS order_number, o.id AS order_id',
            'key' => 'id',
            'key_sql' => 'r.id',
            'search' => ['r.number', 'o.number', 'r.email'],
            'filters' => [
                'status' => ['label' => 'Status', 'column' => 'r.status', 'options' => [
                    'requested' => 'Requested', 'approved' => 'Accepted', 'received' => 'Received', 'refunded' => 'Refunded', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled',
                ]],
                'kind' => ['label' => 'Type', 'column' => 'r.kind', 'options' => ['return' => 'Return', 'withdrawal' => 'Withdrawal']],
            ],
            'sort' => ['r.id', 'desc'],
            'per_page' => 25,
            'empty' => ['title' => 'No returns yet', 'text' => 'Returns show up here when a customer asks for one from their order.'],
            'row_url' => static function (array $row) use ($app): string {
                return $app->url()->plain('/admin/returns/' . (int) $row['id']);
            },
            'columns' => [
                'number' => ['label' => 'Number', 'link' => true, 'sort' => 'r.id'],
                'status' => ['label' => 'Status', 'sort' => 'r.status', 'render' => static function (array $row, App $app): string {
                    return Admin::badge(ReturnLabels::status((string) $row['status']), ReturnLabels::kind((string) $row['status']));
                }],
                'kind' => ['label' => 'Type', 'hide_sm' => true, 'render' => static function (array $row, App $app): string {
                    return e(ReturnLabels::type((string) $row['kind']));
                }],
                'order_number' => ['label' => 'Order', 'render' => static function (array $row, App $app): string {
                    return '<a class="af-nowrap" href="' . e($app->url()->plain('/admin/orders/' . (int) $row['order_id'])) . '">' . e((string) $row['order_number']) . '</a>';
                }],
                'email' => ['label' => 'Customer', 'hide_sm' => true],
                'requested_at' => ['label' => 'Requested', 'type' => 'datetime', 'sort' => 'r.requested_at', 'hide_sm' => true],
                'refunded_amount' => ['label' => 'Refunded', 'type' => 'money', 'currency_field' => 'currency', 'sort' => 'r.refunded_amount', 'hide_sm' => true],
            ],
        ]);

        return Admin::page($app, 'returns::admin/list', ['table' => $table->render($request)], [
            'title' => t('Returns'),
            'active' => '/admin/returns',
            'help' => Admin::helpText("The customer asks for a return from the page of their order or from their account, and the withdrawal form is public.\nA request goes through Requested, Accepted, Received and Refunded; it can also be Rejected or Cancelled.\nRefunding the money calls the refund of the order, so the stock and the credit note are handled there."),
        ]);
    }

    /** @param array<string,string> $params id */
    public function show(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('returns.view');
        $return = $this->load((int) $params['id']);
        $order = $app->service('orders')->find((int) $return['order_id']);
        if ($order === null) {
            throw HttpError::notFound();
        }
        $id = (int) $return['id'];
        $photos = [];
        foreach ($return['photos'] as $i => $photo) {
            $photos[] = ['url' => $app->url()->plain('/admin/returns/' . $id . '/photo/' . (int) $i), 'name' => (string) $photo['name']];
        }
        $method = $app->service('payments')->get((string) $order['payment_method']);
        $proposal = $this->returns()->proposal($return);
        $status = (string) $return['status'];
        $lines = [];
        foreach ($return['items'] as $line) {
            $lines[] = $line + ['amount' => isset($proposal['lines'][(int) $line['id']]) ? (int) $proposal['lines'][(int) $line['id']] : 0];
        }

        return Admin::page($app, 'returns::admin/show', [
            'return' => $return,
            'lines' => $lines,
            'order' => $order,
            'orderUrl' => $app->url()->plain('/admin/orders/' . (int) $order['id']),
            'photos' => $photos,
            'proposal' => $proposal,
            'reasonText' => $this->returns()->policy()->reasonLabel((string) $return['reason']),
            'canManage' => $app->auth()->can('returns.manage'),
            'gateway' => $method !== null && $method->supportsRefund(),
            'defaultInstructions' => $this->returns()->instructions((string) $return['number']),
            'open' => in_array($status, Returns::OPEN, true),
            'actions' => [
                'approve' => $app->url()->plain('/admin/returns/' . $id . '/approve'),
                'reject' => $app->url()->plain('/admin/returns/' . $id . '/reject'),
                'receive' => $app->url()->plain('/admin/returns/' . $id . '/receive'),
                'refund' => $app->url()->plain('/admin/returns/' . $id . '/refund'),
                'cancel' => $app->url()->plain('/admin/returns/' . $id . '/cancel'),
                'note' => $app->url()->plain('/admin/returns/' . $id . '/note'),
            ],
        ], [
            'title' => t('Return {number}', ['number' => (string) $return['number']]),
            'crumbs' => [[t('Returns'), $app->url()->plain('/admin/returns')]],
            'active' => '/admin/returns',
        ]);
    }

    /**
     * Una foto de la devolución, en línea y solo con permiso de ver. Nunca tiene una dirección pública.
     *
     * @param array<string,string> $params id y n
     */
    public function photo(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('returns.view');
        $return = $this->load((int) $params['id']);
        $n = (int) $params['n'];
        if (!isset($return['photos'][$n]) || !$app->has('files')) {
            throw HttpError::notFound();
        }
        $photo = $return['photos'][$n];
        $path = $app->service('files')->path((string) $photo['path']);
        if ($path === null) {
            throw HttpError::notFound();
        }
        $response = Response::file($path, (string) $photo['name'], (string) $photo['mime'], true);
        $response->headers['X-Content-Type-Options'] = 'nosniff';
        $response->headers['X-Robots-Tag'] = 'noindex, nofollow';
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }

    /** @param array<string,string> $params id */
    public function approve(Request $request, array $params): Response
    {
        return $this->act($request, $params, 'approve', function (int $id) use ($request): void {
            $this->returns()->approve($id, $request->str('instructions'), $request->bool('notify', true), $this->userId());
        }, t('The return is accepted.'));
    }

    /** @param array<string,string> $params id */
    public function reject(Request $request, array $params): Response
    {
        return $this->act($request, $params, 'reject', function (int $id) use ($request): void {
            $this->returns()->reject($id, $request->str('reason'), $request->bool('notify', true), $this->userId());
        }, t('The return is rejected.'));
    }

    /** @param array<string,string> $params id */
    public function receive(Request $request, array $params): Response
    {
        return $this->act($request, $params, 'receive', function (int $id) use ($request): void {
            $restock = [];
            foreach ($request->arr('restock') as $lineId => $on) {
                if (is_scalar($on) && in_array(strtolower((string) $on), ['1', 'on', 'true', 'yes'], true)) {
                    $restock[(int) $lineId] = true;
                }
            }
            $this->returns()->receive($id, $restock, $request->bool('notify', true), $this->userId());
        }, t('The return is marked as received.'));
    }

    /** @param array<string,string> $params id */
    public function refund(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('returns.manage');
        $return = $this->load((int) $params['id']);
        $text = $request->str('amount');
        $amount = $text !== '' ? OrdersAdmin::parseMoney($text) : $this->returns()->proposal($return)['total'];
        if ($amount === null) {
            Admin::flash($this->app, 'bad', t('Enter a valid amount, for example 12.50.'));

            return $this->back((int) $return['id']);
        }

        return $this->act($request, $params, 'refund', function (int $id) use ($request, $amount): void {
            $this->returns()->refund($id, $amount, $request->bool('gateway'), $request->bool('notify', true), $this->userId());
        }, t('Refund of {amount} recorded.', ['amount' => money($amount, (string) $return['currency'])]));
    }

    /** @param array<string,string> $params id */
    public function cancel(Request $request, array $params): Response
    {
        return $this->act($request, $params, 'cancel', function (int $id) use ($request): void {
            $this->returns()->cancel($id, $request->str('reason'), $request->bool('notify', true), $this->userId(), 'staff');
        }, t('The return is cancelled.'));
    }

    /** @param array<string,string> $params id */
    public function note(Request $request, array $params): Response
    {
        return $this->act($request, $params, 'note', function (int $id) use ($request): void {
            $this->returns()->addNote($id, $request->str('note'), $this->userId());
        }, t('The note is saved.'));
    }

    /**
     * Una acción sobre una devolución: permiso, la llamada al servicio, el aviso y la auditoría. Un fallo del servicio (estado que no
     * lo permite, importe que no vale, pasarela que rechaza) se enseña tal cual y no cambia nada.
     *
     * @param array<string,string> $params
     */
    private function act(Request $request, array $params, string $action, callable $run, string $done): Response
    {
        $this->app->auth()->requireCap('returns.manage');
        $return = $this->load((int) $params['id']);
        $id = (int) $return['id'];
        try {
            $run($id);
        } catch (\InvalidArgumentException $e) {
            Admin::flash($this->app, 'bad', t($e->getMessage()));

            return $this->back($id);
        } catch (\RuntimeException $e) {
            Admin::flash($this->app, 'bad', t('The refund failed: {reason}', ['reason' => t($e->getMessage())]));

            return $this->back($id);
        }
        Admin::audit($this->app, 'return.' . $action, 'return', (string) $id, (string) $return['number']);
        Admin::flash($this->app, 'ok', $done);

        return $this->back($id);
    }

    /**
     * @return array<string,mixed>
     */
    private function load(int $id): array
    {
        $return = $this->returns()->find($id);
        if ($return === null) {
            throw HttpError::notFound();
        }

        return $return;
    }

    private function back(int $id): Response
    {
        return Response::redirect($this->app->url()->plain('/admin/returns/' . $id));
    }

    private function userId(): ?int
    {
        return $this->app->auth()->adminId();
    }
}

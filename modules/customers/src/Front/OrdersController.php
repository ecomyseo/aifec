<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Customers\Front;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Pedidos y descargas de quien ha entrado. Solo existen si el sitio tiene el módulo de pedidos (si no, 404).
 * Un pedido de otro cliente, o uno anterior al registro mientras el correo no esté verificado, es un 404.
 */
final class OrdersController
{
    private const PER_PAGE = 10;

    /** @var App */
    private $app;

    /** @var Account */
    private $account;

    /** @var OrderList */
    private $list;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->account = new Account($app);
        $this->list = new OrderList($app, $this->account);
    }

    /**
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $a = $this->account;
        $this->list->orders();
        $me = $a->me();
        if ($me === null) {
            return $a->toLogin();
        }
        $total = $this->list->count($me);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, $request->int('page', 1)));

        return $a->render('orders', t('My orders'), [
            'customer' => $me, 'active' => 'orders', 'rows' => $this->list->page($me, $page, self::PER_PAGE),
            'currentPage' => $page, 'pages' => $pages, 'hidden' => $this->list->hidden($me), 'unverified' => empty($me['verified']),
        ]);
    }

    /**
     * @param array<string,string> $params number
     */
    public function show(Request $request, array $params): Response
    {
        $a = $this->account;
        $this->list->orders();
        $me = $a->me();
        if ($me === null) {
            return $a->toLogin();
        }
        $order = $this->list->find($me, isset($params['number']) ? (string) $params['number'] : '');
        if ($order === null) {
            throw HttpError::notFound();
        }
        $view = $this->list->view($order);

        return $a->render('order', t('Order {number}', ['number' => $view['number']]), [
            'customer' => $me, 'active' => 'orders', 'order' => $view,
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function downloads(Request $request, array $params): Response
    {
        $a = $this->account;
        $this->list->orders();
        $me = $a->me();
        if ($me === null) {
            return $a->toLogin();
        }

        return $a->render('downloads', t('My downloads'), [
            'customer' => $me, 'active' => 'downloads', 'groups' => $this->list->downloadGroups($me),
        ]);
    }

    /**
     * Da un enlace nuevo para una descarga y manda a ella. El enlace anterior deja de valer.
     *
     * @param array<string,string> $params id
     */
    public function downloadLink(Request $request, array $params): Response
    {
        $a = $this->account;
        $orders = $this->list->orders();
        $me = $a->me();
        if ($me === null) {
            return $a->toLogin();
        }
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        foreach ($this->list->paid($me, 50) as $order) {
            foreach ($this->list->downloadsOf((int) $order['id']) as $download) {
                if ($download['id'] !== $id) {
                    continue;
                }
                $url = $download['available'] ? $orders->downloads()->renew($id, (int) $order['id']) : null;
                if ($url === null) {
                    throw HttpError::notFound();
                }

                return $a->goTo($url);
            }
        }

        throw HttpError::notFound();
    }
}

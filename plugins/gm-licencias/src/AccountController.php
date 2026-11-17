<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Licencias;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Customers\Front\Account;

/**
 * «Mis licencias» en la zona de cliente: las licencias de quien ha entrado, con sus dominios y la fecha de actualizaciones.
 *
 * Solo ve las suyas: las de sus pedidos y, con el correo verificado, las compradas con ese correo. La página no se guarda en
 * ninguna caché y no se indexa.
 */
final class AccountController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $account = new Account($this->app);
        $me = $account->me();
        if ($me === null) {
            return $account->toLogin();
        }
        $licenses = $this->app->service('gm_licenses');
        $items = [];
        foreach ($licenses->forCustomer($me) as $license) {
            $items[] = ['license' => $license, 'domains' => $licenses->domains((int) $license['id'])];
        }
        $url = $this->app->url();
        $page = [
            'title' => t('My licences'), 'type' => 'account', 'robots' => 'noindex, nofollow', 'body_class' => 'af-account',
            'canonical' => $url->origin() . $url->to($request->path),
        ];
        $response = $this->app->render('gm-licencias::front/account', [
            'page' => $page, 'messages' => [], 'customer' => $me, 'token' => $account->token(), 'account' => $account, 'active' => 'gm-licencias', 'items' => $items,
        ]);
        $response->headers['Cache-Control'] = 'no-store, private';
        $response->headers['X-Robots-Tag'] = 'noindex, nofollow';

        return $response;
    }
}

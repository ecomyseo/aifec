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
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * La portada de la zona de cliente, el aviso de correo sin verificar y el estado de la sesión para la cabecera.
 */
final class AccountController
{
    /** @var App */
    private $app;

    /** @var Account */
    private $account;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->account = new Account($app);
    }

    /**
     * Mi cuenta: saludo, aviso de correo sin verificar, últimos pedidos y accesos.
     *
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $a = $this->account;
        $me = $a->me();
        if ($me === null) {
            return $a->toLogin();
        }
        $recent = [];
        $hidden = 0;
        if ($a->hasOrders()) {
            $list = new OrderList($this->app, $a);
            $recent = $list->page($me, 1, 5);
            $hidden = $list->hidden($me);
        }
        $name = trim((string) $me['first_name']) !== '' ? trim((string) $me['first_name']) : (string) $me['email'];

        return $a->render('account', t('My account'), [
            'customer' => $me, 'active' => 'account', 'name' => $name, 'recent' => $recent, 'hidden' => $hidden,
            'unverified' => empty($me['verified']),
        ]);
    }

    /**
     * Manda otra vez el correo para confirmar la dirección.
     *
     * @param array<string,string> $params
     */
    public function resend(Request $request, array $params): Response
    {
        $a = $this->account;
        $me = $a->me();
        if ($me === null) {
            return $a->toLogin();
        }
        if (!empty($me['verified'])) {
            $a->flash('info', t('Your email address is already confirmed.'));
        } else {
            $a->customers()->sendVerification((int) $me['id']);
            $a->flash('ok', t('We have sent you an email to confirm your address.'));
        }

        return $a->go('account');
    }

    /**
     * Quién ha entrado, para que el guion del tema cambie «Entrar» por el nombre. Sin caché y solo el nombre de pila.
     *
     * @param array<string,string> $params
     */
    public function status(Request $request, array $params): Response
    {
        $me = $this->account->me();

        return Response::json(['logged' => $me !== null, 'name' => $me !== null ? trim((string) $me['first_name']) : '']);
    }
}

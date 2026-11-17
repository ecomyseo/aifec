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
 * Privacidad: descargar los datos propios (JSON) y eliminar la cuenta. Eliminar pide la contraseña y escribir la palabra
 * de confirmación; borra los datos personales con Customers::anonymise() (los pedidos se conservan sin nombre) y cierra
 * la sesión.
 */
final class PrivacyController
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
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $a = $this->account;
        $me = $a->me();
        if ($me === null) {
            return $a->toLogin();
        }

        return $a->render('privacy', t('Privacy'), ['customer' => $me, 'active' => 'privacy', 'word' => t('DELETE')]);
    }

    /**
     * Todos los datos que se guardan de la persona, en un fichero JSON.
     *
     * @param array<string,string> $params
     */
    public function export(Request $request, array $params): Response
    {
        $a = $this->account;
        $me = $a->me();
        if ($me === null) {
            return $a->toLogin();
        }
        $json = json_encode($a->customers()->exportData((int) $me['id']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $response = Response::text(is_string($json) ? $json : '{}', 200, 'application/json');
        $response->headers['Content-Disposition'] = 'attachment; filename="my-data.json"';
        $response->headers['Cache-Control'] = 'no-store, private';
        $response->headers['X-Robots-Tag'] = 'noindex, nofollow';

        return $response;
    }

    /**
     * Elimina la cuenta: pide la contraseña y la palabra de confirmación.
     *
     * @param array<string,string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $a = $this->account;
        $me = $a->me();
        if ($me === null) {
            return $a->toLogin();
        }
        $word = t('DELETE');
        $typed = mb_strtoupper($request->str('confirm'), 'UTF-8');
        $password = $request->input('password');
        $messages = [];
        if ($typed !== mb_strtoupper($word, 'UTF-8') && $typed !== 'DELETE') {
            $messages[] = ['bad', t('Type the confirmation word to delete your account.')];
        } else {
            $problem = $a->passwordProblem($me, is_string($password) ? $password : '');
            if ($problem !== '') {
                $messages[] = ['bad', $problem];
            }
        }
        if ($messages === []) {
            $a->customers()->anonymise((int) $me['id']);

            return $a->goTo($a->link('account.login') . '?done=deleted');
        }

        return $a->render('privacy', t('Privacy'), ['customer' => $me, 'active' => 'privacy', 'word' => $word, 'messages' => $messages]);
    }
}

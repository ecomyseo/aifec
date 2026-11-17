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
use Aifec\Modules\Customers\Customers;

/**
 * Mis datos: los datos personales, el cambio de correo y el cambio de contraseña, cada uno con su formulario
 * (campo «action»: profile, email o password).
 *
 * El correo y la contraseña piden la contraseña actual, y esa comprobación cuenta como un intento de entrada (la misma
 * cuenta no se puede probar sin límite desde aquí). Un correo nuevo deja la cuenta sin verificar. Una contraseña nueva
 * cierra las demás sesiones de la cuenta y deja abierta la actual.
 */
final class ProfileController
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
    public function profile(Request $request, array $params): Response
    {
        $a = $this->account;
        $me = $a->me();
        if ($me === null) {
            return $a->toLogin();
        }
        $customers = $a->customers();
        $messages = [];
        $form = [
            'first_name' => (string) $me['first_name'], 'last_name' => (string) $me['last_name'], 'phone' => (string) $me['phone'],
            'company' => (string) $me['company'], 'vat_id' => (string) $me['vat_id'], 'marketing' => (int) $me['marketing'] === 1,
            'new_email' => '',
        ];
        if (trim($form['phone']) === '') {
            // Sin teléfono en la cuenta, pero con uno en una dirección guardada (la de entrega por defecto primero): se
            // propone ese en vez de enseñar el campo vacío. Se guarda en la cuenta cuando ella pulse «Guardar».
            foreach ($customers->addresses((int) $me['id']) as $saved) {
                if (isset($saved['phone']) && trim((string) $saved['phone']) !== '') {
                    $form['phone'] = trim((string) $saved['phone']);
                    break;
                }
            }
        }
        if ($request->isPost()) {
            $action = $request->str('action');
            if ($action === 'profile') {
                $form = [
                    'first_name' => mb_substr($request->str('first_name'), 0, 80, 'UTF-8'),
                    'last_name' => mb_substr($request->str('last_name'), 0, 120, 'UTF-8'),
                    'phone' => mb_substr($request->str('phone'), 0, 40, 'UTF-8'),
                    'company' => mb_substr($request->str('company'), 0, 160, 'UTF-8'),
                    'vat_id' => mb_substr($request->str('vat_id'), 0, 40, 'UTF-8'),
                    'marketing' => $request->bool('marketing'),
                    'new_email' => '',
                ];
                if ($form['first_name'] === '') {
                    $messages[] = ['bad', t('The first name is required')];
                } else {
                    try {
                        $customers->update((int) $me['id'], [
                            'first_name' => $form['first_name'], 'last_name' => $form['last_name'], 'phone' => $form['phone'],
                            'company' => $form['company'], 'vat_id' => $form['vat_id'], 'marketing' => $form['marketing'],
                        ]);
                        $a->flash('ok', t('Your details have been saved.'));

                        return $a->go('account.profile');
                    } catch (\InvalidArgumentException $e) {
                        $messages[] = ['bad', t($e->getMessage())];
                    }
                }
            } elseif ($action === 'email') {
                $form['new_email'] = mb_substr($request->str('new_email'), 0, 191, 'UTF-8');
                $problem = $a->passwordProblem($me, $this->text($request, 'current_password'));
                if ($problem !== '') {
                    $messages[] = ['bad', $problem];
                } elseif (strtolower($form['new_email']) === strtolower((string) $me['email'])) {
                    $messages[] = ['info', t('That is already your email address.')];
                } else {
                    try {
                        $customers->update((int) $me['id'], ['email' => $form['new_email']]);
                        $a->flash('ok', t('Your email address has been changed. We have sent a link to the new one to confirm it.'));

                        return $a->go('account.profile');
                    } catch (\InvalidArgumentException $e) {
                        $messages[] = ['bad', t($e->getMessage())];
                    }
                }
            } elseif ($action === 'password') {
                $new = $this->text($request, 'new_password');
                $problem = $a->passwordProblem($me, $this->text($request, 'current_password'));
                if ($problem !== '') {
                    $messages[] = ['bad', $problem];
                } elseif ($new !== $this->text($request, 'new_password_confirm')) {
                    $messages[] = ['bad', t('The two passwords are not the same.')];
                } else {
                    try {
                        $customers->update((int) $me['id'], ['password' => $new]);
                        // La comprobación de arriba abrió una sesión nueva que aún no está guardada: solo caen las demás.
                        $this->app->db()->delete('sessions', ['guard' => 'customer', 'user_id' => (int) $me['id']]);
                        $a->flash('ok', t('Your password has been changed. The other sessions have been closed.'));

                        return $a->go('account.profile');
                    } catch (\InvalidArgumentException $e) {
                        $messages[] = ['bad', t($e->getMessage())];
                    }
                }
            } else {
                throw HttpError::badRequest();
            }
            $fresh = $customers->current();
            $me = $fresh !== null ? $fresh : $me;
        }

        return $a->render('profile', t('My details'), [
            'customer' => $me, 'active' => 'profile', 'form' => $form, 'messages' => $messages, 'minPassword' => Customers::MIN_PASSWORD,
        ]);
    }

    /** Un campo de contraseña tal como llegó: sin recortar. */
    private function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }
}

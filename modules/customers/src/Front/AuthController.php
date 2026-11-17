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
 * Entrar, registrarse, recuperar la contraseña, el enlace de entrada, verificar el correo y salir.
 *
 * Reglas de seguridad de estas pantallas:
 *  - los errores de entrada son genéricos (no dicen si el correo existe) y el bloqueo por intentos lo decide el servicio;
 *  - recuperar la contraseña y pedir el enlace contestan IGUAL exista o no la cuenta;
 *  - el registro con un correo que ya es de una cuenta da el mismo mensaje neutro que cualquier otro fallo;
 *  - «next» solo admite caminos del propio sitio.
 */
final class AuthController
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
    public function login(Request $request, array $params): Response
    {
        $a = $this->account;
        if ($a->me() !== null) {
            return $a->go('account');
        }
        $next = $a->safeNext($request->str('next'));
        $messages = [];
        $status = 200;
        if ($request->isPost()) {
            $email = mb_substr($request->str('email'), 0, 191, 'UTF-8');
            $password = $request->input('password');
            $password = is_string($password) ? $password : '';
            $customers = $a->customers();
            if ($email === '' || $password === '') {
                $messages[] = ['bad', t('Enter your email and your password.')];
            } elseif ($customers->isLocked($email)) {
                $messages[] = ['bad', $a->lockedText()];
                $status = 429;
            } elseif ($customers->login($email, $password) !== null) {
                return $a->goTo($next !== '' ? $next : $a->link('account'));
            } else {
                $locked = $customers->isLocked($email);
                $messages[] = ['bad', $locked ? $a->lockedText() : t('The email or the password is not correct.')];
                $status = $locked ? 429 : 200;
            }

            return $this->loginPage($email, $next, $messages, $status);
        }
        $done = $request->str('done');
        if ($done === 'reset') {
            $messages[] = ['ok', t('Your password has been changed. Sign in with the new one.')];
        } elseif ($done === 'verified') {
            $messages[] = ['ok', t('Your email address is confirmed. Sign in to continue.')];
        } elseif ($done === 'closed') {
            $messages[] = ['info', t('You have signed out.')];
        } elseif ($done === 'deleted') {
            $messages[] = ['info', t('Your account has been deleted.')];
        }

        return $this->loginPage('', $next, $messages, 200);
    }

    /**
     * Pide el enlace de entrada. Contesta lo mismo exista o no la cuenta.
     *
     * @param array<string,string> $params
     */
    public function loginLink(Request $request, array $params): Response
    {
        $a = $this->account;
        if (!$this->app->settings()->bool('customers.magic_link', true)) {
            throw HttpError::notFound();
        }
        if ($a->me() !== null) {
            return $a->go('account');
        }
        $a->customers()->requestMagicLink($request->str('email'));
        $messages = [['info', t('If that email belongs to an account, we have sent a link to sign in. It works once and for a few minutes.')]];

        return $this->loginPage('', $a->safeNext($request->str('next')), $messages, 200);
    }

    /**
     * Entra con el enlace del correo (una sola vez).
     *
     * @param array<string,string> $params token
     */
    public function magic(Request $request, array $params): Response
    {
        $a = $this->account;
        if ($a->customers()->loginWithToken(isset($params['token']) ? (string) $params['token'] : '') !== null) {
            return $a->go('account');
        }
        $messages = [['bad', t('The link is not valid or has expired. Ask for a new one.')]];

        return $this->loginPage('', '', $messages, 200);
    }

    /**
     * Confirma el correo con el enlace que se mandó.
     *
     * @param array<string,string> $params token
     */
    public function verify(Request $request, array $params): Response
    {
        $a = $this->account;
        if ($a->customers()->verify(isset($params['token']) ? (string) $params['token'] : '')) {
            if ($a->me() !== null) {
                $a->flash('ok', t('Your email address is confirmed.'));

                return $a->go('account');
            }

            return $a->goTo($a->link('account.login') . '?done=verified');
        }
        $me = $a->me();

        return $a->render('notice', t('This link does not work'), [
            'text' => t('The link is not valid or has expired.'),
            'customer' => $me,
            'resend' => $me !== null && empty($me['verified']),
        ]);
    }

    /**
     * Crea la cuenta, apunta que aceptó la privacidad y la deja dentro.
     *
     * @param array<string,string> $params
     */
    public function register(Request $request, array $params): Response
    {
        $a = $this->account;
        if (!$this->app->settings()->bool('customers.allow_register', true)) {
            throw HttpError::notFound();
        }
        if ($a->me() !== null) {
            return $a->go('account');
        }
        $form = ['first_name' => '', 'last_name' => '', 'email' => '', 'marketing' => false, 'privacy' => false];
        $messages = [];
        $status = 200;
        if ($request->isPost()) {
            $password = $request->input('password');
            $password = is_string($password) ? $password : '';
            $form = [
                'first_name' => mb_substr($request->str('first_name'), 0, 80, 'UTF-8'),
                'last_name' => mb_substr($request->str('last_name'), 0, 120, 'UTF-8'),
                'email' => mb_substr($request->str('email'), 0, 191, 'UTF-8'),
                'marketing' => $request->bool('marketing'),
                'privacy' => $request->bool('accept_privacy'),
            ];
            if ($form['first_name'] === '' || $form['email'] === '' || $password === '') {
                $messages[] = ['bad', t('Fill in your name, your email and a password.')];
            } elseif (!$form['privacy']) {
                $messages[] = ['bad', t('You have to accept the privacy policy to create an account.')];
            } else {
                $customers = $a->customers();
                try {
                    $id = $customers->register([
                        'first_name' => $form['first_name'], 'last_name' => $form['last_name'], 'email' => $form['email'],
                        'password' => $password, 'marketing' => $form['marketing'],
                    ]);
                    $customers->consent($id, $form['email'], 'privacy', true, '', 'register');
                    $customers->login($form['email'], $password);
                    $a->flash('ok', t('Your account has been created. We have sent you an email to confirm your address.'));

                    return $a->go('account');
                } catch (\InvalidArgumentException $e) {
                    $messages[] = ['bad', t($e->getMessage())];
                }
            }
        }

        return $a->render('register', t('Create an account'), [
            'form' => $form, 'messages' => $messages, 'minPassword' => Customers::MIN_PASSWORD,
        ], $status);
    }

    /**
     * Pide un enlace para elegir otra contraseña. Contesta lo mismo exista o no la cuenta.
     *
     * @param array<string,string> $params
     */
    public function recover(Request $request, array $params): Response
    {
        $a = $this->account;
        if ($a->me() !== null) {
            return $a->go('account');
        }
        $sent = false;
        $messages = [];
        if ($request->isPost()) {
            $a->customers()->requestReset($request->str('email'));
            $sent = true;
            $messages[] = ['info', t('If that email belongs to an account, we have sent a message with a link to choose a new password.')];
        }

        return $a->render('recover', t('Recover your password'), ['sent' => $sent, 'messages' => $messages]);
    }

    /**
     * Elige la contraseña nueva con la ficha del correo. La página se enseña con cualquier ficha (comprobarla la gastaría);
     * la ficha se valida al enviar.
     *
     * @param array<string,string> $params token
     */
    public function reset(Request $request, array $params): Response
    {
        $a = $this->account;
        $token = isset($params['token']) ? (string) $params['token'] : '';
        $messages = [];
        $expired = false;
        if ($request->isPost()) {
            $first = $request->input('password');
            $second = $request->input('password_confirm');
            $first = is_string($first) ? $first : '';
            $second = is_string($second) ? $second : '';
            if ($first !== $second) {
                $messages[] = ['bad', t('The two passwords are not the same.')];
            } else {
                try {
                    if ($a->customers()->resetPassword($token, $first)) {
                        return $a->goTo($a->link('account.login') . '?done=reset');
                    }
                    $messages[] = ['bad', t('The link is not valid or has expired. Ask for a new one.')];
                    $expired = true;
                } catch (\InvalidArgumentException $e) {
                    $messages[] = ['bad', t($e->getMessage())];
                }
            }
        }

        return $a->render('reset', t('Choose a new password'), [
            'token_url' => $a->link('account.reset', ['token' => $token]), 'messages' => $messages, 'expired' => $expired,
            'minPassword' => Customers::MIN_PASSWORD,
        ]);
    }

    /**
     * Cierra la sesión.
     *
     * @param array<string,string> $params
     */
    public function logout(Request $request, array $params): Response
    {
        $a = $this->account;
        $a->customers()->logout();

        return $a->goTo($a->link('account.login') . '?done=closed');
    }

    /**
     * @param array<int,array<int,string>> $messages
     */
    private function loginPage(string $email, string $next, array $messages, int $status): Response
    {
        return $this->account->render('login', t('Sign in'), [
            'email' => $email, 'next' => $next, 'messages' => $messages,
            'magic' => $this->app->settings()->bool('customers.magic_link', true),
            'canRegister' => $this->app->settings()->bool('customers.allow_register', true),
        ], $status);
    }
}

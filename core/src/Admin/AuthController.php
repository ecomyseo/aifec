<?php
/**
 * AIFEC — entrada al panel: contraseña, segundo paso con código o con uno de recuperación (si la persona lo tiene activado), salida y
 * restablecimiento de la contraseña por correo. Los intentos fallidos se cuentan y se frenan.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Admin;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Security\Crypto;
use Aifec\Security\Passwords;
use Aifec\Security\Random;
use Aifec\Security\RecoveryCodes;
use Aifec\Security\Totp;

final class AuthController
{
    private const MAX_PER_ACCOUNT = 5;
    private const MAX_PER_IP = 20;
    private const WINDOW = 900;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** @param array<string,string> $p */
    public function form(Request $request, array $p): Response
    {
        if ($this->app->auth()->admin() !== null) {
            return Response::redirect($this->app->url()->plain('/admin'));
        }

        $email = trim($request->str('email'));
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 191 ? $email : '';

        return $this->screen('login', ['next' => $request->str('next'), 'email' => $email]);
    }

    /** @param array<string,string> $p */
    public function login(Request $request, array $p): Response
    {
        $this->sameOrigin($request);
        $app = $this->app;
        $db = $app->db();
        $email = strtolower(trim($request->str('email')));
        $password = (string) $request->input('password', '');
        $since = $app->clock()->sql($app->clock()->now() - self::WINDOW);

        $byAccount = (int) $db->value("SELECT COUNT(*) FROM {login_attempts} WHERE guard = 'admin' AND identifier = ? AND ok = 0 AND created_at >= ?", [$email, $since]);
        $byIp = (int) $db->value("SELECT COUNT(*) FROM {login_attempts} WHERE guard = 'admin' AND ip = ? AND ok = 0 AND created_at >= ?", [$request->ip, $since]);
        if ($byAccount >= self::MAX_PER_ACCOUNT || $byIp >= self::MAX_PER_IP) {
            return $this->screen('login', ['next' => $request->str('next'), 'email' => $email, 'error' => t('Too many attempts. Wait fifteen minutes and try again.')], 429);
        }

        $user = $email !== '' ? $db->row('SELECT * FROM {users} WHERE email = ? AND active = 1', [$email]) : null;
        $ok = $user !== null && Passwords::verify($password, (string) $user['password']);
        if (!$ok) {
            // Se gasta el mismo tiempo exista o no la cuenta.
            if ($user === null) {
                Passwords::verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            }
            $db->insert('login_attempts', ['guard' => 'admin', 'identifier' => substr($email, 0, 190), 'ip' => $request->ip, 'ok' => 0, 'created_at' => $app->clock()->sql()]);

            return $this->screen('login', ['next' => $request->str('next'), 'email' => $email, 'error' => t('The email or the password is not correct.')], 422);
        }

        if (!empty($user['totp_secret'])) {
            $session = $app->session('admin');
            $session->set('pending_2fa', (int) $user['id']);
            $session->set('pending_2fa_at', $app->clock()->now());

            return Response::redirect($app->url()->plain('/admin/login/code', ['next' => $request->str('next')]));
        }

        return $this->enter($user, $request);
    }

    /** @param array<string,string> $p */
    public function codeForm(Request $request, array $p): Response
    {
        if ($this->pendingUser() === null) {
            return Response::redirect($this->app->url()->plain('/admin/login'));
        }

        return $this->screen('code', ['next' => $request->str('next'), 'recovery' => $request->str('mode') === 'recovery']);
    }

    /**
     * El código de seis cifras de la aplicación o, con «recovery», uno de los códigos de recuperación (de un solo uso).
     * Los dos comparten los intentos: cinco fallos entre los dos y la cuenta espera un cuarto de hora.
     *
     * @param array<string,string> $p
     */
    public function code(Request $request, array $p): Response
    {
        $this->sameOrigin($request);
        $app = $this->app;
        $user = $this->pendingUser();
        if ($user === null) {
            return Response::redirect($app->url()->plain('/admin/login'));
        }
        $recovery = $request->str('mode') === 'recovery' || $request->str('recovery') !== '';
        $since = $app->clock()->sql($app->clock()->now() - self::WINDOW);
        $failed = (int) $app->db()->value("SELECT COUNT(*) FROM {login_attempts} WHERE guard = 'admin2' AND identifier = ? AND ok = 0 AND created_at >= ?", [(string) $user['email'], $since]);
        if ($failed >= self::MAX_PER_ACCOUNT) {
            return $this->screen('code', ['next' => $request->str('next'), 'recovery' => $recovery, 'error' => t('Too many attempts. Wait fifteen minutes and try again.')], 429);
        }
        if ($recovery) {
            $codes = new RecoveryCodes($app->db(), $app->site()->secret());
            if (!$codes->consume((int) $user['id'], $request->str('recovery'), $app->clock()->sql())) {
                $this->countFailure($user, $request);

                return $this->screen('code', ['next' => $request->str('next'), 'recovery' => true, 'error' => t('That recovery code is not valid, or it was already used.')], 422);
            }

            return $this->enter($user, $request, true);
        }
        $secret = (new Crypto($app->site()->secret()))->decrypt((string) $user['totp_secret']);
        $code = preg_replace('/\D+/', '', $request->str('code'));
        if ($secret === null || !Totp::verify($secret, (string) $code, $app->clock()->now())) {
            $this->countFailure($user, $request);

            return $this->screen('code', ['next' => $request->str('next'), 'recovery' => false, 'error' => t('That code is not valid. Check the clock of your phone and try the next one.')], 422);
        }

        return $this->enter($user, $request);
    }

    /** @param array<string,string> $p */
    public function logout(Request $request, array $p): Response
    {
        $admin = $this->app->auth()->admin();
        if ($admin !== null) {
            Admin::audit($this->app, 'logout', 'user', (string) $admin['id'], (string) $admin['email']);
        }
        $this->app->session('admin')->logout();
        $this->app->auth()->reset();

        return Response::redirect($this->app->url()->plain('/admin/login'));
    }

    /** @param array<string,string> $p */
    public function forgotForm(Request $request, array $p): Response
    {
        return $this->screen('forgot', []);
    }

    /** Siempre contesta lo mismo, exista o no la cuenta: no se dice quién tiene acceso al panel. */
    public function forgot(Request $request, array $p): Response
    {
        $this->sameOrigin($request);
        $app = $this->app;
        $email = strtolower(trim($request->str('email')));
        $user = $email !== '' ? $app->db()->row('SELECT id, email, name, locale, role FROM {users} WHERE email = ? AND active = 1', [$email]) : null;
        $recent = (int) $app->db()->value("SELECT COUNT(*) FROM {tokens} WHERE kind = 'admin_reset' AND subject = ? AND created_at >= ?", [$email, $app->clock()->sql($app->clock()->now() - 600)]);
        if ($user !== null && !$app->auth()->isPublicDemoIdentity($user) && $recent < 3 && $app->has('mailer')) {
            $token = Random::token(32);
            $app->db()->insert('tokens', [
                'kind' => 'admin_reset', 'subject' => $email, 'hash' => hash('sha256', $token), 'data' => (string) $user['id'],
                'expires_at' => $app->clock()->sql($app->clock()->now() + 3600), 'created_at' => $app->clock()->sql(),
            ]);
            $app->service('mailer')->queue('admin_reset', (string) $user['email'], [
                'name' => (string) $user['name'],
                'url' => $app->url()->origin() . $app->url()->plain('/admin/reset/' . $token),
                'minutes' => 60,
            ], ['locale' => (string) $user['locale'], 'to_name' => (string) $user['name'], 'now' => true]);
        }

        return $this->screen('forgot', ['sent' => true]);
    }

    /** @param array<string,string> $p */
    public function resetForm(Request $request, array $p): Response
    {
        if ($this->resetToken((string) $p['token']) === null) {
            return $this->screen('reset', ['invalid' => true], 410);
        }

        return $this->screen('reset', ['token' => (string) $p['token']]);
    }

    /** @param array<string,string> $p */
    public function reset(Request $request, array $p): Response
    {
        $this->sameOrigin($request);
        $app = $this->app;
        $row = $this->resetToken((string) $p['token']);
        if ($row === null) {
            return $this->screen('reset', ['invalid' => true], 410);
        }
        $password = (string) $request->input('password', '');
        if (mb_strlen($password) < 10) {
            return $this->screen('reset', ['token' => (string) $p['token'], 'error' => t('Use at least {n} characters', ['n' => 10])], 422);
        }
        if ($password !== (string) $request->input('password2', '')) {
            return $this->screen('reset', ['token' => (string) $p['token'], 'error' => t('The two values do not match')], 422);
        }
        $userId = (int) $row['data'];
        $first = $app->db()->run('UPDATE {tokens} SET used_at = ? WHERE id = ? AND used_at IS NULL', [$app->clock()->sql(), (int) $row['id']]) === 1;
        if (!$first) {
            return $this->screen('reset', ['invalid' => true], 410);
        }
        $app->db()->update('users', ['password' => Passwords::hash($password), 'updated_at' => $app->clock()->sql()], ['id' => $userId]);
        // Quien tuviera una sesión abierta con la contraseña anterior se queda fuera.
        $app->db()->delete('sessions', ['guard' => 'admin', 'user_id' => $userId]);
        Admin::audit($app, 'password_reset', 'user', (string) $userId);
        Admin::flash($app, 'ok', t('Password changed. You can sign in now.'));

        return Response::redirect($app->url()->plain('/admin/login'));
    }

    // ------------------------------------------------------------------ piezas

    /**
     * Un código fallido (de la aplicación o de recuperación) cuenta como intento. Del código no se apunta nada.
     *
     * @param array<string,mixed> $user
     */
    private function countFailure(array $user, Request $request): void
    {
        $this->app->db()->insert('login_attempts', ['guard' => 'admin2', 'identifier' => (string) $user['email'], 'ip' => $request->ip, 'ok' => 0, 'created_at' => $this->app->clock()->sql()]);
    }

    /**
     * @param array<string,mixed> $user
     */
    private function enter(array $user, Request $request, bool $viaRecovery = false): Response
    {
        $app = $this->app;
        $session = $app->session('admin');
        $session->login((int) $user['id']);
        $session->forget('pending_2fa');
        $session->forget('pending_2fa_at');
        $app->auth()->reset();
        $update = ['last_login_at' => $app->clock()->sql()];
        $password = (string) $request->input('password', '');
        if ($password !== '' && Passwords::needsRehash((string) $user['password'])) {
            $update['password'] = Passwords::hash($password);
        }
        $app->db()->update('users', $update, ['id' => (int) $user['id']]);
        $app->db()->insert('login_attempts', ['guard' => 'admin', 'identifier' => (string) $user['email'], 'ip' => $request->ip, 'ok' => 1, 'created_at' => $app->clock()->sql()]);
        // Tras entrar bien, los fallos anteriores de esa cuenta dejan de contar.
        $app->db()->run("DELETE FROM {login_attempts} WHERE guard IN ('admin', 'admin2') AND identifier = ? AND ok = 0", [(string) $user['email']]);
        Admin::audit($app, 'login', 'user', (string) $user['id'], (string) $user['email']);
        if ($viaRecovery) {
            $left = (new RecoveryCodes($app->db(), $app->site()->secret()))->remaining((int) $user['id']);
            Admin::audit($app, 'login.recovery_code', 'user', (string) $user['id'], 'left: ' . $left);
            Admin::flash($app, $left <= 2 ? 'warn' : 'info', tn('You signed in with a recovery code. {n} is left: make new ones in My account.', 'You signed in with a recovery code. {n} are left: make new ones in My account.', $left));
        }

        return Admin::back($app, $request, $app->url()->plain('/admin'));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function pendingUser(): ?array
    {
        $session = $this->app->session('admin');
        $id = (int) $session->get('pending_2fa', 0);
        $at = (int) $session->get('pending_2fa_at', 0);
        if ($id <= 0 || $at + 600 < $this->app->clock()->now()) {
            return null;
        }

        return $this->app->db()->row('SELECT * FROM {users} WHERE id = ? AND active = 1', [$id]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resetToken(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{20,128}$/', $token) !== 1) {
            return null;
        }

        $row = $this->app->db()->row("SELECT * FROM {tokens} WHERE kind = 'admin_reset' AND hash = ? AND used_at IS NULL AND expires_at > ?", [hash('sha256', $token), $this->app->clock()->sql()]);
        if ($row === null) {
            return null;
        }
        $user = $this->app->db()->row('SELECT id, email, role FROM {users} WHERE id = ? AND active = 1', [(int) $row['data']]);
        if ($user === null || $this->app->auth()->isPublicDemoIdentity($user) || strtolower((string) $user['email']) !== strtolower((string) $row['subject'])) {
            return null;
        }

        return $row;
    }

    private function sameOrigin(Request $request): void
    {
        if (!$request->isSameOrigin()) {
            throw HttpError::forbidden('Cross-site request refused');
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function screen(string $step, array $data, int $status = 200): Response
    {
        $titles = ['login' => t('Sign in'), 'code' => t('Verification code'), 'forgot' => t('Reset the password'), 'reset' => t('New password')];

        return Admin::page($this->app, 'core::admin/login', $data + ['step' => $step, 'error' => '', 'next' => ''], ['title' => $titles[$step], 'bare' => true], $status);
    }
}

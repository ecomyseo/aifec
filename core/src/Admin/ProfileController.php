<?php
/**
 * AIFEC — «Mi cuenta» del panel: los datos de la persona que ha entrado, su contraseña, la verificación en dos pasos
 * y sus sesiones abiertas. Cada apartado va en su pestaña y tiene su propio formulario.
 *
 * Reglas:
 *   - Cambiar el correo o apagar la verificación en dos pasos pide la contraseña actual.
 *   - Al cambiar la contraseña se cierran las demás sesiones de esa persona.
 *   - El secreto de la verificación en dos pasos solo se guarda (cifrado) cuando la persona demuestra, con un código,
 *     que su aplicación lo tiene bien. Mientras tanto vive 15 minutos en su sesión.
 *   - Al activarla se generan diez códigos de recuperación (RecoveryCodes). Se enseñan UNA vez, en la respuesta del propio
 *     envío (nunca pasan por la sesión ni por la base de datos en claro); después solo se sabe cuántos quedan. Generar
 *     códigos nuevos pide la contraseña y anula los anteriores.
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
use Aifec\Security\RecoveryCodes;
use Aifec\Security\Totp;

final class ProfileController
{
    /** Pestañas de la pantalla (clave con prefijo propio => etiqueta en inglés). */
    public const TABS = ['prdata' => 'My details', 'prpassword' => 'Password', 'prtwostep' => 'Two-step verification', 'prsessions' => 'Sessions'];

    private const MIN_PASSWORD = 10;
    private const PENDING_TTL = 900;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** @param array<string,string> $p */
    public function show(Request $request, array $p): Response
    {
        return $this->screen($request, [], $request->str('tab'));
    }

    /** @param array<string,string> $p */
    public function saveData(Request $request, array $p): Response
    {
        $app = $this->app;
        $user = $this->user();
        $name = trim($request->str('name'));
        $email = strtolower(trim($request->str('email')));
        $locale = $request->str('locale');
        if (!isset(self::locales()[$locale])) {
            $locale = (string) $user['locale'];
        }
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = t('Write your name.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            $errors['email'] = t('That email address is not valid.');
        } elseif ($email !== strtolower((string) $user['email'])) {
            if (!Passwords::verify((string) $request->input('current_password', ''), (string) $user['password'])) {
                $errors['current_password'] = t('To change the email, write your current password.');
            } elseif ((int) $app->db()->value('SELECT COUNT(*) FROM {users} WHERE email = ? AND id <> ?', [$email, (int) $user['id']]) > 0) {
                $errors['email'] = t('Another person already uses that email.');
            }
        }
        if ($errors !== []) {
            return $this->screen($request, $errors, 'prdata', ['name' => $name, 'email' => $email, 'locale' => $locale], 422);
        }
        $app->db()->update('users', ['name' => $name, 'email' => $email, 'locale' => $locale, 'updated_at' => $app->clock()->sql()], ['id' => (int) $user['id']]);
        $app->auth()->reset();
        Admin::audit($app, 'profile.update', 'user', (string) $user['id'], $email);
        Admin::flash($app, 'ok', t('Saved'));

        return $this->back('prdata');
    }

    /** @param array<string,string> $p */
    public function savePassword(Request $request, array $p): Response
    {
        $app = $this->app;
        $user = $this->user();
        $current = (string) $request->input('current_password', '');
        $new = (string) $request->input('password', '');
        $repeat = (string) $request->input('password2', '');
        $errors = [];
        if (!Passwords::verify($current, (string) $user['password'])) {
            $errors['current_password'] = t('That is not your current password.');
        }
        if (mb_strlen($new) < self::MIN_PASSWORD) {
            $errors['password'] = t('The password needs at least {n} characters.', ['n' => self::MIN_PASSWORD]);
        } elseif ($new !== $repeat) {
            $errors['password2'] = t('The two passwords are not the same.');
        } elseif ($new === $current) {
            $errors['password'] = t('The new password has to be different from the current one.');
        }
        if ($errors !== []) {
            return $this->screen($request, $errors, 'prpassword', [], 422);
        }
        $app->db()->update('users', ['password' => Passwords::hash($new), 'updated_at' => $app->clock()->sql()], ['id' => (int) $user['id']]);
        $this->closeAllAndStay((int) $user['id']);
        Admin::audit($app, 'profile.password', 'user', (string) $user['id'], (string) $user['email']);
        Admin::flash($app, 'ok', t('Password changed. Your other sessions have been closed.'));

        return $this->back('prpassword');
    }

    /** @param array<string,string> $p */
    public function startTwoStep(Request $request, array $p): Response
    {
        $user = $this->user();
        if (!empty($user['totp_secret'])) {
            return $this->back('prtwostep');
        }
        $session = $this->app->session('admin');
        $session->set('totp_pending', Totp::generateSecret());
        $session->set('totp_pending_at', $this->app->clock()->now());

        return $this->back('prtwostep');
    }

    /** @param array<string,string> $p */
    public function confirmTwoStep(Request $request, array $p): Response
    {
        $app = $this->app;
        $user = $this->user();
        $secret = $this->pendingSecret();
        if ($secret === null) {
            Admin::flash($app, 'warn', t('That took too long. Start again.'));

            return $this->back('prtwostep');
        }
        $code = (string) preg_replace('/\D+/', '', $request->str('code'));
        if (!Totp::verify($secret, $code, $app->clock()->now())) {
            return $this->screen($request, ['code' => t('That code is not valid. Check the clock of your phone and try the next one.')], 'prtwostep', [], 422);
        }
        $cipher = (new Crypto($app->site()->secret()))->encrypt($secret);
        $app->db()->update('users', ['totp_secret' => $cipher, 'updated_at' => $app->clock()->sql()], ['id' => (int) $user['id']]);
        $this->forgetPending();
        $app->auth()->reset();
        $codes = $this->recoveryCodes()->issue((int) $user['id'], $app->clock()->sql());
        Admin::audit($app, 'profile.two_step_on', 'user', (string) $user['id'], (string) $user['email']);
        Admin::flash($app, 'ok', t('Two-step verification is on. From now on you will be asked for a code when you sign in.'));

        // Los códigos van en esta misma respuesta, sin redirigir: así no se guardan en la sesión.
        return $this->screen($request, [], 'prtwostep', [], 200, $codes);
    }

    /** @param array<string,string> $p */
    public function newRecoveryCodes(Request $request, array $p): Response
    {
        $app = $this->app;
        $user = $this->user();
        if (empty($user['totp_secret'])) {
            return $this->back('prtwostep');
        }
        if (!Passwords::verify((string) $request->input('current_password', ''), (string) $user['password'])) {
            return $this->screen($request, ['codes_password' => t('That is not your current password.')], 'prtwostep', [], 422);
        }
        $codes = $this->recoveryCodes()->issue((int) $user['id'], $app->clock()->sql());
        Admin::audit($app, 'profile.recovery_codes', 'user', (string) $user['id'], (string) $user['email']);
        Admin::flash($app, 'ok', t('New recovery codes made. The old ones no longer work.'));

        return $this->screen($request, [], 'prtwostep', [], 200, $codes);
    }

    /** @param array<string,string> $p */
    public function cancelTwoStep(Request $request, array $p): Response
    {
        $this->forgetPending();

        return $this->back('prtwostep');
    }

    /** @param array<string,string> $p */
    public function disableTwoStep(Request $request, array $p): Response
    {
        $app = $this->app;
        $user = $this->user();
        if (!Passwords::verify((string) $request->input('current_password', ''), (string) $user['password'])) {
            return $this->screen($request, ['off_password' => t('That is not your current password.')], 'prtwostep', [], 422);
        }
        $app->db()->update('users', ['totp_secret' => null, 'updated_at' => $app->clock()->sql()], ['id' => (int) $user['id']]);
        $this->recoveryCodes()->clear((int) $user['id']);
        $app->auth()->reset();
        Admin::audit($app, 'profile.two_step_off', 'user', (string) $user['id'], (string) $user['email']);
        Admin::flash($app, 'ok', t('Two-step verification is off.'));

        return $this->back('prtwostep');
    }

    /** @param array<string,string> $p */
    public function closeSessions(Request $request, array $p): Response
    {
        $user = $this->user();
        $closed = $this->closeAllAndStay((int) $user['id']);
        Admin::audit($this->app, 'profile.sessions_closed', 'user', (string) $user['id'], (string) $closed);
        Admin::flash($this->app, 'ok', tn('{n} other session closed.', '{n} other sessions closed.', $closed));

        return $this->back('prsessions');
    }

    // ------------------------------------------------------------------ piezas

    /**
     * Idiomas en los que está el panel.
     *
     * @return array<string,string>
     */
    public static function locales(): array
    {
        return ['es' => 'Español', 'en' => 'English'];
    }

    /**
     * @return array<string,mixed>
     */
    private function user(): array
    {
        $admin = $this->app->auth()->admin();
        if ($admin === null) {
            throw HttpError::forbidden();
        }
        // La fila entera y fresca: aquí hacen falta la contraseña y el secreto, que la sesión no lleva encima.
        $row = $this->app->db()->row('SELECT * FROM {users} WHERE id = ? AND active = 1', [(int) $admin['id']]);
        if ($row === null) {
            throw HttpError::forbidden();
        }
        if ($this->app->request()->method !== 'GET' && $this->publicDemoIdentity($row)) {
            throw HttpError::forbidden(t('Public demo accounts have fixed credentials so other visitors can keep signing in.'));
        }

        return $row;
    }

    /** Las cuentas compartidas de fábrica no pueden bloquear a los siguientes visitantes. */
    private function publicDemoIdentity(array $user): bool
    {
        return $this->app->auth()->isPublicDemoIdentity($user);
    }

    private function recoveryCodes(): RecoveryCodes
    {
        return new RecoveryCodes($this->app->db(), $this->app->site()->secret());
    }

    /** El secreto pendiente de confirmar, si sigue vigente. */
    private function pendingSecret(): ?string
    {
        $session = $this->app->session('admin');
        $secret = $session->get('totp_pending');
        $at = (int) $session->get('totp_pending_at', 0);
        if (!is_string($secret) || $secret === '' || $this->app->clock()->now() - $at > self::PENDING_TTL) {
            if ($secret !== null) {
                $this->forgetPending();
            }

            return null;
        }

        return $secret;
    }

    private function forgetPending(): void
    {
        $session = $this->app->session('admin');
        $session->forget('totp_pending');
        $session->forget('totp_pending_at');
    }

    /** Cierra todas las sesiones de la persona y le abre una nueva en este navegador. Devuelve cuántas OTRAS había. */
    private function closeAllAndStay(int $userId): int
    {
        $db = $this->app->db();
        $total = (int) $db->value("SELECT COUNT(*) FROM {sessions} WHERE guard = 'admin' AND user_id = ?", [$userId]);
        $db->run("DELETE FROM {sessions} WHERE guard = 'admin' AND user_id = ?", [$userId]);
        $this->app->session('admin')->login($userId);

        return max(0, $total - 1);
    }

    private function back(string $tab): Response
    {
        return Response::redirect($this->app->url()->plain('/admin/profile', ['tab' => $tab]) . '#tab=' . $tab, 303);
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,string> $input  lo que escribió la persona, para no hacérselo repetir
     * @param string[]             $codes  códigos de recuperación recién hechos, para enseñarlos esta única vez
     */
    private function screen(Request $request, array $errors, string $tab, array $input = [], int $status = 200, array $codes = []): Response
    {
        $app = $this->app;
        $user = $this->user();
        $pending = empty($user['totp_secret']) ? $this->pendingSecret() : null;
        $now = $app->clock()->sql();
        $sessions = $app->db()->select("SELECT created_at, last_at FROM {sessions} WHERE guard = 'admin' AND user_id = ? AND expires_at > ? ORDER BY last_at DESC", [(int) $user['id'], $now]);

        return Admin::page($app, 'core::admin/profile', [
            'user' => $user,
            'input' => $input + ['name' => (string) $user['name'], 'email' => (string) $user['email'], 'locale' => (string) $user['locale']],
            'errors' => $errors,
            'tab' => isset(self::TABS[$tab]) ? $tab : 'prdata',
            'tabs' => self::TABS,
            'locales' => self::locales(),
            'twoStepOn' => !empty($user['totp_secret']),
            'pending' => $pending,
            'pendingUri' => $pending !== null ? Totp::uri($pending, (string) $user['email'], $app->settings()->str('store.name', 'AIFEC')) : '',
            'newCodes' => $codes,
            'codesLeft' => !empty($user['totp_secret']) ? $this->recoveryCodes()->remaining((int) $user['id']) : 0,
            'sessions' => is_array($sessions) ? $sessions : [],
            'minPassword' => self::MIN_PASSWORD,
            'publicIdentityReadOnly' => $this->publicDemoIdentity($user),
        ], ['title' => t('My account'), 'active' => '/admin/profile'], $status);
    }
}

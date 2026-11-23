<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Security;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Passkeys del panel: alta y borrado (con la sesión abierta) y entrada (sin sesión, por el botón de la pantalla de acceso).
 *
 * El desafío de cada operación vive en la sesión del panel y se gasta al usarlo. La entrada comparte el recuento de intentos
 * fallidos de la pantalla de acceso («login_attempts»): diez fallos desde una dirección en un cuarto de hora y espera.
 */
final class Passkeys
{
    private const MAX_PER_USER = 10;
    private const MAX_FAILURES_PER_IP = 10;
    private const WINDOW = 900;
    private const CHALLENGE_TTL = 300;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public static function enabled(App $app): bool
    {
        return $app->settings()->bool('security.passkeys', true) && function_exists('openssl_verify');
    }

    // ------------------------------------------------------------------ con la sesión abierta

    /**
     * POST /admin/passkeys/options: lo que el navegador necesita para crear una passkey.
     *
     * @param array<string,string> $p
     */
    public function registerOptions(Request $request, array $p): Response
    {
        $user = $this->user();
        if ((int) $this->app->db()->value('SELECT COUNT(*) FROM {passkeys} WHERE user_id = ?', [(int) $user['id']]) >= self::MAX_PER_USER) {
            return Response::json(['error' => t('You already have ten passkeys. Delete one to add another.')], 422);
        }
        $challenge = WebAuthn::challenge();
        $session = $this->app->session('admin');
        $session->set('passkey_reg', $challenge);
        $session->set('passkey_reg_at', $this->app->clock()->now());
        $exclude = [];
        foreach ($this->app->db()->select('SELECT credential_id FROM {passkeys} WHERE user_id = ?', [(int) $user['id']]) as $row) {
            $exclude[] = ['type' => 'public-key', 'id' => (string) $row['credential_id']];
        }

        return Response::json(['publicKey' => [
            'challenge' => $challenge,
            'rp' => ['name' => $this->app->settings()->str('store.name', 'AIFEC'), 'id' => $this->rpId()],
            'user' => ['id' => WebAuthn::b64url('af-user-' . (int) $user['id']), 'name' => (string) $user['email'], 'displayName' => (string) $user['name']],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
            'timeout' => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'excludeCredentials' => $exclude,
        ]]);
    }

    /**
     * POST /admin/passkeys/register: comprueba lo que devolvió el navegador y guarda la clave pública.
     *
     * @param array<string,string> $p
     */
    public function register(Request $request, array $p): Response
    {
        $user = $this->user();
        $session = $this->app->session('admin');
        $challenge = $this->takeChallenge($session, 'passkey_reg');
        if ($challenge === null) {
            return Response::json(['error' => t('The request expired. Try again.')], 422);
        }
        $body = $request->json();
        $response = isset($body['response']) && is_array($body['response']) ? $body['response'] : [];
        try {
            $found = WebAuthn::verifyRegistration(['id' => $body['id'] ?? '', 'clientDataJSON' => $response['clientDataJSON'] ?? '', 'attestationObject' => $response['attestationObject'] ?? ''], $challenge, $this->app->url()->origin(), $this->rpId());
        } catch (\InvalidArgumentException $e) {
            $this->app->log()->warning('passkey register: ' . $e->getMessage());

            return Response::json(['error' => t('That passkey could not be checked. Try again or use another device.')], 422);
        }
        if ((int) $this->app->db()->value('SELECT COUNT(*) FROM {passkeys} WHERE credential_id = ?', [$found['credentialId']]) > 0) {
            return Response::json(['error' => t('That passkey is already saved.')], 422);
        }
        $name = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($body['name'] ?? ''))));
        $name = $name !== '' ? mb_substr($name, 0, 80) : t('My passkey');
        $this->app->db()->insert('passkeys', [
            'user_id' => (int) $user['id'], 'credential_id' => $found['credentialId'], 'public_key' => $found['publicKey'],
            'sign_count' => $found['signCount'], 'name' => $name, 'created_at' => $this->app->clock()->sql(),
        ]);
        Admin::audit($this->app, 'passkey.add', 'user', (string) $user['id'], $name);

        return Response::json(['ok' => true]);
    }

    /**
     * POST /admin/passkeys/delete: borra una passkey propia.
     *
     * @param array<string,string> $p
     */
    public function delete(Request $request, array $p): Response
    {
        $user = $this->user();
        $id = $request->int('id');
        $row = $this->app->db()->row('SELECT id, name FROM {passkeys} WHERE id = ? AND user_id = ?', [$id, (int) $user['id']]);
        if ($row !== null) {
            $this->app->db()->delete('passkeys', ['id' => $id]);
            Admin::audit($this->app, 'passkey.delete', 'user', (string) $user['id'], (string) $row['name']);
            Admin::flash($this->app, 'ok', t('Passkey deleted.'));
        }

        return Response::redirect($this->app->url()->plain('/admin/security/passkeys'));
    }

    // ------------------------------------------------------------------ sin sesión: entrar

    /**
     * POST /admin/passkey/options: el desafío para entrar sin escribir el correo.
     *
     * @param array<string,string> $p
     */
    public function loginOptions(Request $request, array $p): Response
    {
        $this->guard($request);
        $challenge = WebAuthn::challenge();
        $session = $this->app->session('admin');
        $session->set('passkey_login', $challenge);
        $session->set('passkey_login_at', $this->app->clock()->now());

        return Response::json(['publicKey' => ['challenge' => $challenge, 'rpId' => $this->rpId(), 'timeout' => 60000, 'userVerification' => 'required']]);
    }

    /**
     * POST /admin/passkey/login: comprueba la firma y abre la sesión del panel.
     *
     * @param array<string,string> $p
     */
    public function login(Request $request, array $p): Response
    {
        $this->guard($request);
        $app = $this->app;
        $db = $app->db();
        $since = $app->clock()->sql($app->clock()->now() - self::WINDOW);
        if ((int) $db->value("SELECT COUNT(*) FROM {login_attempts} WHERE guard = 'admin' AND ip = ? AND ok = 0 AND created_at >= ?", [$request->ip, $since]) >= self::MAX_FAILURES_PER_IP) {
            return Response::json(['error' => t('Too many attempts. Wait fifteen minutes and try again.')], 429);
        }
        $session = $app->session('admin');
        $challenge = $this->takeChallenge($session, 'passkey_login');
        $body = $request->json();
        $response = isset($body['response']) && is_array($body['response']) ? $body['response'] : [];
        $fail = function (string $why) use ($app, $db, $request): Response {
            $app->log()->warning('passkey login: ' . $why);
            $db->insert('login_attempts', ['guard' => 'admin', 'identifier' => 'passkey', 'ip' => $request->ip, 'ok' => 0, 'created_at' => $app->clock()->sql()]);

            return Response::json(['error' => t('That passkey is not valid.')], 422);
        };
        if ($challenge === null) {
            return $fail('challenge missing or expired');
        }
        $credentialId = is_string($body['id'] ?? null) ? $body['id'] : '';
        $key = $credentialId !== '' ? $db->row('SELECT * FROM {passkeys} WHERE credential_id = ?', [$credentialId]) : null;
        $user = $key !== null ? $db->row('SELECT * FROM {users} WHERE id = ? AND active = 1', [(int) $key['user_id']]) : null;
        if ($key === null || $user === null) {
            return $fail('unknown credential or inactive user');
        }
        try {
            $count = WebAuthn::verifyAssertion([
                'clientDataJSON' => $response['clientDataJSON'] ?? '', 'authenticatorData' => $response['authenticatorData'] ?? '', 'signature' => $response['signature'] ?? '',
            ], $challenge, $app->url()->origin(), $this->rpId(), (string) $key['public_key'], (int) $key['sign_count']);
        } catch (\InvalidArgumentException $e) {
            return $fail($e->getMessage());
        }
        $db->update('passkeys', ['sign_count' => $count, 'last_used_at' => $app->clock()->sql()], ['id' => (int) $key['id']]);
        $session->login((int) $user['id']);
        $session->forget('pending_2fa');
        $session->forget('pending_2fa_at');
        $app->auth()->reset();
        $db->update('users', ['last_login_at' => $app->clock()->sql()], ['id' => (int) $user['id']]);
        $db->insert('login_attempts', ['guard' => 'admin', 'identifier' => (string) $user['email'], 'ip' => $request->ip, 'ok' => 1, 'created_at' => $app->clock()->sql()]);
        $db->run("DELETE FROM {login_attempts} WHERE guard IN ('admin', 'admin2') AND identifier = ? AND ok = 0", [(string) $user['email']]);
        Admin::audit($app, 'login.passkey', 'user', (string) $user['id'], (string) $user['email']);
        $next = Admin::back($app, $request, $app->url()->plain('/admin'));

        return Response::json(['ok' => true, 'redirect' => (string) ($next->headers['Location'] ?? $app->url()->plain('/admin'))]);
    }

    // ------------------------------------------------------------------ ayudas

    /** @return array<string,mixed> */
    private function user(): array
    {
        $admin = $this->app->auth()->admin();
        if ($admin === null) {
            throw HttpError::forbidden('Sign in to continue');
        }

        return $admin;
    }

    private function guard(Request $request): void
    {
        if (!self::enabled($this->app)) {
            throw HttpError::notFound();
        }
        if (!$request->isSameOrigin()) {
            throw HttpError::forbidden('Cross-site request refused');
        }
    }

    /** El desafío guardado, una sola vez y solo si no ha caducado. */
    private function takeChallenge(\Aifec\Kernel\Session $session, string $key): ?string
    {
        $value = $session->get($key);
        $at = (int) $session->get($key . '_at', 0);
        $session->forget($key);
        $session->forget($key . '_at');

        return is_string($value) && $value !== '' && $at + self::CHALLENGE_TTL >= $this->app->clock()->now() ? $value : null;
    }

    private function rpId(): string
    {
        return (string) parse_url($this->app->url()->origin(), PHP_URL_HOST);
    }
}

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Kernel;

use Aifec\Db\Db;

/**
 * Sesión guardada en la base de datos. Hay una por «guardia»: la del panel («admin») y la de la
 * tienda («customer»), cada una con su cookie.
 *
 * - No se crea hasta que hace falta escribir algo: un visitante que solo mira no tiene sesión ni cookie,
 *   y por eso sus páginas pueden salir de la caché.
 * - La cookie lleva una ficha aleatoria; en la base se guarda su resumen (quien lea la tabla no puede usarla).
 * - Al entrar y al salir se cambia de ficha.
 */
final class Session
{
    /** @var Db */
    private $db;

    /** @var Clock */
    private $clock;

    /** @var string */
    private $guard;

    /** @var string */
    private $cookieName;

    /** @var int segundos de vida sin actividad */
    private $ttl;

    /** @var string|null ficha en claro (la de la cookie) */
    private $token;

    /** @var array<string,mixed> */
    private $data = [];

    /** @var int|null */
    private $userId;

    /** @var bool existe ya en la base */
    private $stored = false;

    /** @var bool hay cambios sin guardar */
    private $dirty = false;

    /** @var bool hay que mandar la cookie (ficha nueva o borrada) */
    private $sendCookie = false;

    /** @var bool */
    private $destroyed = false;

    /** @var int última actividad guardada */
    private $lastAt = 0;

    public function __construct(Db $db, Clock $clock, string $guard, string $cookieName, int $ttl = 1209600)
    {
        $this->db = $db;
        $this->clock = $clock;
        $this->guard = $guard;
        $this->cookieName = $cookieName;
        $this->ttl = $ttl;
    }

    public function cookieName(): string
    {
        return $this->cookieName;
    }

    /** Carga la sesión de la cookie, si existe y sigue viva. */
    public function load(?string $token): void
    {
        if ($token === null || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return;
        }
        $row = $this->db->row(
            'SELECT user_id, data, last_at, expires_at FROM {sessions} WHERE id = ? AND guard = ?',
            [hash('sha256', $token), $this->guard]
        );
        if ($row === null || Clock::parse((string) $row['expires_at']) < $this->clock->now()) {
            return;
        }
        $data = json_decode((string) $row['data'], true);
        $this->token = $token;
        $this->data = is_array($data) ? $data : [];
        $this->userId = $row['user_id'] !== null ? (int) $row['user_id'] : null;
        $this->stored = true;
        $this->lastAt = Clock::parse((string) $row['last_at']);
    }

    public function isStarted(): bool
    {
        return $this->token !== null && !$this->destroyed;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    /**
     * @param mixed $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    /**
     * @param mixed $value
     */
    public function set(string $key, $value): void
    {
        $this->start();
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function forget(string $key): void
    {
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
    }

    /**
     * Mensaje para la petición siguiente (el aviso de «guardado» tras una redirección).
     *
     * @param mixed $value
     */
    public function flash(string $key, $value): void
    {
        $flash = $this->get('_flash', []);
        $flash = is_array($flash) ? $flash : [];
        $flash[$key] = $value;
        $this->set('_flash', $flash);
    }

    /**
     * Lee y borra los mensajes de un solo uso.
     *
     * @return array<string,mixed>
     */
    public function pullFlash(): array
    {
        $flash = $this->get('_flash', []);
        if (is_array($flash) && $flash !== []) {
            $this->forget('_flash');

            return $flash;
        }

        return [];
    }

    /** Identifica al usuario. Cambia la ficha para que una ficha robada antes de entrar no sirva. */
    public function login(int $userId): void
    {
        $this->regenerate();
        $this->userId = $userId;
        $this->dirty = true;
    }

    public function logout(): void
    {
        if ($this->token !== null && $this->stored) {
            $this->db->delete('sessions', ['id' => hash('sha256', $this->token)]);
        }
        $this->token = null;
        $this->data = [];
        $this->userId = null;
        $this->stored = false;
        $this->dirty = false;
        $this->destroyed = true;
        $this->sendCookie = true;
    }

    /** Secreto de la sesión del que cuelgan las fichas CSRF. */
    public function csrfSecret(): string
    {
        $secret = $this->get('_csrf');
        if (!is_string($secret) || $secret === '') {
            $secret = bin2hex(random_bytes(16));
            $this->set('_csrf', $secret);
        }

        return $secret;
    }

    /**
     * Guarda lo pendiente y pone (o quita) la cookie en la respuesta.
     */
    public function commit(Response $response, string $cookiePath, bool $secure, string $sameSite = 'Lax'): void
    {
        if ($this->destroyed) {
            if ($this->sendCookie) {
                $response->clearCookie($this->cookieName, $cookiePath);
            }

            return;
        }
        if ($this->token === null) {
            return;
        }
        $now = $this->clock->now();
        // Sin cambios, solo se refresca la caducidad de vez en cuando (no en cada petición).
        if (!$this->dirty && $this->stored && $now - $this->lastAt < 300) {
            return;
        }
        $row = [
            'id' => hash('sha256', $this->token),
            'guard' => $this->guard,
            'user_id' => $this->userId,
            'data' => json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'last_at' => $this->clock->sql($now),
            'expires_at' => $this->clock->sql($now + $this->ttl),
        ];
        if (!$this->stored) {
            $row['created_at'] = $this->clock->sql($now);
        }
        $this->db->upsert('sessions', $row, ['id']);
        $this->stored = true;
        $this->dirty = false;
        $this->lastAt = $now;
        if ($this->sendCookie) {
            $response->cookie($this->cookieName, $this->token, [
                'expires' => $now + $this->ttl,
                'path' => $cookiePath,
                'secure' => $secure,
                'httponly' => true,
                'samesite' => $sameSite,
            ]);
            $this->sendCookie = false;
        }
    }

    /** Borra las sesiones caducadas (tarea diaria). */
    public static function purge(Db $db, Clock $clock): int
    {
        return $db->run('DELETE FROM {sessions} WHERE expires_at < ?', [$clock->sql()]);
    }

    private function start(): void
    {
        if ($this->token === null) {
            $this->token = bin2hex(random_bytes(32));
            $this->stored = false;
            $this->sendCookie = true;
            $this->destroyed = false;
        }
    }

    private function regenerate(): void
    {
        if ($this->token !== null && $this->stored) {
            $this->db->delete('sessions', ['id' => hash('sha256', $this->token)]);
        }
        $this->token = bin2hex(random_bytes(32));
        $this->stored = false;
        $this->sendCookie = true;
        $this->destroyed = false;
    }
}

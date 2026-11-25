<?php
/**
 * AIFEC — servicio de clientes: cuentas, entrada, fichas de un solo uso, direcciones, consentimientos y derechos sobre los datos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Customers;

use Aifec\Kernel\App;
use Aifec\Security\Passwords;
use Aifec\Security\Random;

/**
 * Publicado como el servicio «customers»:  $app->service('customers')->login($correo, $contrasena);
 *
 * Seguridad:
 *  - Las contraseñas van con Passwords (también las de WordPress y PrestaShop antiguo) y se regraban al entrar.
 *  - Entrar tiene límite de intentos en login_attempts (guard «customer»): 5 fallos por cuenta y 20 por dirección en 15 minutos.
 *    Exista o no la cuenta se hace una comprobación de contraseña, para gastar el mismo tiempo.
 *  - Las fichas de un solo uso (restablecer, enlace de entrada, verificar el correo) van en la tabla tokens: se guarda
 *    el resumen SHA-256 y se consumen con un UPDATE condicionado mirando que cambie una fila.
 *  - Quien se registra con un correo que ya es de una cuenta NO se entera: el mensaje es neutro y el dueño recibe un aviso.
 *  - Un invitado que se registra conserva su fila y sus pedidos, pero queda SIN verificar hasta que pulse el enlace del correo.
 *
 * Acciones: customer.registered (array $cliente), customer.login (array $cliente), customer.anonymised (int $id, string $correoAnterior).
 * Filtro: customer.export (array $datos, int $id), para que otros módulos añadan lo suyo a la copia de datos de una persona.
 */
final class Customers
{
    public const MIN_PASSWORD = 8;
    public const MAX_PASSWORD = 4096;
    public const MAX_FAILS_PER_ACCOUNT = 5;
    public const MAX_FAILS_PER_IP = 20;
    public const LOCK_SECONDS = 900;
    public const RESET_SECONDS = 3600;
    public const MAGIC_SECONDS = 900;
    public const VERIFY_SECONDS = 604800;
    public const MAIL_BURST = 3;
    public const MAIL_WINDOW = 600;

    private const CONSENT_KINDS = ['terms', 'privacy', 'marketing', 'withdrawal_waiver'];

    private App $app;

    /** @var string|null resumen de contraseña de relleno (se calcula una vez por servicio) */
    private ?string $dummyHash = null;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ lectura

    /** El cliente que ha entrado (sin contraseña), o null. Un invitado o una cuenta apagada no cuentan. */
    public function current(): ?array
    {
        $id = $this->app->auth()->customerId();
        if ($id === null) {
            return null;
        }
        $row = $this->raw($id);
        if ($row === null || (int) $row['active'] !== 1 || (int) $row['guest'] === 1) {
            return null;
        }

        return $this->present($row);
    }

    public function find(int $id): ?array
    {
        $row = $this->raw($id);

        return $row !== null ? $this->present($row) : null;
    }

    public function findByEmail(string $email): ?array
    {
        $row = $this->rawByEmail($email);

        return $row !== null ? $this->present($row) : null;
    }

    public function isVerified(int $customerId): bool
    {
        $row = $this->raw($customerId);

        return $row !== null && isset($row['email_verified_at']) && (string) $row['email_verified_at'] !== '';
    }

    /**
     * El grupo (o el de por defecto): ['id', 'name', 'discount', 'tax_exempt'].
     *
     * @return array<string,mixed>
     */
    public function group(?int $id): array
    {
        $db = $this->app->db();
        $row = null;
        if ($id !== null && $id > 0) {
            $row = $db->row('SELECT * FROM {customer_groups} WHERE id = ?', [$id]);
        }
        if ($row === null) {
            $row = $db->row('SELECT * FROM {customer_groups} WHERE is_default = 1 ORDER BY id');
        }
        if ($row === null) {
            $row = $db->row('SELECT * FROM {customer_groups} ORDER BY id');
        }
        if ($row === null) {
            return ['id' => 0, 'name' => 'Customers', 'discount' => 0, 'tax_exempt' => false];
        }

        return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'discount' => (int) $row['discount'], 'tax_exempt' => (int) $row['tax_exempt'] === 1];
    }

    // ------------------------------------------------------------------ entrar y salir

    /**
     * Comprueba la contraseña y abre la sesión. Devuelve el id, o null si no vale (cuenta inexistente, apagada, invitado,
     * contraseña mala o demasiados intentos).
     */
    public function login(string $email, string $password): ?int
    {
        $app = $this->app;
        $db = $app->db();
        $email = strtolower(trim($email));
        $ip = $app->request()->ip;
        if ($this->lockedOut($email, $ip)) {
            return null;
        }
        $row = $email !== '' ? $this->rawByEmail($email) : null;
        $ok = false;
        if ($row !== null && (int) $row['guest'] === 0 && (int) $row['active'] === 1 && (string) $row['password'] !== '') {
            $ok = $this->passwordMatches($password, (string) $row['password']);
        } else {
            // Se gasta el mismo tiempo exista o no la cuenta.
            Passwords::verify($password, $this->dummyHash());
        }
        if (!$ok || $row === null) {
            $this->noteAttempt($email, $ip, false);

            return null;
        }
        $id = (int) $row['id'];
        $update = ['last_login_at' => $app->clock()->sql()];
        if (Passwords::needsRehash((string) $row['password'])) {
            $update['password'] = Passwords::hash($password);
        }
        $db->update('customers', $update, ['id' => $id]);
        $this->noteAttempt($email, $ip, true);
        // Tras entrar bien, los fallos anteriores de esa cuenta dejan de contar.
        $db->run("DELETE FROM {login_attempts} WHERE guard = 'customer' AND identifier = ? AND ok = 0", [$email]);
        $app->session('customer')->login($id);
        $customer = $this->find($id);
        if ($customer !== null) {
            $app->hooks()->fire('customer.login', $customer);
        }

        return $id;
    }

    /** Verdadero si esa cuenta, o la dirección de quien pregunta, ha agotado los intentos de entrada. */
    public function isLocked(string $email): bool
    {
        return $this->lockedOut(strtolower(trim($email)), $this->app->request()->ip);
    }

    public function logout(): void
    {
        $this->app->session('customer')->logout();
    }

    // ------------------------------------------------------------------ alta y cambios

    /**
     * Crea una cuenta. Datos: email, password, first_name, last_name, company, vat_id, phone, locale, marketing.
     * Si el correo es de un invitado, esa fila pasa a ser cuenta (conserva su id y sus pedidos) pero queda sin verificar.
     *
     * @param array<string,mixed> $data
     *
     * @throws \InvalidArgumentException con el motivo; si el correo ya es de una cuenta el mensaje no lo dice
     */
    public function register(array $data): int
    {
        $app = $this->app;
        $db = $app->db();
        if (!$app->settings()->bool('customers.allow_register', true)) {
            throw new \InvalidArgumentException('Registration is closed');
        }
        $email = Rules::email(Rules::str(isset($data['email']) ? $data['email'] : ''));
        if ($email === '') {
            throw new \InvalidArgumentException('The email address is not valid');
        }
        $password = isset($data['password']) && is_string($data['password']) ? $data['password'] : '';
        $this->checkPassword($password);
        $cols = $this->profile($data, true);
        $marketing = Rules::bool(isset($data['marketing']) ? $data['marketing'] : false);
        $now = $app->clock()->sql();
        $hash = Passwords::hash($password);

        $existing = $this->rawByEmail($email);
        $id = 0;
        if ($existing === null) {
            try {
                $id = $db->insert('customers', $cols + [
                    'email' => $email, 'password' => $hash, 'guest' => 0, 'group_id' => $this->defaultGroupId(),
                    'locale' => $app->lang(), 'active' => 1, 'marketing' => $marketing ? 1 : 0, 'marketing_at' => $marketing ? $now : null,
                    'orders_count' => 0, 'total_spent' => 0, 'source' => 'register', 'created_at' => $now, 'updated_at' => $now,
                ]);
            } catch (\Throwable $e) {
                // Otra petición se adelantó con el mismo correo: se sigue como si ya existiera.
                $existing = $this->rawByEmail($email);
                if ($existing === null) {
                    throw $e;
                }
            }
        }
        if ($id === 0 && $existing !== null) {
            // Un invitado (alguien que ya compró con ese correo) tampoco se toma: sin demostrar que el correo es suyo, nadie le pone
            // contraseña ni sesión a su ficha, que lleva sus pedidos y sus datos. La reclama desde «he olvidado mi contraseña».
            $this->refuseExisting($existing);
        }
        if ($marketing) {
            $this->recordConsent($id, $email, 'marketing', true, '', 'register');
        }
        $row = $this->raw($id);
        if ($row !== null) {
            $token = $this->issueToken('customer_verify', $email, $id, self::VERIFY_SECONDS);
            $this->mail('customer_welcome', $row, [
                'verify_url' => $this->link('account.verify', 'verificar', $token),
                'account_url' => $this->accountUrl(),
                'days' => (int) (self::VERIFY_SECONDS / 86400),
            ]);
        }
        $customer = $this->find($id);
        if ($customer !== null) {
            $app->hooks()->fire('customer.registered', $customer);
        }

        return $id;
    }

    /**
     * Cliente sin cuenta para un pedido. Si el correo es de una cuenta, devuelve su id (el pedido se le asocia) sin
     * iniciar sesión; si es de un invitado, reutiliza su fila (y completa lo que falte); si no, crea una.
     *
     * @param array<string,mixed> $data first_name, last_name, company, vat_id, phone, locale, marketing
     */
    public function ensureGuest(string $email, array $data = []): int
    {
        $app = $this->app;
        $db = $app->db();
        $email = Rules::email($email);
        if ($email === '') {
            throw new \InvalidArgumentException('The email address is not valid');
        }
        $existing = $this->rawByEmail($email);
        if ($existing !== null && (int) $existing['guest'] === 0) {
            return (int) $existing['id'];
        }
        $cols = $this->profile($data, false);
        $marketing = Rules::bool(isset($data['marketing']) ? $data['marketing'] : false);
        $now = $app->clock()->sql();
        $id = 0;
        if ($existing === null) {
            try {
                $id = $db->insert('customers', $cols + [
                    'email' => $email, 'password' => null, 'guest' => 1, 'group_id' => $this->defaultGroupId(), 'locale' => $app->lang(),
                    'active' => 1, 'marketing' => $marketing ? 1 : 0, 'marketing_at' => $marketing ? $now : null,
                    'orders_count' => 0, 'total_spent' => 0, 'source' => 'checkout', 'created_at' => $now, 'updated_at' => $now,
                ]);
            } catch (\Throwable $e) {
                $existing = $this->rawByEmail($email);
                if ($existing === null) {
                    throw $e;
                }
                if ((int) $existing['guest'] === 0) {
                    return (int) $existing['id'];
                }
            }
        }
        if ($id === 0 && $existing !== null) {
            $id = (int) $existing['id'];
            // Solo se rellena lo que trae valor: un pedido nuevo sin teléfono no borra el que ya había.
            $set = array_filter($cols, static function ($value): bool {
                return $value !== null;
            });
            if ($marketing) {
                $set['marketing'] = 1;
                $set['marketing_at'] = $now;
            }
            if ($set !== []) {
                $db->update('customers', $set + ['updated_at' => $now], ['id' => $id]);
            }
        }
        if ($marketing) {
            $this->recordConsent($id, $email, 'marketing', true, '', 'checkout');
        }

        return $id;
    }

    /**
     * Cambia datos de un cliente: email, password, first_name, last_name, company, vat_id, phone, locale, group_id, active,
     * marketing, notes. Un correo nuevo deja la cuenta sin verificar y manda el enlace de verificación al nuevo.
     *
     * @param array<string,mixed> $data
     *
     * @throws \InvalidArgumentException
     */
    public function update(int $id, array $data): void
    {
        $app = $this->app;
        $db = $app->db();
        $row = $this->raw($id);
        if ($row === null) {
            throw new \InvalidArgumentException('Unknown customer');
        }
        $set = $this->profile($data, true);
        $emailChanged = false;
        if (array_key_exists('email', $data)) {
            $email = Rules::email(Rules::str($data['email']));
            if ($email === '') {
                throw new \InvalidArgumentException('The email address is not valid');
            }
            if ($email !== (string) $row['email']) {
                if ($db->value('SELECT id FROM {customers} WHERE email = ? AND id <> ?', [$email, $id]) !== null) {
                    throw new \InvalidArgumentException('We could not change the email address');
                }
                $set['email'] = $email;
                $set['email_verified_at'] = null;
                $emailChanged = true;
            }
        }
        if (array_key_exists('password', $data)) {
            $password = is_string($data['password']) ? $data['password'] : '';
            $this->checkPassword($password);
            $set['password'] = Passwords::hash($password);
            $set['guest'] = 0;
        }
        if (array_key_exists('group_id', $data)) {
            $group = $data['group_id'] === null || $data['group_id'] === '' ? null : (int) $data['group_id'];
            if ($group !== null && $db->value('SELECT id FROM {customer_groups} WHERE id = ?', [$group]) === null) {
                throw new \InvalidArgumentException('Unknown group');
            }
            $set['group_id'] = $group;
        }
        if (array_key_exists('active', $data)) {
            $set['active'] = Rules::bool($data['active']) ? 1 : 0;
        }
        if (array_key_exists('notes', $data)) {
            $notes = trim(Rules::str($data['notes']));
            $set['notes'] = $notes !== '' ? $notes : null;
        }
        $marketingChange = null;
        if (array_key_exists('marketing', $data)) {
            $want = Rules::bool($data['marketing']);
            if ($want !== ((int) $row['marketing'] === 1)) {
                $marketingChange = $want;
                $set['marketing'] = $want ? 1 : 0;
                $set['marketing_at'] = $want ? $app->clock()->sql() : null;
            }
        }
        if ($set === []) {
            return;
        }
        $db->update('customers', $set + ['updated_at' => $app->clock()->sql()], ['id' => $id]);
        if ($marketingChange !== null) {
            $this->recordConsent($id, isset($set['email']) ? (string) $set['email'] : (string) $row['email'], 'marketing', $marketingChange, '', 'account');
        }
        if (isset($set['active']) && $set['active'] === 0) {
            $db->delete('sessions', ['guard' => 'customer', 'user_id' => $id]);
        }
        if ($emailChanged && (int) $row['guest'] === 0) {
            $this->sendVerification($id);
        }
    }

    // ------------------------------------------------------------------ direcciones

    /**
     * Las direcciones de un cliente, las de por defecto primero.
     *
     * @return array<int,array<string,mixed>>
     */
    public function addresses(int $customerId): array
    {
        $rows = $this->app->db()->select(
            'SELECT * FROM {addresses} WHERE customer_id = ? ORDER BY default_shipping DESC, default_billing DESC, id ASC',
            [$customerId]
        );

        return array_map([$this, 'presentAddress'], $rows);
    }

    /** Una dirección, solo si es de ese cliente. */
    public function address(int $id, int $customerId): ?array
    {
        $row = $this->app->db()->row('SELECT * FROM {addresses} WHERE id = ? AND customer_id = ?', [$id, $customerId]);

        return $row !== null ? $this->presentAddress($row) : null;
    }

    /**
     * Alta o cambio de una dirección. Se valida entera (país de dos letras, código postal del país, teléfono, NIF) y la
     * provincia de España se deduce del código postal si no viene. Solo hay una de envío y una de facturación por
     * defecto; la primera dirección de un cliente lo es de las dos.
     *
     * @param array<string,mixed> $data first_name, last_name, company, vat_id, phone, line1, line2, postcode, city, region,
     *                                  country, label, default_shipping, default_billing
     *
     * @throws \InvalidArgumentException con el motivo, o si la dirección es de otro cliente
     */
    public function saveAddress(int $customerId, array $data, ?int $addressId = null): int
    {
        $app = $this->app;
        $db = $app->db();
        if ($this->raw($customerId) === null) {
            throw new \InvalidArgumentException('Unknown customer');
        }
        $current = null;
        if ($addressId !== null) {
            $current = $this->address($addressId, $customerId);
            if ($current === null) {
                throw new \InvalidArgumentException('Unknown address');
            }
        }
        $cols = Rules::address($current !== null ? $data + $current : $data);
        $others = (int) $db->value('SELECT COUNT(*) FROM {addresses} WHERE customer_id = ? AND id <> ?', [$customerId, $addressId !== null ? $addressId : 0]);
        $shipping = array_key_exists('default_shipping', $data) ? Rules::bool($data['default_shipping']) : ($current !== null && (int) $current['default_shipping'] === 1);
        $billing = array_key_exists('default_billing', $data) ? Rules::bool($data['default_billing']) : ($current !== null && (int) $current['default_billing'] === 1);
        // Quien ya es la dirección por defecto lo sigue siendo: el valor por defecto cambia al marcar OTRA.
        $shipping = $shipping || $others === 0 || ($current !== null && (int) $current['default_shipping'] === 1);
        $billing = $billing || $others === 0 || ($current !== null && (int) $current['default_billing'] === 1);
        $now = $app->clock()->sql();
        if ($addressId === null) {
            // La misma dirección otra vez (la de la caja y la que se guarda luego en la cuenta) no se duplica: se pone al día la que hay
            // (14/11/2026: dos tarjetas «Casa» idénticas y dos opciones iguales en la caja).
            foreach ($db->select('SELECT * FROM {addresses} WHERE customer_id = ?', [$customerId]) as $row) {
                $same = true;
                foreach (['first_name', 'last_name', 'line1', 'line2', 'postcode', 'city', 'country'] as $key) {
                    $a = isset($row[$key]) ? mb_strtolower(trim((string) $row[$key]), 'UTF-8') : '';
                    $b = isset($cols[$key]) ? mb_strtolower(trim((string) $cols[$key]), 'UTF-8') : '';
                    if ($a !== $b) {
                        $same = false;
                        break;
                    }
                }
                if ($same) {
                    $addressId = (int) $row['id'];
                    break;
                }
            }
        }

        return (int) $db->transaction(static function ($db) use ($customerId, $addressId, $cols, $shipping, $billing, $now): int {
            $flags = ['default_shipping' => $shipping ? 1 : 0, 'default_billing' => $billing ? 1 : 0];
            if ($addressId === null) {
                $id = $db->insert('addresses', ['customer_id' => $customerId] + $cols + $flags + ['created_at' => $now, 'updated_at' => $now]);
            } else {
                $id = $addressId;
                $db->update('addresses', $cols + $flags + ['updated_at' => $now], ['id' => $id, 'customer_id' => $customerId]);
            }
            if ($shipping) {
                $db->run('UPDATE {addresses} SET default_shipping = 0 WHERE customer_id = ? AND id <> ?', [$customerId, $id]);
            }
            if ($billing) {
                $db->run('UPDATE {addresses} SET default_billing = 0 WHERE customer_id = ? AND id <> ?', [$customerId, $id]);
            }

            return $id;
        });
    }

    /**
     * Borra una dirección. Si era la de por defecto, la más reciente de las que quedan ocupa su sitio.
     *
     * @throws \InvalidArgumentException si no existe o es de otro cliente
     */
    public function deleteAddress(int $id, int $customerId): void
    {
        $db = $this->app->db();
        $address = $this->address($id, $customerId);
        if ($address === null) {
            throw new \InvalidArgumentException('Unknown address');
        }
        $db->transaction(static function ($db) use ($id, $customerId, $address): void {
            $db->delete('addresses', ['id' => $id, 'customer_id' => $customerId]);
            foreach (['default_shipping', 'default_billing'] as $flag) {
                if ((int) $address[$flag] !== 1) {
                    continue;
                }
                $next = $db->value('SELECT id FROM {addresses} WHERE customer_id = ? ORDER BY id DESC', [$customerId]);
                if ($next !== null) {
                    $db->update('addresses', [$flag => 1], ['id' => (int) $next]);
                }
            }
        });
    }

    // ------------------------------------------------------------------ consentimientos

    /**
     * Apunta un consentimiento (qué aceptó o retiró, cuándo, desde qué dirección y con qué versión del texto).
     * Si es el de publicidad y hay cliente, también actualiza su casilla.
     *
     * @throws \InvalidArgumentException si el tipo no existe o no hay a quién atribuirlo
     */
    public function consent(?int $customerId, string $email, string $kind, bool $granted, string $version = '', string $context = ''): void
    {
        if (!in_array($kind, self::CONSENT_KINDS, true)) {
            throw new \InvalidArgumentException('Unknown consent kind');
        }
        $clean = Rules::email($email);
        if ($customerId === null && $clean === '') {
            throw new \InvalidArgumentException('A consent needs a customer or an email address');
        }
        $this->recordConsent($customerId, $clean, $kind, $granted, $version, $context);
        if ($kind === 'marketing' && $customerId !== null) {
            $now = $this->app->clock()->sql();
            $this->app->db()->update('customers', ['marketing' => $granted ? 1 : 0, 'marketing_at' => $granted ? $now : null, 'updated_at' => $now], ['id' => $customerId]);
        }
    }

    // ------------------------------------------------------------------ restablecer, enlace de entrada y verificación

    /** Siempre contesta igual, exista o no la cuenta. Si existe, manda «customer_reset» con un enlace de un solo uso (1 hora). */
    public function requestReset(string $email): void
    {
        $row = $this->accountForMail($email, 'customer_reset', true);
        if ($row === null) {
            return;
        }
        $token = $this->issueToken('customer_reset', (string) $row['email'], (int) $row['id'], self::RESET_SECONDS);
        $this->mail('customer_reset', $row, [
            'url' => $this->link('account.reset', 'restablecer', $token),
            'minutes' => (int) (self::RESET_SECONDS / 60),
        ]);
    }

    /**
     * Cambia la contraseña con la ficha del correo; la ficha se gasta y solo vale una vez.
     * Quien tuviera sesión abierta con la contraseña anterior se queda fuera, y los fallos de entrada dejan de contar.
     *
     * @throws \InvalidArgumentException si la contraseña no vale (la ficha no se gasta)
     */
    public function resetPassword(string $token, string $password): bool
    {
        $this->checkPassword($password);
        $row = $this->findToken('customer_reset', $token);
        if ($row === null) {
            return false;
        }
        $customer = $this->raw((int) $row['data']);
        if ($customer === null || (int) $customer['active'] !== 1 || (string) $customer['email'] !== (string) $row['subject']) {
            return false;
        }
        if (!$this->consumeToken((int) $row['id'])) {
            return false;
        }
        $db = $this->app->db();
        $id = (int) $customer['id'];
        $now = $this->app->clock()->sql();
        // Elegir la contraseña desde el enlace del correo demuestra que el correo es suyo: un invitado se convierte en cuenta.
        $update = ['password' => Passwords::hash($password), 'guest' => 0, 'updated_at' => $now];
        if ((string) $customer['email_verified_at'] === '') {
            $update['email_verified_at'] = $now;
        }
        $db->update('customers', $update, ['id' => $id]);
        $db->delete('sessions', ['guard' => 'customer', 'user_id' => $id]);
        $db->run("DELETE FROM {login_attempts} WHERE guard = 'customer' AND identifier = ? AND ok = 0", [(string) $customer['email']]);

        return true;
    }

    /** Si customers.magic_link está encendido y la cuenta existe: manda «customer_magic_link», válido 15 minutos y de un solo uso. */
    public function requestMagicLink(string $email): void
    {
        if (!$this->app->settings()->bool('customers.magic_link', true)) {
            return;
        }
        $row = $this->accountForMail($email, 'customer_magic');
        if ($row === null) {
            return;
        }
        $token = $this->issueToken('customer_magic', (string) $row['email'], (int) $row['id'], self::MAGIC_SECONDS);
        $this->mail('customer_magic_link', $row, [
            'url' => $this->link('account.magic', 'entrar', $token),
            'minutes' => (int) (self::MAGIC_SECONDS / 60),
        ]);
    }

    /** Entra con la ficha del enlace. Devuelve el id, o null si no vale, caducó, ya se usó o el enlace está apagado. */
    public function loginWithToken(string $token): ?int
    {
        if (!$this->app->settings()->bool('customers.magic_link', true)) {
            return null;
        }
        $row = $this->findToken('customer_magic', $token);
        if ($row === null) {
            return null;
        }
        $customer = $this->raw((int) $row['data']);
        if ($customer === null || (int) $customer['guest'] === 1 || (int) $customer['active'] !== 1 || (string) $customer['email'] !== (string) $row['subject']) {
            return null;
        }
        if (!$this->consumeToken((int) $row['id'])) {
            return null;
        }
        $id = (int) $customer['id'];
        $this->app->db()->update('customers', ['last_login_at' => $this->app->clock()->sql()], ['id' => $id]);
        $this->app->session('customer')->login($id);
        $found = $this->find($id);
        if ($found !== null) {
            $this->app->hooks()->fire('customer.login', $found);
        }

        return $id;
    }

    /** Manda «customer_verify» con el enlace para confirmar el correo. No hace nada si ya está verificado o no es una cuenta. */
    public function sendVerification(int $customerId): void
    {
        $row = $this->raw($customerId);
        if ($row === null || (int) $row['guest'] === 1 || (int) $row['active'] !== 1 || (string) $row['email_verified_at'] !== '') {
            return;
        }
        if ($this->recentTokens('customer_verify', (string) $row['email']) >= self::MAIL_BURST) {
            return;
        }
        $token = $this->issueToken('customer_verify', (string) $row['email'], $customerId, self::VERIFY_SECONDS);
        $this->mail('customer_verify', $row, [
            'url' => $this->link('account.verify', 'verificar', $token),
            'days' => (int) (self::VERIFY_SECONDS / 86400),
        ]);
    }

    /** Confirma el correo con la ficha del enlace. */
    public function verify(string $token): bool
    {
        $row = $this->findToken('customer_verify', $token);
        if ($row === null) {
            return false;
        }
        $customer = $this->raw((int) $row['data']);
        // Si el correo cambió después de pedir el enlace, la ficha ya no vale.
        if ($customer === null || (string) $customer['email'] !== (string) $row['subject']) {
            return false;
        }
        if (!$this->consumeToken((int) $row['id'])) {
            return false;
        }
        $now = $this->app->clock()->sql();
        $this->app->db()->run('UPDATE {customers} SET email_verified_at = ?, updated_at = ? WHERE id = ? AND email_verified_at IS NULL', [$now, $now, (int) $customer['id']]);

        return true;
    }

    // ------------------------------------------------------------------ derechos sobre los datos

    /**
     * Todo lo que se guarda de esa persona, para entregárselo: el cliente (sin contraseña), sus direcciones, sus
     * consentimientos y sus desistimientos. El filtro «customer.export» deja a otros módulos añadir lo suyo.
     *
     * @return array<string,mixed>
     *
     * @throws \InvalidArgumentException si el cliente no existe
     */
    public function exportData(int $customerId): array
    {
        $db = $this->app->db();
        $row = $this->raw($customerId);
        if ($row === null) {
            throw new \InvalidArgumentException('Unknown customer');
        }
        $consents = $db->select(
            'SELECT * FROM {consents} WHERE customer_id = ? OR (customer_id IS NULL AND email = ?) ORDER BY id',
            [$customerId, (string) $row['email']]
        );
        $customer = $this->present($row);
        unset($customer['has_password']);
        $data = [
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z', $this->app->clock()->now()),
            'customer' => $customer,
            'addresses' => $this->addresses($customerId),
            'consents' => $consents,
            'withdrawals' => $db->select('SELECT * FROM {withdrawals} WHERE customer_id = ? ORDER BY id', [$customerId]),
        ];
        $filtered = $this->app->hooks()->apply('customer.export', $data, $customerId);

        return is_array($filtered) ? $filtered : $data;
    }

    /**
     * Sustituye los datos personales por marcadores, borra sus direcciones, fichas y sesiones, y conserva el id (los pedidos
     * siguen apuntando a él). Dispara «customer.anonymised» (id y el correo que tenía) para que cada módulo limpie lo suyo.
     *
     * @throws \InvalidArgumentException si el cliente no existe
     */
    public function anonymise(int $customerId): void
    {
        $app = $this->app;
        $row = $this->raw($customerId);
        if ($row === null) {
            throw new \InvalidArgumentException('Unknown customer');
        }
        $old = (string) $row['email'];
        $marker = 'anonymised-' . $customerId . '@anonymised.invalid';
        $now = $app->clock()->sql();
        $app->db()->transaction(static function ($db) use ($customerId, $old, $marker, $now): void {
            $db->update('customers', [
                'email' => $marker, 'password' => null, 'guest' => 1, 'first_name' => 'Anonymised', 'last_name' => null, 'company' => null,
                'vat_id' => null, 'phone' => null, 'active' => 0, 'marketing' => 0, 'marketing_at' => null, 'notes' => null, 'legacy_id' => null,
                'email_verified_at' => null, 'last_login_at' => null, 'updated_at' => $now,
            ], ['id' => $customerId]);
            $db->delete('addresses', ['customer_id' => $customerId]);
            $db->delete('sessions', ['guard' => 'customer', 'user_id' => $customerId]);
            // La prueba de cada consentimiento se queda, pero sin el correo ni la dirección IP de la persona.
            $db->run('UPDATE {consents} SET email = ?, ip = NULL WHERE customer_id = ? OR (customer_id IS NULL AND email = ?)', [$marker, $customerId, $old]);
            $db->run("DELETE FROM {tokens} WHERE kind IN ('customer_reset', 'customer_magic', 'customer_verify', 'customer_exists') AND subject = ?", [$old]);
            $db->run("DELETE FROM {login_attempts} WHERE guard = 'customer' AND identifier = ?", [$old]);
        });
        if ($app->auth()->customerId() === $customerId) {
            $app->session('customer')->logout();
        }
        $app->hooks()->fire('customer.anonymised', $customerId, $old);
    }

    // ------------------------------------------------------------------ piezas

    /** @return array<string,mixed>|null */
    private function raw(int $id): ?array
    {
        return $this->app->db()->row('SELECT * FROM {customers} WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    private function rawByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));

        return $email !== '' ? $this->app->db()->row('SELECT * FROM {customers} WHERE email = ?', [$email]) : null;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        $row['id'] = (int) $row['id'];
        foreach (['guest', 'active', 'marketing', 'orders_count', 'total_spent'] as $key) {
            $row[$key] = isset($row[$key]) ? (int) $row[$key] : 0;
        }
        $row['group_id'] = isset($row['group_id']) && $row['group_id'] !== null && $row['group_id'] !== '' ? (int) $row['group_id'] : null;
        $row['has_password'] = isset($row['password']) && (string) $row['password'] !== '';
        unset($row['password']);
        $row['verified'] = isset($row['email_verified_at']) && (string) $row['email_verified_at'] !== '';
        $row['name'] = trim(Rules::str(isset($row['first_name']) ? $row['first_name'] : '') . ' ' . Rules::str(isset($row['last_name']) ? $row['last_name'] : ''));

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function presentAddress(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['customer_id'] = (int) $row['customer_id'];
        $row['default_shipping'] = (int) $row['default_shipping'];
        $row['default_billing'] = (int) $row['default_billing'];

        return $row;
    }

    /**
     * Las columnas de perfil que vengan en $data, validadas. Con $strict un teléfono o un NIF malos lanzan; sin él se descartan.
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function profile(array $data, bool $strict): array
    {
        $cols = [];
        if (array_key_exists('first_name', $data) || array_key_exists('last_name', $data)) {
            $names = Rules::names(Rules::str(isset($data['first_name']) ? $data['first_name'] : ''), Rules::str(isset($data['last_name']) ? $data['last_name'] : ''));
            if (array_key_exists('first_name', $data)) {
                $cols['first_name'] = $names['first_name'] !== '' ? $names['first_name'] : null;
            }
            if (array_key_exists('last_name', $data)) {
                $cols['last_name'] = $names['last_name'] !== '' ? $names['last_name'] : null;
            }
        }
        if (array_key_exists('company', $data)) {
            $company = Rules::text($data['company'], 160);
            $cols['company'] = $company !== '' ? $company : null;
        }
        if (array_key_exists('vat_id', $data)) {
            $vat = Rules::vat(Rules::str($data['vat_id']));
            if ($vat === null) {
                if ($strict) {
                    throw new \InvalidArgumentException('The tax ID is not valid');
                }
            } else {
                $cols['vat_id'] = $vat !== '' ? $vat : null;
            }
        }
        if (array_key_exists('phone', $data)) {
            $phone = Rules::phone(Rules::str($data['phone']));
            if ($phone === null) {
                if ($strict) {
                    throw new \InvalidArgumentException('The phone number is not valid');
                }
            } else {
                $cols['phone'] = $phone !== '' ? $phone : null;
            }
        }
        if (array_key_exists('locale', $data)) {
            $cols['locale'] = Rules::locale(Rules::str($data['locale']), $this->app->lang());
        }

        return $cols;
    }

    private function checkPassword(string $password): void
    {
        if (mb_strlen($password, 'UTF-8') < self::MIN_PASSWORD) {
            throw new \InvalidArgumentException('The password must have at least ' . self::MIN_PASSWORD . ' characters');
        }
        if (strlen($password) > self::MAX_PASSWORD) {
            throw new \InvalidArgumentException('The password is too long');
        }
    }

    private function passwordMatches(string $password, string $hash): bool
    {
        if (Passwords::verify($password, $hash)) {
            return true;
        }
        $key = $this->app->secret('customers.legacy_cookie_key');

        return $key !== '' && Passwords::verifyPrestashopLegacy($password, $hash, $key);
    }

    private function dummyHash(): string
    {
        if ($this->dummyHash === null) {
            $this->dummyHash = Passwords::hash(Random::token(8));
        }

        return $this->dummyHash;
    }

    private function lockedOut(string $email, string $ip): bool
    {
        $db = $this->app->db();
        $since = $this->app->clock()->sql($this->app->clock()->now() - self::LOCK_SECONDS);
        $byAccount = (int) $db->value("SELECT COUNT(*) FROM {login_attempts} WHERE guard = 'customer' AND identifier = ? AND ok = 0 AND created_at >= ?", [substr($email, 0, 190), $since]);
        if ($byAccount >= self::MAX_FAILS_PER_ACCOUNT) {
            return true;
        }
        $byIp = (int) $db->value("SELECT COUNT(*) FROM {login_attempts} WHERE guard = 'customer' AND ip = ? AND ok = 0 AND created_at >= ?", [$ip, $since]);

        return $byIp >= self::MAX_FAILS_PER_IP;
    }

    private function noteAttempt(string $email, string $ip, bool $ok): void
    {
        $this->app->db()->insert('login_attempts', ['guard' => 'customer', 'identifier' => substr($email, 0, 190), 'ip' => $ip, 'ok' => $ok ? 1 : 0, 'created_at' => $this->app->clock()->sql()]);
    }

    private function defaultGroupId(): ?int
    {
        $id = $this->app->db()->value('SELECT id FROM {customer_groups} WHERE is_default = 1 ORDER BY id');

        return $id !== null ? (int) $id : null;
    }

    private function recordConsent(?int $customerId, string $email, string $kind, bool $granted, string $version, string $context): void
    {
        $this->app->db()->insert('consents', [
            'customer_id' => $customerId,
            'email' => $email !== '' ? $email : null,
            'kind' => $kind,
            'granted' => $granted ? 1 : 0,
            'version' => $version !== '' ? substr($version, 0, 40) : null,
            'context' => $context !== '' ? substr($context, 0, 60) : null,
            'ip' => substr($this->app->request()->ip, 0, 45),
            'created_at' => $this->app->clock()->sql(),
        ]);
    }

    // ------------------------------------------------------------------ fichas de un solo uso

    private function issueToken(string $kind, string $email, int $customerId, int $seconds): string
    {
        $token = Random::token(32);
        $clock = $this->app->clock();
        $this->app->db()->insert('tokens', [
            'kind' => $kind, 'subject' => $email, 'hash' => hash('sha256', $token), 'data' => (string) $customerId,
            'expires_at' => $clock->sql($clock->now() + $seconds), 'created_at' => $clock->sql(),
        ]);

        return $token;
    }

    /** @return array<string,mixed>|null la fila de una ficha sin usar y sin caducar */
    private function findToken(string $kind, string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{20,128}$/D', $token) !== 1) {
            return null;
        }

        return $this->app->db()->row(
            'SELECT * FROM {tokens} WHERE kind = ? AND hash = ? AND used_at IS NULL AND expires_at > ?',
            [$kind, hash('sha256', $token), $this->app->clock()->sql()]
        );
    }

    /** Gasta la ficha. Solo la primera petición lo consigue: se mira que cambie una fila. */
    private function consumeToken(int $id): bool
    {
        return $this->app->db()->run('UPDATE {tokens} SET used_at = ? WHERE id = ? AND used_at IS NULL', [$this->app->clock()->sql(), $id]) === 1;
    }

    private function recentTokens(string $kind, string $email): int
    {
        $since = $this->app->clock()->sql($this->app->clock()->now() - self::MAIL_WINDOW);

        return (int) $this->app->db()->value('SELECT COUNT(*) FROM {tokens} WHERE kind = ? AND subject = ? AND created_at >= ?', [$kind, $email, $since]);
    }

    /**
     * La cuenta (con contraseña posible, activa) a la que mandar un enlace, o null si no existe o ya se mandaron
     * demasiados en poco tiempo. $kind es la clase de ficha, para contar los envíos recientes.
     *
     * @return array<string,mixed>|null
     */
    private function accountForMail(string $email, string $kind, bool $allowGuest = false): ?array
    {
        $email = Rules::email($email);
        if ($email === '' || !$this->app->has('mailer')) {
            return null;
        }
        $row = $this->rawByEmail($email);
        if ($row === null || ((int) $row['guest'] === 1 && !$allowGuest) || (int) $row['active'] !== 1) {
            return null;
        }

        return $this->recentTokens($kind, $email) >= self::MAIL_BURST ? null : $row;
    }

    /**
     * Quien intenta registrarse con el correo de una cuenta no se entera, pero el dueño recibe un aviso (como mucho uno cada
     * 10 minutos), y siempre se lanza el mismo mensaje.
     *
     * @param array<string,mixed> $existing
     *
     * @throws \InvalidArgumentException siempre
     */
    private function refuseExisting(array $existing): void
    {
        $email = (string) $existing['email'];
        if ($this->recentTokens('customer_exists', $email) < 1) {
            $now = $this->app->clock()->sql();
            $this->app->db()->insert('tokens', [
                'kind' => 'customer_exists', 'subject' => $email, 'hash' => hash('sha256', Random::token(16)), 'data' => (string) $existing['id'],
                'expires_at' => $now, 'used_at' => $now, 'created_at' => $now,
            ]);
            $this->mail('customer_exists', $existing, [
                'login_url' => $this->accountUrl(),
                'reset_url' => $this->accountUrl('recuperar'),
            ]);
        }

        // Sin decir si el correo existe (nadie puede averiguar quién es cliente), pero con qué hacer (14/11/2026: «no dice nada»).
        throw new \InvalidArgumentException('If that email has a record with us (an account or an earlier purchase), we have written to it. Press «Claim my record» in that message, choose a password and you will see your orders.');
    }

    // ------------------------------------------------------------------ correo y enlaces

    /**
     * Encola un correo al cliente con el idioma suyo. Un fallo del correo nunca tumba lo que se estaba haciendo.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $data
     */
    private function mail(string $template, array $row, array $data): void
    {
        if (!$this->app->has('mailer')) {
            return;
        }
        try {
            $name = $this->displayName($row);
            $options = ['to_name' => $name, 'now' => true, 'related' => 'customer:' . (int) $row['id']];
            $locale = Rules::str(isset($row['locale']) ? $row['locale'] : '');
            if ($locale !== '') {
                $options['locale'] = $locale;
            }
            $this->app->service('mailer')->queue($template, (string) $row['email'], ['name' => $name] + $data, $options);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'customers:mail:' . $template);
        }
    }

    /** @param array<string,mixed> $row */
    private function displayName(array $row): string
    {
        $name = trim(Rules::str(isset($row['first_name']) ? $row['first_name'] : '') . ' ' . Rules::str(isset($row['last_name']) ? $row['last_name'] : ''));
        if ($name !== '') {
            return $name;
        }
        $email = Rules::str(isset($row['email']) ? $row['email'] : '');
        $at = strpos($email, '@');

        return $at !== false && $at > 0 ? substr($email, 0, $at) : $email;
    }

    /** Dirección completa de una acción de la zona de cliente con su ficha: por nombre de ruta si existe, y si no por `urls.account`. */
    private function link(string $routeName, string $action, string $token): string
    {
        $url = $this->app->url();
        if ($this->app->router()->has($routeName)) {
            return $url->origin() . $url->route($routeName, ['token' => $token]);
        }

        return $url->origin() . $url->to('/' . $this->accountBase() . '/' . $action . '/' . $token);
    }

    private function accountUrl(string $action = ''): string
    {
        $url = $this->app->url();
        if ($action === '' && $this->app->router()->has('account')) {
            return $url->origin() . $url->route('account');
        }
        if ($action === 'recuperar' && $this->app->router()->has('account.recover')) {
            return $url->origin() . $url->route('account.recover');
        }

        return $url->origin() . $url->to('/' . $this->accountBase() . ($action !== '' ? '/' . $action : ''));
    }

    private function accountBase(): string
    {
        $base = trim($this->app->settings()->str('urls.account', 'mi-cuenta'), '/');

        return $base !== '' ? $base : 'mi-cuenta';
    }
}

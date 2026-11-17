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

/**
 * Quién está al otro lado: la persona del equipo que ha entrado al panel y sus permisos, o el cliente
 * identificado en la tienda. El inicio y cierre de sesión los hacen los módulos de acceso; aquí solo se consulta.
 *
 * Permisos: cada papel (tabla roles) tiene una lista de permisos como «catalog.edit» o «orders.view».
 * «*» los concede todos; «catalog.*» concede todos los de esa área.
 */
final class Auth
{
    /** @var App */
    private $app;

    /** @var array<string,mixed>|false|null fila del usuario del panel (false = ya buscado y no hay) */
    private $admin;

    /** @var string[]|null */
    private $caps;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Persona del equipo que ha entrado al panel, o null.
     *
     * @return array<string,mixed>|null id, email, name, role, locale, active…
     */
    public function admin(): ?array
    {
        if ($this->admin === null) {
            $id = $this->app->session('admin')->userId();
            $row = $id !== null ? $this->app->db()->row('SELECT * FROM {users} WHERE id = ? AND active = 1', [$id]) : null;
            if ($row !== null) {
                unset($row['password'], $row['totp_secret']);
            }
            $this->admin = $row !== null ? $row : false;
        }

        return $this->admin === false ? null : $this->admin;
    }

    public function adminId(): ?int
    {
        $admin = $this->admin();

        return $admin !== null ? (int) $admin['id'] : null;
    }

    /**
     * Permisos del papel de quien ha entrado.
     *
     * @return string[]
     */
    public function caps(): array
    {
        if ($this->caps === null) {
            $this->caps = [];
            $admin = $this->admin();
            if ($admin !== null) {
                $json = $this->app->db()->value('SELECT caps FROM {roles} WHERE code = ?', [(string) $admin['role']]);
                $caps = is_string($json) ? json_decode($json, true) : null;
                $this->caps = is_array($caps) ? array_values(array_filter($caps, 'is_string')) : [];
            }
        }

        return $this->caps;
    }

    /** Identidad compartida de fábrica: no depende de que haya una sesión abierta. */
    public function isPublicDemoIdentity(array $user): bool
    {
        return $this->app->site()->isDemo() && ($this->app->site()->get('public_admin_demo', false)
            || in_array((string) $user['role'], ['demo', 'demo_admin'], true));
    }

    /** ¿Puede quien ha entrado hacer esto? Sin sesión, nunca. */
    public function can(string $cap): bool
    {
        if ($this->admin() === null) {
            return false;
        }
        // El administrador público solo administra su tienda de ejemplo. Ni un papel con «*» levanta estas barreras.
        if ($this->app->site()->isDemo() && $this->app->site()->get('public_admin_demo', false)) {
            $area = strpos($cap, '.') !== false ? substr($cap, 0, (int) strpos($cap, '.')) : $cap;
            if (in_array($area, ['plugins', 'sites'], true) || ($area === 'team' && $cap !== 'team.view') || in_array($cap, ['appearance.create', 'tools.restore', 'tools.backup'], true)) {
                return false;
            }
        }
        $caps = $this->caps();
        if (in_array('*', $caps, true) || in_array($cap, $caps, true)) {
            return true;
        }
        $pos = strpos($cap, '.');

        return $pos !== false && in_array(substr($cap, 0, $pos) . '.*', $caps, true);
    }

    /** Corta con un 403 si no tiene el permiso. */
    public function requireCap(string $cap): void
    {
        if (!$this->can($cap)) {
            throw HttpError::forbidden(t('Missing permission: {cap}', ['cap' => $cap]));
        }
    }

    /** Identificador del cliente que ha entrado en la tienda, o null. */
    public function customerId(): ?int
    {
        return $this->app->session('customer')->userId();
    }

    /** Olvida lo leído (tras entrar, salir o cambiar de papel en la misma petición). */
    public function reset(): void
    {
        $this->admin = null;
        $this->caps = null;
    }
}

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Sites;

use Aifec\Kernel\App;
use Aifec\Security\Passwords;

/**
 * Lo que hace seguro a un sitio de demostración, aplicado por DemoBuilder y comprobado al final:
 *
 *  - el correo queda retenido («hold») y ni siquiera salen los avisos del sistema;
 *  - solo se ofrece la forma de pago simulada (los módulos de pago reales los rechaza el paquete);
 *  - el sitio entero lleva «noindex»;
 *  - hay una persona del panel con el papel «demo», con una contraseña fija que se enseña en la página pública de demos;
 *  - el papel «demo» de ESE sitio no puede cambiar personas, ajustes (el del correo incluido), plugins ni sitios.
 */
final class DemoGuard
{
    /** Contraseña de demostración por defecto: pública a propósito, vale solo para el panel de las demos. */
    public const DEFAULT_PASSWORD = 'demo-aifec-2026';

    /** Áreas de permisos que el papel «demo» no puede tener: personas y papeles, ajustes, correo, plugins y sitios. */
    public const FORBIDDEN_AREAS = ['team', 'settings', 'mail', 'plugins', 'sites'];

    /** Permisos concretos que se comprueban al final (si alguno se concede, la demostración no se entrega). */
    public const MUST_DENY = ['team.view', 'team.edit', 'settings.edit', 'mail.view', 'mail.edit', 'plugins.view', 'plugins.edit', 'sites.manage', 'appearance.create'];

    /**
     * Áreas que el papel «demo» conserva solo en parte: su comodín se cambia por estos permisos. En una demostración
     * pública se puede personalizar el tema, pero no crear temas nuevos (eso escribe ficheros en el servidor).
     */
    public const NARROWED = ['appearance.*' => ['appearance.view', 'appearance.edit']];

    /** Permisos del papel «demo» si el sitio no lo trae de serie. */
    private const FALLBACK_CAPS = ['dashboard.*', 'orders.*', 'customers.view', 'catalog.*', 'coupons.*', 'reviews.*', 'invoices.view', 'media.*', 'content.*', 'seo.*',
        'appearance.view', 'appearance.edit', 'search.view', 'shipping.view', 'tax.view', 'assistant.view', 'ai.view', 'ai.use', 'tools.view'];

    /**
     * Lo que el papel «demo» tiene siempre, aunque el sitio lo traiga de antes sin ello: quien prueba AIFEC tiene que poder ver y
     * tocar el sistema de IA (el asistente, sus ajustes, el uso, las propuestas, la búsqueda por significado). El gasto no corre
     * peligro: en una demostración los topes diarios son fijos (Aifec\Modules\Ai\Usage::DEMO_CENTS y DEMO_CALLS).
     */
    public const ALWAYS = ['assistant.view', 'assistant.edit', 'ai.view', 'ai.use', 'ai.edit', 'semantic.view', 'semantic.edit', 'copilot.use', 'search.view', 'search.edit'];

    /**
     * La dirección de correo de la persona de demostración: demo@<dominio de la tienda>. Sin un dominio de verdad
     * (localhost, una IP) se usa el reservado tienda.test.
     */
    public static function email(string $siteUrl): string
    {
        $host = parse_url($siteUrl, PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : '';
        if (strpos($host, 'www.') === 0) {
            $host = (string) substr($host, 4);
        }
        if ($host === '' || strpos($host, '.') === false || filter_var($host, FILTER_VALIDATE_IP) !== false || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            $host = 'tienda.test';
        }

        return 'demo@' . $host;
    }

    /**
     * Aplica todo a un sitio de demostración (el que se pasa es su aplicación, ya arrancada).
     *
     * @return array{email:string,removed:string[]}
     */
    public static function apply(App $app, string $password): array
    {
        $settings = $app->settings();
        $settings->set('mail.mode', 'hold');
        $settings->set('mail.transport', 'log');
        $settings->set('mail.system_always', false);
        $settings->set('seo.noindex_site', true);
        $settings->set('payments.simulated.enabled', true);
        $settings->set('payments.simulated.mode', 'ask');
        $settings->set('payments.simulated.allow_live', false);

        $removed = self::restrictRole($app);
        $email = self::email($app->site()->url());
        self::person($app, $email, $password);
        self::assertDenied($app);

        return ['email' => $email, 'removed' => $removed];
    }

    /**
     * ¿Concede esta lista de permisos el permiso pedido? Es la misma regla que Aifec\Kernel\Auth::can().
     *
     * @param string[] $caps
     */
    public static function allows(array $caps, string $cap): bool
    {
        if (in_array('*', $caps, true) || in_array($cap, $caps, true)) {
            return true;
        }
        $pos = strpos($cap, '.');

        return $pos !== false && in_array(substr($cap, 0, $pos) . '.*', $caps, true);
    }

    /**
     * Quita al papel «demo» de este sitio lo que sobre.
     *
     * @return string[] los permisos quitados
     */
    private static function restrictRole(App $app): array
    {
        return self::refreshRole($app->db());
    }

    /**
     * Lo mismo sobre la base de datos de una demostración ya hecha (lo llama Owners al sincronizar las cuentas, así que el papel
     * se pone al día al pulsar «Abrir panel» o con sites:owners, sin rehacer la demostración).
     *
     * @return string[] los permisos quitados
     */
    public static function refreshRole(\Aifec\Db\Db $db): array
    {
        $json = $db->value('SELECT caps FROM {roles} WHERE code = ?', ['demo']);
        $caps = is_string($json) ? json_decode($json, true) : null;
        $present = is_array($caps);
        $caps = $present ? array_values(array_filter($caps, 'is_string')) : self::FALLBACK_CAPS;
        $kept = [];
        $removed = [];
        foreach ($caps as $cap) {
            $area = strpos($cap, '.') !== false ? substr($cap, 0, (int) strpos($cap, '.')) : $cap;
            if ($cap === '*' || in_array($area, self::FORBIDDEN_AREAS, true) || in_array($cap, self::MUST_DENY, true)) {
                $removed[] = $cap;
            } elseif (isset(self::NARROWED[$cap])) {
                $removed[] = $cap;
                foreach (self::NARROWED[$cap] as $narrow) {
                    $kept[] = $narrow;
                }
            } else {
                $kept[] = $cap;
            }
        }
        $before = $kept;
        foreach (self::ALWAYS as $cap) {
            if (!self::allows($kept, $cap)) {
                $kept[] = $cap;
            }
        }
        $kept = array_values(array_unique($kept));
        if ($json === null) {
            $db->insert('roles', ['code' => 'demo', 'name' => 'Demo', 'caps' => json_encode($kept), 'system' => 1]);
        } elseif ($removed !== [] || $kept !== array_values(array_unique($before))) {
            $db->update('roles', ['caps' => json_encode($kept)], ['code' => 'demo']);
        }

        return $removed;
    }

    private static function person(App $app, string $email, string $password): void
    {
        $db = $app->db();
        $now = $app->clock()->sql();
        $hash = Passwords::hash($password);
        $id = $db->value('SELECT id FROM {users} WHERE email = ?', [$email]);
        if ($id === null) {
            $db->insert('users', [
                'email' => $email, 'name' => 'Demo', 'password' => $hash, 'role' => 'demo',
                'locale' => substr($app->site()->locale(), 0, 2), 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);

            return;
        }
        $db->update('users', ['password' => $hash, 'role' => 'demo', 'active' => 1, 'updated_at' => $now], ['id' => (int) $id]);
    }

    /**
     * La segunda comprobación: con los permisos que ha quedado, el papel «demo» no puede nada de lo prohibido.
     */
    private static function assertDenied(App $app): void
    {
        $json = $app->db()->value('SELECT caps FROM {roles} WHERE code = ?', ['demo']);
        $caps = is_string($json) ? json_decode($json, true) : null;
        $caps = is_array($caps) ? array_values(array_filter($caps, 'is_string')) : [];
        foreach (self::MUST_DENY as $cap) {
            if (self::allows($caps, $cap)) {
                throw new \RuntimeException('The demo role would still be allowed «' . $cap . '»');
            }
        }
    }
}

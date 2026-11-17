<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Admin;

use Aifec\Commerce\PaymentMethod;
use Aifec\Kernel\App;

/**
 * «Formas de pago» del panel: qué formas hay registradas, si cada una se ofrecería hoy a un pedido normal de la tienda, y su
 * orden en la caja (el ajuste orders.payment_order). No guarda claves ni ajustes de ninguna forma: enlaza a su pantalla.
 *
 * Lo que dice la interfaz PaymentMethod: código, textos, si se ofrece para una compra y si está en pruebas. Lo demás se lee
 * solo si la forma lo declara con estos métodos opcionales (si no, la celda dice «—»; no se inventa):
 *   countries(): string[]   países a los que se ofrece ([] = cualquiera)
 *   configured(): bool      si tiene lo que necesita para cobrar (claves, cuenta…)
 * Si no declara configured(), «faltan las claves» se deduce de los ajustes del módulo: declara campos secretos y no hay
 * ninguno puesto.
 *
 * Un módulo que registra muchas formas (PayPal, una por fuente) sale en una sola fila: cuántas se ofrecen y su lista plegada.
 */
final class PaymentsAdmin
{
    /** Desde cuántas formas de un mismo módulo se juntan en una fila. */
    public const GROUP_FROM = 4;

    /** Largo máximo del ajuste orders.payment_order (la regla de su campo en los ajustes de ventas). */
    public const ORDER_MAX = 200;

    /** Importe del pedido normal con el que se pregunta a cada forma: 50,00. */
    public const NORMAL_TOTAL = 5000;

    /**
     * El pedido normal de la tienda: 50,00 en su moneda, a su país, con envío, sin cliente.
     *
     * @return array<string,mixed>
     */
    public static function normalContext(App $app): array
    {
        $settings = $app->settings();
        $country = strtoupper(trim($settings->str('shipping.default_country', $settings->str('tax.base_country', 'ES'))));
        $country = preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : 'ES';

        return [
            'total' => self::NORMAL_TOTAL,
            'currency' => strtoupper($settings->str('shop.currency', 'EUR')),
            'country' => $country,
            'billing_country' => $country,
            'needs_shipping' => true,
            'customer_id' => null,
            'lang' => $app->defaultLang(),
        ];
    }

    /**
     * Las filas de la pantalla, en el orden de la caja; los módulos de pago apagados, aparte; y si alguna se ofrecería hoy.
     *
     * @return array{rows:array<int,array<string,mixed>>,off:array<int,array<string,mixed>>,offered:int,context:array<string,mixed>}
     */
    public static function overview(App $app): array
    {
        $context = self::normalContext($app);
        $methods = self::methods($app, true);
        $offered = 0;
        $forms = [];
        foreach (self::effectiveOrder($app, array_keys($methods)) as $code) {
            $form = self::describe($app, $methods[$code], $context);
            $offered += $form['offered'] ? 1 : 0;
            $forms[$code] = $form;
        }

        return ['rows' => self::rows($app, $forms), 'off' => self::offModules($app), 'offered' => $offered, 'context' => $context];
    }

    /**
     * Mueve una fila un puesto arriba o abajo y guarda el orden de la caja. Devuelve false si no se movió nada.
     *
     * @param string $move «up:<fila>» o «down:<fila>»
     */
    public static function move(App $app, string $move): bool
    {
        if (preg_match('/^(up|down):([a-z]:[a-z0-9_.-]{1,60})$/', $move, $m) !== 1) {
            return false;
        }
        $overview = self::overview($app);
        $keys = array_column($overview['rows'], 'key');
        $at = array_search($m[2], $keys, true);
        if ($at === false) {
            return false;
        }
        $to = $m[1] === 'up' ? $at - 1 : $at + 1;
        if ($to < 0 || $to >= count($keys)) {
            return false;
        }
        $rows = $overview['rows'];
        $swap = $rows[$to];
        $rows[$to] = $rows[$at];
        $rows[$at] = $swap;
        $wanted = [];
        $offered = [];
        foreach ($rows as $row) {
            foreach ($row['forms'] as $form) {
                $wanted[] = $form['code'];
                if ($form['offered']) {
                    $offered[] = $form['code'];
                }
            }
        }
        $value = implode(',', self::shortest($wanted, array_keys(self::methods($app, true))));
        if (strlen($value) > self::ORDER_MAX) {
            // Demasiado largo para el campo de los ajustes de ventas: se guarda el orden de las que se ofrecen hoy.
            $value = implode(',', self::shortest($offered, array_keys(self::methods($app, true))));
        }
        $before = $app->settings()->get('orders.payment_order');
        $app->settings()->set('orders.payment_order', $value);
        $app->invalidate(['settings', 'pages']);
        $app->hooks()->fire('settings.saved', 'orders', ['orders.payment_order'], $app);
        \Aifec\Admin\Admin::audit($app, 'settings', 'settings', 'orders', 'orders.payment_order', [
            'orders.payment_order' => [is_scalar($before) ? (string) $before : '', $value],
        ]);

        return true;
    }

    /**
     * Los códigos que hay que nombrar en orders.payment_order para que la caja salga en el orden pedido: la caja pone primero
     * los nombrados y luego el resto por orden de registro, así que basta con el principio más corto que deje el resto igual.
     *
     * @param string[] $wanted     el orden completo que se quiere
     * @param string[] $registered los códigos por orden de registro
     *
     * @return string[]
     */
    public static function shortest(array $wanted, array $registered): array
    {
        $wanted = array_values($wanted);
        for ($k = 0; $k <= count($wanted); $k++) {
            $named = array_slice($wanted, 0, $k);
            $rest = array_values(array_filter($registered, static function (string $code) use ($named, $wanted): bool {
                return !in_array($code, $named, true) && in_array($code, $wanted, true);
            }));
            if ($rest === array_slice($wanted, $k)) {
                return $named;
            }
        }

        return $wanted;
    }

    /**
     * Los códigos en el orden en que los enseña la caja: primero los de orders.payment_order, luego el resto por registro.
     *
     * @param string[] $registered
     *
     * @return string[]
     */
    public static function effectiveOrder(App $app, array $registered): array
    {
        $value = $app->settings()->get('orders.payment_order', []);
        $list = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);
        $out = [];
        foreach ($list as $item) {
            $code = is_scalar($item) ? strtolower(trim((string) $item)) : '';
            if ($code !== '' && in_array($code, $registered, true) && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }
        foreach ($registered as $code) {
            if (!in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ piezas

    /**
     * Las formas registradas por código (en minúsculas), en orden de registro. Con $checkoutOnly, sin las que nunca salen en
     * la caja (info()['checkout'] === false: Google Pay desde una plataforma de IA), que no tienen puesto en su orden.
     *
     * @return array<string,PaymentMethod>
     */
    private static function methods(App $app, bool $checkoutOnly = false): array
    {
        $out = [];
        $service = $app->has('payments') ? $app->service('payments') : null;
        foreach (is_object($service) && method_exists($service, 'all') ? $service->all() : [] as $code => $method) {
            if (!$method instanceof PaymentMethod) {
                continue;
            }
            if ($checkoutOnly) {
                try {
                    $info = $method->info();
                } catch (\Throwable $e) {
                    $info = [];
                }
                if (isset($info['checkout']) && $info['checkout'] === false) {
                    continue;
                }
            }
            $out[strtolower(trim((string) $code))] = $method;
        }

        return $out;
    }

    /**
     * El nombre de una forma de pago para quien mira un pedido, en el idioma del panel. El pedido guarda el texto con que se cobró
     * (puede estar en otro idioma o ser el código a secas: «simulated»), así que se prefiere el nombre actual de la forma
     * registrada; si ya no está, se traduce lo guardado, y a falta de todo, el código.
     *
     * @param string $code  código de la forma («cod», «simulated»…)
     * @param string $saved texto guardado en el pedido
     */
    public static function labelFor(App $app, string $code, string $saved = ''): string
    {
        $key = strtolower(trim($code));
        $saved = trim($saved);
        if ($key !== '') {
            $method = self::methods($app)[$key] ?? null;
            if ($method instanceof PaymentMethod) {
                try {
                    $info = $method->info();
                    if (isset($info['title']) && trim((string) $info['title']) !== '') {
                        return (string) $info['title'];
                    }
                } catch (\Throwable $e) {
                    $app->log()->exception($e, 'admin.payments.label:' . $key);
                }
            }
        }
        if ($saved !== '' && $saved !== $code) {
            return t($saved);
        }

        return $code;
    }

    /**
     * Lo que se sabe de una forma: nombre, módulo, si se ofrece, entorno y países. Una forma que falla al responder cuenta
     * como que no se ofrece, y la pantalla sigue.
     *
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>
     */
    private static function describe(App $app, PaymentMethod $method, array $context): array
    {
        $code = strtolower(trim($method->code()));
        $title = $code;
        $offered = false;
        $test = null;
        $countries = null;
        $configured = null;
        try {
            $info = $method->info();
            $title = isset($info['title']) && trim((string) $info['title']) !== '' ? (string) $info['title'] : $code;
            $offered = $method->available($context);
            $test = $method->isTest();
            // is_callable y no method_exists: un método privado «existe», y llamarlo desde aquí lanza un TypeError que dejaba la
            // forma de pago como «no se ofrece» sin serlo (13/11/2026: el contra reembolso, en vivo).
            if (is_callable([$method, 'countries'])) {
                $list = call_user_func([$method, 'countries']);
                $countries = is_array($list) ? array_values(array_filter(array_map('strval', $list))) : null;
            }
            if (is_callable([$method, 'configured'])) {
                $configured = (bool) call_user_func([$method, 'configured']);
            }
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'admin.payments:' . $code);
            $offered = false;
        }
        $module = self::moduleOf($app, $method);
        if ($configured === null) {
            $configured = !self::keysMissing($app, $module);
        }

        return [
            'code' => $code,
            'title' => $title,
            'module' => $module,
            'offered' => $offered,
            'state' => $offered ? 'offered' : ($configured ? 'not_offered' : 'unconfigured'),
            'test' => $test,
            'countries' => $countries,
        ];
    }

    /**
     * Junta las formas en filas: una por forma, y una por módulo cuando el módulo tiene muchas. La fila de un módulo va donde
     * va la primera de sus formas.
     *
     * @param array<string,array<string,mixed>> $forms en el orden de la caja
     *
     * @return array<int,array<string,mixed>>
     */
    private static function rows(App $app, array $forms): array
    {
        $count = [];
        foreach ($forms as $form) {
            if ($form['module'] !== '') {
                $count[$form['module']] = isset($count[$form['module']]) ? $count[$form['module']] + 1 : 1;
            }
        }
        $rows = [];
        $groupAt = [];
        foreach ($forms as $form) {
            $module = (string) $form['module'];
            if ($module !== '' && $count[$module] >= self::GROUP_FROM) {
                if (!isset($groupAt[$module])) {
                    $groupAt[$module] = count($rows);
                    $rows[] = ['key' => 'm:' . $module, 'group' => true, 'module' => $module, 'forms' => []];
                }
                $rows[$groupAt[$module]]['forms'][] = $form;
                continue;
            }
            $rows[] = ['key' => 'c:' . $form['code'], 'group' => false, 'module' => $module, 'forms' => [$form]];
        }
        foreach ($rows as $i => $row) {
            $rows[$i] += self::summary($app, $row);
        }

        return $rows;
    }

    /**
     * Lo que enseña una fila: nombre, módulo, estado, entorno, países y la dirección de sus ajustes.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private static function summary(App $app, array $row): array
    {
        $forms = $row['forms'];
        $module = (string) $row['module'];
        $offered = 0;
        $unconfigured = false;
        $tests = [];
        $countries = [];
        $declared = true;
        foreach ($forms as $form) {
            $offered += $form['offered'] ? 1 : 0;
            $unconfigured = $unconfigured || $form['state'] === 'unconfigured';
            if ($form['test'] !== null) {
                $tests[$form['test'] ? 'test' : 'live'] = true;
            }
            if ($form['countries'] === null) {
                $declared = false;
            } else {
                $countries = array_merge($countries, $form['countries'] === [] ? ['*'] : $form['countries']);
            }
        }
        $state = $offered > 0 ? 'offered' : ($unconfigured ? 'unconfigured' : 'not_offered');
        $countries = array_values(array_unique($countries));
        sort($countries);

        return [
            'name' => $row['group'] ? self::moduleName($app, $module) : (string) $forms[0]['title'],
            'module_name' => $module !== '' ? self::moduleName($app, $module) : '',
            'state' => $state,
            'offered' => $offered,
            'total' => count($forms),
            'environment' => count($tests) === 1 ? (string) key($tests) : '',
            'countries' => !$declared ? null : (in_array('*', $countries, true) ? [] : $countries),
            'settings_url' => self::settingsUrl($app, $module),
        ];
    }

    /** El módulo que registró la forma, por el espacio de nombres de su clase ('' si no es de ningún módulo cargado). */
    private static function moduleOf(App $app, PaymentMethod $method): string
    {
        $parts = explode('\\', get_class($method));
        $studly = '';
        if (count($parts) >= 4 && $parts[0] === 'Aifec' && $parts[1] === 'Modules') {
            $studly = $parts[2];
        } elseif (count($parts) >= 3 && $parts[0] === 'Plugins') {
            $studly = $parts[1];
        }
        if ($studly === '') {
            return '';
        }
        foreach ($app->modules()->loaded() as $id) {
            if (str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', (string) $id))) === $studly) {
                return (string) $id;
            }
        }

        return '';
    }

    /** ¿Declara el módulo campos secretos en sus ajustes y no hay ninguno puesto? */
    private static function keysMissing(App $app, string $module): bool
    {
        if ($module === '') {
            return false;
        }
        $definition = $app->modules()->definition($module, 'settings');
        $secrets = 0;
        foreach (isset($definition['tabs']) && is_array($definition['tabs']) ? $definition['tabs'] : [] as $tab) {
            foreach (is_array($tab) && isset($tab['fields']) && is_array($tab['fields']) ? $tab['fields'] : [] as $key => $field) {
                if (is_array($field) && isset($field['type']) && $field['type'] === 'secret') {
                    $secrets++;
                    if ($app->secret((string) $key) !== '') {
                        return false;
                    }
                }
            }
        }

        return $secrets > 0;
    }

    /** La pantalla de ajustes del módulo, si la tiene y la persona puede abrirla. */
    private static function settingsUrl(App $app, string $module): string
    {
        if ($module === '') {
            return '';
        }
        $definition = $app->modules()->definition($module, 'settings');
        if (!isset($definition['tabs']) || !$app->auth()->can(isset($definition['cap']) ? (string) $definition['cap'] : 'settings.edit')) {
            return '';
        }

        return $app->url()->plain('/admin/settings/' . $module);
    }

    private static function moduleName(App $app, string $module): string
    {
        $manifest = $app->modules()->manifest($module);

        return t($manifest !== null && isset($manifest['name']) ? (string) $manifest['name'] : $module);
    }

    /**
     * Los módulos de pago instalados y apagados (por convenio, los que se llaman «payments-…»), con si se pueden encender aquí.
     *
     * @return array<int,array{id:string,name:string,can_enable:bool}>
     */
    private static function offModules(App $app): array
    {
        $out = [];
        $canEnable = $app->has('plugins') && $app->auth()->can('plugins.edit');
        foreach ($app->modules()->all() as $id => $manifest) {
            if (strpos((string) $id, 'payments-') === 0 && !$app->modules()->isLoaded((string) $id)) {
                $out[] = ['id' => (string) $id, 'name' => t(isset($manifest['name']) ? (string) $manifest['name'] : (string) $id), 'can_enable' => $canEnable];
            }
        }

        return $out;
    }

    /** ¿Es un módulo de pago instalado y apagado? */
    public static function isOffPaymentModule(App $app, string $id): bool
    {
        foreach (self::offModules($app) as $module) {
            if ($module['id'] === $id) {
                return true;
            }
        }

        return false;
    }
}

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot\Actions;

use Aifec\Kernel\App;
use Aifec\Modules\Copilot\Reader;

/**
 * Cambiar un ajuste de una lista CERRADA de ajustes seguros (nombre de la tienda, correo de contacto, productos por página,
 * precios con o sin impuestos…). Todo lo que no esté en la lista no se cambia desde aquí: se encuentra en el buscador y se
 * cambia en su pantalla. Cada ajuste exige el permiso de su propia pantalla de ajustes.
 */
final class SettingActions
{
    /**
     * Los ajustes que se pueden cambiar desde el copiloto: clave => módulo, texto (en inglés), tipo, permiso, límites y las
     * expresiones con las que una persona los nombra (sin tildes, separadas por «|», en castellano y en inglés).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function safe(App $app): array
    {
        $all = [
            'store.name' => ['module' => 'core', 'label' => 'Shop name', 'type' => 'text', 'max' => 120, 'cap' => 'settings.edit', 'strip' => true,
                'terms' => 'nombre de la tienda|nombre de tienda|nombre del negocio|shop name|store name'],
            'store.email' => ['module' => 'core', 'label' => 'Contact email', 'type' => 'email', 'cap' => 'settings.edit', 'strip' => true,
                'terms' => 'correo de contacto|email de contacto|correo electronico de contacto|correo de la tienda|email de la tienda|contact email|shop email|store email'],
            'store.phone' => ['module' => 'core', 'label' => 'Phone', 'type' => 'text', 'max' => 40, 'cap' => 'settings.edit', 'strip' => true,
                'terms' => 'telefono de la tienda|telefono de contacto|shop phone|store phone|contact phone'],
            'catalog.per_page' => ['module' => 'catalog', 'label' => 'Products per page', 'type' => 'int', 'min' => 1, 'max' => 200, 'cap' => 'catalog.edit', 'strip' => true,
                'terms' => 'productos por pagina|articulos por pagina|products per page|items per page'],
            'catalog.low_stock' => ['module' => 'catalog', 'label' => 'Warn when the stock is at or below', 'type' => 'int', 'min' => 0, 'max' => 100000, 'cap' => 'catalog.edit', 'strip' => true,
                'terms' => 'aviso de stock bajo|stock bajo|low stock'],
            'catalog.show_out_of_stock' => ['module' => 'catalog', 'label' => 'Show the products that are out of stock', 'type' => 'bool', 'cap' => 'catalog.edit', 'strip' => true,
                'terms' => 'productos agotados|productos sin stock|out of stock products|sold out products'],
            'tax.display_with_tax' => ['module' => 'tax', 'label' => 'Show prices with tax in the shop', 'type' => 'bool', 'cap' => 'tax.edit', 'strip' => false,
                'terms' => 'precios con impuestos|precios sin impuestos|precios con iva|precios sin iva|prices with tax|prices without tax|prices incl tax|prices excl tax'],
            'orders.guest_checkout' => ['module' => 'orders', 'label' => 'Allow buying without an account', 'type' => 'bool', 'cap' => 'orders.edit', 'strip' => true,
                'terms' => 'compra como invitado|comprar sin cuenta|compra sin cuenta|guest checkout|buying without an account'],
            'customers.allow_register' => ['module' => 'customers', 'label' => 'Allow customers to create an account', 'type' => 'bool', 'cap' => 'customers.edit', 'strip' => true,
                'terms' => 'registro de clientes|crear cuenta|crear una cuenta|customer registration|account registration|create an account'],
        ];
        $out = [];
        foreach ($all as $key => $def) {
            if ($def['module'] === 'core' || $app->modules()->isLoaded((string) $def['module'])) {
                $out[$key] = $def;
            }
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function definitions(App $app): array
    {
        $list = [];
        foreach (self::safe($app) as $key => $def) {
            $list[] = $key . ' (' . $def['label'] . ')';
        }

        return [[
            'id' => 'settings.set', 'module' => '', 'permission' => [self::class, 'permission'],
            'label' => 'Change a shop setting',
            'description' => 'Changes one setting of this closed list: ' . implode(', ', $list) . '. Any other setting cannot be changed here.',
            'params' => [
                ['name' => 'setting', 'type' => 'string', 'required' => true, 'description' => 'The setting key from the list'],
                ['name' => 'value', 'type' => 'string', 'required' => true, 'description' => 'The new value (yes or no for an on/off setting)'],
            ],
            'preview' => [self::class, 'preview'], 'apply' => [self::class, 'apply'], 'undo' => [self::class, 'undo'],
            'understand' => [self::class, 'read'],
            'examples' => ['Change the shop name to Muebles Ejemplo', 'Show the prices without tax'],
        ]];
    }

    /**
     * El permiso del ajuste elegido (el de su pantalla de ajustes); sin elegir aún, el general.
     *
     * @param array<string,mixed> $args
     */
    public static function permission(App $app, array $args): string
    {
        $safe = self::safe($app);
        $key = isset($args['setting']) ? self::key($app, (string) $args['setting']) : '';

        return $key !== '' && isset($safe[$key]) ? (string) $safe[$key]['cap'] : 'settings.edit';
    }

    /** La clave de un ajuste a partir de lo que se escribió (la clave o su texto), o vacío si no hay una sola lectura. */
    private static function key(App $app, string $ref): string
    {
        $safe = self::safe($app);
        $ref = trim($ref);
        if (isset($safe[$ref])) {
            return $ref;
        }
        $norm = Reader::norm($ref);
        $hits = [];
        foreach ($safe as $key => $def) {
            if ($norm !== '' && (Reader::norm(t((string) $def['label'])) === $norm || Reader::norm((string) $def['label']) === $norm)) {
                $hits[] = $key;
            }
        }

        return count($hits) === 1 ? $hits[0] : '';
    }

    // ------------------------------------------------------------------ entender

    /**
     * @return array<string,mixed>|null
     */
    public static function read(App $app, string $raw, string $norm): ?array
    {
        $found = null;
        $term = '';
        foreach (self::safe($app) as $key => $def) {
            foreach (explode('|', (string) $def['terms']) as $candidate) {
                if (Reader::has($norm, preg_quote($candidate, '/')) && strlen($candidate) > strlen($term)) {
                    $found = $key;
                    $term = $candidate;
                }
            }
        }
        if ($found === null) {
            return null;
        }
        $def = self::safe($app)[$found];
        $fold = Reader::fold($raw);
        $value = null;
        $type = (string) $def['type'];
        if ($type === 'bool') {
            $text = !empty($def['strip']) ? trim((string) preg_replace('/\s+/', ' ', str_replace($term, ' ', $norm))) : $norm;
            $yes = Reader::yesNo($text);
            $value = $yes === null ? null : ($yes ? '1' : '0');
        } elseif ($type === 'email') {
            $value = preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $raw, $m) === 1 ? $m[0] : null;
        } elseif ($type === 'int') {
            $value = preg_match('/(?<![\d.,])(\d+)(?![\d.,]*\d)/', str_replace($term, ' ', $norm), $m) === 1 ? $m[1] : null;
        } else {
            $quoted = Reader::capture($raw, $fold, '/["“«]([^"“«”»]{1,160})["”»]/u');
            if ($quoted !== null && $quoted !== '') {
                $value = $quoted;
            } else {
                $at = self::termAt($fold, $term);
                $rest = $at >= 0 ? (string) mb_substr($raw, $at, null, 'UTF-8') : $raw;
                $restFold = Reader::fold($rest);
                $value = Reader::capture($rest, $restFold, '/\s(?:a|en|to|como|as|es|is|=|:)\s+(.+)$/is');
                if ($value === null) {
                    $value = Reader::capture($rest, $restFold, '/:\s*(.+)$/s');
                }
                if ($type === 'text' && $found === 'store.phone' && $value !== null) {
                    $value = preg_match('/[+]?[0-9][0-9 .\-]{5,}/', $value, $m) === 1 ? trim($m[0]) : $value;
                }
            }
        }
        $args = ['setting' => $found];
        if ($value !== null && trim($value) !== '') {
            $args['value'] = trim($value);
        }

        return ['args' => $args, 'score' => 70 + (isset($args['value']) ? 10 : 0)];
    }

    /** La posición (en caracteres) donde acaba una expresión en el texto sin tildes, o -1. */
    private static function termAt(string $fold, string $term): int
    {
        $lower = mb_strtolower($fold, 'UTF-8');
        $at = mb_strpos($lower, $term, 0, 'UTF-8');

        return $at === false ? -1 : $at + mb_strlen($term, 'UTF-8');
    }

    // ------------------------------------------------------------------ plan

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    private static function plan(App $app, array $args): array
    {
        $out = ['errors' => [], 'key' => '', 'def' => null, 'before' => null, 'after' => null, 'had' => false];
        $key = self::key($app, (string) $args['setting']);
        if ($key === '') {
            $out['errors'][] = t('That setting cannot be changed from the copilot. Search for it and change it in its own screen.');

            return $out;
        }
        $def = self::safe($app)[$key];
        $out['key'] = $key;
        $out['def'] = $def;
        if (!$app->auth()->can((string) $def['cap'])) {
            $out['errors'][] = t('You do not have permission to change this setting.');

            return $out;
        }
        $settings = $app->settings();
        $out['had'] = $settings->has($key);
        $out['before'] = $settings->get($key);
        $text = trim((string) $args['value']);
        $type = (string) $def['type'];
        if ($type === 'int') {
            if (preg_match('/^\d{1,9}$/', $text) !== 1 || (int) $text < (int) $def['min'] || (int) $text > (int) $def['max']) {
                $out['errors'][] = t('The value has to be a whole number between {min} and {max}.', ['min' => (string) $def['min'], 'max' => (string) $def['max']]);
            } else {
                $out['after'] = (int) $text;
            }
        } elseif ($type === 'bool') {
            $yes = \Aifec\Modules\Copilot\Params::parse('bool', $text);
            if ($yes === null) {
                $out['errors'][] = t('The value has to be yes or no.');
            } else {
                $out['after'] = (bool) $yes;
            }
        } elseif ($type === 'email') {
            if ($text === '' || filter_var($text, FILTER_VALIDATE_EMAIL) === false || mb_strlen($text) > 190) {
                $out['errors'][] = t('That is not a valid email address.');
            } else {
                $out['after'] = $text;
            }
        } else {
            $max = isset($def['max']) ? (int) $def['max'] : 160;
            if ($text === '' || mb_strlen($text, 'UTF-8') > $max) {
                $out['errors'][] = t('The text cannot be empty or longer than {max} characters.', ['max' => (string) $max]);
            } else {
                $out['after'] = $text;
            }
        }
        if ($out['errors'] === [] && $out['after'] === $out['before']) {
            $out['errors'][] = t('Nothing to change: it is already set like that.');
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    private static function show($value, string $type): string
    {
        if ($value === null) {
            return '—';
        }
        if ($type === 'bool') {
            return $value ? t('Yes') : t('No');
        }

        return (string) $value;
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function preview(App $app, array $args): array
    {
        $plan = self::plan($app, $args);
        if ($plan['def'] === null) {
            return ['summary' => t('Change a shop setting'), 'changes' => [], 'count' => 0, 'errors' => $plan['errors']];
        }
        $type = (string) $plan['def']['type'];
        $changes = [];
        if ($plan['after'] !== null) {
            $changes[] = ['label' => t((string) $plan['def']['label']), 'from' => self::show($plan['before'], $type), 'to' => self::show($plan['after'], $type)];
        }

        return [
            'summary' => t('Change the setting «{name}»', ['name' => t((string) $plan['def']['label'])]),
            'changes' => $changes, 'count' => $changes === [] ? 0 : 1, 'warnings' => [], 'errors' => $plan['errors'],
        ];
    }

    /**
     * @param array<string,mixed> $args
     *
     * @return array<string,mixed>
     */
    public static function apply(App $app, array $args): array
    {
        $plan = self::plan($app, $args);
        if ($plan['errors'] !== [] || $plan['def'] === null) {
            return ['ok' => false, 'message' => $plan['errors'] !== [] ? (string) $plan['errors'][0] : t('Nothing to change.'), 'undo' => null];
        }
        $app->settings()->set($plan['key'], $plan['after']);
        self::saved($app, (string) $plan['def']['module'], $plan['key']);

        return [
            'ok' => true, 'message' => t('The setting «{name}» is updated.', ['name' => t((string) $plan['def']['label'])]),
            'undo' => ['key' => $plan['key'], 'module' => (string) $plan['def']['module'], 'before' => $plan['before'], 'had' => $plan['had'], 'after' => $plan['after']],
        ];
    }

    /**
     * @param array<string,mixed> $undo
     *
     * @return array<string,mixed>
     */
    public static function undo(App $app, array $undo): array
    {
        $key = (string) $undo['key'];
        $safe = self::safe($app);
        if (!isset($safe[$key]) || !$app->auth()->can((string) $safe[$key]['cap'])) {
            return ['ok' => false, 'message' => t('You do not have permission to change this setting.')];
        }
        if ($app->settings()->get($key) !== $undo['after']) {
            return ['ok' => false, 'message' => t('The setting has changed since. It is not undone so that nothing else is lost.')];
        }
        if (!empty($undo['had'])) {
            $app->settings()->set($key, $undo['before']);
        } else {
            $app->settings()->delete($key);
        }
        self::saved($app, (string) $undo['module'], $key);

        return ['ok' => true, 'message' => t('The setting is back as it was.')];
    }

    /** Lo que sigue a guardar un ajuste, igual que cuando se guarda desde su pantalla. */
    private static function saved(App $app, string $module, string $key): void
    {
        $app->invalidate(['settings', 'pages']);
        $app->hooks()->fire('settings.saved', $module, [$key], $app);
    }
}

<?php
/**
 * AIFEC — piezas del panel de clientes: listados, validación y efectos de guardar y borrar, columna lateral y botones de la ficha.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Customers;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;

final class CustomersAdmin
{
    /**
     * Configuración del listado de clientes. Con $csv son las columnas de la exportación (sin HTML).
     * Tiene que ser un array y no una función: el panel lo lee así al pintar el listado.
     *
     * @return array<string,mixed>
     */
    public static function listConfig(bool $csv): array
    {
        $config = [
            'from' => '{customers} t LEFT JOIN {customer_groups} g ON g.id = t.group_id',
            'select' => 't.*, g.name AS group_name',
            'key' => 'id',
            'key_sql' => 't.id',
            'search' => ['t.first_name', 't.last_name', 't.email', 't.phone', 't.vat_id', 't.company'],
            'filters' => [
                'group' => ['label' => 'Group', 'column' => 't.group_id', 'raw' => true, 'options' => static function (App $app): array {
                    $options = [];
                    foreach ($app->db()->select('SELECT id, name FROM {customer_groups} ORDER BY name') as $row) {
                        $options[(string) $row['id']] = t((string) $row['name']);
                    }

                    return $options;
                }],
                'guest' => ['label' => 'Type', 'column' => 't.guest', 'options' => ['0' => 'Registered', '1' => 'Guest']],
                'marketing' => ['label' => 'Marketing', 'column' => 't.marketing', 'options' => ['1' => 'Accepts marketing emails', '0' => 'Does not accept']],
            ],
            'sort' => ['t.id', 'desc'],
            'per_page' => 25,
            'export' => false,
        ];
        if ($csv) {
            $config['columns'] = [
                'first_name' => ['label' => 'First name'],
                'last_name' => ['label' => 'Last name'],
                'email' => ['label' => 'Email'],
                'phone' => ['label' => 'Phone'],
                'vat_id' => ['label' => 'Tax ID'],
                'company' => ['label' => 'Company'],
                'group_name' => ['label' => 'Group', 'translate' => true],
                'guest' => ['label' => 'Guest'],
                'marketing' => ['label' => 'Accepts marketing emails'],
                'orders_count' => ['label' => 'Orders'],
                'total_spent' => ['label' => 'Total spent', 'type' => 'money'],
                'created_at' => ['label' => 'Joined'],
            ];

            return $config;
        }
        $config['columns'] = [
            'first_name' => ['label' => 'Name', 'link' => true, 'sort' => 't.first_name', 'render' => static function (array $row, App $app): string {
                $name = trim(Rules::str($row['first_name']) . ' ' . Rules::str($row['last_name']));
                $html = e($name !== '' ? $name : '—');
                if ((int) $row['guest'] === 1) {
                    $html .= ' ' . Admin::badge(t('Guest'), 'info');
                }
                if ((int) $row['active'] !== 1) {
                    $html .= ' ' . Admin::badge(t('Inactive'), 'warn');
                }

                return $html;
            }],
            'email' => ['label' => 'Email', 'sort' => 't.email'],
            'group_name' => ['label' => 'Group', 'sort' => 'g.name', 'hide_sm' => true, 'translate' => true],
            'orders_count' => ['label' => 'Orders', 'type' => 'int', 'sort' => 't.orders_count', 'hide_sm' => true],
            'total_spent' => ['label' => 'Total spent', 'type' => 'money', 'sort' => 't.total_spent'],
            'created_at' => ['label' => 'Joined', 'type' => 'date', 'sort' => 't.created_at', 'hide_sm' => true],
        ];
        $config['toolbar'] = new ExportLink();

        return $config;
    }

    /**
     * @return array<string,mixed>
     */
    public static function groupListConfig(): array
    {
        return [
            'from' => '{customer_groups} t',
            'select' => 't.*, (SELECT COUNT(*) FROM {customers} c WHERE c.group_id = t.id) AS customers_count',
            'key' => 'id',
            'key_sql' => 't.id',
            'columns' => [
                'name' => ['label' => 'Name', 'link' => true, 'sort' => 't.name', 'translate' => true],
                'discount' => ['label' => 'Discount', 'sort' => 't.discount', 'render' => static function (array $row, App $app): string {
                    return e($app->i18n()->number(((int) $row['discount']) / 100, 2) . ' %');
                }],
                'tax_exempt' => ['label' => 'Exempt from taxes', 'type' => 'bool', 'hide_sm' => true],
                'is_default' => ['label' => 'Default group', 'type' => 'bool'],
                'customers_count' => ['label' => 'Customers', 'type' => 'int', 'hide_sm' => true],
            ],
            'search' => ['t.name'],
            'sort' => ['t.name', 'asc'],
            'per_page' => 50,
        ];
    }

    // ------------------------------------------------------------------ clientes: guardar y borrar

    /**
     * Normaliza y valida lo que el formulario ya ha leído: correo, nombres, NIF, teléfono, contraseña y grupo.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function beforeSave(array $values, ?array $current, App $app): array
    {
        $errors = [];
        $values['email'] = Rules::email(Rules::str(isset($values['email']) ? $values['email'] : ''));
        if ($values['email'] === '') {
            $errors['email'] = t('Enter a valid email address');
        }
        $names = Rules::names(Rules::str(isset($values['first_name']) ? $values['first_name'] : ''), Rules::str(isset($values['last_name']) ? $values['last_name'] : ''));
        $values['first_name'] = $names['first_name'] !== '' ? $names['first_name'] : null;
        $values['last_name'] = $names['last_name'] !== '' ? $names['last_name'] : null;
        $company = Rules::text(isset($values['company']) ? $values['company'] : '', 160);
        $values['company'] = $company !== '' ? $company : null;
        $vat = Rules::vat(Rules::str(isset($values['vat_id']) ? $values['vat_id'] : ''));
        if ($vat === null) {
            $errors['vat_id'] = t('Enter a valid tax ID');
        } else {
            $values['vat_id'] = $vat !== '' ? $vat : null;
        }
        $phone = Rules::phone(Rules::str(isset($values['phone']) ? $values['phone'] : ''));
        if ($phone === null) {
            $errors['phone'] = t('Enter a valid phone number');
        } else {
            $values['phone'] = $phone !== '' ? $phone : null;
        }
        $values['locale'] = Rules::locale(Rules::str(isset($values['locale']) ? $values['locale'] : ''), $app->lang());
        $notes = trim(Rules::str(isset($values['notes']) ? $values['notes'] : ''));
        $values['notes'] = $notes !== '' ? $notes : null;
        if (isset($values['password']) && (string) $values['password'] !== '') {
            if (mb_strlen((string) $values['password'], 'UTF-8') < Customers::MIN_PASSWORD) {
                $errors['password'] = t('Use at least {n} characters', ['n' => Customers::MIN_PASSWORD]);
            } else {
                $values['password'] = \Aifec\Security\Passwords::hash((string) $values['password']);
            }
        } else {
            unset($values['password']);
        }
        $group = isset($values['group_id']) && $values['group_id'] !== '' && $values['group_id'] !== null ? (int) $values['group_id'] : null;
        if ($group === null && $current === null) {
            $default = $app->db()->value('SELECT id FROM {customer_groups} WHERE is_default = 1 ORDER BY id');
            $group = $default !== null ? (int) $default : null;
        }
        $values['group_id'] = $group;
        if ($errors !== []) {
            return ['_errors' => $errors];
        }

        return $values;
    }

    /**
     * Lo que el formulario no guarda solo: invitado, correo verificado, consentimiento de publicidad y origen.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     */
    public static function afterSave(int $id, array $values, ?array $current, App $app): void
    {
        $db = $app->db();
        $now = $app->clock()->sql();
        $row = $db->row('SELECT * FROM {customers} WHERE id = ?', [$id]);
        if ($row === null) {
            return;
        }
        $set = [];
        if (isset($values['password']) && (string) $values['password'] !== '') {
            if ((int) $row['guest'] === 1) {
                $set['guest'] = 0;
            }
        } elseif ($current === null) {
            // Sin contraseña es un contacto: si algún día se registra con ese correo, conserva su ficha y sus pedidos.
            $set['guest'] = 1;
        }
        if ($current === null) {
            $set['source'] = 'admin';
        }
        $want = !empty($values['verified']);
        $was = $current !== null && (string) $current['email_verified_at'] !== '';
        if ($current !== null && $was && (string) $current['email'] !== (string) $row['email']) {
            // Un correo nuevo no hereda la verificación del anterior.
            $set['email_verified_at'] = null;
        } elseif ($want && !$was) {
            $set['email_verified_at'] = $now;
        } elseif (!$want && $was) {
            $set['email_verified_at'] = null;
        }
        if ($set !== []) {
            $db->update('customers', $set + ['updated_at' => $now], ['id' => $id]);
        }
        $marketingBefore = $current !== null && (int) $current['marketing'] === 1;
        $marketingNow = (int) $row['marketing'] === 1;
        if ($marketingNow !== $marketingBefore && $app->has('customers')) {
            $app->service('customers')->consent($id, (string) $row['email'], 'marketing', $marketingNow, '', 'admin');
        }
        if ((int) $row['active'] !== 1) {
            $db->delete('sessions', ['guard' => 'customer', 'user_id' => $id]);
        }
    }

    /**
     * Un cliente con pedidos no se borra: hay que anonimizarlo.
     *
     * @param array<string,mixed> $row
     */
    public static function beforeDelete(array $row, App $app): ?string
    {
        $db = $app->db();
        $has = (int) $row['orders_count'] > 0;
        if (!$has && $db->tableExists('orders') && in_array('customer_id', $db->columns('orders'), true)) {
            $has = $db->value('SELECT id FROM {orders} WHERE customer_id = ?', [(int) $row['id']]) !== null;
        }

        return $has ? t('This customer has orders and cannot be deleted. Anonymise it to erase the personal data.') : null;
    }

    /**
     * Lo que cuelga de un cliente borrado.
     *
     * @param array<string,mixed> $row
     */
    public static function afterDelete(array $row, App $app): void
    {
        $db = $app->db();
        $id = (int) $row['id'];
        $email = (string) $row['email'];
        $db->delete('addresses', ['customer_id' => $id]);
        $db->delete('sessions', ['guard' => 'customer', 'user_id' => $id]);
        $db->delete('consents', ['customer_id' => $id]);
        $db->run("DELETE FROM {tokens} WHERE kind IN ('customer_reset', 'customer_magic', 'customer_verify', 'customer_exists') AND subject = ?", [$email]);
        $db->run("DELETE FROM {login_attempts} WHERE guard = 'customer' AND identifier = ?", [$email]);
    }

    // ------------------------------------------------------------------ clientes: pantalla de la ficha

    /**
     * Columna derecha de la ficha: direcciones y cifras.
     *
     * @param array<string,mixed>|null $row
     */
    public static function side(?array $row, App $app): string
    {
        if ($row === null || !$app->has('customers')) {
            return '';
        }
        $id = (int) $row['id'];
        $addresses = $app->service('customers')->addresses($id);
        $html = '<div class="af-card"><div class="af-card-head"><h2>' . e(t('Addresses')) . '</h2></div><div class="af-card-body af-stack">';
        if ($addresses === []) {
            $html .= '<p class="af-muted">' . e(t('No addresses yet.')) . '</p>';
        }
        foreach (array_slice($addresses, 0, 5) as $address) {
            $name = trim(Rules::str($address['first_name']) . ' ' . Rules::str($address['last_name']));
            $badges = '';
            if ((int) $address['default_shipping'] === 1) {
                $badges .= ' ' . Admin::badge(t('Shipping'), 'info');
            }
            if ((int) $address['default_billing'] === 1) {
                $badges .= ' ' . Admin::badge(t('Billing'), 'info');
            }
            $html .= '<div><strong>' . e($name) . '</strong>' . $badges . '<br><span class="af-small af-muted">'
                . e(Rules::str($address['line1']) . ', ' . Rules::str($address['postcode']) . ' ' . Rules::str($address['city']) . ' (' . Rules::str($address['country']) . ')') . '</span></div>';
        }
        if (count($addresses) > 5) {
            $html .= '<p class="af-small af-muted">' . e(tn('{n} more address', '{n} more addresses', count($addresses) - 5)) . '</p>';
        }
        $html .= '<a class="af-btn" href="' . e($app->url()->plain('/admin/customers/' . $id . '/addresses')) . '">' . Admin::icon('edit') . e(t('Manage addresses')) . '</a>';
        $html .= '</div></div>';

        $html .= self::lastOrders($row, $app);

        $html .= '<div class="af-card"><div class="af-card-head"><h2>' . e(t('Summary')) . '</h2></div><div class="af-card-body"><dl class="af-kv">'
            . '<dt>' . e(t('Orders')) . '</dt><dd>' . (int) $row['orders_count'] . '</dd>'
            . '<dt>' . e(t('Total spent')) . '</dt><dd>' . e(money((int) $row['total_spent'])) . '</dd>'
            . '<dt>' . e(t('Joined')) . '</dt><dd>' . e(self::when($app, $row['created_at'])) . '</dd>'
            . '<dt>' . e(t('Last sign-in')) . '</dt><dd>' . e(self::when($app, $row['last_login_at'])) . '</dd>'
            . '<dt>' . e(t('Email verified')) . '</dt><dd>' . e((string) $row['email_verified_at'] !== '' ? t('Yes') : t('No')) . '</dd>'
            . '<dt>' . e(t('Guest')) . '</dt><dd>' . e((int) $row['guest'] === 1 ? t('Yes') : t('No')) . '</dd>'
            . '</dl></div></div>';

        return $html;
    }

    /**
     * Los últimos pedidos del cliente, con su estado y enlace a cada uno (14/11/2026: «la ficha del cliente no lista sus pedidos:
     * hay que volver a Pedidos y buscar por correo»). Solo para quien puede ver pedidos.
     *
     * @param array<string,mixed> $row
     */
    private static function lastOrders(array $row, App $app): string
    {
        if (!$app->has('orders') || !$app->auth()->can('orders.view')) {
            return '';
        }
        $db = $app->db();
        $orders = $db->select(
            'SELECT o.id, o.number, o.total, o.currency, o.created_at, o.test, s.name AS state, s.color AS color FROM {orders} o '
            . 'LEFT JOIN {order_states} s ON s.code = o.status WHERE o.customer_id = ? ORDER BY o.id DESC LIMIT 5',
            [(int) $row['id']]
        );
        $html = '<div class="af-card"><div class="af-card-head"><h2>' . e(t('Last orders')) . '</h2></div><div class="af-card-body af-stack">';
        if ($orders === []) {
            $html .= '<p class="af-muted">' . e(t('No orders yet.')) . '</p>';
        }
        foreach ($orders as $order) {
            $html .= '<div><a href="' . e($app->url()->plain('/admin/orders/' . (int) $order['id'])) . '"><strong>' . e((string) $order['number']) . '</strong></a> '
                . Admin::badge(t((string) $order['state']), (string) ($order['color'] !== null ? $order['color'] : 'info'))
                . ((int) $order['test'] === 1 ? ' ' . Admin::badge(t('Test order'), 'warn') : '')
                . '<br><span class="af-small af-muted">' . e(money((int) $order['total'], (string) $order['currency']) . ' · ' . self::when($app, $order['created_at'])) . '</span></div>';
        }
        $html .= '<a class="af-btn" href="' . e($app->url()->plain('/admin/orders', ['q' => (string) $row['email']])) . '">' . e(t('See all their orders')) . '</a>';

        return $html . '</div></div>';
    }

    /**
     * Ficha de solo lectura, para quien puede ver clientes pero no cambiarlos.
     *
     * @param array<string,mixed> $row
     */
    public static function show(array $row, App $app): string
    {
        $group = $app->has('customers') ? $app->service('customers')->group($row['group_id'] !== null ? (int) $row['group_id'] : null) : ['name' => ''];
        $pairs = [
            t('Email') => (string) $row['email'],
            t('First name') => Rules::str($row['first_name']),
            t('Last name') => Rules::str($row['last_name']),
            t('Company') => Rules::str($row['company']),
            t('Tax ID') => Rules::str($row['vat_id']),
            t('Phone') => Rules::str($row['phone']),
            t('Group') => (string) $group['name'],
            t('Language') => Rules::str($row['locale']),
            t('Active') => (int) $row['active'] === 1 ? t('Yes') : t('No'),
            t('Accepts marketing emails') => (int) $row['marketing'] === 1 ? t('Yes') : t('No'),
        ];
        $html = '<div class="af-grid"><div class="af-col-8"><div class="af-card"><div class="af-card-body"><dl class="af-kv">';
        foreach ($pairs as $label => $value) {
            $html .= '<dt>' . e($label) . '</dt><dd>' . ($value !== '' ? e($value) : '<span class="af-muted">—</span>') . '</dd>';
        }

        return $html . '</dl></div></div></div><div class="af-col-4 af-stack">' . self::side($row, $app) . '</div></div>';
    }

    /**
     * Botones de la cabecera de la ficha: descargar los datos de la persona y anonimizarla.
     *
     * @param array<string,mixed>|null $row
     */
    public static function actions(?array $row, App $app): string
    {
        if ($row === null || !$app->auth()->can('customers.privacy')) {
            return '';
        }
        $id = (int) $row['id'];

        return '<a class="af-btn" href="' . e($app->url()->plain('/admin/customers/' . $id . '/data.json')) . '">' . Admin::icon('download') . e(t('Download data')) . '</a>'
            . '<form method="post" action="' . e($app->url()->plain('/admin/customers/' . $id . '/anonymise')) . '" class="af-inline">' . Admin::tokenField($app)
            . '<button type="submit" class="af-btn af-btn-danger" data-af-confirm="' . e(t('Anonymise this customer? Their personal data will be erased and it cannot be undone.')) . '">'
            . Admin::icon('shield') . e(t('Anonymise')) . '</button></form>';
    }

    // ------------------------------------------------------------------ grupos

    /**
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function groupBeforeSave(array $values, ?array $current, App $app): array
    {
        if ($current !== null && (int) $current['is_default'] === 1 && empty($values['is_default'])) {
            return ['_errors' => ['is_default' => t('There must always be a default group. Make another group the default instead.')]];
        }

        return $values;
    }

    /**
     * Solo hay un grupo por defecto: el último que se marca.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     */
    public static function groupAfterSave(int $id, array $values, ?array $current, App $app): void
    {
        if (!empty($values['is_default'])) {
            $app->db()->run('UPDATE {customer_groups} SET is_default = 0 WHERE id <> ?', [$id]);
        }
    }

    /**
     * No se borra el grupo por defecto ni uno con clientes.
     *
     * @param array<string,mixed> $row
     */
    public static function groupBeforeDelete(array $row, App $app): ?string
    {
        if ((int) $row['is_default'] === 1) {
            return t('The default group cannot be deleted.');
        }
        if ($app->db()->value('SELECT id FROM {customers} WHERE group_id = ?', [(int) $row['id']]) !== null) {
            return t('This group has customers. Move them to another group first.');
        }

        return null;
    }

    /** Fecha y hora legibles de un valor SQL, o '' si no hay. */
    private static function when(App $app, $sql): string
    {
        $ts = Clock::parse(is_string($sql) ? $sql : '');

        return $ts > 0 ? $app->i18n()->date($ts, 'medium', $app->site()->timezone()) : '';
    }
}

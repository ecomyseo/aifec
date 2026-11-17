<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Coupons;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;

/**
 * Piezas del panel de cupones: listado con sus filtros, formulario con pestañas, validación al guardar,
 * borrado y la columna lateral con los últimos usos.
 */
final class CouponsAdmin
{
    // ------------------------------------------------------------------ listado

    /**
     * Configuración del listado. Es una función porque los filtros de estado dependen de la hora de ahora.
     *
     * @return array<string,mixed>
     */
    public static function listConfig(App $app): array
    {
        $now = $app->clock()->sql();
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now) !== 1) {
            throw new \UnexpectedValueException('The clock gave a date that is not valid');
        }

        return [
            'select' => '*',
            'search' => ['code', 'note'],
            'filters' => [
                'kind' => ['label' => 'Type', 'options' => ['percent' => 'Percentage', 'fixed' => 'Fixed amount', 'free_shipping' => 'Free shipping']],
                // El único valor que entra en estas condiciones es la fecha del reloj del sitio, con el formato comprobado arriba.
                'status' => ['label' => 'Status', 'options' => ['valid' => 'Valid', 'expired' => 'Expired', 'used_up' => 'Used up'], 'sql' => [
                    'valid' => "active = 1 AND (starts_at IS NULL OR starts_at <= '" . $now . "') AND (ends_at IS NULL OR ends_at >= '" . $now . "') AND (max_uses = 0 OR uses < max_uses)",
                    'expired' => "ends_at IS NOT NULL AND ends_at < '" . $now . "'",
                    'used_up' => 'max_uses > 0 AND uses >= max_uses',
                ]],
            ],
            'columns' => [
                'code' => ['label' => 'Code', 'link' => true, 'sort' => true],
                'discount' => ['label' => 'Discount', 'render' => static function (array $row, App $app): string {
                    return e(self::describe($row, $app));
                }],
                'uses' => ['label' => 'Uses', 'sort' => true, 'render' => static function (array $row, App $app): string {
                    $max = (int) $row['max_uses'];
                    $html = e((string) (int) $row['uses'] . ' / ' . ($max > 0 ? (string) $max : '∞'));
                    if ($max > 0 && (int) $row['uses'] >= $max) {
                        $html .= ' ' . Admin::badge(t('Used up'), 'warn');
                    }

                    return '<span class="af-nowrap">' . $html . '</span>';
                }],
                'ends_at' => ['label' => 'Validity', 'sort' => true, 'hide_sm' => true, 'render' => static function (array $row, App $app): string {
                    return self::validity($row, $app);
                }],
                'active' => ['label' => 'Active', 'type' => 'bool', 'sort' => true],
            ],
            'sort' => ['id', 'desc'],
            'per_page' => 25,
            'export' => false,
        ];
    }

    /**
     * Valor del descuento para enseñarlo: «10 %», «5,00 €» o «Envío gratis».
     *
     * @param array<string,mixed> $row
     */
    public static function describe(array $row, App $app): string
    {
        $kind = (string) $row['kind'];
        if ($kind === 'percent') {
            $hundredths = (int) $row['percent'];
            $decimals = $hundredths % 100 === 0 ? 0 : ($hundredths % 10 === 0 ? 1 : 2);

            return $app->i18n()->number($hundredths / 100, $decimals) . ' %';
        }
        if ($kind === 'fixed') {
            return $app->i18n()->money((int) $row['amount'], $app->settings()->str('shop.currency', 'EUR'));
        }

        return t('Free shipping');
    }

    /**
     * Fechas de inicio y fin, con una marca si ya ha caducado.
     *
     * @param array<string,mixed> $row
     */
    private static function validity(array $row, App $app): string
    {
        $from = Clock::parse(isset($row['starts_at']) ? (string) $row['starts_at'] : null);
        $to = Clock::parse(isset($row['ends_at']) ? (string) $row['ends_at'] : null);
        if ($from === 0 && $to === 0) {
            return '<span class="af-muted">—</span>';
        }
        $tz = $app->site()->timezone();
        $date = static function (int $time) use ($app, $tz): string {
            return $app->i18n()->date($time, 'short', $tz);
        };
        if ($from > 0 && $to > 0) {
            $text = $date($from) . ' – ' . $date($to);
        } elseif ($from > 0) {
            $text = t('From {date}', ['date' => $date($from)]);
        } else {
            $text = t('Until {date}', ['date' => $date($to)]);
        }
        $html = '<span class="af-nowrap">' . e($text) . '</span>';
        if ($to > 0 && $to < $app->clock()->now()) {
            $html .= ' ' . Admin::badge(t('Expired'), 'bad');
        }

        return $html;
    }

    // ------------------------------------------------------------------ formulario

    /**
     * Formulario con dos pestañas. Con ?generate=1 en el alta, el código sale ya relleno con uno al azar.
     * Los campos de productos y de categorías solo están si existe el servicio «catalog».
     *
     * @return array<string,mixed>
     */
    public static function formDefinition(App $app, ?int $id): array
    {
        $code = ['label' => 'Code', 'rules' => 'required|max:40|unique:coupons,code', 'tab' => 'cpmain', 'width' => 'md',
            'hint' => 'The customer types it in the cart. It is saved in capital letters.'];
        if ($id === null && $app->request()->str('generate') === '1' && $app->has('coupons')) {
            $code['default'] = $app->service('coupons')->generateCode();
        }
        $fields = ['code' => $code];
        if ($id === null) {
            $fields['code_generate'] = ['type' => 'html', 'label' => '', 'tab' => 'cpmain', 'html' => static function (array $values, App $app): string {
                return '<a class="af-btn af-btn-sm" href="' . e(Admin::resourceUrl($app, 'coupons', 'new', ['generate' => 1])) . '">' . Admin::icon('plus') . e(t('Generate a code')) . '</a>';
            }];
        }
        $fields += [
            'kind' => ['type' => 'select', 'label' => 'Type', 'tab' => 'cpmain', 'default' => 'percent', 'rules' => 'required',
                'options' => ['percent' => 'Percentage', 'fixed' => 'Fixed amount', 'free_shipping' => 'Free shipping']],
            'percent' => ['type' => 'percent', 'label' => 'Percentage', 'scale' => 100, 'rules' => 'nullable', 'tab' => 'cpmain',
                'hint' => 'Between 0.01 and 100.', 'show_if' => ['kind' => 'percent']],
            'amount' => ['type' => 'money', 'label' => 'Amount', 'rules' => 'nullable', 'tab' => 'cpmain',
                'hint' => 'Written like the product prices. Greater than zero.', 'show_if' => ['kind' => 'fixed']],
            'active' => ['type' => 'toggle', 'label' => 'Active', 'default' => 1, 'tab' => 'cpmain'],
            'note' => ['label' => 'Internal note', 'rules' => 'nullable|max:191', 'tab' => 'cpmain'],
            'starts_at' => ['type' => 'datetime', 'label' => 'Starts', 'tab' => 'cplimits'],
            'ends_at' => ['type' => 'datetime', 'label' => 'Ends', 'tab' => 'cplimits'],
            'max_uses' => ['type' => 'number', 'label' => 'Maximum uses', 'rules' => 'nullable|min:0', 'hint' => '0 = no limit.', 'default' => 0, 'tab' => 'cplimits'],
            'max_uses_per_customer' => ['type' => 'number', 'label' => 'Uses per customer', 'rules' => 'nullable|min:0', 'hint' => '0 = no limit.', 'default' => 0, 'tab' => 'cplimits'],
            'min_subtotal' => ['type' => 'money', 'label' => 'Minimum order amount', 'rules' => 'nullable', 'hint' => 'Leave it empty for no minimum.', 'tab' => 'cplimits'],
            'first_order_only' => ['type' => 'toggle', 'label' => 'Only for the first order', 'default' => 0, 'tab' => 'cplimits'],
            'exclude_sale' => ['type' => 'toggle', 'label' => 'Not valid on items on sale', 'default' => 0, 'tab' => 'cplimits'],
            'combinable' => ['type' => 'toggle', 'label' => 'Can be added to other coupons', 'default' => 0, 'tab' => 'cplimits'],
            'customer_id' => ['type' => 'relation', 'resource' => 'customers', 'label' => 'Limited to one customer', 'tab' => 'cplimits'],
        ];
        if ($app->has('catalog')) {
            $fields['products'] = ['type' => 'tags', 'label' => 'Limited to products', 'hint' => 'Product numbers separated by commas. Empty = all products.', 'tab' => 'cplimits'];
            $fields['categories'] = ['type' => 'checks', 'label' => 'Limited to categories', 'hint' => 'Nothing ticked = all categories.', 'translate' => false, 'tab' => 'cplimits',
                'options' => static function (App $app): array {
                    return self::categoryOptions($app);
                }];
        }

        return ['tabs' => ['cpmain' => 'Coupon', 'cplimits' => 'Limits'], 'fields' => $fields];
    }

    /**
     * @return array<string,string> identificador => nombre de la categoría en el idioma principal
     */
    private static function categoryOptions(App $app): array
    {
        $db = $app->db();
        if (!$db->tableExists('category_i18n')) {
            return [];
        }
        $options = [];
        foreach ($db->select('SELECT category_id, name FROM {category_i18n} WHERE lang = ? ORDER BY name LIMIT 500', [$app->defaultLang()]) as $row) {
            $options[(string) $row['category_id']] = (string) $row['name'];
        }

        return $options;
    }

    // ------------------------------------------------------------------ guardar y borrar

    /**
     * Normaliza el código, valida lo que depende del tipo y de las fechas, y deja los números en su sitio.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function beforeSave(array $values, ?array $current, App $app): array
    {
        $db = $app->db();
        $errors = [];

        $code = Coupons::normalizeCode(isset($values['code']) && is_string($values['code']) ? $values['code'] : '');
        if ($code === '' || preg_match('/^[\p{L}\p{N}_-]{1,40}$/u', $code) !== 1) {
            $errors['code'] = t('Use only letters, numbers, hyphens and underscores');
        } else {
            $sql = 'SELECT id FROM {coupons} WHERE code = ?';
            $params = [$code];
            if ($current !== null) {
                $sql .= ' AND id <> ?';
                $params[] = (int) $current['id'];
            }
            if ($db->value($sql, $params) !== null) {
                $errors['code'] = t('Another coupon already has this code');
            }
        }
        $values['code'] = $code;

        $kind = isset($values['kind']) ? (string) $values['kind'] : 'percent';
        $percent = isset($values['percent']) && is_numeric($values['percent']) ? (int) $values['percent'] : null;
        $amount = isset($values['amount']) && is_numeric($values['amount']) ? (int) $values['amount'] : null;
        if ($kind === 'percent') {
            if ($percent === null || $percent < 1 || $percent > 10000) {
                $errors['percent'] = t('Enter a percentage between 0.01 and 100');
            }
            $amount = 0;
        } elseif ($kind === 'fixed') {
            if ($amount === null || $amount <= 0) {
                $errors['amount'] = t('Enter an amount greater than zero');
            }
            $percent = 0;
        } else {
            $percent = 0;
            $amount = 0;
        }
        $values['percent'] = (int) $percent;
        $values['amount'] = (int) $amount;

        $starts = Clock::parse(isset($values['starts_at']) ? (string) $values['starts_at'] : null);
        $ends = Clock::parse(isset($values['ends_at']) ? (string) $values['ends_at'] : null);
        if ($starts > 0 && $ends > 0 && $ends <= $starts) {
            $errors['ends_at'] = t('The end must be after the start');
        }

        foreach (['max_uses', 'max_uses_per_customer', 'min_subtotal'] as $field) {
            $values[$field] = isset($values[$field]) && is_numeric($values[$field]) ? max(0, (int) $values[$field]) : 0;
        }

        $customerId = isset($values['customer_id']) && is_numeric($values['customer_id']) ? (int) $values['customer_id'] : 0;
        if ($customerId > 0 && $db->tableExists('customers') && $db->find('customers', ['id' => $customerId]) === null) {
            $errors['customer_id'] = t('This customer does not exist');
        }
        $values['customer_id'] = $customerId > 0 ? $customerId : null;

        foreach (['products', 'categories'] as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = Coupons::ids($values[$field]);
            }
        }

        $note = isset($values['note']) && is_string($values['note']) ? trim($values['note']) : '';
        $values['note'] = $note !== '' ? $note : null;

        if ($errors !== []) {
            return ['_errors' => $errors];
        }

        return $values;
    }

    /**
     * Un cupón que ya se ha usado no se borra: su historial es el de los pedidos.
     *
     * @param array<string,mixed> $row
     */
    public static function beforeDelete(array $row, App $app): ?string
    {
        $used = (int) $row['uses'] > 0 || $app->db()->value('SELECT id FROM {coupon_uses} WHERE coupon_id = ?', [(int) $row['id']]) !== null;

        return $used ? t('This coupon has been used and cannot be deleted. Turn it off instead.') : null;
    }

    // ------------------------------------------------------------------ columna derecha

    /**
     * Los últimos diez usos del cupón: pedido, correo, importe y fecha.
     *
     * @param array<string,mixed>|null $row
     */
    public static function side(?array $row, App $app): string
    {
        if ($row === null) {
            return '';
        }
        $db = $app->db();
        $uses = $db->select('SELECT * FROM {coupon_uses} WHERE coupon_id = ? ORDER BY created_at DESC, id DESC LIMIT 10', [(int) $row['id']]);
        $numbers = [];
        if ($uses !== [] && $db->tableExists('orders') && in_array('number', $db->columns('orders'), true)) {
            $params = [];
            $ids = array_map(static function (array $use): int {
                return (int) $use['order_id'];
            }, $uses);
            $placeholders = $db->in($ids, $params);
            foreach ($db->select('SELECT id, number FROM {orders} WHERE id IN (' . $placeholders . ')', $params) as $order) {
                $numbers[(int) $order['id']] = (string) $order['number'];
            }
        }
        $max = (int) $row['max_uses'];
        $html = '<div class="af-card"><div class="af-card-head"><h2>' . e(t('Uses')) . '</h2></div><div class="af-card-body af-stack">'
            . '<p><strong>' . e((string) (int) $row['uses'] . ' / ' . ($max > 0 ? (string) $max : '∞')) . '</strong></p>';
        if ($uses === []) {
            $html .= '<p class="af-muted">' . e(t('Nobody has used this coupon yet.')) . '</p>';
        } else {
            $html .= '<div class="af-table-wrap"><table class="af-table"><thead><tr><th scope="col">' . e(t('Order')) . '</th><th scope="col">' . e(t('Email')) . '</th>'
                . '<th scope="col" class="af-num">' . e(t('Amount')) . '</th><th scope="col">' . e(t('Date')) . '</th></tr></thead><tbody>';
            $tz = $app->site()->timezone();
            foreach ($uses as $use) {
                $orderId = (int) $use['order_id'];
                $time = Clock::parse(isset($use['created_at']) ? (string) $use['created_at'] : null);
                $html .= '<tr><td>' . e(isset($numbers[$orderId]) ? $numbers[$orderId] : '#' . $orderId) . '</td>'
                    . '<td>' . (isset($use['email']) && (string) $use['email'] !== '' ? e((string) $use['email']) : '<span class="af-muted">—</span>') . '</td>'
                    . '<td class="af-num">' . e($app->i18n()->money((int) $use['amount'], $app->settings()->str('shop.currency', 'EUR'))) . '</td>'
                    . '<td class="af-nowrap">' . e($time > 0 ? $app->i18n()->date($time, 'medium', $tz) : '') . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        return $html . '</div></div>';
    }
}

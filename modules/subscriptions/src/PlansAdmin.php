<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Subscriptions;

use Aifec\Kernel\App;

final class PlansAdmin
{
    public static function form(App $app): array
    {
        $classes = [];
        foreach ($app->service('tax')->classes() as $id => $class) {
            $classes[(string) $id] = $class['name'];
        }

        return ['fields' => [
            'name' => ['label' => 'Name', 'rules' => 'required|max:120'],
            'description' => ['type' => 'textarea', 'label' => 'Service included', 'rules' => 'nullable|max:5000'],
            'amount' => ['type' => 'money', 'label' => 'Monthly price', 'rules' => 'required', 'hint' => 'Written like product prices. Taxes are calculated for the billing address before the customer confirms.'],
            'tax_class_id' => ['type' => 'select', 'label' => 'Tax class', 'options' => $classes, 'rules' => 'nullable|int'],
            'active' => ['type' => 'toggle', 'label' => 'Active', 'default' => 1],
        ]];
    }

    public static function save(array $values, ?array $current, App $app): array
    {
        if ((int) ($values['amount'] ?? 0) <= 0 || (int) $values['amount'] > 100000000) {
            throw new \InvalidArgumentException(t('The monthly price must be greater than zero.'));
        }
        if (!empty($values['tax_class_id']) && !isset($app->service('tax')->classes()[(int) $values['tax_class_id']])) {
            throw new \InvalidArgumentException(t('Choose a valid tax class.'));
        }
        return $values;
    }

    public static function delete(array $current, App $app): void
    {
        if ((int) $app->db()->value('SELECT COUNT(*) FROM {subscriptions} WHERE plan_id = ?', [(int) $current['id']]) > 0) {
            throw new \InvalidArgumentException(t('A used plan cannot be deleted. Turn it off instead.'));
        }
    }
}

<?php
/**
 * AIFEC — ajustes de las devoluciones y del desistimiento: plazos, envío de vuelta, motivos, exclusiones y textos legales.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

return [
    'title' => 'Returns',
    'description' => 'Return and withdrawal periods, shipping back, reasons and legal texts',
    'icon' => 'refresh',
    'cap' => 'returns.manage',
    'help' => "The periods count from the delivery of the order (when it is marked as completed) or, if there is no delivery date, from the shipment plus the days of transit.\nThe legal texts are only a model: review them, or have them reviewed, before you publish them. The shop is responsible for what they say.\nThe products excluded by law from the right of withdrawal are marked with the extra data «no_withdrawal» of the product, or by their category in the Reasons and exclusions tab.",
    'tabs' => [
        'rtgeneral' => ['label' => 'Periods', 'fields' => [
            'returns.days' => ['type' => 'number', 'label' => 'Days to ask for a return', 'default' => 30, 'rules' => 'int|min:0|max:365', 'width' => 'sm'],
            'returns.withdrawal_days' => ['type' => 'number', 'label' => 'Days of the right of withdrawal', 'default' => 14, 'rules' => 'int|min:0|max:365', 'width' => 'sm',
                'hint' => 'Fourteen days is the legal minimum for consumers in the European Union.'],
            'returns.transit_days' => ['type' => 'number', 'label' => 'Days of transit', 'default' => 3, 'rules' => 'int|min:0|max:60', 'width' => 'sm',
                'hint' => 'Added to the shipping date when the order has no delivery date.'],
            'returns.refund_shipping' => ['type' => 'toggle', 'label' => 'Refund the outgoing shipping when the whole order is withdrawn', 'default' => true],
            'returns.auto_approve_withdrawal' => ['type' => 'toggle', 'label' => 'Accept the withdrawals automatically', 'default' => false],
        ]],
        'rtshipping' => ['label' => 'Shipping back', 'fields' => [
            'returns.return_shipping_payer' => ['type' => 'textarea', 'label' => 'Who pays the shipping back', 'rows' => 3, 'rules' => 'nullable|max:1000',
                'hint' => 'Shown to the customer. Empty uses the standard text.'],
            'returns.shipping_instructions' => ['type' => 'textarea', 'label' => 'Shipping instructions', 'rows' => 5, 'rules' => 'nullable|max:2000',
                'hint' => 'Address and how to pack. Use {number} for the return number. Empty uses the standard text.'],
        ]],
        'rtreasons' => ['label' => 'Reasons and exclusions', 'fields' => [
            'returns.reasons' => ['type' => 'textarea', 'label' => 'Reasons to return', 'rows' => 6, 'rules' => 'nullable|max:2000',
                'hint' => 'One per line, as code|text. The code «defective» allows photos. Empty uses the standard reasons.'],
            'returns.excluded_categories' => ['type' => 'checks', 'label' => 'Categories excluded from the right of withdrawal', 'default' => [], 'translate' => false,
                'options' => static function (App $app): array {
                    $out = [];
                    $sql = 'SELECT c.id AS id, i.name AS name FROM {categories} c LEFT JOIN {category_i18n} i ON i.category_id = c.id AND i.lang = ? ORDER BY c.path, c.id LIMIT 500';
                    foreach ($app->db()->select($sql, [$app->defaultLang()]) as $row) {
                        $out[(string) $row['id']] = (string) ($row['name'] !== null ? $row['name'] : '#' . $row['id']);
                    }

                    return $out;
                }],
        ]],
        'rtlegal' => ['label' => 'Legal texts', 'fields' => [
            'returns.withdrawal_text' => ['type' => 'textarea', 'label' => 'Text of the withdrawal page', 'rows' => 8, 'rules' => 'nullable|max:5000',
                'hint' => 'A model that the shop must review. Empty uses the standard model.'],
            'returns.exclusions_text' => ['type' => 'textarea', 'label' => 'Text about the products excluded by law', 'rows' => 4, 'rules' => 'nullable|max:2000',
                'hint' => 'A model that the shop must review. Empty uses the standard model.'],
        ]],
    ],
];

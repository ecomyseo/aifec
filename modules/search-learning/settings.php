<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Search learning preferences', 'description' => 'Suggested form values; these settings never start a round',
    'icon' => 'search', 'cap' => 'search_learning.manage',
    'help' => 'Each round needs a separate authorisation and an explicit budget. These preferences do not start AI calls or apply proposals.',
    'tabs' => ['general' => ['label' => 'Suggested values', 'fields' => [
        'search_learning.default_profiles' => ['type' => 'number', 'label' => 'Suggested number of simulated profiles', 'default' => 5, 'rules' => 'required|int|min:1|max:20'],
        'search_learning.default_history_days' => ['type' => 'number', 'label' => 'Suggested history period in days', 'default' => 7, 'rules' => 'required|int|min:1|max:365'],
        'search_learning.default_tier' => ['type' => 'select', 'label' => 'Suggested AI tier', 'options' => ['economy' => 'Economy', 'standard' => 'Standard', 'advanced' => 'Advanced'], 'default' => 'economy'],
    ]]],
];

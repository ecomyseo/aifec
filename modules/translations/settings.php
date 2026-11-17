<?php
/**
 * AIFEC — ajustes de las traducciones: el enlace a la pantalla de cada idioma (el módulo no ocupa ninguna entrada del menú), si lo
 * que traduce la IA se publica sin revisar (apagado de serie) y qué vende la tienda (va con cada encargo a la IA).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Kernel\App;

return [
    'title' => 'Translations',
    'description' => 'What is missing in each language and how to translate it, by hand or with AI',
    'icon' => 'globe',
    'cap' => 'translations.edit',
    'help' => "What the AI translates waits as a proposal until a person approves it.\nThe glossary of each language is written in Translations, in its tab.",
    'tabs' => [
        'trgeneral' => ['label' => 'General', 'fields' => [
            'translations.open' => ['type' => 'html', 'label' => '', 'html' => static function ($values, App $app): string {
                return '<a class="af-btn af-btn-primary" href="' . e($app->url()->plain('/admin/translations')) . '" data-tr-open>' . Admin::icon('globe') . e(t('Open the translations of each language')) . '</a>';
            }],
            'translations.publish_unreviewed' => ['type' => 'toggle', 'label' => 'Publish the AI translations without review', 'hint' => 'Off: nothing the AI translates is shown in the shop until someone approves it.', 'default' => false],
            'translations.sector' => ['type' => 'text', 'label' => 'What the shop sells', 'hint' => 'A few words that go with every translation request, so that the AI picks the right words.', 'rules' => 'max:160', 'default' => ''],
        ]],
    ],
];

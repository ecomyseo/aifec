<?php
/**
 * AIFEC — ajustes del consentimiento: el aviso (textos y enlace), la versión de la política y el registro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Cookie consent',
    'description' => 'The cookie notice, the choices of your visitors and the proof of each one',
    'icon' => 'shield',
    'cap' => 'consent.edit',
    'help' => "The notice is the same for every visitor, so your pages stay in the page cache.\nA script of a third party is only run when the visitor has accepted its category. In your templates, write it with consent_script('statistics', 'https://…').\nIf you leave the title or the message empty, the standard texts are used in each language.\nWhen you change the policy version, everybody is asked again. Do it when you add a service that uses cookies.\nIf the browser sends the Global Privacy Control signal, marketing is denied without asking.",
    'tabs' => [
        'cnnotice' => ['label' => 'Notice', 'fields' => [
            'consent.enabled' => ['type' => 'toggle', 'label' => 'Show the cookie notice', 'default' => true],
            'consent.title' => ['type' => 'text', 'label' => 'Title', 'hint' => 'Empty: the standard text, translated.', 'rules' => 'nullable|max:80'],
            'consent.message' => ['type' => 'textarea', 'label' => 'Message', 'hint' => 'Empty: the standard text, translated.', 'rules' => 'nullable|max:500'],
            'consent.policy_url' => ['type' => 'url', 'label' => 'Cookie policy page', 'hint' => 'A full address or one of your own pages, like /politica-de-cookies.', 'rules' => 'nullable|max:255'],
        ]],
        'cnpolicy' => ['label' => 'Policy and proof', 'fields' => [
            'consent.version' => ['type' => 'number', 'label' => 'Policy version', 'default' => 1, 'rules' => 'int|min:1|max:999999', 'width' => 'sm', 'hint' => 'Change it to ask everybody again.'],
            'consent.log' => ['type' => 'toggle', 'label' => 'Keep the proof of each decision (5 years)', 'default' => true],
        ]],
    ],
];

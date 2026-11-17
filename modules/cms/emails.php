<?php
/**
 * AIFEC — plantillas de correo de contenidos: el aviso a la tienda cuando alguien rellena un formulario.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

// No es del sistema: con el correo retenido (modo de seguridad) el aviso espera a que una persona lo libere; el mensaje queda
// siempre guardado y visible en el panel, con o sin aviso.
return [
    'form_entry' => [
        'subject' => 'New message in the form {form} of {shop}',
        'view' => 'cms::emails/form_entry',
        'system' => false,
        'description' => 'Notice to the shop when someone sends a form of the website',
        'sample' => [
            'form' => 'Contact',
            'fields' => [
                ['label' => 'Name', 'value' => 'Ana'],
                ['label' => 'Email', 'value' => 'ana@cliente.test'],
                ['label' => 'Message', 'value' => 'I would like to know if you deliver on Saturdays.'],
            ],
            'entry_id' => 1,
            'entry_url' => 'https://tienda.test/admin/r/form_entries/1',
        ],
    ],
];

<?php
/**
 * AIFEC — catálogo castellano del módulo de pago simulado.
 * La clave es la cadena inglesa tal como está en el código; no se toca. El valor es la traducción.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'Simulated payment' => 'Pago simulado',
    'A payment that charges nothing, to test the shop and for demos' => 'Un pago que no cobra nada, para probar la tienda y para las demos',

    // Ajustes
    'A payment that charges nothing, for tests and demos' => 'Un pago que no cobra nada, para pruebas y demos',
    'It is only offered in demo sites and in sites in debug mode, unless you allow it on a live shop.' => 'Solo se ofrece en los sitios de demostración y en los que están en modo de depuración, salvo que lo permitas en una tienda real.',
    'In Ask mode the customer lands on a test page and chooses whether the payment goes well or badly.' => 'En el modo «Preguntar», el cliente llega a una página de prueba y elige si el pago sale bien o mal.',
    'A simulated payment never moves money: its orders are marked as test orders.' => 'Un pago simulado nunca mueve dinero: sus pedidos quedan marcados como pedidos de prueba.',
    'Offer the simulated payment' => 'Ofrecer el pago simulado',
    'What happens when the customer chooses it' => 'Qué pasa cuando el cliente lo elige',
    'Ask on a test page' => 'Preguntar en una página de prueba',
    'Approve every payment' => 'Dar todos los pagos por buenos',
    'Decline every payment' => 'Rechazar todos los pagos',
    'Allow it on a live shop' => 'Permitirlo en una tienda real',
    'Warning: real customers would place orders without paying. Leave it off unless you know why you need it.' => 'Atención: los clientes reales podrían hacer pedidos sin pagar. Déjalo apagado salvo que sepas para qué lo necesitas.',
    'Texts' => 'Textos',
    'Title' => 'Título',
    'Description' => 'Descripción',
    'Empty: the standard title.' => 'Vacío: el título de serie.',
    'Empty: the standard description.' => 'Vacío: la descripción de serie.',

    // Lo que ve el cliente
    'Test payment' => 'Pago de prueba',
    'A payment that does not charge anything. Only for tests and demos.' => 'Un pago que no cobra nada. Solo para pruebas y demostraciones.',
    'The simulated payment was declined.' => 'El pago simulado se ha rechazado.',
    'This is a test: no money will be charged.' => 'Esto es una prueba: no se cobrará ningún dinero.',
    'Order {number}' => 'Pedido {number}',
    'Simulate a successful payment' => 'Simular pago correcto',
    'Simulate a declined payment' => 'Simular pago rechazado',
];

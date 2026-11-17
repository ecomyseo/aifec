<?php
/**
 * AIFEC — catálogo castellano del módulo de contra reembolso.
 * La clave es la cadena inglesa tal como está en el código; no se toca. El valor es la traducción.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'Cash on delivery' => 'Contra reembolso',
    'The customer pays when the order is delivered' => 'El cliente paga cuando se le entrega el pedido',

    // Ajustes
    'Where it is offered and what it costs' => 'Dónde se ofrece y cuánto cuesta',
    'Cash on delivery is only offered for orders that need shipping.' => 'El contra reembolso solo se ofrece en los pedidos que necesitan envío.',
    'The fee is a fixed amount plus a percentage of the order total, never less than the minimum.' => 'El recargo es un importe fijo más un porcentaje del total del pedido, y nunca menos que el mínimo.',
    'Amounts are written like your prices: with taxes included or not, as set in the tax settings.' => 'Los importes se escriben como tus precios: con impuestos incluidos o sin ellos, según los ajustes de impuestos.',
    'Where' => 'Dónde',
    'Countries' => 'Países',
    'Two-letter country codes, for example ES, PT.' => 'Códigos de país de dos letras, por ejemplo ES, PT.',
    'Highest order total' => 'Total de pedido más alto',
    '0 means no limit.' => 'Con 0 no hay límite.',
    'Fee' => 'Recargo',
    'Fixed fee' => 'Recargo fijo',
    'Percentage of the order total' => 'Porcentaje del total del pedido',
    'Minimum fee' => 'Recargo mínimo',
    'Texts' => 'Textos',
    'Title' => 'Título',
    'Description' => 'Descripción',
    'Empty: the standard title.' => 'Vacío: el título de serie.',
    'Empty: the standard description.' => 'Vacío: la descripción de serie.',

    // Lo que ve el cliente
    'You pay when you receive your order.' => 'Pagas al recibir tu pedido.',
    'You will pay when you receive the order.' => 'Pagarás al recibir el pedido.',
    'Amount to pay on delivery: {amount}' => 'Importe a pagar en la entrega: {amount}',
];

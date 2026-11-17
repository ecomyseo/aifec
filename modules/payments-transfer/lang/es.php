<?php
/**
 * AIFEC — catálogo castellano del módulo de transferencia bancaria.
 * La clave es la cadena inglesa tal como está en el código; no se toca. El valor es la traducción.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'Bank transfer' => 'Transferencia bancaria',
    'Payment by bank transfer to your account' => 'Pago por transferencia bancaria a tu cuenta',

    // Ajustes
    'The bank account your customers pay into' => 'La cuenta bancaria en la que pagan tus clientes',
    'Without a valid IBAN the bank transfer is not offered.' => 'Sin un IBAN válido, la transferencia bancaria no se ofrece.',
    'The order stays on hold until you mark it as paid.' => 'El pedido queda en espera hasta que lo marques como pagado.',
    'The customer sees these details on the order page and in the on-hold email.' => 'El cliente ve estos datos en la página del pedido y en el correo de pedido en espera.',
    'Bank account' => 'Cuenta bancaria',
    'Account holder' => 'Titular',
    'IBAN' => 'IBAN',
    'Two letters, two check digits and the account number. Spaces are fine.' => 'Dos letras, dos dígitos de control y el número de cuenta. Los espacios no importan.',
    'Bank' => 'Banco',
    'Days to pay' => 'Días para pagar',
    '0 means no deadline is mentioned.' => 'Con 0 no se menciona ningún plazo.',
    'Extra note' => 'Nota adicional',
    'Shown below the bank details. Plain text.' => 'Se enseña debajo de los datos bancarios. Texto sin formato.',
    'Texts' => 'Textos',
    'Title' => 'Título',
    'Description' => 'Descripción',
    'Empty: the standard title.' => 'Vacío: el título de serie.',
    'Empty: the standard description.' => 'Vacío: la descripción de serie.',
    'The IBAN is not valid: its check digits are wrong. It has not been saved.' => 'El IBAN no es válido: sus dígitos de control están mal. No se ha guardado.',

    // Lo que ve el cliente
    'You will get our bank details and we will prepare your order when the payment arrives.' => 'Recibirás nuestros datos bancarios y prepararemos tu pedido cuando llegue el pago.',
    'Please transfer the amount of your order to this bank account:' => 'Por favor, transfiere el importe de tu pedido a esta cuenta bancaria:',
    'Reference' => 'Concepto',
    'Amount' => 'Importe',
    'Write the order number as the reference so we can find your payment.' => 'Escribe el número de pedido como concepto para que podamos localizar tu pago.',
    'Please pay within {n} day.|Please pay within {n} days.' => 'Por favor, paga en un plazo de {n} día.|Por favor, paga en un plazo de {n} días.',
];

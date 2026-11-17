<?php
/**
 * AIFEC — transferencia bancaria: registra su forma de pago y vigila que el IBAN guardado tenga buenos sus dígitos de control.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\PaymentsTransfer\TransferPayment;
use Aifec\Modules\PaymentsTransfer\TransferSettings;

return static function (App $app): void {
    // Un módulo de pago solo se registra: la caja y el carrito preguntan al servicio y nunca lo conocen por su nombre.
    $app->service('payments')->register(new TransferPayment($app));

    // El IBAN se valida al guardar los ajustes: el formulario comprueba la forma y este gancho los dígitos de control.
    $app->hooks()->on('settings.saved', static function (string $module, array $keys, App $app): void {
        if ($module === 'payments-transfer' && in_array(TransferSettings::IBAN, $keys, true)) {
            TransferSettings::checkIban($app);
        }
    });
};

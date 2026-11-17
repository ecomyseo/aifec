<?php
/**
 * AIFEC — la comprobación del IBAN al guardar los ajustes de la transferencia.
 *
 * El formulario de ajustes del núcleo comprueba la forma del IBAN (regla regex) pero no sabe hacer el módulo 97. Esa parte la
 * hace este gancho, justo después de guardar: un IBAN con los dígitos de control malos NO se queda guardado; vuelve el último
 * bueno (o ninguno) y se avisa con un mensaje rojo. Uno bueno se guarda sin espacios y en mayúsculas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\PaymentsTransfer;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;

final class TransferSettings
{
    public const IBAN = 'payments.transfer.iban';

    /** El último IBAN que pasó la comprobación: es al que se vuelve cuando el nuevo no pasa. */
    public const LAST_VALID = 'payments.transfer.iban_last_valid';

    /**
     * Comprueba el IBAN que acaba de guardar el formulario.
     */
    public static function checkIban(App $app): void
    {
        $settings = $app->settings();
        $typed = trim($settings->str(self::IBAN));
        if ($typed === '') {
            // Vaciarlo es una decisión: la transferencia deja de ofrecerse.
            $settings->set(self::LAST_VALID, '');

            return;
        }
        if (Iban::isValid($typed)) {
            $clean = Iban::normalize($typed);
            $settings->set(self::IBAN, $clean);
            $settings->set(self::LAST_VALID, $clean);

            return;
        }
        $settings->set(self::IBAN, trim($settings->str(self::LAST_VALID)));
        Admin::flash($app, 'bad', t('The IBAN is not valid: its check digits are wrong. It has not been saved.'));
    }
}

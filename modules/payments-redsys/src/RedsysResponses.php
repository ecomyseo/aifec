<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\PaymentsRedsys;

/**
 * Los códigos de respuesta de Redsys que llegan con más frecuencia, con su explicación para quien mira el pedido.
 * Los textos son los de la librería de Redsys; aquí pasan por la traducción.
 */
final class RedsysResponses
{
    /** Explicación traducida de un código de respuesta (0 a 99 = autorizada). */
    public static function text(int $code): string
    {
        if ($code >= 0 && $code <= 99) {
            return t('Transaction approved');
        }
        switch ($code) {
            case 101:
                return t('Card expired');
            case 102:
                return t('Card temporarily suspended or under suspicion of fraud');
            case 104:
                return t('Transaction not allowed for this card');
            case 106:
                return t('PIN attempts exceeded');
            case 116:
                return t('Insufficient funds');
            case 118:
                return t('Card not registered');
            case 125:
                return t('Card not effective');
            case 129:
                return t('Wrong security code (CVV)');
            case 167:
                return t('Suspected fraud');
            case 180:
                return t('Card not valid for this service');
            case 184:
                return t('Cardholder authentication failed');
            case 190:
                return t('Declined by the issuer without a reason');
            case 191:
                return t('Wrong expiry date');
            case 202:
                return t('Card suspended or under suspicion of fraud, retain card');
            case 900:
                return t('Transaction approved for refunds and confirmations');
            case 904:
                return t('Merchant not registered');
            case 909:
                return t('System error');
            case 913:
                return t('Duplicated order number');
            case 944:
                return t('Wrong session');
            case 950:
                return t('Refund not allowed');
            case 9915:
                return t('Payment cancelled by the customer');
            case 9998:
                return t('Transaction waiting for the card data');
            case 9999:
                return t('Transaction redirected to the issuer to authenticate');
        }

        return t('Unknown response');
    }
}

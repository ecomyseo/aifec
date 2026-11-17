<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders;

use Aifec\Modules\Customers\Rules;

/**
 * El teléfono de la entrega: obligatorio en todo pedido que se envía, porque lo exigen todos los transportistas para avisar
 * o resolver una incidencia (16/11/2026). Vale el español de nueve cifras o el internacional con su «+».
 */
final class DeliveryPhone
{
    /** '' si el teléfono vale; si no, el aviso para quien compra. */
    public static function problem(string $phone): string
    {
        if (trim($phone) === '') {
            return t('Enter a phone for the delivery');
        }

        return Rules::phone($phone) === null ? t('The phone number is not valid') : '';
    }
}

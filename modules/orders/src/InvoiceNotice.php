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

use Aifec\Kernel\App;

/**
 * Qué se le dice al cliente de su factura, igual en todas las tiendas y en todos los sitios (caja, página del pedido, correo
 * de confirmación, asistente, API y MCP).
 *
 * 14/11/2026, decisión del usuario: si la tienda no emite las facturas con AIFEC (módulo de facturas apagado o sin emisión
 * automática), se avisa de que las hace su ERP y se mandan por correo; si las emite, la factura va por correo con la
 * confirmación del pedido (`invoices.attach`, encendido de serie).
 */
final class InvoiceNotice
{
    /** AIFEC la emite y va adjunta a la confirmación. */
    public const ATTACHED = 'attached';

    /** AIFEC la emite y se descarga del pedido y de la cuenta (el adjunto está apagado). */
    public const ACCOUNT = 'account';

    /** La tienda no factura con AIFEC: la hace su ERP y la manda por correo. */
    public const ERP = 'erp';

    /** Cuál de los tres casos es esta tienda. */
    public static function mode(App $app): string
    {
        $settings = $app->settings();
        if (!$app->has('invoices') || !$settings->bool('invoices.auto', true)) {
            return self::ERP;
        }

        return $settings->bool('invoices.attach', true) ? self::ATTACHED : self::ACCOUNT;
    }

    /** La frase del correo de confirmación del pago (el que lleva la factura adjunta, si va). */
    public static function mailText(App $app): string
    {
        switch (self::mode($app)) {
            case self::ATTACHED:
                return t('Your invoice is attached to this email.');
            case self::ACCOUNT:
                return t('You can download the invoice from the order page and from your account.');
            default:
                return t('We issue the invoices with our management system (ERP) and send them to you by email.');
        }
    }

    /** La frase para el cliente, en el idioma de ahora. */
    public static function text(App $app): string
    {
        switch (self::mode($app)) {
            case self::ATTACHED:
                return t('We send you the invoice by email, attached to the order confirmation. You can also download it from the order page.');
            case self::ACCOUNT:
                return t('You can download the invoice from the order page and from your account.');
            default:
                return t('We issue the invoices with our management system (ERP) and send them to you by email.');
        }
    }
}

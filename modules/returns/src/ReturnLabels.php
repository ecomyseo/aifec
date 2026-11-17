<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Returns;

/**
 * Los textos de los estados y de los tipos de una devolución, ya traducidos, y el color de cada estado. Un solo sitio para la tienda,
 * el panel y los correos: así el comprobador de traducciones ve cada texto una vez.
 */
final class ReturnLabels
{
    /** El texto de un estado: «requested», «approved», «received», «refunded», «rejected» o «cancelled». */
    public static function status(string $code): string
    {
        switch ($code) {
            case 'requested':
                return t('Requested');
            case 'approved':
                return t('Accepted');
            case 'received':
                return t('Received');
            case 'refunded':
                return t('Refunded');
            case 'rejected':
                return t('Rejected');
            case 'cancelled':
                return t('Cancelled');
        }

        return $code;
    }

    /** El color del estado para las etiquetas del panel: ok, warn, bad o info. */
    public static function kind(string $code): string
    {
        switch ($code) {
            case 'refunded':
                return 'ok';
            case 'rejected':
            case 'cancelled':
                return 'bad';
            case 'requested':
                return 'warn';
        }

        return 'info';
    }

    /** El texto del tipo: devolución o desistimiento. */
    public static function type(string $kind): string
    {
        return $kind === 'withdrawal' ? t('Withdrawal') : t('Return');
    }

    /**
     * Qué se le explica al cliente en la página de su pedido según el estado en que está su devolución.
     */
    public static function customerText(string $code): string
    {
        switch ($code) {
            case 'requested':
                return t('We have your request and we are reviewing it.');
            case 'approved':
                return t('Accepted. Send the products back following the instructions.');
            case 'received':
                return t('We have received the products and we are checking them.');
            case 'refunded':
                return t('Completed: the money has been refunded.');
            case 'rejected':
                return t('We could not accept this return.');
            case 'cancelled':
                return t('This return was cancelled.');
        }

        return '';
    }
}

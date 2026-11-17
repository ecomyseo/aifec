<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Invoices;

use Aifec\Kernel\I18n;

/**
 * Los textos fijos de un PDF en el idioma del pedido, no en el de quien lo pide: una factura se pinta igual la abra quien la abra.
 * Se llama t('texto') como en el resto del código, así el comprobador de traducciones los ve.
 */
final class InvoiceLabels
{
    private I18n $i18n;

    private string $lang;

    public function __construct(I18n $i18n, string $lang)
    {
        $this->i18n = $i18n;
        $this->lang = $lang;
    }

    /**
     * @param array<string,string|int|float> $vars
     */
    public function t(string $text, array $vars = []): string
    {
        return $this->i18n->t($text, $vars, $this->lang);
    }
}

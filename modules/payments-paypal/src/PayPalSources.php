<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\PaymentsPaypal;

use Aifec\Geo\Countries;
use Aifec\Kernel\App;
use Aifec\Payments\PayPal\PayPalFundingSources;

/**
 * Las formas de pago de PayPal vistas desde la tienda: el código con que se guardan en el pedido, el nombre y la frase que
 * ve el cliente (ya traducidos) y la explicación, para quien lleva la tienda, de un «no» de PayPal.
 *
 * Códigos: la cartera conserva «paypal» (los pedidos ya hechos la siguen encontrando para devolver); las demás, «paypal_»
 * más la forma del catálogo (paypal_card, paypal_ideal, paypal_google_pay…). Solo las que se ofrecen: las de redirección y
 * las del guion que están hechas. Las solo catalogadas (pago contra factura) no tienen código.
 */
final class PayPalSources
{
    /** La cartera de PayPal: su forma de pago y su código. */
    public const WALLET = 'paypal';

    /** Prefijo del código de las demás formas de pago. */
    public const PREFIX = 'paypal_';

    /** El código de la forma de pago de la tienda para una forma del catálogo. */
    public static function code(string $source): string
    {
        $source = strtolower(trim($source));

        return $source === self::WALLET ? PayPalPayment::CODE : self::PREFIX . $source;
    }

    /** La forma del catálogo de un código de la tienda («paypal_ideal» → «ideal»), o null si no es una de PayPal. */
    public static function source(string $code): ?string
    {
        $code = strtolower(trim($code));
        if ($code === PayPalPayment::CODE) {
            return self::WALLET;
        }
        if (strpos($code, self::PREFIX) !== 0) {
            return null;
        }
        $source = substr($code, strlen(self::PREFIX));

        return $source !== self::WALLET && PayPalFundingSources::isOfferable($source) ? $source : null;
    }

    /** ¿Se paga con el guion de PayPal en la página de pago de la tienda? */
    public static function usesSdk(string $source): bool
    {
        $known = PayPalFundingSources::get($source);

        return $known !== null && $known['flow'] === PayPalFundingSources::FLOW_SDK && !empty($known['implemented']);
    }

    /**
     * Todos los códigos de PayPal de la tienda, en el orden del catálogo.
     *
     * @return string[]
     */
    public static function codes(): array
    {
        $out = [];
        foreach (array_keys(PayPalFundingSources::offerable()) as $source) {
            $out[] = self::code((string) $source);
        }

        return $out;
    }

    /** El nombre comercial, traducido donde hace falta (las marcas no se traducen). */
    public static function name(string $source): string
    {
        switch ($source) {
            case self::WALLET:
                return t('PayPal');
            case 'card':
                return t('Card');
            case 'card_fields':
                return t('Credit or debit card');
            case 'paylater':
                return t('Pay Later');
            case 'pay_upon_invoice':
                return t('Pay upon Invoice');
            default:
                $known = PayPalFundingSources::get($source);

                return $known !== null ? (string) $known['name'] : $source;
        }
    }

    /**
     * El título en la caja: «PayPal» para la cartera, «iDEAL por PayPal» para las de redirección y el nombre a secas para las
     * del guion («Google Pay», «Apple Pay», «Tarjeta de crédito o débito»; «Paga más tarde con PayPal»).
     */
    public static function title(string $source): string
    {
        if ($source === self::WALLET) {
            return t('PayPal');
        }
        if ($source === 'paylater') {
            return t('Pay Later with PayPal');
        }
        if (self::usesSdk($source)) {
            return self::name($source);
        }

        return t('{name} through PayPal', ['name' => self::name($source)]);
    }

    /** La frase de una línea bajo el título, en la caja. Las del guion avisan de que necesitan JavaScript. */
    public static function description(string $source): string
    {
        switch ($source) {
            case self::WALLET:
                return t('Pay on the secure page of PayPal.');
            case 'card':
                return t('Pay by card on the secure page of PayPal, without a PayPal account.');
            case 'blik':
                return t('Pay with a BLIK code from your banking app.');
            case 'trustly':
                return t('Pay from your bank account. The bank can take some days to confirm it.');
            case 'paylater':
                return t('Pay in instalments with PayPal, in the next step. It needs JavaScript.');
            case 'card_fields':
                return t('Type your card in secure PayPal fields, without leaving the shop. It needs JavaScript.');
            case 'google_pay':
                return t('Pay with Google Pay in the next step, if your browser supports it. It needs JavaScript.');
            case 'apple_pay':
                return t('Pay with Apple Pay in the next step, in Safari on an Apple device. It needs JavaScript.');
            case 'venmo':
                return t('Pay with your Venmo account in the next step. It needs JavaScript.');
            default:
                return t('Pay on the page of your bank.');
        }
    }

    /** Por qué no se ofrece una forma solo catalogada (o qué necesita una del guion). */
    public static function sdkReason(string $source): string
    {
        switch ($source) {
            case 'pay_upon_invoice':
                return t('It needs the birth date of the buyer and the legal text of Ratepay before the order is placed, which the checkout does not ask for, and the bank details for the transfer in the order email.');
            default:
                return t('It needs the script of PayPal in the page.');
        }
    }

    /** Lo que hay que activar o preparar para una forma de pago, en una frase (vacío si no hace falta nada). */
    public static function requirement(string $source): string
    {
        switch ($source) {
            case 'card':
                return t('Turn on paying without a PayPal account in your PayPal account.');
            case 'paylater':
                return t('Your PayPal account must be based in the country of the buyer; PayPal decides who can use it.');
            case 'card_fields':
                return t('Activate advanced card payments in your PayPal account.');
            case 'google_pay':
                return t('Activate Google Pay in your PayPal app and accept the Google Pay terms.');
            case 'apple_pay':
                return t('Activate Apple Pay in your PayPal app, register this domain in PayPal and paste the domain file below.');
            case 'venmo':
                return t('Only for a PayPal account in the United States.');
            default:
                $known = PayPalFundingSources::get($source);

                return $known !== null && !empty($known['approval']) ? t('Activate it in your PayPal account.') : '';
        }
    }

    /**
     * El cuadro de la pestaña «Formas de pago»: a quién se ofrece cada una, en qué moneda, desde qué importe, qué hay que
     * activar; el aviso de las que la moneda de la tienda deja fuera, y las que aún no se ofrecen.
     */
    public static function panel(App $app): string
    {
        $lang = $app->lang();
        $shopCurrency = strtoupper($app->settings()->str('shop.currency', 'EUR'));
        $rows = '';
        $excluded = [];
        foreach (PayPalFundingSources::offerable() as $source => $known) {
            $source = (string) $source;
            $countries = [];
            foreach ($known['countries'] as $iso) {
                $name = Countries::name((string) $iso, $lang);
                $countries[] = $name !== '' ? $name : (string) $iso;
            }
            $min = [];
            foreach ($known['min'] as $currency => $minor) {
                $min[] = money((int) $minor, (string) $currency);
            }
            if (isset($known['limits'][$app->settings()->str('tax.base_country', 'ES')])) {
                $limit = $known['limits'][$app->settings()->str('tax.base_country', 'ES')];
                $min[] = money((int) $limit[1], (string) $limit[0]) . ' – ' . money((int) $limit[2], (string) $limit[0]);
            }
            $countryText = $countries === [] ? t('Any country') : (count($countries) > 12 ? t('{count} countries', ['count' => count($countries)]) : implode(', ', $countries));
            $requirement = self::requirement($source);
            $rows .= '<tr><td>' . e(self::title($source)) . '</td>'
                . '<td>' . e($countryText) . '</td>'
                . '<td>' . e(count($known['currencies']) > 6 ? t('{count} currencies', ['count' => count($known['currencies'])]) : implode(', ', $known['currencies'])) . '</td>'
                . '<td>' . e($min === [] ? '—' : implode(' · ', $min)) . '</td>'
                . '<td>' . e($requirement !== '' ? $requirement : '—') . '</td></tr>';
            if (!in_array($shopCurrency, $known['currencies'], true)) {
                $excluded[] = t('{method} only charges in {currencies}.', ['method' => self::name($source), 'currencies' => implode(', ', $known['currencies'])]);
            }
        }
        $html = '';
        if ($excluded !== []) {
            $html .= '<p class="af-alert af-alert-warn" role="note">' . e(t('With the shop in {currency} these are never offered:', ['currency' => $shopCurrency]) . ' ' . implode(' ', $excluded)) . '</p>';
        }
        $html .= '<div class="af-table-wrap"><table class="af-table"><thead><tr>'
            . '<th>' . e(t('Payment method')) . '</th><th>' . e(t('Buyers from')) . '</th><th>' . e(t('Currencies')) . '</th>'
            . '<th>' . e(t('Amount')) . '</th><th>' . e(t('What to activate')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        $html .= '<p class="af-hint">' . e(t('Pay Later, the card on this page, Google Pay, Apple Pay and Venmo load the script of PayPal only on the page where the customer pays, and only if they are on.')) . '</p>';
        $notYet = '';
        foreach (PayPalFundingSources::needsSdk() as $source => $known) {
            if (empty($known['implemented'])) {
                $notYet .= '<li><strong>' . e(self::name((string) $source)) . '</strong>: ' . e(self::sdkReason((string) $source)) . '</li>';
            }
        }

        return $html . ($notYet !== '' ? '<p class="af-hint">' . e(t('Not offered yet:')) . '</p><ul class="af-hint">' . $notYet . '</ul>' : '');
    }

    /**
     * La frase útil, para el historial del pedido, de un «no» de PayPal al crear la orden. Nunca se enseña al cliente.
     */
    public static function explain(string $source, string $issue): string
    {
        $name = self::name($source);
        $issue = $issue !== '' ? $issue : '?';
        switch ($issue) {
            case 'NOT_ENABLED_FOR_PAYMENT_SOURCE':
            case 'PARTNER_OR_MERCHANT_RESTRICTED_FOR_COUNTRY':
            case 'UNSUPPORTED_PROCESSING_INSTRUCTION':
            case 'NOT_ENABLED_FOR_CARD_PROCESSING':
                return t('PayPal refused {method}: it is not activated in your PayPal account. Activate it in PayPal or turn it off in the PayPal settings of the shop ({issue}).', ['method' => $name, 'issue' => $issue]);
            case 'INVALID_CURRENCY_CODE':
            case 'CURRENCY_NOT_SUPPORTED':
                return t('PayPal does not take {method} in this currency, or your PayPal account blocks payments in it. Check the payment preferences of your PayPal account ({issue}).', ['method' => $name, 'issue' => $issue]);
            case 'MISSING_REQUIRED_PARAMETER':
            case 'INVALID_COUNTRY_CODE':
            case 'DOMESTIC_TRANSACTION_REQUIRED':
            case 'PAYMENT_SOURCE_INFO_CANNOT_BE_VERIFIED':
                return t('PayPal refused {method} because of the buyer data (name, country or email) ({issue}).', ['method' => $name, 'issue' => $issue]);
            case 'PAYEE_ACCOUNT_RESTRICTED':
            case 'PAYEE_ACCOUNT_LOCKED_OR_CLOSED':
                return t('Your PayPal account cannot receive payments right now. Check it in PayPal ({issue}).', ['issue' => $issue]);
            case 'PAYER_NOT_SUPPORTED_BY_PAYMENT_SOURCE':
            case 'PAYMENT_SOURCE_DECLINED_BY_PROCESSOR':
            case 'PAYMENT_SOURCE_CANNOT_BE_USED':
            case 'INSTRUMENT_DECLINED':
            case 'TRANSACTION_REFUSED':
            case 'PAYMENT_DENIED':
            case 'PAYER_CANNOT_PAY':
            case 'COMPLIANCE_VIOLATION':
                return t('PayPal did not accept {method} for this buyer ({issue}).', ['method' => $name, 'issue' => $issue]);
            default:
                return t('PayPal could not start the payment with {method} ({issue}).', ['method' => $name, 'issue' => $issue]);
        }
    }
}

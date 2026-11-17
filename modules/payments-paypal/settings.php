<?php
/**
 * AIFEC — ajustes de PayPal: el entorno, las credenciales de cada entorno, el aviso, el título que ve el cliente y qué formas de
 * pago de PayPal se ofrecen y en qué orden. Son por sitio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Modules\PaymentsPaypal\PayPalSettings;
use Aifec\Modules\PaymentsPaypal\PayPalSources;

return [
    'title' => 'PayPal',
    'description' => 'PayPal payments',
    'icon' => 'card',
    'cap' => 'orders.edit',
    'help' => "Create a REST app in your PayPal developer account: it gives you a client ID and a secret for the sandbox and another pair for live.\nGive PayPal the notification address shown below and tick the events listed there: the order is also marked as paid when PayPal calls it with a valid signature, even if the customer never comes back to the shop.\nStart in the sandbox and switch to live only after you have checked a whole test payment.\nIn the Payment methods tab, tick the ones you want to offer and give them an order: each one is a separate option of the checkout, offered only to buyers of its countries and in its currencies.\nBancontact, BLIK, eps, iDEAL, MyBank, Przelewy24 and Trustly must also be activated in your PayPal account; the card without account needs the option to pay without a PayPal account turned on in PayPal, and your account must accept payments in the currency of the shop.\nIf one of them is not active in PayPal, the order history says so and the customer is only asked to choose another payment method.\nTrustly can confirm the payment days later: the order waits on hold, unpaid, until the notification of PayPal arrives.\nNothing is loaded from PayPal in the checkout page: the customer goes to PayPal or to the bank and comes back.\nPay Later, the card on the payment page, Google Pay, Apple Pay and Venmo are paid on a payment page of the shop that loads the script of PayPal (and of Google for Google Pay); without JavaScript they cannot be used, and the customer is told so.\nTo offer them, activate them in your PayPal app: advanced card payments for the card, Google Pay (and accept its terms), Apple Pay (register the domain and paste its file here).",
    'tabs' => [
        'ppmain' => ['label' => 'General', 'fields' => [
            'payments.paypal.environment' => ['type' => 'select', 'label' => 'Environment', 'default' => 'sandbox', 'rules' => 'required', 'width' => 'md',
                'options' => ['sandbox' => 'Sandbox: no real money moves', 'live' => 'Live: real payments'],
                'hint' => 'A payment made in the sandbox is marked as a test order.'],
            'payments.paypal.title' => ['type' => 'text', 'label' => 'Payment title', 'default' => '', 'rules' => 'max:120', 'width' => 'md',
                'hint' => 'Empty: the standard title.'],
        ]],
        'ppkeys' => ['label' => 'Keys', 'fields' => [
            'payments.paypal.client_id_sandbox' => ['type' => 'text', 'label' => 'Client ID (sandbox)', 'default' => '', 'width' => 'lg',
                'rules' => ['nullable', 'regex:/^[A-Za-z0-9_-]{8,255}$/'], 'hint' => 'Letters, digits, hyphens and underscores.'],
            'payments.paypal.secret_sandbox' => ['type' => 'secret', 'label' => 'Secret (sandbox)',
                'rules' => ['nullable', 'regex:/^[A-Za-z0-9_-]{8,255}$/'], 'hint' => 'Saved encrypted; leave it empty to keep the current one.'],
            'payments.paypal.webhook_id_sandbox' => ['type' => 'text', 'label' => 'Webhook ID (sandbox)', 'default' => '', 'width' => 'md',
                'rules' => ['nullable', 'regex:/^[A-Za-z0-9]{8,64}$/'], 'hint' => 'PayPal shows it after you add the notification address.'],
            'payments.paypal.client_id_live' => ['type' => 'text', 'label' => 'Client ID (live)', 'default' => '', 'width' => 'lg',
                'rules' => ['nullable', 'regex:/^[A-Za-z0-9_-]{8,255}$/'], 'hint' => 'Letters, digits, hyphens and underscores.'],
            'payments.paypal.secret_live' => ['type' => 'secret', 'label' => 'Secret (live)',
                'rules' => ['nullable', 'regex:/^[A-Za-z0-9_-]{8,255}$/'], 'hint' => 'Saved encrypted; leave it empty to keep the current one.'],
            'payments.paypal.webhook_id_live' => ['type' => 'text', 'label' => 'Webhook ID (live)', 'default' => '', 'width' => 'md',
                'rules' => ['nullable', 'regex:/^[A-Za-z0-9]{8,64}$/'], 'hint' => 'PayPal shows it after you add the notification address.'],
            'payments.paypal.notify_url' => ['type' => 'html', 'label' => 'Notification address', 'html' => static function ($values, App $app): string {
                $url = (new PayPalSettings($app))->notifyUrl();

                return '<div class="af-input-group"><input type="text" class="af-input" readonly value="' . e($url) . '" aria-label="' . e(t('Notification address')) . '">'
                    . '<button type="button" class="af-btn af-btn-ghost" data-af-copy="' . e($url) . '">' . Admin::icon('copy') . e(t('Copy')) . '</button></div>'
                    . '<p class="af-hint">' . e(t('Add this address as a webhook in PayPal and tick these events: PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.DENIED and PAYMENT.CAPTURE.REFUNDED.')) . '</p>';
            }],
        ]],
        // Una casilla y un número por forma de pago: payments.paypal.source_<forma> y payments.paypal.position_<forma>.
        'ppsources' => ['label' => 'Payment methods', 'fields' => [
            'payments.paypal.sources_panel' => ['type' => 'html', 'label' => 'Who can use each one', 'html' => static function ($values, App $app): string {
                return PayPalSources::panel($app);
            }],
            'payments.paypal.source_paypal' => ['type' => 'toggle', 'label' => 'PayPal', 'default' => true,
                'hint' => 'The PayPal wallet: the buyer logs in to PayPal. Any country.'],
            'payments.paypal.position_paypal' => ['type' => 'number', 'label' => 'Order of PayPal', 'default' => 1, 'rules' => 'nullable|min:0|max:99', 'width' => 'sm'],
            'payments.paypal.source_card' => ['type' => 'toggle', 'label' => 'Card through PayPal', 'default' => true,
                'hint' => 'The card form on the PayPal page, without a PayPal account. Any country.'],
            'payments.paypal.position_card' => ['type' => 'number', 'label' => 'Order of the card', 'default' => 2, 'rules' => 'nullable|min:0|max:99', 'width' => 'sm'],
            'payments.paypal.source_bancontact' => ['type' => 'toggle', 'label' => 'Bancontact', 'default' => false,
                'hint' => 'Buyers from Belgium, in euros.'],
            'payments.paypal.position_bancontact' => ['type' => 'number', 'label' => 'Order of Bancontact', 'default' => 3, 'rules' => 'nullable|min:0|max:99', 'width' => 'sm'],
            'payments.paypal.source_blik' => ['type' => 'toggle', 'label' => 'BLIK', 'default' => false,
                'hint' => 'Buyers from Poland, in złotys (PLN) only.'],
            'payments.paypal.position_blik' => ['type' => 'number', 'label' => 'Order of BLIK', 'default' => 4, 'rules' => 'nullable|min:0|max:99', 'width' => 'sm'],
            'payments.paypal.source_eps' => ['type' => 'toggle', 'label' => 'eps', 'default' => false,
                'hint' => 'Buyers from Austria, in euros.'],
            'payments.paypal.position_eps' => ['type' => 'number', 'label' => 'Order of eps', 'default' => 5, 'rules' => 'nullable|min:0|max:99', 'width' => 'sm'],
            'payments.paypal.source_ideal' => ['type' => 'toggle', 'label' => 'iDEAL', 'default' => false,
                'hint' => 'Buyers from the Netherlands, in euros.'],
            'payments.paypal.position_ideal' => ['type' => 'number', 'label' => 'Order of iDEAL', 'default' => 6, 'rules' => 'nullable|min:0|max:99', 'width' => 'sm'],
            'payments.paypal.source_mybank' => ['type' => 'toggle', 'label' => 'MyBank', 'default' => false,
                'hint' => 'Buyers from Italy, in euros.'],
            'payments.paypal.position_mybank' => ['type' => 'number', 'label' => 'Order of MyBank', 'default' => 7, 'rules' => 'nullable|min:0|max:99', 'width' => 'sm'],
            'payments.paypal.source_p24' => ['type' => 'toggle', 'label' => 'Przelewy24', 'default' => false,
                'hint' => 'Buyers from Poland, in złotys or euros.'],
            'payments.paypal.position_p24' => ['type' => 'number', 'label' => 'Order of Przelewy24', 'default' => 8, 'rules' => 'nullable|min:0|max:99', 'width' => 'sm'],
            'payments.paypal.source_trustly' => ['type' => 'toggle', 'label' => 'Trustly', 'default' => false,
                'hint' => 'Buyers from twelve European countries. The bank can confirm the payment days later.'],
            'payments.paypal.position_trustly' => ['type' => 'number', 'label' => 'Order of Trustly', 'default' => 9, 'rules' => 'nullable|min:0|max:99', 'width' => 'sm'],
            // Las del guion de PayPal: se pagan en la página de pago del módulo, la única que carga el guion.
            'payments.paypal.sdk_heading' => ['type' => 'heading', 'label' => 'With the script of PayPal, on the payment page'],
            'payments.paypal.source_paylater' => ['type' => 'toggle', 'label' => 'Pay Later with PayPal', 'default' => false,
                'hint' => 'Instalments of PayPal for buyers of eight countries, Spain among them, each with its currency and amounts.'],
            'payments.paypal.position_paylater' => ['type' => 'number', 'label' => 'Order of Pay Later', 'default' => 10, 'rules' => 'nullable|min:0|max:99', 'width' => 'sm'],
            'payments.paypal.paylater_message' => ['type' => 'toggle', 'label' => 'Show the instalments message next to the amount', 'default' => false,
                'hint' => 'On the payment page of Pay Later, the message of PayPal with the instalments.'],
            'payments.paypal.source_card_fields' => ['type' => 'toggle', 'label' => 'Credit or debit card on the payment page', 'default' => false,
                'hint' => 'The card is typed in fields of PayPal inside the shop page and never goes through the shop. Not the same as the card through PayPal above.'],
            'payments.paypal.position_card_fields' => ['type' => 'number', 'label' => 'Order of the card on the payment page', 'default' => 11, 'rules' => 'nullable|min:0|max:99', 'width' => 'sm'],
            'payments.paypal.source_google_pay' => ['type' => 'toggle', 'label' => 'Google Pay', 'default' => false,
                'hint' => 'Buyers of 36 countries, Spain among them, with a browser that supports it.'],
            'payments.paypal.position_google_pay' => ['type' => 'number', 'label' => 'Order of Google Pay', 'default' => 13, 'rules' => 'nullable|min:0|max:99', 'width' => 'sm'],
            'payments.paypal.source_apple_pay' => ['type' => 'toggle', 'label' => 'Apple Pay', 'default' => false,
                'hint' => 'Buyers of 36 countries, Spain among them, in Safari on an Apple device. It needs the domain file below.'],
            'payments.paypal.position_apple_pay' => ['type' => 'number', 'label' => 'Order of Apple Pay', 'default' => 12, 'rules' => 'nullable|min:0|max:99', 'width' => 'sm'],
            'payments.paypal.applepay_domain_file' => ['type' => 'textarea', 'label' => 'Apple Pay domain file', 'default' => '', 'rows' => 3, 'rules' => 'max:30000',
                'hint' => 'Paste the content of the file that PayPal gives you to register the domain. It is served at /.well-known/apple-developer-merchantid-domain-association.'],
            // El paso a paso para registrar el dominio (pedido por el usuario el 16/11/2026). Los nombres de los menús y de los
            // botones de PayPal son los de su guía «Integrate Apple Pay with JS SDK for direct merchants».
            'payments.paypal.applepay_steps' => ['type' => 'html', 'label' => 'Apple Pay: register the domain, step by step', 'html' => static function ($values, App $app): string {
                $parts = parse_url((string) $app->site()->url());
                $origin = (isset($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . (isset($parts['host']) ? $parts['host'] : '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
                $address = $origin . '/.well-known/apple-developer-merchantid-domain-association';
                $inFolder = trim(isset($parts['path']) ? (string) $parts['path'] : '', '/') !== '';
                $guide = 'https://developer.paypal.com/docs/checkout/apm/apple-pay/';
                $link = static function (string $url, string $text): string {
                    return '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . e($text) . '</a>';
                };
                $steps = [
                    e(t('In your PayPal developer account (developer.paypal.com) open «Apps & Credentials», choose «Sandbox» or «Live» with the switch and open your app. In «Features», tick «Apple Pay» and save.')),
                    e(t('Download the domain association file. PayPal gives it in its Apple Pay guide, one for the sandbox and another for live:')) . ' ' . $link($guide, t('Apple Pay guide of PayPal')) . '.',
                    e(t('Open the downloaded file with a text editor (it has no extension), copy all of it and paste it in «Apple Pay domain file», above. Save.')),
                    e(t('Check that your shop serves it. This address has to download the same file, over HTTPS and without redirects:')) . ' ' . $link($address, $address),
                    e(t('Back in PayPal, in your app, go to the «Apple Pay» section and press «Manage». Press «Add Domain», type the domain of your shop and press «Register Domain». PayPal reads the file at that moment: if it fails, repeat step 4.')),
                    e(t('Turn on «Apple Pay», above, and save. The button is only shown in Safari on an Apple device.')),
                ];
                $html = '<ol class="af-hint" data-af-applepay-steps>';
                foreach ($steps as $step) {
                    $html .= '<li>' . $step . '</li>';
                }
                $html .= '</ol><p class="af-hint">' . e(t('Each domain and each subdomain that shows the button is registered separately, and so are the sandbox and live. To test in the sandbox you also need an Apple Pay sandbox test account, from Apple. You do not need an Apple developer account.')) . '</p>';
                if ($inFolder) {
                    $html .= '<p class="af-hint">' . e(t('This shop lives in a folder of the domain. Apple reads the file at the root of the domain, so the shop at the root has to serve it.')) . '</p>';
                }
                $has = trim((string) $app->settings()->str(PayPalSettings::APPLE_PAY_FILE, '')) !== '';

                return $html . '<p class="af-hint"><strong>' . e($has ? t('Now: the shop has a domain file and serves it.') : t('Now: the shop has no domain file yet, and the address of step 4 answers «not found».')) . '</strong></p>';
            }],
            'payments.paypal.source_venmo' => ['type' => 'toggle', 'label' => 'Venmo', 'default' => false,
                'hint' => 'Buyers from the United States, in dollars.'],
            'payments.paypal.position_venmo' => ['type' => 'number', 'label' => 'Order of Venmo', 'default' => 14, 'rules' => 'nullable|min:0|max:99', 'width' => 'sm'],
        ]],
    ],
];

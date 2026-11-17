<?php
/**
 * AIFEC — ajustes de Redsys: el comercio, las claves de cada entorno, Bizum y los textos que ve el cliente. Son por sitio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Modules\PaymentsRedsys\RedsysSettings;

return [
    'title' => 'Redsys',
    'description' => 'Card and Bizum payments through Redsys',
    'icon' => 'card',
    'cap' => 'orders.edit',
    'help' => "Redsys gives you the merchant code, the terminal number and a secret key for each environment when it activates your shop.\nGive the bank the notification address shown below: the order is marked as paid only when the bank calls it with a valid signature.\nStart in the test environment and switch to live only after you have checked a whole test payment.",
    'tabs' => [
        'rdmain' => ['label' => 'General', 'fields' => [
            'payments.redsys.environment' => ['type' => 'select', 'label' => 'Environment', 'default' => 'test', 'rules' => 'required', 'width' => 'md',
                'options' => ['test' => 'Test: no real money moves', 'live' => 'Live: real payments'],
                'hint' => 'A payment made in the test environment is marked as a test order.'],
            'payments.redsys.test_terminal' => ['type' => 'html', 'label' => 'Your own test terminal', 'html' => static function ($values, App $app): string {
                $links = [
                    'https://pagosonline.redsys.es/desarrolladores-inicio/integrate-con-nosotros/tarjetas-y-entornos-de-prueba/' => t('Redsys: test cards and test environments'),
                    'https://sis-t.redsys.es:25443/admincanales-web/index.jsp#/login?highlightTrialUser=true' => t('Redsys: sign up for a test terminal'),
                ];
                $html = '<p class="af-hint">' . e(t('AIFEC does not include any Redsys test merchant or key. Whoever tests signs up for a test terminal of their own and types its merchant code, terminal and key here.')) . '</p><ul class="af-hint">';
                foreach ($links as $url => $label) {
                    $html .= '<li><a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . e($label) . '</a></li>';
                }

                return $html . '</ul>';
            }],
            'payments.redsys.merchant_code' => ['type' => 'text', 'label' => 'Merchant code', 'default' => '', 'width' => 'md',
                'rules' => ['nullable', 'regex:/^[0-9]{1,15}$/'], 'hint' => 'Digits only. To test, sign up for your own Redsys test terminal and use its merchant code.'],
            'payments.redsys.terminal' => ['type' => 'text', 'label' => 'Terminal', 'default' => '1', 'width' => 'sm',
                'rules' => ['nullable', 'regex:/^[0-9]{1,3}$/']],
            'payments.redsys.bizum' => ['type' => 'toggle', 'label' => 'Offer Bizum', 'default' => false,
                'hint' => 'Your Redsys contract has to include Bizum.'],
            'payments.redsys.title' => ['type' => 'text', 'label' => 'Card payment title', 'default' => '', 'rules' => 'max:120', 'width' => 'md',
                'hint' => 'Empty: the standard title.'],
            'payments.redsys.bizum_title' => ['type' => 'text', 'label' => 'Bizum title', 'default' => '', 'rules' => 'max:120', 'width' => 'md',
                'hint' => 'Empty: the standard title.'],
        ]],
        'rdkeys' => ['label' => 'Keys', 'fields' => [
            'payments.redsys.secret_test' => ['type' => 'secret', 'label' => 'Secret key (test)',
                'rules' => ['nullable', 'regex:/^[A-Za-z0-9+\/]{32}$/'], 'hint' => '32 characters, from your own Redsys test terminal: AIFEC does not include one. Saved encrypted; leave it empty to keep the current one.'],
            'payments.redsys.secret_live' => ['type' => 'secret', 'label' => 'Secret key (live)',
                'rules' => ['nullable', 'regex:/^[A-Za-z0-9+\/]{32}$/'], 'hint' => '32 characters. Saved encrypted; leave it empty to keep the current one.'],
            'payments.redsys.notify_url' => ['type' => 'html', 'label' => 'Notification address', 'html' => static function ($values, App $app): string {
                $url = (new RedsysSettings($app))->notifyUrl();

                return '<div class="af-input-group"><input type="text" class="af-input" readonly value="' . e($url) . '" aria-label="' . e(t('Notification address')) . '">'
                    . '<button type="button" class="af-btn af-btn-ghost" data-af-copy="' . e($url) . '">' . Admin::icon('copy') . e(t('Copy')) . '</button></div>'
                    . '<p class="af-hint">' . e(t('Give this address to the bank as the notification (merchant) URL.')) . '</p>';
            }],
        ]],
        'rdgpay' => ['label' => 'Google Pay', 'fields' => [
            'payments.redsys.googlepay_about' => ['type' => 'html', 'label' => 'What it is for', 'html' => static function ($values, App $app): string {
                $settings = new RedsysSettings($app);

                return '<p class="af-hint">' . e(t('With this on, an AI platform that buys through UCP (Google AI Mode and Gemini) can pay your orders with Google Pay: it sends the card token encrypted for your bank and the shop charges it through Redsys at once. It does not appear in your normal checkout.')) . '</p>'
                    . '<ol class="af-hint">'
                    . '<li>' . e(t('Ask your bank to activate Google Pay on your Redsys terminal.')) . '</li>'
                    . '<li>' . e(t('For real payments, register in the Google Pay & Wallet Console and copy your Google merchant ID below.')) . '</li>'
                    . '<li>' . e(t('Turn on UCP in Settings → UCP and agents. Google only buys this way in some countries: today, the United States.')) . '</li>'
                    . '</ol>'
                    . '<p class="af-hint" data-af-googlepay-state>' . e($settings->googlePayReady()
                        ? t('Ready: Google Pay is offered through UCP in the {environment} environment.', ['environment' => $settings->googlePayEnvironment()])
                        : t('Not offered yet: turn it on and complete the Redsys details of the chosen environment.')) . '</p>';
            }],
            'payments.redsys.googlepay' => ['type' => 'toggle', 'label' => 'Accept Google Pay from AI platforms', 'default' => false,
                'hint' => 'The test environment of Redsys uses the test environment of Google Pay: nothing is charged.'],
            'payments.redsys.googlepay_merchant_id' => ['type' => 'text', 'label' => 'Google merchant ID', 'default' => '', 'width' => 'md',
                'rules' => ['nullable', 'regex:/^[A-Za-z0-9]{8,40}$/'], 'hint' => 'From the Google Pay & Wallet Console. Needed for real payments; not for tests.'],
            'payments.redsys.googlepay_networks' => ['type' => 'text', 'label' => 'Card networks', 'default' => 'VISA,MASTERCARD', 'width' => 'md',
                'rules' => ['nullable', 'max:80', 'regex:/^[A-Za-z, ]*$/'], 'hint' => 'Separated by commas: VISA, MASTERCARD, AMEX, DISCOVER, JCB. Only those your Redsys contract accepts.'],
            'payments.redsys.googlepay_pan_only' => ['type' => 'toggle', 'label' => 'Also accept cards saved in the Google account', 'default' => false,
                'hint' => 'They may need 3D Secure, which cannot be done through the platform: those orders are finished in your checkout. Cards on the phone are always accepted.'],
        ]],
    ],
];

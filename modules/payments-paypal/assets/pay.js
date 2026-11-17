/*
 * AIFEC — PayPal: la página de pago de las formas del guion de PayPal (Paga más tarde, tarjeta en la página, Google Pay,
 * Apple Pay, Venmo). Carga el guion de PayPal (y el de Google para Google Pay) que dice el servidor, pinta el botón o los
 * campos y deja que el navegador solo APRUEBE: la orden la crea y la cobra el servidor. Los textos llegan en data-msg-*.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
(function () {
    'use strict';
    var root = document.querySelector('[data-af-pp]');
    if (!root) { return; }
    var cfg;
    try { cfg = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { return; }
    var box = root.querySelector('[data-af-pp-button]');
    var status = root.querySelector('[data-af-pp-status]');

    function msg(key) { return root.getAttribute('data-msg-' + key) || ''; }
    function say(key) { if (status) { status.textContent = msg(key); status.hidden = false; } }
    function quiet() { if (status) { status.hidden = true; } }
    function fail() { say('failed'); }

    // Solo la ficha del formulario y, al capturar, la orden de PayPal: ni un dato de la tarjeta pasa por la tienda.
    function post(url, paypalOrder) {
        var body = '_token=' + encodeURIComponent(cfg.token) + (paypalOrder ? '&paypal_order=' + encodeURIComponent(paypalOrder) : '');
        return fetch(url, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store', body: body,
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'}
        }).then(function (r) { return r.json(); });
    }
    function create() {
        return post(cfg.create).then(function (d) {
            if (d.redirect) { window.location.href = d.redirect; }
            if (!d.id) { throw new Error('create'); }
            return d.id;
        });
    }
    function capture(id) {
        return post(cfg.capture, id).then(function (d) {
            if (!d.ok || !d.redirect) { throw new Error('capture'); }
            return d;
        });
    }
    function done(d) { say('paid'); window.location.href = d.redirect; }
    function approve(data) { return capture(data.orderID).then(done, fail); }

    function load(src) {
        return new Promise(function (ok, ko) {
            var s = document.createElement('script');
            s.src = src;
            s.async = true;
            if (cfg.nonce) { s.setAttribute('nonce', cfg.nonce); s.setAttribute('data-csp-nonce', cfg.nonce); }
            s.onload = ok;
            s.onerror = ko;
            document.head.appendChild(s);
        });
    }

    function buttons(funding) {
        var pp = window.paypal;
        var b = pp.Buttons({
            fundingSource: pp.FUNDING[funding], createOrder: create, onApprove: approve,
            onCancel: function () { say('cancelled'); }, onError: fail
        });
        if (!b.isEligible()) { say('unavailable'); return; }
        quiet();
        b.render(box);
        var m = root.querySelector('[data-af-pp-message]');
        if (m && cfg.message && pp.Messages) {
            pp.Messages({amount: Number(cfg.amount), currency: cfg.currency, placement: 'payment'}).render(m);
        }
    }

    function cardFields() {
        var f = window.paypal.CardFields({createOrder: create, onApprove: approve, onError: fail});
        if (!f.isEligible()) { say('unavailable'); return; }
        f.NumberField().render(root.querySelector('[data-af-pp-number]'));
        f.ExpiryField().render(root.querySelector('[data-af-pp-expiry]'));
        f.CVVField().render(root.querySelector('[data-af-pp-cvv]'));
        var form = root.querySelector('[data-af-pp-card]');
        form.hidden = false;
        quiet();
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            say('paying');
            f.submit().catch(fail);
        });
    }

    function googlePay() {
        var gp = window.paypal.Googlepay();
        var base = {apiVersion: 2, apiVersionMinor: 0};
        return gp.config().then(function (conf) {
            if (!conf.isEligible) { say('unavailable'); return null; }
            var client = new window.google.payments.api.PaymentsClient({
                environment: cfg.test ? 'TEST' : 'PRODUCTION',
                paymentDataCallbacks: {onPaymentAuthorized: function (pd) {
                    return create().then(function (id) {
                        return gp.confirmOrder({orderId: id, paymentMethodData: pd.paymentMethodData}).then(function (r) {
                            return r.status === 'PAYER_ACTION_REQUIRED' ? gp.initiatePayerAction({orderId: id}).then(function () { return id; }) : id;
                        });
                    }).then(capture).then(function (d) {
                        window.setTimeout(function () { done(d); }, 0);
                        return {transactionState: 'SUCCESS'};
                    }, function () {
                        fail();
                        return {transactionState: 'ERROR', error: {intent: 'PAYMENT_AUTHORIZATION', reason: 'PAYMENT_DATA_INVALID', message: msg('failed')}};
                    });
                }}
            });
            return client.isReadyToPay(Object.assign({allowedPaymentMethods: conf.allowedPaymentMethods}, base)).then(function (r) {
                if (!r.result) { say('unsupported'); return; }
                quiet();
                box.appendChild(client.createButton({buttonSizeMode: 'fill', onClick: function () {
                    client.loadPaymentData(Object.assign({
                        allowedPaymentMethods: conf.allowedPaymentMethods, merchantInfo: conf.merchantInfo, callbackIntents: ['PAYMENT_AUTHORIZATION'],
                        transactionInfo: {countryCode: conf.countryCode || cfg.country, currencyCode: cfg.currency, totalPriceStatus: 'FINAL', totalPrice: cfg.amount}
                    }, base)).catch(function () {});
                }}));
            });
        });
    }

    function applePay() {
        var S = window.ApplePaySession;
        if (!S || !S.canMakePayments()) { say('unsupported'); return null; }
        var ap = window.paypal.Applepay();
        return ap.config().then(function (c) {
            if (!c.isEligible) { say('unavailable'); return; }
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'af-btn af-btn-lg af-pp-apple';
            b.setAttribute('aria-label', msg('apple'));
            b.style.setProperty('-webkit-appearance', '-apple-pay-button');
            b.addEventListener('click', function () {
                var s = new S(4, {
                    countryCode: c.countryCode, currencyCode: cfg.currency, merchantCapabilities: c.merchantCapabilities,
                    supportedNetworks: c.supportedNetworks, requiredBillingContactFields: ['postalAddress'],
                    total: {label: cfg.label, type: 'final', amount: cfg.amount}
                });
                s.onvalidatemerchant = function (e) {
                    ap.validateMerchant({validationUrl: e.validationURL, displayName: cfg.label}).then(function (v) {
                        s.completeMerchantValidation(v.merchantSession);
                    }, function () { s.abort(); fail(); });
                };
                s.onpaymentauthorized = function (e) {
                    create().then(function (id) {
                        return ap.confirmOrder({orderId: id, token: e.payment.token, billingContact: e.payment.billingContact}).then(function () { return id; });
                    }).then(capture).then(function (d) {
                        s.completePayment(S.STATUS_SUCCESS);
                        done(d);
                    }, function () {
                        s.completePayment(S.STATUS_FAILURE);
                        fail();
                    });
                };
                s.oncancel = function () { say('cancelled'); };
                s.begin();
            });
            quiet();
            box.appendChild(b);
        });
    }

    var run = {
        paylater: function () { return buttons('PAYLATER'); },
        venmo: function () { return buttons('VENMO'); },
        card_fields: cardFields,
        google_pay: googlePay,
        apple_pay: applePay
    };
    if (!cfg.sdk || !run[cfg.source]) { say('unavailable'); return; }
    var chain = load(cfg.sdk);
    (cfg.scripts || []).forEach(function (src) { chain = chain.then(function () { return load(src); }); });
    chain.then(function () {
        if (!window.paypal) { throw new Error('sdk'); }
        return run[cfg.source]();
    }).catch(function () { say('error'); });
}());

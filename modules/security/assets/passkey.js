/**
 * AIFEC — AI-First E-Commerce.
 *
 * Passkeys del panel. En la pantalla de acceso: el botón «Entrar con passkey». En «Seguridad → Passkeys»: el botón de añadir una.
 * Sin dependencias; el navegador hace lo difícil (navigator.credentials).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
(function () {
    'use strict';

    function b64uToBuf(text) {
        var s = String(text).replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) { s += '='; }
        var bin = atob(s);
        var out = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) { out[i] = bin.charCodeAt(i); }
        return out.buffer;
    }

    function bufToB64u(buf) {
        var bytes = new Uint8Array(buf);
        var bin = '';
        for (var i = 0; i < bytes.length; i++) { bin += String.fromCharCode(bytes[i]); }
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function token() {
        var meta = document.querySelector('meta[name="af-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    function post(url, body) {
        var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        if (token() !== '') { headers['X-CSRF-Token'] = token(); }
        return fetch(url, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: headers, body: JSON.stringify(body || {}) })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); });
    }

    function show(el, text) {
        if (!el) { return; }
        el.textContent = text;
        el.hidden = !text;
    }

    function supported() {
        return !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.get);
    }

    function attestation(cred) {
        return { id: cred.id, response: { clientDataJSON: bufToB64u(cred.response.clientDataJSON), attestationObject: bufToB64u(cred.response.attestationObject) } };
    }

    function assertion(cred) {
        return { id: cred.id, response: {
            clientDataJSON: bufToB64u(cred.response.clientDataJSON),
            authenticatorData: bufToB64u(cred.response.authenticatorData),
            signature: bufToB64u(cred.response.signature)
        } };
    }

    // ---- pantalla de acceso
    var script = document.currentScript || document.querySelector('script[data-login]');
    var loginButton = document.getElementById('af-passkey-login');
    if (script && loginButton && supported()) {
        var errorBox = document.getElementById('af-passkey-error');
        loginButton.hidden = false;
        loginButton.addEventListener('click', function () {
            show(errorBox, '');
            loginButton.disabled = true;
            post(script.getAttribute('data-options')).then(function (r) {
                if (!r.ok) { throw new Error(r.data && r.data.error ? r.data.error : script.getAttribute('data-fail')); }
                var pk = r.data.publicKey;
                pk.challenge = b64uToBuf(pk.challenge);
                return navigator.credentials.get({ publicKey: pk });
            }).then(function (cred) {
                return post(script.getAttribute('data-login'), assertion(cred));
            }).then(function (r) {
                if (!r.ok || !r.data.ok) { throw new Error(r.data && r.data.error ? r.data.error : script.getAttribute('data-fail')); }
                window.location.href = r.data.redirect || '/';
            }).catch(function (e) {
                loginButton.disabled = false;
                // El usuario cerró la ventana del navegador: no es un error que contar.
                if (e && e.name === 'NotAllowedError') { return; }
                show(errorBox, e && e.message ? e.message : script.getAttribute('data-fail'));
            });
        });
    }

    // ---- «Seguridad → Passkeys»: añadir una
    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-af-passkey-add]') : null;
        if (!button) { return; }
        var box = document.getElementById('af-passkey-message');
        if (!supported()) { show(box, button.getAttribute('data-unsupported')); return; }
        var nameInput = document.getElementById('af-passkey-name');
        show(box, '');
        button.disabled = true;
        post(button.getAttribute('data-options')).then(function (r) {
            if (!r.ok) { throw new Error(r.data && r.data.error ? r.data.error : button.getAttribute('data-fail')); }
            var pk = r.data.publicKey;
            pk.challenge = b64uToBuf(pk.challenge);
            pk.user.id = b64uToBuf(pk.user.id);
            pk.excludeCredentials = (pk.excludeCredentials || []).map(function (c) { return { type: c.type, id: b64uToBuf(c.id) }; });
            return navigator.credentials.create({ publicKey: pk });
        }).then(function (cred) {
            var body = attestation(cred);
            body.name = nameInput ? nameInput.value : '';
            return post(button.getAttribute('data-register'), body);
        }).then(function (r) {
            if (!r.ok || !r.data.ok) { throw new Error(r.data && r.data.error ? r.data.error : button.getAttribute('data-fail')); }
            window.location.reload();
        }).catch(function (e) {
            button.disabled = false;
            if (e && e.name === 'NotAllowedError') { return; }
            show(box, e && e.message ? e.message : button.getAttribute('data-fail'));
        });
    });
}());

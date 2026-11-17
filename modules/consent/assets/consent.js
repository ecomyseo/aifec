/* AIFEC consent.js · Ecom Experts 2026 */
(function (w, d) {
  'use strict';
  var box = d.querySelector('[data-af-consent]');
  if (!box) { return; }
  var D = box.dataset, NAME = D.cookie, VER = D.version, PATH = D.path || '/', REC = D.record;
  var GPC = navigator.globalPrivacyControl === true;
  var L1 = box.querySelector('[data-l="1"]'), L2 = box.querySelector('[data-l="2"]');
  var FOC = 'a[href],button,input:not([disabled]),summary';
  var opener = null, H = d.documentElement, cur = read();

  function read() {
    var m = d.cookie.match(new RegExp('(?:^|; )' + NAME + '=([^;]*)'));
    var p = m ? decodeURIComponent(m[1]).split('.') : [];
    if (p[0] !== VER || p.length < 4 || !/^[psm]{0,3}$/.test(p[1])) { return null; }
    return { c: GPC ? p[1].replace('m', '') : p[1], id: p[3] };
  }
  function has(c, l) { return (c || '').indexOf(l) > -1; }
  function mode(c) {
    var g = function (l) { return has(c, l) ? 'granted' : 'denied'; }, m = g('m'), p = g('p');
    return { ad_storage: m, ad_user_data: m, ad_personalization: m, analytics_storage: g('s'), functionality_storage: p, personalization_storage: p, security_storage: 'granted' };
  }
  function rnd() {
    var a = new Uint8Array(8), s = '', i;
    w.crypto.getRandomValues(a);
    for (i = 0; i < 8; i++) { s += ('0' + a[i].toString(16)).slice(-2); }
    return s;
  }
  function cat(v) {
    v = String(v).toLowerCase().charAt(0);
    return v === 'e' ? 's' : (v === 'p' || v === 'm' || v === 's') ? v : 'm';
  }
  function run(el) {
    var s, i, a;
    el.setAttribute('data-done', '1');
    if (el.tagName === 'IFRAME') {
      el.src = el.getAttribute('data-src');
      el.hidden = false;
      a = el.previousSibling;
      if (a && a.getAttribute && a.getAttribute('data-grant')) { a.parentNode.removeChild(a); }
      return;
    }
    s = d.createElement('script');
    for (i = 0; i < el.attributes.length; i++) {
      a = el.attributes[i];
      if (!/^(type|data-consent|data-src|data-done)$/.test(a.name)) { s.setAttribute(a.name, a.value); }
    }
    if (el.getAttribute('data-src')) { s.src = el.getAttribute('data-src'); } else { s.text = el.text; }
    el.parentNode.replaceChild(s, el);
  }
  function ph(el, k) {
    var b;
    if (el.getAttribute('data-ph')) { return; }
    b = d.createElement('button');
    b.type = 'button';
    b.className = 'af-consent-ph';
    b.setAttribute('data-grant', k);
    b.textContent = D.tPh;
    el.setAttribute('data-ph', '1');
    el.hidden = true;
    el.parentNode.insertBefore(b, el);
  }
  function apply() {
    var c = cur ? cur.c : '', n = d.querySelectorAll('[data-consent]'), i, k;
    if (w.gtag) { w.gtag('consent', 'update', mode(c)); }
    for (i = 0; i < n.length; i++) {
      if (n[i].getAttribute('data-done')) { continue; }
      k = cat(n[i].getAttribute('data-consent'));
      if (has(c, k)) { run(n[i]); } else if (n[i].tagName === 'IFRAME') { ph(n[i], k); }
    }
    w.dispatchEvent(new CustomEvent('aifec:consent', { detail: { letters: c } }));
  }
  function sync() {
    var n = box.querySelectorAll('[data-cat]'), i, l, g;
    for (i = 0; i < n.length; i++) {
      l = n[i].getAttribute('data-cat');
      g = l === 'm' && GPC;
      if (l) { n[i].checked = !g && has(cur ? cur.c : '', l); n[i].disabled = g; }
    }
  }
  function pick() {
    var n = box.querySelectorAll('[data-cat]'), i, l, c = '';
    for (i = 0; i < n.length; i++) {
      l = n[i].getAttribute('data-cat');
      if (l && n[i].checked) { c += l; }
    }
    return c;
  }
  function show(n) {
    var f;
    L1.hidden = n !== 1;
    L2.hidden = n !== 2;
    box.hidden = !n;
    H.style.setProperty('--af-consent-h', n === 1 ? L1.offsetHeight + 12 + 'px' : '0px');
    if (n === 2) {
      sync();
      opener = opener || d.activeElement;
      f = L2.querySelector(FOC);
      if (f) { f.focus(); }
    } else if (n === 0 && opener) {
      if (opener.focus) { opener.focus(); }
      opener = null;
    }
  }
  function save(c) {
    var id = cur ? cur.id : rnd(), old = cur ? cur.c : '', i, drop = false;
    for (i = 0; i < old.length; i++) { if (!has(c, old.charAt(i))) { drop = true; } }
    d.cookie = NAME + '=' + VER + '.' + c + '.' + (Date.now() / 1000 | 0) + '.' + id + '; max-age=15552000; path=' + PATH + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    cur = { c: c, id: id };
    if (REC && w.fetch) {
      w.fetch(REC, { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + id + '&v=' + VER + '&c=' + c + '&g=' + (GPC ? 1 : 0) + '&l=' + encodeURIComponent(H.lang || '') }).catch(function () {});
    }
    show(0);
    apply();
    if (drop) { w.location.reload(); }
  }

  d.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-act],[data-af-consent-open],[data-grant]') : null, a;
    if (!t) { return; }
    if (t.hasAttribute('data-af-consent-open')) { e.preventDefault(); opener = t; show(2); return; }
    if (t.hasAttribute('data-grant')) { save((cur ? cur.c : '') + t.getAttribute('data-grant')); return; }
    if (!box.contains(t)) { return; }
    a = t.getAttribute('data-act');
    if (a === 'accept') { save(GPC ? 'ps' : 'psm'); } else if (a === 'reject') { save(''); } else if (a === 'config') { show(2); } else if (a === 'save') { save(pick()); }
  });
  d.addEventListener('keydown', function (e) {
    var f, x, y;
    if (L2.hidden) { return; }
    if (e.key === 'Escape') { show(cur ? 0 : 1); return; }
    if (e.key !== 'Tab') { return; }
    f = L2.querySelectorAll(FOC);
    x = f[0];
    y = f[f.length - 1];
    if (!x) { return; }
    if (e.shiftKey && d.activeElement === x) { e.preventDefault(); y.focus(); } else if (!e.shiftKey && d.activeElement === y) { e.preventDefault(); x.focus(); }
  });

  w.AifecConsent = w.AifecConsent || {};
  w.AifecConsent.open = function () { show(2); };
  w.AifecConsent.letters = function () { return cur ? cur.c : ''; };
  if (!cur) { show(1); }
  apply();
}(window, document));

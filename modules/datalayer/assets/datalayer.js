/* AIFEC datalayer.js */
(function (w, d) {
  'use strict';
  var el = d.getElementById('af-dl-config'), C, ai = '', hist = [], seeded = {}, qty = 1, keys = {}, items = [], co = null, ship = {}, pay = {}, opened = false, pre = null;
  if (!el) { return; }
  try { C = JSON.parse(el.textContent); } catch (x) { return; }
  var dl = w.dataLayer = w.dataLayer || [];
  if (C.off || navigator.webdriver || /bot|crawl|spider|slurp|lighthouse|headless|prerender/i.test(navigator.userAgent)) { return; }
  var HIDE = /^(keys|shipping|payment)$/, SKIP = /^(event|ecommerce|user_data|event_id|keys|shipping|payment)$/;
  var MAP = { view_item: ['ViewContent', 'ViewContent'], add_to_cart: ['AddToCart', 'AddToCart'], begin_checkout: ['InitiateCheckout', 'InitiateCheckout'],
    add_payment_info: ['AddPaymentInfo', 'AddPaymentInfo'], purchase: ['Purchase', 'CompletePayment'], search: ['Search', 'Search'], sign_up: ['CompleteRegistration', 'CompleteRegistration'] };

  function store(k, v) {
    try { if (v === undefined) { return w.sessionStorage.getItem(k); } w.sessionStorage.setItem(k, v); } catch (x) { return null; }
    return null;
  }
  function aiSource() {
    var s = store('af_ai') || '', u = /[?&]utm_source=([^&]+)/.exec(location.search), cs = [], k, i;
    if (s) { return s; }
    try { cs.push(new URL(d.referrer).hostname); } catch (x) { cs = []; }
    if (u) { cs.push(decodeURIComponent(u[1]).toLowerCase()); }
    for (i = 0; i < cs.length; i++) {
      for (k in C.ai) { if (!s && (cs[i] === k || cs[i].slice(-k.length - 1) === '.' + k)) { s = C.ai[k]; } }
    }
    if (s) { store('af_ai', s); }
    return s;
  }
  function params(e) {
    var p = {}, k;
    for (k in e.ecommerce || {}) { p[k] = e.ecommerce[k]; }
    for (k in e) { if (!SKIP.test(k)) { p[k] = e[k]; } }
    return p;
  }
  function ids(x) { return (x.items || []).map(function (i) { return i.item_id; }); }
  function meta(e) {
    var m = MAP[e.event], x = e.ecommerce || {}, p;
    if (!m) { return; }
    p = { value: x.value, currency: x.currency, content_type: 'product', content_ids: ids(x), contents: (x.items || []).map(function (i) { return { id: i.item_id, quantity: i.quantity }; }) };
    if (e.search_term) { p.search_string = e.search_term; }
    w.fbq('track', m[0], p, e.event_id ? { eventID: e.event_id } : undefined);
  }
  function tik(e) {
    var m = MAP[e.event], x = e.ecommerce || {};
    if (!m) { return; }
    w.ttq.track(m[1], { value: x.value, currency: x.currency, content_type: 'product',
      contents: (x.items || []).map(function (i) { return { content_id: i.item_id, quantity: i.quantity, price: i.price }; }) }, e.event_id ? { event_id: e.event_id } : undefined);
  }
  function uet(e) {
    var x = e.ecommerce || {};
    if (MAP[e.event]) { w.uetq.push('event', e.event, { revenue_value: x.value, currency: x.currency }); }
  }
  function mirror(e, only) {
    var t = [['fbq', C.meta, meta], ['ttq', C.tt, tik], ['uetq', C.uet, uet]], i, h;
    for (i = 0; i < t.length; i++) {
      if (!t[i][1] || !w[t[i][0]]) { continue; }
      if (!seeded[t[i][0]]) {
        seeded[t[i][0]] = 1;
        for (h = 0; h < hist.length - (only ? 0 : 1); h++) { t[i][2](hist[h]); }
      }
      if (!only) { t[i][2](e); }
    }
  }
  function log(e) {
    if (!pre) {
      pre = d.createElement('pre');
      pre.setAttribute('style', 'position:fixed;left:0;bottom:0;z-index:99999;max-height:40vh;overflow:auto;margin:0;padding:.5rem;background:#111;color:#9f9;font:12px monospace');
      d.body.appendChild(pre);
    }
    pre.textContent += JSON.stringify(e) + '\n';
  }
  function emit(ev) {
    var e = {}, k, g;
    if (ev.event_id && store('af_dl_' + ev.event_id)) { return; }
    if (ev.event_id) { store('af_dl_' + ev.event_id, '1'); }
    for (k in ev) { if (!HIDE.test(k)) { e[k] = ev[k]; } }
    if (ai && !e.ai_source) { e.ai_source = ai; }
    hist.push(e);
    if (e.ecommerce) { dl.push({ ecommerce: null }); }
    dl.push(e);
    if (!C.gtm && w.gtag) {
      w.gtag('event', e.event, params(e));
      if (e.event === 'purchase' && C.ads) {
        g = e.ecommerce;
        if (e.user_data) { w.gtag('set', 'user_data', e.user_data); }
        w.gtag('event', 'conversion', { send_to: C.ads.id + '/' + C.ads.label, value: g.value, currency: g.currency, transaction_id: g.transaction_id });
      }
    }
    mirror(e, false);
    if (C.dbg) { log(e); }
  }
  function blocks() {
    var n = d.querySelectorAll('script[data-af-dl]'), i, j, b;
    for (i = 0; i < n.length; i++) {
      if (n[i].getAttribute('data-done')) { continue; }
      n[i].setAttribute('data-done', '1');
      try { b = JSON.parse(n[i].textContent); } catch (x) { continue; }
      for (j = 0; j < (b.e || []).length; j++) {
        if (b.e[j].event === 'view_cart') { keys = b.e[j].keys || {}; items = b.e[j].ecommerce.items; }
        if (b.e[j].event === 'begin_checkout') { co = b.e[j].ecommerce; ship = b.e[j].shipping || {}; pay = b.e[j].payment || {}; }
        emit(b.e[j]);
      }
    }
  }
  function card(c) {
    var a = c.querySelector('a[href]'), n = c.querySelector('[class*="card-name"]'), h = a ? a.getAttribute('href') : '';
    return { item_id: h.replace(/[?#].*$/, '').replace(/\/+$/, '').split('/').pop(), item_name: n ? n.textContent.trim() : '' };
  }
  function n2(c) { return Math.round(c) / 100; }
  var cards = d.querySelectorAll('.af-listing-results .af-product-card');
  if (cards.length) {
    var list = [], h1 = d.querySelector('h1');
    Array.prototype.slice.call(cards, 0, 24).forEach(function (c, i) { var o = card(c); o.index = i; list.push(o); });
    emit({ event: 'view_item_list', ecommerce: { item_list_name: h1 ? h1.textContent.trim() : '', items: list } });
  }
  d.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('.af-product-card, [data-af-assistant-open]') : null, o;
    if (!t) { return; }
    if (t.hasAttribute('data-af-assistant-open')) { if (!opened) { opened = true; emit({ event: 'assistant_open' }); } return; }
    o = card(t);
    emit({ event: 'select_item', ecommerce: { items: [o] } });
  });
  d.addEventListener('submit', function (e) {
    var f = e.target.closest ? e.target.closest('form') : null, q, i, k;
    if (!f) { return; }
    if (f.hasAttribute('data-af-cart-add')) { q = f.elements.qty; qty = q ? parseInt(q.value, 10) || 1 : 1; return; }
    if (C.rm && f.getAttribute('action') && f.getAttribute('action').split('?')[0] === C.rm && f.elements.key) {
      k = f.elements.key.value;
      i = keys[k];
      if (i !== undefined && items[i]) { emit({ event: 'remove_from_cart', ecommerce: { currency: C.cur, value: n2(items[i].price * items[i].quantity * 100), items: [items[i]] } }); }
    }
  }, true);
  d.addEventListener('aifec:cart', function (e) {
    var l = e.detail && e.detail.line, p;
    if (!l) { return; }
    p = l.unit_price_shown !== undefined ? l.unit_price_shown : l.unit_price;
    emit({ event: 'add_to_cart', ecommerce: { currency: C.cur, value: n2(p * qty), items: [{ item_id: String(l.product_id), item_name: l.name, item_variant: l.variant_label || undefined, price: n2(p), quantity: qty }] } });
  });
  d.addEventListener('change', function (e) {
    var t = e.target, x;
    if (!co || !t || t.type !== 'radio') { return; }
    x = JSON.parse(JSON.stringify(co));
    if (t.name === 'carrier_id') { x.shipping_tier = ship[t.value] || t.value; emit({ event: 'add_shipping_info', ecommerce: x }); }
    if (t.name === 'payment_method') { x.payment_type = pay[t.value] || t.value; emit({ event: 'add_payment_info', ecommerce: x }); }
  });
  w.addEventListener('aifec:consent', function () { mirror(null, true); });

  ai = aiSource();
  var mk = new RegExp('(?:^|; )' + C.dl + '=([^;]*)').exec(d.cookie);
  if (mk) {
    d.cookie = C.dl + '=; max-age=0; path=' + (C.cp || '/');
    if (mk[1] === 'login' || mk[1] === 'sign_up') { emit({ event: mk[1], method: 'email' }); }
  }
  if (C.sp && location.pathname === C.sp) {
    var q = new URLSearchParams(location.search).get(C.sq);
    if (q) { emit({ event: 'search', search_term: q }); }
  }
  blocks();
}(window, document));

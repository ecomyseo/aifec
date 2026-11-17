/* AIFEC · selector de categorías de la ficha de producto */
(function () {
 'use strict';
 var D = document, A = window.Aifec, T, N = 0;
 function c(k) { return '[data-cp-' + k + ']'; }
 function q(s, r) { return (r || D).querySelector(s); }
 function Q(k, r) { return q(c(k), r); }
 function qa(s, r) { return [].slice.call((r || D).querySelectorAll(s)); }
 function g(n, a) { return n.getAttribute(a); }
 function sa(n, a, v) { n.setAttribute(a, v); }
 function el(t, k, x) { var e = D.createElement(t); if (k) { e.className = k; } if (x !== undefined) { e.textContent = x; } return e; }
 function R(n) { return n.closest('[data-cp]'); }
 function tx(r, k, v) { return (g(r, 'data-t-' + k) || '').replace('{name}', v || ''); }
 function get(r, s) { return A.get(g(r, 'data-url') + '?' + s).catch(function () { A.toast(tx(r, 'failed'), 'bad'); return {}; }); }
 function lis(r) { return qa(c('chips') + ' li', r); }
 function id(li) { return g(li, 'data-cp-id'); }
 function hid(r, n, v) { var i = el('input'); i.type = 'hidden'; i.name = n; i.value = v; sa(i, 'data-cp-h', ''); r.appendChild(i); }
 function tpl(r, k) { return Q(k, r).content.firstElementChild.cloneNode(true); }
 function fold(t, on) { sa(t, 'aria-expanded', on); t.textContent = on === 'true' ? '▾' : '▸'; }

 function sync(r) {
  var l = lis(r), nm = g(r, 'data-name'), m = '';
  qa(c('h'), r).forEach(function (i) { i.remove(); });
  l.forEach(function (li) { hid(r, nm + '[]', id(li)); if (Q('main', li).checked) { m = id(li); } });
  if (!m && l[0]) { m = id(l[0]); Q('main', l[0]).checked = true; }
  hid(r, nm.replace('categories', 'main_category'), m);
  Q('note', r).hidden = l.length > 0;
 }
 function add(r, k, path) {
  k = String(k);
  if (!lis(r).some(function (li) { return id(li) === k; })) {
   var li = tpl(r, 'tpl');
   sa(li, 'data-cp-id', k);
   Q('name', li).textContent = path;
   sa(Q('x', li), 'aria-label', tx(r, 'remove', path));
   Q('chips', r).appendChild(li);
  }
  sync(r);
 }

 function shut(r) {
  var i = Q('q', r);
  Q('list', r).hidden = true; i.value = '';
  sa(i, 'aria-expanded', 'false'); i.removeAttribute('aria-activedescendant');
 }
 function mark(r, n) {
  var o = qa('[role=option]', r);
  if (!o.length) { return; }
  n = (n + o.length) % o.length;
  o.forEach(function (x, k) { sa(x, 'aria-selected', k === n ? 'true' : 'false'); });
  sa(Q('q', r), 'aria-activedescendant', o[n].id);
 }
 function opt(l, n, text, a, v) {
  var o = el('li', 'af-cp-opt', text);
  sa(o, 'role', 'option'); o.id = l.id + '-' + n; sa(o, a, v); l.appendChild(o);
  return o;
 }
 function show(r, items, text) {
  var l = Q('list', r), have = lis(r).map(id), n = 0, ex = false;
  l.textContent = '';
  items.forEach(function (it) {
   sa(opt(l, n++, (have.indexOf(String(it.id)) < 0 ? '' : '✓ ') + it.path, 'data-id', it.id), 'data-path', it.path);
   ex = ex || it.name.toLowerCase() === text.toLowerCase();
  });
  if (r.hasAttribute('data-new') && !ex) { opt(l, n, tx(r, 'create', text) + '…', 'data-create', text); }
  else if (!items.length) { l.appendChild(el('li', 'af-muted', tx(r, 'empty'))); }
  l.hidden = false;
  sa(Q('q', r), 'aria-expanded', 'true');
  mark(r, 0);
 }
 function make(r, name) {
  var l = Q('list', r), li = el('li', 'af-cp-new', tx(r, 'create', name)), s = el('select', 'af-select'), b = el('button', 'af-btn af-btn-sm af-btn-primary', tx(r, 'go')), o = el('option', '', tx(r, 'top'));
  o.value = '0'; s.add(o); b.type = 'button';
  get(r, 'all=1').then(function (d) { (d.items || []).forEach(function (it) { o = el('option', '', it.path); o.value = it.id; s.add(o); }); });
  b.onclick = function () {
   A.post(g(r, 'data-new'), {name: name, parent: s.value}).then(function (d) { add(r, d.id, d.path); shut(r); },
    function (e) { A.toast(e.data && e.data.message || tx(r, 'failed'), 'bad'); });
  };
  l.textContent = ''; li.appendChild(s); li.appendChild(b); l.appendChild(li); s.focus();
 }
 function pick(r, o) {
  if (o.hasAttribute('data-create')) { return make(r, g(o, 'data-create')); }
  add(r, g(o, 'data-id'), g(o, 'data-path')); shut(r);
 }
 A.on('input', c('q'), function (e, i) {
  var r = R(i), x = i.value.trim(), me = ++N;
  clearTimeout(T);
  if (x.length < 2) { return shut(r); }
  T = setTimeout(function () { get(r, 'q=' + encodeURIComponent(x)).then(function (d) { if (me === N) { show(r, d.items || [], x); } }); }, 200);
 });
 A.on('keydown', c('q'), function (e, i) {
  var r = R(i), l = Q('list', r), a = q('[aria-selected=true]', l), k = e.key;
  if (k === 'ArrowDown' || k === 'ArrowUp') { e.preventDefault(); mark(r, qa('[role=option]', l).indexOf(a) + (k === 'ArrowDown' ? 1 : -1)); }
  else if (k === 'Enter') { e.preventDefault(); if (!l.hidden && a) { pick(r, a); } }
  else if (k === 'Escape') { shut(r); }
 });
 A.on('click', c('list') + ' [role=option]', function (e, o) { pick(R(o), o); });
 A.on('click', null, function (e) { qa('[data-cp]').forEach(function (r) { if (!q('.af-cp-find', r).contains(e.target)) { shut(r); } }); });

 A.on('click', c('x'), function (e, b) { var r = R(b); b.closest('li').remove(); sync(r); });
 A.on('change', c('main'), function (e, m) { var r = R(m); qa(c('main'), r).forEach(function (x) { x.checked = x === m; }); sync(r); });

 function build(r, ul, map, p, pp) {
  (map[p] || []).forEach(function (n) {
   var li = tpl(r, 'node'), t = Q('t', li), u, path = pp ? pp + ' › ' + n.n : n.n;
   sa(li, 'data-id', n.id); sa(li, 'data-path', path);
   q('span', li).textContent = n.n; q('input', li).checked = String(n.id) in r.sel;
   t.disabled = !(n.c || map[n.id]); fold(t, 'false');
   if (map[n.id]) { u = el('ul'); build(r, u, map, n.id, path); li.appendChild(u); fold(t, 'true'); }
   ul.appendChild(li);
  });
 }
 function load(r, s, p) {
  get(r, s).then(function (d) {
   var map = {}, ul = Q('tree', r);
   (d.nodes || []).forEach(function (n) { (map[n.p] = map[n.p] || []).push(n); });
   ul.textContent = '';
   build(r, ul, map, p, '');
  });
 }
 A.on('click', c('open'), function (e, b) {
  var r = R(b);
  r.sel = {};
  lis(r).forEach(function (li) { r.sel[id(li)] = Q('name', li).textContent; });
  Q('filter', r).value = '';
  Q('dlg', r).showModal();
  load(r, 'parent=0', 0);
 });
 A.on('click', c('t'), function (e, t) {
  var li = t.closest('li'), r = R(t), u = q('ul', li), k = g(li, 'data-id');
  if (u) { u.hidden = !u.hidden; }
  else { u = el('ul'); li.appendChild(u); get(r, 'parent=' + k).then(function (d) { var m = {}; m[k] = d.nodes || []; build(r, u, m, k, g(li, 'data-path')); }); }
  fold(t, u.hidden ? 'false' : 'true');
 });
 A.on('change', c('tree') + ' input', function (e, cb) {
  var r = R(cb), li = cb.closest('li'), k = g(li, 'data-id');
  if (cb.checked) { r.sel[k] = g(li, 'data-path'); } else { delete r.sel[k]; }
 });
 A.on('input', c('filter'), function (e, i) {
  var r = R(i), v = i.value.trim();
  clearTimeout(T);
  T = setTimeout(function () { load(r, v === '' ? 'parent=0' : 'tree=' + encodeURIComponent(v), 0); }, 200);
 });
 A.on('click', c('all'), function (e, b) { load(R(b), g(b, 'data-cp-all') === '1' ? 'tree=' : 'parent=0', 0); });
 A.on('click', c('close'), function (e, b) { Q('dlg', R(b)).close(); });
 A.on('click', c('apply'), function (e, b) {
  var r = R(b);
  lis(r).forEach(function (li) { if (!(id(li) in r.sel)) { li.remove(); } });
  Object.keys(r.sel).forEach(function (k) { add(r, k, r.sel[k]); });
  Q('dlg', r).close();
 });

 D.addEventListener('aifec:category-add', function (e) {
  var r = q('[data-cp]');
  if (r) { get(r, 'ids=' + encodeURIComponent(e.detail)).then(function (d) { (d.items || []).forEach(function (it) { add(r, it.id, it.path); }); }); }
 });
 qa('[data-cp]').forEach(function (r) {
  var s = r.closest('form').elements[g(r, 'data-name').replace('categories', 'main_category')];
  if (s) { s.disabled = true; s.closest('[data-af-field]').hidden = true; }
  r.hidden = false; sync(r);
 });
}());

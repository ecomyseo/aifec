/* AIFEC — cargar el panel la primera vez que se abre. */
(function () {
  'use strict';
  var box = document.querySelector('[data-af-assistant]');
  if (!box || !window.fetch) { return; }
  var trigger = box.querySelector('[data-af-assistant-open]');
  if (!trigger) { return; }
  box.hidden = false;
  var resume = box.querySelector('[data-af-assistant-resume]');
  try {
    sessionStorage.removeItem('af-assistant-log');
    var count = Number(sessionStorage.getItem('af-chat-unread')) || 0;
    var badge = box.querySelector('[data-af-unread]');
    if (count && badge) {
      badge.hidden = false;
      badge.textContent = String(count);
      trigger.dataset.originalLabel = trigger.getAttribute('aria-label');
      trigger.setAttribute('aria-label', trigger.dataset.originalLabel + '. ' + badge.getAttribute('data-label').replace('{count}', String(count)));
    }
    if (resume && JSON.parse(sessionStorage.getItem('af-assistant-public-log') || '[]').length) {
      resume.hidden = false;
    }
  } catch (e) { /* La tienda también funciona sin almacenamiento de sesión. */ }

  var loading = false;
  var style;
  function load() {
    if (loading) { return; }
    loading = true;
    trigger.setAttribute('aria-busy', 'true');
    if (!style) {
      style = document.createElement('link');
      style.rel = 'stylesheet';
      style.href = box.getAttribute('data-af-panel-style');
      document.head.appendChild(style);
    }
    var script = document.createElement('script');
    script.src = box.getAttribute('data-af-panel-script');
    script.onload = function () {
      trigger.removeAttribute('aria-busy');
      trigger.removeEventListener('click', load);
      trigger.click();
    };
    script.onerror = function () {
      loading = false;
      script.remove();
      trigger.removeAttribute('aria-busy');
      if (resume) { resume.textContent = box.getAttribute('data-t-error'); resume.hidden = false; }
    };
    document.head.appendChild(script);
  }
  trigger.addEventListener('click', load);
  var docked = false;
  try { docked = sessionStorage.getItem('af-chat-docked') === '1'; } catch (e) { /* opcional */ }
  if (document.body.hasAttribute('data-af-chat-window') || docked) { load(); }
}());

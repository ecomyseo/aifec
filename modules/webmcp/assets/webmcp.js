/*!
 * AIFEC — WebMCP: registra las herramientas de la tienda para el agente de IA del navegador.
 *
 * Se carga con defer y no hace nada si el navegador no ofrece la API (document.modelContext o, en versiones anteriores,
 * navigator.modelContext). Trabaja cuando el navegador está en reposo y no pinta ni mueve nada de la página. No lleva
 * ningún texto visible: las descripciones y los esquemas llegan del servidor (/webmcp/tools.json), de la misma tabla de
 * herramientas que usan el asistente, la API y el MCP, y cada herramienta llama a su dirección JSON.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
(function () {
  'use strict';

  var script = document.currentScript;
  var listUrl = script ? script.getAttribute('data-webmcp-tools') : '';
  var modelContext = document.modelContext || (window.navigator && window.navigator.modelContext);

  if (!listUrl || !modelContext || typeof modelContext.registerTool !== 'function' || typeof window.fetch !== 'function') {
    return;
  }

  // Las direcciones de la propia web: nunca se llama a otro sitio aunque la lista lo pidiera.
  function ownPath(url) {
    return typeof url === 'string' && /^\/(?!\/)/.test(url);
  }

  // Una herramienta se ejecuta llamando a su dirección. Devuelve el JSON como texto, que es lo que lee el agente.
  function run(tool, args) {
    return window.fetch(tool.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'fetch'},
      body: JSON.stringify(args && typeof args === 'object' ? args : {})
    }).then(function (response) {
      return response.json().catch(function () {
        return {error: response.status};
      }).then(function (data) {
        return JSON.stringify(data === null || typeof data !== 'object' ? {result: data} : data);
      });
    }, function () {
      return JSON.stringify({error: 'network'});
    });
  }

  // Un formulario marcado ya ofrece esa herramienta: registrarla otra vez con el mismo nombre daría un error.
  function declared(name) {
    var forms = document.querySelectorAll('form[toolname]');
    for (var i = 0; i < forms.length; i++) {
      if (forms[i].getAttribute('toolname') === name) {
        return true;
      }
    }
    return false;
  }

  function register(tool) {
    if (!tool || typeof tool.name !== 'string' || !ownPath(tool.endpoint) || declared(tool.name)) {
      return;
    }
    var annotations = {};
    if (tool.annotations && tool.annotations.readOnlyHint === true) {
      annotations.readOnlyHint = true;
    }
    var definition = {
      name: tool.name,
      description: String(tool.description || ''),
      inputSchema: tool.inputSchema && typeof tool.inputSchema === 'object' ? tool.inputSchema : {type: 'object', properties: {}},
      annotations: annotations,
      execute: function (args) {
        return run(tool, args);
      }
    };
    try {
      var done = modelContext.registerTool(definition);
      if (done && typeof done.catch === 'function') {
        done.catch(function () {});
      }
    } catch (error) {
      // Un nombre repetido o un esquema que el navegador no admite no tumban el resto de herramientas.
    }
  }

  function start() {
    window.fetch(listUrl, {credentials: 'same-origin'}).then(function (response) {
      return response.ok ? response.json() : null;
    }).then(function (data) {
      var tools = data && Array.isArray(data.tools) ? data.tools : [];
      for (var i = 0; i < tools.length; i++) {
        register(tools[i]);
      }
    }).catch(function () {});
  }

  // En reposo: la página ya está pintada y el agente no tiene prisa.
  if (typeof window.requestIdleCallback === 'function') {
    window.requestIdleCallback(start, {timeout: 4000});
  } else {
    window.setTimeout(start, 1500);
  }
}());

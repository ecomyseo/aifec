/*
 * AIFEC — arnés de Node de las pruebas del asistente: carga assistant.js y escribe, en JSON, lo que devuelve el conversor de formato
 * (la función pura parse) para una lista de textos. Lo lanza FastStreamTest.php. Uso: node format_harness.js <ruta de assistant.js>
 * Autor: Ecom Experts · 2026 · Ver LICENSE.md
 */
'use strict';

var path = process.argv[2];
var api = require(path);
var cases = [
  '**negrita** y texto',
  '- uno\n- dos',
  '* uno\n* dos **x**',
  'Primera linea\nSegunda linea',
  'Parrafo uno\n\nParrafo dos',
  '<img src=x onerror=alert(1)>',
  '[pulsa aqui](javascript:alert(1))',
  '**<b>x</b>**',
  '***raro***',
  '',
  null,
  'Total: **64,85 euros**\n- silla\n- mesa\nFin'
];
var out = cases.map(function (text) {
  return api.parse(text);
});
process.stdout.write(JSON.stringify(out));

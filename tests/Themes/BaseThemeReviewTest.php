<?php
/**
 * AIFEC — tema base: fallos que se vieron al abrir las páginas en un navegador y que ninguna prueba de HTML cazaba.
 * Cada prueba dice qué se vio. Son comprobaciones de la hoja de estilos: lo que de verdad lo confirma es la captura.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/** La hoja del tema base, sin comentarios ni espacios, para comparar reglas sin depender del formato. */
function baseThemeCssPacked(): string
{
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/themes/base/assets/css/theme.css');
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);

    return (string) preg_replace('/\s+/', '', $css);
}

test('listado sin filtros: los resultados ocupan todo el ancho, no la columna del lateral', function (): void {
    // Visto el 12/11/2026 en /tema/muestra a 1440 px: sin el panel de filtros, la rejilla de productos caía en la
    // columna de 16rem reservada al lateral y salía un producto por fila.
    $css = baseThemeCssPacked();
    contains('.af-listing{grid-template-columns:16remminmax(0,1fr)', $css, 'el listado de escritorio sigue teniendo lateral y resultados');
    contains('.af-listing>.af-listing-results:first-child{grid-column:1/-1}', $css, 'sin lateral delante, los resultados cruzan las dos columnas');
});

test('un campo no se estira cuando el de al lado es más alto', function (): void {
    // Visto el 13/11/2026 en la caja a 1440 px: «Empresa» y «NIF» van en la misma fila y «NIF» lleva una ayuda debajo.
    // El campo es una rejilla; estirado por la fila, repartía el alto de más entre la etiqueta y la casilla, y la casilla
    // de «Empresa» salía más baja y más alta que la de «NIF».
    $css = baseThemeCssPacked();
    check(preg_match('/\.af-field\{[^}]*align-content:start[^}]*\}/', $css) === 1, '.af-field coloca su contenido arriba');
});

test('logotipo con imagen: tiene alto fijo y no deforma la cabecera', function (): void {
    $css = baseThemeCssPacked();
    check(preg_match('/\.af-logo-img\{[^}]*height:[0-9.]+rem[^}]*\}/', $css) === 1, '.af-logo-img fija su alto');
    check(preg_match('/\.af-logo-img\{[^}]*max-width:[0-9.]+rem[^}]*\}/', $css) === 1, '.af-logo-img limita su ancho');
});

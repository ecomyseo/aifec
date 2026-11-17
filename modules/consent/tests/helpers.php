<?php
/**
 * AIFEC — ayudas de prueba del consentimiento. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   consentApp(array $opciones = []): App
 *       Aplicación con el módulo «consent» (y el módulo de tareas), con los ajustes que se pidan en $opciones['settings'].
 *
 *   consentCookie(App $app, string $letras = 'sm', int $version = 1): string
 *       El valor de una cookie de decisión válida (identificador de mentira) para esa versión.
 *
 *   consentWeb(App $app, ?string $letras = null): TestClient
 *       Navegador de mentira; con $letras, ya trae la cookie de la decisión.
 *
 *   consentNotice(string $html): string
 *       El trozo de HTML del aviso (de «<div class="af-consent"» al final de su capa), para comparar entre visitantes.
 *
 *   consentRows(App $app): array
 *       Filas del registro de decisiones.
 *
 *   consentPost(TestClient $web, array $campos = [], array $cabeceras = []): Response
 *       La prueba de una decisión tal como la manda consent.js (formulario, mismo origen).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Response;

if (!function_exists('consentApp')) {
    /**
     * @param array<string,mixed> $opciones
     */
    function consentApp(array $opciones = []): App
    {
        $opciones['modules'] = array_merge(['consent', 'jobs'], isset($opciones['modules']) && is_array($opciones['modules']) ? $opciones['modules'] : []);

        $app = testApp($opciones);
        $app->boot();

        return $app;
    }

    function consentCookie(App $app, string $letras = 'sm', int $version = 1): string
    {
        return $version . '.' . $letras . '.' . $app->clock()->now() . '.9f2c4e1ab7d30586';
    }

    function consentWeb(App $app, ?string $letras = null, int $version = 1): TestClient
    {
        $web = new TestClient($app);
        if ($letras !== null) {
            $web->cookies[$app->cookieName('consent')] = consentCookie($app, $letras, $version);
        }

        return $web;
    }

    function consentNotice(string $html): string
    {
        $start = strpos($html, '<div class="af-consent"');
        if ($start === false) {
            return '';
        }
        $end = strpos($html, '</body>', $start);

        return (string) substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    function consentRows(App $app): array
    {
        return $app->db()->select('SELECT * FROM {consent_log} ORDER BY id', []);
    }

    /**
     * @param array<string,mixed>  $campos
     * @param array<string,string> $cabeceras
     */
    function consentPost(TestClient $web, array $campos = [], array $cabeceras = []): Response
    {
        return $web->post('/consentimiento', $campos + ['id' => '9f2c4e1ab7d30586', 'v' => '1', 'c' => 'sm', 'g' => '0', 'l' => 'es'], $cabeceras);
    }
}

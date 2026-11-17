<?php
/**
 * AIFEC — ayudantes de plantilla del consentimiento: consent_script() y consent_iframe().
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

if (!function_exists('consent_script')) {
    /**
     * Un guion de terceros que solo se activa con su categoría concedida. $srcOrCode es una dirección o el código.
     * Uso en una plantilla: <?= consent_script('statistics', 'https://analiticas.tienda.test/a.js', ['async' => true]) ?>
     *
     * @param array<string,mixed> $attrs
     */
    function consent_script(string $category, string $srcOrCode, array $attrs = []): string
    {
        $app = app();
        if (!$app->has('consent')) {
            // Sin el módulo no hay quien lo active: el guion no sale (lo prudente es no cargar nada de terceros).
            return '';
        }

        return $app->service('consent')->script($category, $srcOrCode, $attrs);
    }
}

if (!function_exists('consent_iframe')) {
    /**
     * Un marco (vídeo, mapa) que no se carga hasta que la categoría esté concedida; mientras tanto sale «Acepta para ver».
     *
     * @param array<string,mixed> $attrs
     */
    function consent_iframe(string $category, string $src, array $attrs = []): string
    {
        $app = app();
        if (!$app->has('consent')) {
            return '';
        }

        return $app->service('consent')->iframe($category, $src, $attrs);
    }
}

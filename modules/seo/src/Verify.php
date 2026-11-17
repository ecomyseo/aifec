<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Seo;

use Aifec\Kernel\App;

/**
 * Códigos de verificación de Google y de Bing: solo el código, nunca la etiqueta entera. Se validan al guardar (settings.php lleva
 * la misma expresión que PATTERN) y otra vez al pintar, por si alguien los escribió directamente en la base de datos.
 */
final class Verify
{
    private const PATTERN = '/^[A-Za-z0-9_-]{8,100}\z/';

    /** ¿Es un código de verificación con pinta de serlo? Letras, cifras, guion y guion bajo, de 8 a 100 caracteres. */
    public static function valid(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1;
    }

    /** Las etiquetas «meta» que van en el <head>; vacío si no hay ningún código válido. */
    public static function tags(App $app): string
    {
        $html = '';
        $settings = $app->settings();
        $map = ['seo.verify_google' => 'google-site-verification', 'seo.verify_bing' => 'msvalidate.01'];
        foreach ($map as $key => $name) {
            $code = $settings->str($key);
            if ($code !== '' && self::valid($code)) {
                $html .= '<meta name="' . $name . '" content="' . e($code) . "\">\n";
            }
        }

        return $html;
    }
}

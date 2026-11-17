<?php
/**
 * AIFEC — código del tema atelier: tres ayudas para sus plantillas (imagen de una opción del tema, enlace seguro y
 * color de una muestra). No registra rutas ni ganchos: todo lo demás lo hereda del tema base.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

if (!function_exists('atelier_image')) {
    /**
     * La imagen de la biblioteca que el comerciante ha elegido en una opción del tema (campaña, bloque editorial).
     * Sin elección, sin módulo de medios o con un identificador que ya no existe, devuelve null y la plantilla usa su dibujo.
     *
     * @return array<string,mixed>|null
     */
    function atelier_image(string $key): ?array
    {
        $id = theme_option($key, '');
        if (!is_numeric($id) || (int) $id <= 0) {
            return null;
        }
        $app = App::current();
        if (!$app->has('media')) {
            return null;
        }
        try {
            $image = $app->service('media')->image((int) $id);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($image) && !empty($image['url']) ? $image : null;
    }
}

if (!function_exists('atelier_hero_image')) {
    /**
     * La foto de la cabecera de la portada (P100): la elegida en «Imagen de campaña» o, si no hay, la de un producto destacado
     * de la propia tienda (el primero con foto; sin destacados, el más reciente con foto). Nunca un dibujo de otro sector:
     * sin ninguna foto devuelve null y la cabecera va sin imagen. «product» dice si es la de un producto (se enseña entera).
     *
     * @return array{image:array<string,mixed>,product:bool}|null
     */
    function atelier_hero_image(): ?array
    {
        $chosen = atelier_image('hero_image');
        if ($chosen !== null) {
            return ['image' => $chosen, 'product' => false];
        }
        $app = App::current();
        if (!$app->has('catalog')) {
            return null;
        }
        foreach ([['featured' => true, 'per_page' => 12], ['sort' => 'newest', 'per_page' => 12]] as $query) {
            try {
                $result = $app->service('catalog')->listing($query);
            } catch (\Throwable $e) {
                return null;
            }
            foreach (isset($result['items']) && is_array($result['items']) ? $result['items'] : [] as $card) {
                if (is_array($card) && isset($card['image']) && is_array($card['image']) && !empty($card['image']['url'])) {
                    return ['image' => $card['image'], 'product' => true];
                }
            }
        }

        return null;
    }
}

if (!function_exists('atelier_link')) {
    /**
     * Dirección que se puede poner en un enlace a partir de un texto libre de una opción: una ruta de la propia tienda
     * («/novedades»), un ancla («#novedades») o una dirección http(s). Cualquier otra cosa (javascript:, data:…) da «».
     */
    function atelier_link(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^/(?!/)#', $value) === 1) {
            return url($value);
        }
        if (preg_match('/^#[A-Za-z0-9_-]+$/', $value) === 1 || preg_match('#^https?://[^\s"<>]+$#i', $value) === 1) {
            return $value;
        }

        return '';
    }
}

if (!function_exists('atelier_hex')) {
    /** Color #rrggbb de una muestra que trae un dato de producto, o «» si no es un color válido. */
    function atelier_hex($value): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : '';
    }
}

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Themes;

/**
 * Compone la paleta de un tema nuevo a partir del fondo y del acento que elige la persona (o que propone la IA) y la
 * deja con contraste AA: el texto sobre el fondo, el acento sobre el fondo y el texto sobre el acento. Lo que no llega se
 * ajusta acercándolo a negro o a blanco; lo que ni así llega se rechaza. Los textos de los avisos van en inglés y los
 * traduce quien los enseña.
 */
final class Palette
{
    /**
     * @param string|null          $bg     fondo de la página («#rrggbb») o null para dejar el del tema padre
     * @param string|null          $accent acento («#rrggbb») o null para dejar el del tema padre
     * @param array<string,string> $base   colores de fábrica del tema padre: bg, surface, ink, muted, line, accent
     *
     * @return array{colors:array<string,string>,notes:string[],rejected:string[]} colors: solo los que cambian
     */
    public static function build(?string $bg, ?string $accent, array $base): array
    {
        $colors = [];
        $notes = [];
        $rejected = [];

        if ($bg !== null && !Color::isHex($bg)) {
            $rejected[] = 'The background colour is not valid';
            $bg = null;
        }
        if ($accent !== null && !Color::isHex($accent)) {
            $rejected[] = 'The accent colour is not valid';
            $accent = null;
        }

        $bgFinal = isset($base['bg']) ? (string) $base['bg'] : '#ffffff';
        $ink = isset($base['ink']) ? (string) $base['ink'] : '#1f2421';
        if ($bg !== null) {
            $bg = strtolower($bg);
            $bgFinal = $bg;
            $dark = Color::luminance($bg) < 0.35;
            $ink = Color::ensure($dark ? '#f4f4f0' : '#1f2421', $bg, 7.0);
            if ($ink === null) {
                $ink = Color::textOn($bg);
            }
            $surface = $dark ? Color::mix($bg, '#ffffff', 0.07) : (Color::luminance($bg) > 0.9 ? '#ffffff' : Color::mix($bg, '#ffffff', 0.6));
            $muted = Color::ensure(Color::mix($ink, $bg, 0.38), $bg, Color::AA);
            $colors['bg'] = $bg;
            $colors['surface'] = $surface;
            $colors['ink'] = $ink;
            $colors['muted'] = $muted !== null ? $muted : $ink;
            $colors['line'] = Color::mix($ink, $bg, 0.85);
        }

        if ($accent !== null || $bg !== null) {
            $wanted = $accent !== null ? strtolower($accent) : (isset($base['accent']) ? (string) $base['accent'] : '#1f2421');
            $fixed = Color::ensure($wanted, $bgFinal, Color::AA);
            if ($fixed === null) {
                $fixed = $ink;
                $rejected[] = 'The accent colour cannot reach enough contrast with the background';
            } elseif ($fixed !== $wanted && $accent !== null) {
                $notes[] = 'The accent colour was darkened or lightened to reach AA contrast with the background';
            }
            $colors['accent'] = $fixed;
            $colors['accent_ink'] = Color::textOn($fixed);
        }

        return ['colors' => $colors, 'notes' => $notes, 'rejected' => $rejected];
    }
}

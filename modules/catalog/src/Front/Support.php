<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Front;

use Aifec\Kernel\App;
use Aifec\Text\HtmlSanitizer;

/**
 * Piezas pequeñas que comparten los controladores y las plantillas de la tienda del catálogo: migas, direcciones completas,
 * HTML de descripciones, importes escritos por una persona y el texto del plazo de entrega.
 */
final class Support
{
    /** Importe máximo que se acepta en un filtro de precio, en unidades de la moneda (para no desbordar al pasar a céntimos). */
    private const MAX_UNITS = 99999999;

    /**
     * Migas de una página de la tienda: la portada y lo que se le pase detrás (el último elemento va sin enlace).
     *
     * @param array<int,array{name:string,url:string}> $tail
     *
     * @return array<int,array{name:string,url:string}>
     */
    public static function crumbs(App $app, array $tail): array
    {
        return array_merge([['name' => t('Home'), 'url' => $app->url()->to('/')]], $tail);
    }

    /**
     * Una dirección de la propia tienda como dirección completa (las imágenes vienen relativas a la raíz del dominio).
     */
    public static function absolute(App $app, string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
            return $url;
        }
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }

        return $app->url()->origin() . '/' . ltrim($url, '/');
    }

    /**
     * El HTML de una descripción, limpio por si lo guardó alguien que no pasó por el panel (importaciones, API).
     */
    public static function html(string $html): string
    {
        $html = trim($html);

        return $html === '' ? '' : HtmlSanitizer::clean($html);
    }

    /**
     * Un importe escrito por una persona («19,99», «19.99», «1.234,50», «20 €») en céntimos; null si no es un importe válido.
     * Con un solo separador sin coma, el punto es el decimal.
     */
    public static function cents(string $text): ?int
    {
        $text = trim(str_replace([' ', "\u{a0}", '€', "\u{202f}"], '', $text));
        if ($text === '') {
            return null;
        }
        $comma = strrpos($text, ',');
        $dot = strrpos($text, '.');
        if ($comma !== false && $dot !== false) {
            // Los dos a la vez: el último es el decimal y el otro separa millares.
            if ($comma > $dot) {
                $text = str_replace(',', '.', str_replace('.', '', $text));
            } else {
                $text = str_replace(',', '', $text);
            }
        } else {
            $text = str_replace(',', '.', $text);
        }
        if (preg_match('/^(\d{1,8})(?:\.(\d+))?$/', $text, $m) !== 1) {
            return null;
        }
        $units = (int) $m[1];
        if ($units > self::MAX_UNITS) {
            return null;
        }
        $fraction = isset($m[2]) ? substr($m[2] . '00', 0, 2) : '00';

        return $units * 100 + (int) $fraction;
    }

    /** Céntimos como se escriben en un campo de filtro: «19,99» y «20» (sin decimales si no los hay). */
    public static function formatCents(int $cents): string
    {
        $cents = max(0, $cents);
        $units = intdiv($cents, 100);
        $rest = $cents % 100;

        return $rest === 0 ? (string) $units : $units . ',' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT);
    }

    /**
     * El plazo de entrega en palabras; vacío si no hay.
     *
     * @param mixed $days [mínimo, máximo] de la disponibilidad, o null
     */
    public static function delivery($days): string
    {
        if (!is_array($days) || count($days) < 2) {
            return '';
        }
        $days = array_values($days);
        $min = max(0, (int) $days[0]);
        $max = max(0, (int) $days[1]);
        if ($max === 0 && $min === 0) {
            return '';
        }
        if ($max < $min) {
            $max = $min;
        }
        if ($min === $max) {
            return tn('Delivery in {n} day', 'Delivery in {n} days', $min);
        }

        return t('Delivery in {min} to {max} days', ['min' => $min, 'max' => $max]);
    }

    /** El título de una página de listado: de la segunda en adelante lleva «— página N». */
    public static function pageTitle(string $title, int $page): string
    {
        return $page > 1 ? $title . ' — ' . t('page {n}', ['n' => $page]) : $title;
    }

    /** La clase CSS de la disponibilidad (is-ok, is-low, is-out). */
    /**
     * Las etiquetas de caché de la página de una entidad del catálogo («product», «category» o «brand»). La página en HTML
     * y su versión en texto (módulo semantic) usan ESTA lista, así una etiqueta nueva llega a las dos a la vez. La ficha
     * lleva además «shipping» (su línea de envío) y «cms» (el enlace a la página de envíos); la de cada relacionado la añade
     * su controlador.
     *
     * @return string[]
     */
    public static function pageTags(string $type, int $id): array
    {
        $tags = [$type . ':' . $id, 'catalog', 'tax'];
        if ($type === 'product') {
            $tags[] = 'shipping';
            $tags[] = 'cms';
        }

        return $tags;
    }

    public static function availabilityClass(string $status): string
    {
        if ($status === 'out_of_stock') {
            return 'is-out';
        }

        return $status === 'low_stock' ? 'is-low' : 'is-ok';
    }
}

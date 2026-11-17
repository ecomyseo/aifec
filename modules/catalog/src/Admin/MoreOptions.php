<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

/**
 * «Más opciones» en cada pestaña de la ficha de producto: lo que casi nadie toca va dentro de un <details> plegado (sin librerías)
 * que se abre solo si alguno de sus campos tiene valor o error. Ningún campo desaparece: siguen siendo campos del mismo
 * formulario, con su validación y su guardado de siempre; solo cambia dónde se pintan.
 *
 * El formulario genérico del panel pinta cada campo como un bloque «af-field» dentro de su pestaña. Aquí se recortan esos
 * bloques ya pintados (con la cuenta de «div» abiertos y cerrados, que es exacta porque todo dato va escapado) y se meten en un
 * <details> al final de la pestaña.
 */
final class MoreOptions
{
    /**
     * Los campos de cada pestaña que van plegados. Los de «datos clave» y «preguntas» son uno por idioma.
     *
     * @param string[] $langs
     *
     * @return array<string,string[]> pestaña => campos
     */
    public static function groups(array $langs): array
    {
        $seo = [];
        foreach ($langs as $lang) {
            $seo[] = 'facts_' . $lang;
        }
        foreach ($langs as $lang) {
            $seo[] = 'faqs_' . $lang;
        }

        return [
            'pdmain' => ['visibility', 'featured', 'brand_id', 'family_id', 'kind'],
            'pdprice' => ['sale_price', 'sale_from', 'sale_to', 'cost', 'tax_class_id'],
            'pdstock' => ['gtin', 'mpn', 'stock_alert', 'backorders', 'min_qty', 'max_qty', 'length', 'width', 'height', 'delivery_min_days', 'delivery_max_days'],
            'pdcats' => ['main_category'],
            'pdseo' => $seo,
        ];
    }

    /**
     * ¿Tiene valor este campo? Valor = algo distinto de vacío y distinto de lo que el campo trae de serie.
     *
     * @param array<string,mixed> $field definición del campo
     * @param mixed               $value
     */
    public static function hasValue(array $field, $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $one) {
                if (self::hasValue(['default' => null], $one)) {
                    return true;
                }
            }

            return false;
        }
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return false;
        }
        $default = array_key_exists('default', $field) ? $field['default'] : null;
        if ($default !== null && !is_array($default) && is_scalar($value) && (string) $value === (string) $default) {
            return false;
        }

        return true;
    }

    /**
     * Qué pestañas llevan su «Más opciones» abierto porque algún campo ya tiene valor.
     *
     * @param array<string,string[]>        $groups
     * @param array<string,array<string,mixed>> $fields definición de los campos del formulario
     * @param array<string,mixed>           $values valores que se van a enseñar (lo guardado, o lo enviado si es un reenvío)
     *
     * @return array<string,bool>
     */
    public static function open(array $groups, array $fields, array $values): array
    {
        $open = [];
        foreach ($groups as $tab => $names) {
            $open[$tab] = false;
            foreach ($names as $name) {
                if (!isset($fields[$name]) || !array_key_exists($name, $values)) {
                    continue;
                }
                if (self::hasValue($fields[$name], $values[$name])) {
                    $open[$tab] = true;
                    break;
                }
            }
        }

        return $open;
    }

    /**
     * Mete en un <details> los campos plegados de cada pestaña del HTML de la ficha.
     *
     * @param array<string,string[]> $groups
     * @param array<string,bool>     $open   pestañas que van abiertas aunque no haya errores
     */
    public static function wrap(string $html, array $groups, array $open): string
    {
        foreach ($groups as $tab => $names) {
            if (strpos($html, 'data-af-more="' . $tab . '"') !== false) {
                continue;
            }
            $panelAt = strpos($html, '<div class="af-tabpanel" role="tabpanel" data-af-panel="' . $tab . '"');
            if ($panelAt === false) {
                continue;
            }
            $panelEnd = self::closing($html, $panelAt);
            if ($panelEnd === null) {
                continue;
            }
            $panel = substr($html, $panelAt, $panelEnd - $panelAt);
            $slices = [];
            foreach ($names as $name) {
                if (preg_match('/<div class="af-field[^"]*" data-af-field="' . preg_quote($name, '/') . '"/', $panel, $m, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                $start = (int) $m[0][1];
                $end = self::closing($panel, $start);
                if ($end === null) {
                    continue;
                }
                $slices[] = substr($panel, $start, $end - $start);
                $panel = substr($panel, 0, $start) . substr($panel, $end);
            }
            if ($slices === []) {
                continue;
            }
            $body = implode('', $slices);
            $isOpen = !empty($open[$tab]) || strpos($body, 'af-field-invalid') !== false;
            $details = '<details class="af-help af-more" data-af-more="' . e($tab) . '"' . ($isOpen ? ' open' : '') . '><summary>' . e(t('More options')) . '</summary><div>' . $body . '</div></details>';
            $panel = substr($panel, 0, -strlen('</div>')) . $details . '</div>';
            $html = substr($html, 0, $panelAt) . $panel . substr($html, $panelEnd);
        }

        return $html;
    }

    /**
     * Posición justo después del «</div>» que cierra el «<div» que empieza en $start; null si no cierra.
     */
    private static function closing(string $html, int $start): ?int
    {
        $depth = 0;
        $pos = $start;
        while (preg_match('/<(\/?)div\b[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE, $pos) === 1) {
            $depth += $m[1][0] === '/' ? -1 : 1;
            $pos = (int) $m[0][1] + strlen($m[0][0]);
            if ($depth === 0) {
                return $pos;
            }
        }

        return null;
    }
}

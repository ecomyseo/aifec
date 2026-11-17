<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog;

/**
 * Utilidades de texto del catálogo: elegir la traducción que toca, sacar el texto plano de un HTML y
 * leer las listas guardadas como JSON. Todo es puro (sin base de datos ni aplicación).
 */
final class Texts
{
    /**
     * Elige la fila del idioma pedido; si no hay, la del idioma principal; si tampoco, la primera que exista.
     *
     * @param array<string,array<string,mixed>> $byLang idioma => fila
     *
     * @return array<string,mixed>|null
     */
    public static function pick(array $byLang, string $lang, string $default): ?array
    {
        if (isset($byLang[$lang])) {
            return $byLang[$lang];
        }
        if (isset($byLang[$default])) {
            return $byLang[$default];
        }
        foreach ($byLang as $row) {
            return $row;
        }

        return null;
    }

    /** Texto sin etiquetas, con las entidades descodificadas y los espacios colapsados. */
    public static function plain(string $html): string
    {
        $text = (string) preg_replace('/<[^>]*>/', ' ', $html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Lista de datos comprobables guardada como JSON: [{"label":"Material","value":"Roble"}].
     * Se descarta lo que no tenga etiqueta y valor.
     *
     * @return array<int,array{label:string,value:string}>
     */
    public static function facts(?string $json): array
    {
        $out = [];
        $decoded = $json !== null && $json !== '' ? json_decode($json, true) : null;
        foreach (is_array($decoded) ? $decoded : [] as $fact) {
            if (!is_array($fact) || !isset($fact['label'], $fact['value']) || !is_scalar($fact['label']) || !is_scalar($fact['value'])) {
                continue;
            }
            $label = trim((string) $fact['label']);
            $value = trim((string) $fact['value']);
            if ($label !== '' && $value !== '') {
                $out[] = ['label' => $label, 'value' => $value];
            }
        }

        return $out;
    }

    /**
     * Las etiquetas (ya como «slug») de lo que la tienda dice SIEMPRE por su cuenta y al momento: el precio y las existencias.
     * Un dato guardado con una de ellas es una foto vieja de algo que cambia, y acaba contradiciendo a la ficha.
     */
    private const LIVE_LABELS = [
        'precio', 'pvp', 'precio-con-iva', 'price', 'prix', 'preis', 'prezzo', 'preco', 'preu',
        'disponibilidad', 'existencias', 'stock', 'en-stock', 'availability', 'in-stock', 'disponibilite', 'verfugbarkeit',
        'disponibilita', 'disponibilidade', 'disponibilitat',
    ];

    /**
     * Los datos comprobables que se ENSEÑAN (ficha, datos estructurados, versión en texto, feeds, buscador, asistentes): los
     * guardados menos los que repiten el precio o la disponibilidad. Esos dos los da la tienda al momento; guardados como dato
     * (los escribe una importación o una IA el día que mira la ficha) se quedan viejos y la misma página acaba diciendo «En
     * stock» arriba y «Agotado» en la tabla. En el formulario del panel se siguen viendo todos: ahí se usa facts().
     *
     * @param array<int,array{label:string,value:string}> $facts
     *
     * @return array<int,array{label:string,value:string}>
     */
    public static function shownFacts(array $facts): array
    {
        $out = [];
        foreach ($facts as $fact) {
            if (is_array($fact) && isset($fact['label']) && !in_array(\Aifec\Util\Str::slug((string) $fact['label']), self::LIVE_LABELS, true)) {
                $out[] = $fact;
            }
        }

        return $out;
    }

    /**
     * Lee de un JSON los datos añadidos de un producto: [clave => ['label','value','type','public']].
     *
     * @return array<string,array{label:string,value:mixed,type:string,public:bool}>
     */
    public static function extra(?string $json): array
    {
        $out = [];
        $decoded = $json !== null && $json !== '' ? json_decode($json, true) : null;
        foreach (is_array($decoded) ? $decoded : [] as $key => $item) {
            if (!is_array($item) || !array_key_exists('value', $item)) {
                continue;
            }
            $out[(string) $key] = [
                'label' => isset($item['label']) && is_scalar($item['label']) ? (string) $item['label'] : (string) $key,
                'value' => $item['value'],
                'type' => isset($item['type']) && is_scalar($item['type']) ? (string) $item['type'] : 'text',
                'public' => !empty($item['public']),
            ];
        }

        return $out;
    }

    /** Número con los decimales que tiene de verdad, sin ceros de relleno: 12.5000 → «12.5», 10.0000 → «10». */
    public static function trimNumber(float $value): string
    {
        $text = number_format($value, 4, '.', '');

        return rtrim(rtrim($text, '0'), '.');
    }
}

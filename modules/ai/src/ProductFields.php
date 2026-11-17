<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai;

use Aifec\Kernel\App;
use Aifec\Text\HtmlSanitizer;

/**
 * Los campos de texto de un producto por idioma que la IA puede proponer, y cómo se leen y se escriben «con cuidado»: solo ese
 * campo y solo ese idioma. Los datos comprobables (facts) y las preguntas frecuentes (faqs) viajan como una lista JSON.
 *
 *   name, meta_title, meta_description   texto plano
 *   summary, description                 HTML (siempre saneado)
 *   facts                                [{"label":"…","value":"…"}]            columna product_i18n.facts
 *   faqs                                 [{"question":"…","answer":"…"}]        tabla product_faqs
 */
final class ProductFields
{
    public const FIELDS = ['name', 'summary', 'description', 'facts', 'faqs', 'meta_title', 'meta_description'];

    /** Los campos que son una columna de texto de product_i18n (los que se pueden comparar con una columna). */
    public const TEXT_FIELDS = ['name', 'summary', 'description', 'meta_title', 'meta_description'];

    /** Los campos que son HTML. */
    public const HTML_FIELDS = ['summary', 'description'];

    /** Largo máximo de cada columna de texto con tope (el resto es texto libre). */
    private const LIMITS = ['name' => 191, 'meta_title' => 191, 'meta_description' => 320];

    public static function isField(string $field): bool
    {
        return in_array($field, self::FIELDS, true);
    }

    /** El texto actual de un campo en un idioma, sin caer en otro idioma. Vacío si no hay nada. */
    public static function read(App $app, int $productId, string $lang, string $field): string
    {
        $db = $app->db();
        if ($field === 'faqs') {
            $rows = $db->select('SELECT question, answer FROM {product_faqs} WHERE product_id = ? AND lang = ? ORDER BY position, id', [$productId, $lang]);
            $list = [];
            foreach ($rows as $row) {
                $list[] = ['question' => (string) $row['question'], 'answer' => (string) $row['answer']];
            }

            return $list === [] ? '' : self::encode($list);
        }
        if (!in_array($field, self::TEXT_FIELDS, true) && $field !== 'facts') {
            return '';
        }
        $value = $db->value('SELECT ' . $db->ident($field) . ' FROM {product_i18n} WHERE product_id = ? AND lang = ?', [$productId, $lang]);
        $value = is_string($value) ? $value : '';
        if ($field === 'facts') {
            $list = self::decodeList('facts', $value);

            return $list === null || $list === [] ? '' : self::encode($list);
        }

        return $value;
    }

    /**
     * Escribe un campo en un idioma. Si el producto no tiene fila en ese idioma se crea (con el nombre del idioma principal, que
     * es obligatorio). No toca nada más. Devuelve false si el producto no existe o el texto no vale.
     */
    public static function write(App $app, int $productId, string $lang, string $field, string $text): bool
    {
        if (!self::isField($field) || $lang === '' || strlen($lang) > 8) {
            return false;
        }
        $db = $app->db();
        if ($db->row('SELECT id FROM {products} WHERE id = ?', [$productId]) === null) {
            return false;
        }
        $list = null;
        if ($field === 'facts' || $field === 'faqs') {
            $list = self::decodeList($field, $text);
            if ($list === null) {
                return false;
            }
        } else {
            $text = self::clean($field, $text);
            if ($field === 'name' && $text === '') {
                return false;
            }
        }

        $db->transaction(static function ($db) use ($app, $productId, $lang, $field, $text, $list): void {
            if ($db->row('SELECT id FROM {product_i18n} WHERE product_id = ? AND lang = ?', [$productId, $lang]) === null) {
                $name = $db->value('SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = ?', [$productId, $app->defaultLang()]);
                if (!is_string($name) || $name === '') {
                    $name = (string) $db->value('SELECT name FROM {product_i18n} WHERE product_id = ? ORDER BY id', [$productId]);
                }
                $db->insert('product_i18n', ['product_id' => $productId, 'lang' => $lang, 'name' => $name]);
            }
            if ($field === 'faqs') {
                $db->delete('product_faqs', ['product_id' => $productId, 'lang' => $lang]);
                foreach ((array) $list as $position => $item) {
                    $db->insert('product_faqs', [
                        'product_id' => $productId,
                        'lang' => $lang,
                        'question' => $item['question'],
                        'answer' => $item['answer'],
                        'position' => (int) $position,
                    ]);
                }
            } elseif ($field === 'facts') {
                $db->update('product_i18n', ['facts' => $list === [] ? null : json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['product_id' => $productId, 'lang' => $lang]);
            } else {
                $db->update('product_i18n', [$field => $text], ['product_id' => $productId, 'lang' => $lang]);
            }
            $db->update('products', ['updated_at' => $app->clock()->sql()], ['id' => $productId]);
        });

        return true;
    }

    /**
     * Lo que se guarda de un texto: sin espacios sobrantes y, según el campo, saneado (HTML) o cortado al tope de su columna.
     */
    public static function clean(string $field, string $text): string
    {
        $text = trim($text);
        if (in_array($field, self::HTML_FIELDS, true)) {
            return HtmlSanitizer::clean($text, 'content');
        }
        if (isset(self::LIMITS[$field])) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));

            return mb_substr($text, 0, self::LIMITS[$field], 'UTF-8');
        }

        return $text;
    }

    /**
     * Lee una lista de datos o de preguntas de un JSON y la deja limpia. null si no es una lista de lo esperado.
     *
     * @return array<int,array<string,string>>|null
     */
    public static function decodeList(string $field, string $json): ?array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $keys = $field === 'faqs' ? ['question', 'answer'] : ['label', 'value'];
        $out = [];
        foreach ($data as $item) {
            if (!is_array($item) || !isset($item[$keys[0]], $item[$keys[1]]) || !is_scalar($item[$keys[0]]) || !is_scalar($item[$keys[1]])) {
                return null;
            }
            $first = trim((string) $item[$keys[0]]);
            $second = trim((string) $item[$keys[1]]);
            if ($first === '' || $second === '') {
                continue;
            }
            $out[] = [$keys[0] => $first, $keys[1] => $second];
        }

        return $out;
    }

    /**
     * La lista como texto JSON legible (el que se ve y se edita en la bandeja).
     *
     * @param array<int,array<string,string>> $list
     */
    public static function encode(array $list): string
    {
        return (string) json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

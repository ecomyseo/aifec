<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Assistant;

/**
 * La última comprobación del texto que redacta el modelo, antes de que llegue al cliente. Lo que escribe un modelo no es de fiar:
 *
 *  - una línea con un dato personal enmascarado («C*** d*** M*** 5***») se quita: el cliente no puede comprobar lo que confirma
 *    con eso, y los datos de verdad están en la tarjeta del resumen;
 *  - una frase con un importe que no salió de ninguna herramienta de esta conversación se quita: un precio solo lo da el catálogo,
 *    el carrito o los envíos. Valen los importes que el propio cliente escribió («por menos de 60 €») y los que ya dijo el
 *    asistente antes (pasaron por esta misma comprobación).
 *
 * Todo son funciones puras: texto dentro, texto fuera.
 */
final class TextGuard
{
    /** Un dato enmascarado: tres asteriscos pegados a una letra o cifra («C***», «a***@…», «***001»). */
    private const RE_MASKED = '/\w\*{3}|\*{3}\w/u';

    /** «59,90 €», «59.90 EUR», «1.500 euros». */
    private const RE_AFTER = '/(?<![\d.,])(\d{1,3}(?:[.\s\x{a0}]\d{3})+(?:,\d{1,2})?|\d+(?:[.,]\d{1,2})?)[\s\x{a0}]?(?:€|EUR\b|euros?\b|\$|£)/iu';

    /** «€59.90», «$ 1,500.00», «EUR 59,90». */
    private const RE_BEFORE = '/(?:€|\$|£|EUR\s)[\s\x{a0}]?(\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?|\d+(?:[.,]\d{1,2})?)/iu';

    /**
     * ¿Ha copiado el modelo el resultado de una herramienta? Líneas con forma de clave técnica («prices_include_tax: true»,
     * «payment: "Pago de prueba"», «address: […]»), llaves o corchetes sueltos, o un «true»/«false» suelto detrás de unos dos
     * puntos. Un texto así no se le enseña a nadie: se sustituye por el que monta el código.
     */
    public static function technical(string $text): bool
    {
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $l = trim((string) preg_replace('/^(?:[-•*>]\s*)+/u', '', trim($line)));
            if ($l === '') {
                continue;
            }
            if (preg_match('/^"?[a-z][a-z0-9]*(?:_[a-z0-9]+)+"?\s*[:=]/', $l) === 1
                || preg_match('/^"?[a-z][a-z0-9_]*"?\s*:\s*(?:["\[{]|(?:true|false|null)\b|-?\d+(?:\.\d+)?\s*,?$)/', $l) === 1
                || preg_match('/^[\[\]{}]+,?$/', $l) === 1
                || preg_match('/"[A-Za-z_]+"\s*:/', $l) === 1
                || preg_match('/[{\[]\s*"/', $l) === 1
                || preg_match('/:\s*(?:true|false|null)\s*[,.]?$/i', $l) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Quita las líneas con un dato personal enmascarado. */
    public static function stripMasked(string $text): string
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (preg_match(self::RE_MASKED, $line) === 1) {
                continue;
            }
            $out[] = $line;
        }

        return self::tidy(implode("\n", $out));
    }

    /**
     * Los importes que aparecen en un texto, en céntimos y sin repetir.
     *
     * @return int[]
     */
    public static function amounts(string $text): array
    {
        $found = [];
        foreach ([self::RE_AFTER, self::RE_BEFORE] as $pattern) {
            if (preg_match_all($pattern, $text, $matches) > 0) {
                foreach ($matches[1] as $raw) {
                    $cents = self::parse((string) $raw);
                    if ($cents !== null) {
                        $found[$cents] = true;
                    }
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Los importes que contiene el resultado de una herramienta: los números de las claves que acaban en «_cents» y los
     * importes con formato que lleven los textos.
     *
     * @param mixed $data
     *
     * @return int[]
     */
    public static function collect($data): array
    {
        $found = [];
        self::walk($data, '', $found);

        return array_keys($found);
    }

    /**
     * Quita del texto las frases con un importe que no esté entre los permitidos (céntimos). Una frase es lo que va hasta un punto, un
     * cierre de pregunta o admiración, o un salto de línea.
     *
     * @param int[] $allowed
     */
    public static function dropUnknownAmounts(string $text, array $allowed): string
    {
        $ok = array_fill_keys(array_map('intval', $allowed), true);
        $lines = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $kept = [];
            foreach (preg_split('/(?<=[.!?])\s+/u', $line) ?: [] as $sentence) {
                $unknown = false;
                foreach (self::amounts($sentence) as $cents) {
                    if (!isset($ok[$cents])) {
                        $unknown = true;
                        break;
                    }
                }
                if (!$unknown) {
                    $kept[] = $sentence;
                }
            }
            if ($kept === [] && trim($line) !== '') {
                // La línea entera era un importe inventado: no queda ni su hueco.
                continue;
            }
            $lines[] = implode(' ', $kept);
        }

        return self::tidy(implode("\n", $lines));
    }

    /** El texto sin sus importes («Hasta 60 €» → «Hasta »): para mirar las demás cifras. */
    public static function stripAmounts(string $text): string
    {
        return (string) preg_replace([self::RE_AFTER, self::RE_BEFORE], ' ', $text);
    }

    /**
     * Quita las frases que empujan a comprar sin que nadie lo haya pedido: una pregunta que ofrece añadir al carrito («¿Te gustaría
     * añadirlo al carrito?», «Would you like me to add it to your cart?»). La tarjeta ya lleva su botón. Preguntar QUÉ opción se
     * añade («¿Qué talla quieres que añada?») no es empujar y se queda.
     */
    public static function dropCartPush(string $text): string
    {
        return self::dropSentences($text, static function (string $sentence): bool {
            $n = RuleBrain::norm($sentence);
            $question = strpos($sentence, '?') !== false;

            return $question
                && preg_match('/^(?:y |entonces |pues |asi que |so |and )?(?:te gustaria|quieres|quiere|deseas|desea|te apetece|te lo|te la|lo|la|los|las|lo quieres|would you like|do you want|shall i|should i|can i|may i|want me to)\b/', $n) === 1
                && preg_match('/\b(?:anad\w*|agreg\w*|carrito|cesta|add|cart|basket)\b/', $n) === 1;
        });
    }

    /**
     * Quita las frases que prometen efectos de salud («cura», «alivia el dolor», «previene», «heals»): una tienda no puede decirlo de
     * un producto (llega del asistente de PrestaShop, 0.13.0). Hablar de un tratamiento capilar o de una crema sí se queda.
     */
    public static function dropHealthClaims(string $text): string
    {
        return self::dropSentences($text, static function (string $sentence): bool {
            $n = RuleBrain::norm($sentence);

            return preg_match('/\b(?:cura|curan|curara|sana|sanan|previene|previenen|alivia(?:n)? (?:el|la|los|las) (?:dolor\w*|sintoma\w*|ansiedad|insomnio)|elimina(?:n)? (?:el|los) (?:dolor\w*|sintoma\w*)|cures|heals|prevents (?:disease|illness)|relieves (?:pain|symptoms)|guerit|soigne)\b/', $n) === 1;
        });
    }

    /**
     * Arregla lo que estropea un texto al pasar de mano en mano: caracteres de control (salvo saltos de línea y tabuladores) y texto
     * en UTF-8 leído como Latin-1 («camiÃ³n» → «camión»).
     */
    public static function repair(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        if (preg_match('/Ã[\x{0080}-\x{00BF}]|Â[\x{00A0}-\x{00BF}]/u', $text) === 1) {
            $back = @mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
            if (is_string($back) && preg_match('//u', $back) === 1) {
                $text = $back;
            }
        }

        return $text;
    }

    /**
     * ¿Pide el texto los datos personales del cliente (nombre, correo, dirección, código postal, teléfono)? Solo se piden cuando el
     * cliente ha dicho que quiere comprar, pagar o terminar.
     */
    public static function asksPersonal(string $text): bool
    {
        return self::dropPersonalAsk($text) !== self::tidy($text);
    }

    /** Quita las frases que piden datos personales (nombre, correo, dirección, código postal, ciudad, teléfono). */
    public static function dropPersonalAsk(string $text): string
    {
        return self::dropSentences($text, static function (string $sentence): bool {
            $n = RuleBrain::norm($sentence);
            $data = preg_match_all('/\b(?:nombre|apellidos?|correo|email|e mail|direccion|codigo postal|cp|ciudad|telefono|movil|name|address|postcode|postal code|zip|phone|city)\b/', $n);

            return $data >= 1 && preg_match('/\b(?:indicame|indica|indique|dime|dame|facilitame|facilita|necesito|necesitamos|envia|enviame|escribe|escribeme|pasame|proporciona\w*|introduce|please provide|provide|tell me|send me|give me|i need|what is your|what s your|por favor)\b/', $n) === 1
                && preg_match('/\b(?:tu|tus|su|sus|your)\b/', $n) === 1;
        });
    }

    /**
     * ¿Dice el texto que la tienda no tiene algo? («No tenemos ropa formal», «We do not sell…»).
     */
    public static function denies(string $text): bool
    {
        return preg_match('/\b(?:no (?:tenemos|disponemos de|disponemos|vendemos|contamos con|trabajamos|ofrecemos|hay ningun\w*|encontre|he encontrado|encuentro)|we (?:do not|don t|dont) (?:have|sell|carry|stock)|we have no|i (?:could not|couldn t|did not|didn t) find|i found no(?:thing)?)\b/', RuleBrain::norm($text)) === 1;
    }

    /**
     * El texto con $max palabras como mucho, cortado por frases o líneas enteras (la primera se queda siempre, aunque sea larga).
     */
    public static function limitWords(string $text, int $max): string
    {
        $text = self::tidy($text);
        if ($max <= 0 || count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []) <= $max) {
            return $text;
        }
        $out = [];
        $words = 0;
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $kept = [];
            $full = false;
            foreach (preg_split('/(?<=[.!?])\s+/u', $line) ?: [] as $sentence) {
                $count = count(preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: []);
                if (($out !== [] || $kept !== []) && $words + $count > $max) {
                    $full = true;
                    break;
                }
                $kept[] = $sentence;
                $words += $count;
            }
            if ($kept !== []) {
                $out[] = implode(' ', $kept);
            }
            if ($full) {
                break;
            }
        }

        return self::tidy(implode("\n", $out));
    }

    /**
     * Quita las frases que cumplen $drop, línea a línea.
     *
     * @param callable $drop (string $frase): bool
     */
    private static function dropSentences(string $text, callable $drop): string
    {
        $lines = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $kept = [];
            foreach (preg_split('/(?<=[.!?])\s+/u', $line) ?: [] as $sentence) {
                if (trim($sentence) === '' || !$drop($sentence)) {
                    $kept[] = $sentence;
                }
            }
            if ($kept === [] && trim($line) !== '') {
                continue;
            }
            $lines[] = implode(' ', $kept);
        }

        return self::tidy(implode("\n", $lines));
    }

    /**
     * Un importe escrito («59,90», «1.500», «59.90») en céntimos, o null si no se entiende.
     */
    public static function parse(string $raw): ?int
    {
        $s = (string) preg_replace('/[\s\x{a0}]/u', '', $raw);
        if ($s === '' || preg_match('/^[\d.,]+$/', $s) !== 1) {
            return null;
        }
        $dots = substr_count($s, '.');
        $commas = substr_count($s, ',');
        if ($dots > 0 && $commas > 0) {
            $decimal = strrpos($s, '.') > strrpos($s, ',') ? '.' : ',';
        } elseif ($dots + $commas === 0) {
            $decimal = '';
        } else {
            $sep = $dots > 0 ? '.' : ',';
            $count = $dots + $commas;
            $tail = substr($s, (int) strrpos($s, $sep) + 1);
            // «1.500» son mil quinientos; «59,90» y «59.9» son decimales. Un separador repetido solo puede ser de millares.
            $decimal = $count === 1 && strlen($tail) !== 3 ? $sep : '';
        }
        if ($decimal === '') {
            $int = (string) preg_replace('/[.,]/', '', $s);
            $frac = '';
        } else {
            $pos = (int) strrpos($s, $decimal);
            $int = (string) preg_replace('/[.,]/', '', substr($s, 0, $pos));
            $frac = substr($s, $pos + 1);
        }
        if ($int === '' && $frac === '') {
            return null;
        }
        $frac = str_pad(substr($frac, 0, 2), 2, '0');

        return (int) ($int === '' ? '0' : $int) * 100 + (int) $frac;
    }

    /**
     * @param mixed             $data
     * @param array<int,bool>   $found
     */
    private static function walk($data, string $key, array &$found): void
    {
        if (is_array($data)) {
            foreach ($data as $k => $value) {
                self::walk($value, (string) $k, $found);
            }

            return;
        }
        if (is_int($data) && substr($key, -6) === '_cents') {
            $found[$data] = true;

            return;
        }
        if (is_string($data) && $data !== '') {
            foreach (self::amounts($data) as $cents) {
                $found[$cents] = true;
            }
        }
    }

    /** Recoge los espacios sobrantes y las líneas en blanco de más. */
    private static function tidy(string $text): string
    {
        $text = (string) preg_replace('/[ \t]+\n/', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}

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
 * Lo que el cliente pide del envío y del pago antes de que le toque elegir («el más rápido», «contra reembolso»). Se apunta en el
 * estado de la conversación (clave «prefs»: ship = fastest o cheapest, pay = cod, transfer, card, paypal o bizum) y se aplica al
 * llegar a ese paso, diciéndolo. Así no se le pone el envío estándar a quien ya dijo que lo quería urgente.
 *
 * Todo son funciones puras. Las preguntas («¿aceptáis tarjeta?») no son preferencias.
 */
final class Prefs
{
    private const RE_SHIP_WORD = '/\b(?:envio|envios|entrega|enviar|enviamelo|enviame|enviarlo|transporte|mandar|mandamelo|shipping|delivery|deliver|delivered|ship|send)\b/';

    private const RE_FASTEST = '/\b(?:mas rapid[oa]|rapidisim[oa]|urgente|express|lo antes posible|fastest|quickest|asap|next day|24 ?h)\b/';

    private const RE_CHEAPEST = '/\b(?:mas barat[oa]|mas economic[oa]|economic[oa]|cheapest|cheap|lowest price)\b/';

    /** El envío normal, dicho con o sin la palabra «envío» («y el estándar», «con el standard»). */
    private const RE_STANDARD = '/\b(?:(?:el|la|con|en|envio|entrega|shipping|delivery)\s+(?:estandar|standard|normal|ordinari[oa])|estandar|standard)\b/';

    private const PAY = [
        'cod' => '/\b(?:contra ?reembolso|cash on delivery|cod|pago a la entrega|pagar al recibir|pagar en la entrega)\b/',
        'transfer' => '/\b(?:transferencia|bank transfer|wire transfer)\b/',
        'card' => '/\b(?:tarjeta|credit card|debit card|by card|with card|visa|mastercard)\b/',
        'paypal' => '/\bpaypal\b/',
        'bizum' => '/\bbizum\b/',
    ];

    /** Palabras que identifican cada forma de pago en el identificador o el nombre de la opción. */
    private const PAY_WORDS = [
        'cod' => ['cod', 'reembolso', 'cash on delivery', 'contra'],
        'transfer' => ['transfer', 'transferencia'],
        'card' => ['card', 'tarjeta', 'redsys', 'stripe'],
        'paypal' => ['paypal'],
        'bizum' => ['bizum'],
    ];

    /**
     * Las preferencias de un mensaje.
     *
     * @param string $n        el mensaje normalizado (RuleBrain::norm)
     * @param bool   $question el mensaje es una pregunta
     * @param string $expect   lo que se esperaba del cliente (carrier = estaba eligiendo el envío)
     *
     * @return array<string,string> ship y/o pay
     */
    public static function detect(string $n, bool $question, string $expect = ''): array
    {
        $out = [];
        if ($question || $n === '') {
            return $out;
        }
        if (preg_match(self::RE_SHIP_WORD, $n) === 1 || $expect === 'carrier') {
            if (preg_match(self::RE_FASTEST, $n) === 1) {
                $out['ship'] = 'fastest';
            } elseif (preg_match(self::RE_CHEAPEST, $n) === 1) {
                $out['ship'] = 'cheapest';
            } elseif (preg_match(self::RE_STANDARD, $n) === 1) {
                $out['ship'] = 'standard';
            }
        } elseif (preg_match('/\b(?:el|la|con|y)\s+(?:estandar|standard)\b/', $n) === 1) {
            // «si el arco y el estandar»: lo dice sin la palabra «envío».
            $out['ship'] = 'standard';
        }
        foreach (self::PAY as $kind => $pattern) {
            if (preg_match($pattern, $n) === 1) {
                $out['pay'] = $kind;
                break;
            }
        }

        return $out;
    }

    /**
     * La opción de envío que cumple la preferencia.
     *
     * @param array<int,array<string,mixed>> $options las de checkout_shipping_options (id, name, delay, price_cents)
     *
     * @return array<string,mixed>|null
     */
    public static function shipping(string $pref, array $options): ?array
    {
        $options = array_values(array_filter($options, 'is_array'));
        if ($options === []) {
            return null;
        }
        if ($pref === 'standard') {
            // El que se llama «estándar», «standard» o «normal»; si ninguno se llama así, no se aplica nada.
            foreach ($options as $option) {
                $name = ' ' . RuleBrain::norm((string) (isset($option['name']) ? $option['name'] : '')) . ' ';
                if (preg_match('/ (?:estandar|standard|normal|ordinari[oa]) /', $name) === 1) {
                    return $option;
                }
            }

            return null;
        }
        $best = null;
        $bestKey = null;
        foreach ($options as $option) {
            $price = isset($option['price_cents']) ? (int) $option['price_cents'] : 0;
            if ($pref === 'cheapest') {
                $key = [$price, 0];
            } else {
                $speed = self::days((string) (isset($option['delay']) ? $option['delay'] : ''));
                $name = RuleBrain::norm((string) (isset($option['name']) ? $option['name'] : ''));
                $named = preg_match('/\b(?:urgente|express|rapid\w*|fast\w*)\b/', $name) === 1;
                $key = [$speed !== null ? $speed : ($named ? 0.5 : 1000.0), $price];
            }
            if ($bestKey === null || $key < $bestKey) {
                $best = $option;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * La forma de pago que cumple la preferencia.
     *
     * @param array<int,array<string,mixed>> $options las de checkout_payment_options (id, name, kind)
     *
     * @return array<string,mixed>|null
     */
    public static function payment(string $pref, array $options): ?array
    {
        $words = isset(self::PAY_WORDS[$pref]) ? self::PAY_WORDS[$pref] : [];
        foreach (array_values(array_filter($options, 'is_array')) as $option) {
            $text = ' ' . RuleBrain::norm((string) (isset($option['id']) ? $option['id'] : '') . ' ' . (string) (isset($option['name']) ? $option['name'] : '')) . ' ';
            foreach ($words as $word) {
                if (strpos($text, ' ' . $word) !== false) {
                    return $option;
                }
            }
        }
        if ($pref === 'card') {
            // Pagar con tarjeta es pagar en línea: la primera forma de pago que se paga en la pasarela.
            foreach (array_values(array_filter($options, 'is_array')) as $option) {
                if (isset($option['kind']) && (string) $option['kind'] === 'redirect') {
                    return $option;
                }
            }
        }

        return null;
    }

    /** Los días que dice un plazo («24 h», «2-3 días», «1 day»); null si no se entiende. */
    private static function days(string $delay): ?float
    {
        if (preg_match('/(\d+)(?:\s*(?:-|a|to)\s*(\d+))?\s*(h|hora|horas|hour|hours|d|dia|dias|day|days)\b/iu', RuleBrain::norm($delay), $m) !== 1) {
            return null;
        }
        // Un rango («24-48 h») se compara por su límite alto: es lo que de verdad puede tardar.
        $value = (float) max((int) $m[1], isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0);

        return strtolower($m[3][0]) === 'h' ? $value / 24 : $value;
    }
}

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
 * El idioma de una conversación. El cliente escribe en inglés o en el idioma de la tienda: se decide con el primer mensaje que
 * tenga más de tres palabras y cambia si el cliente cambia. Lo decide una regla (palabras de función de cada idioma), sin IA.
 *
 * Las cadenas fuente del asistente ya son inglesas: para contestar en inglés basta con no traducirlas. Los nombres de producto y de
 * transportista no se tocan: son datos de la tienda.
 */
final class Lang
{
    /** Las cadenas que pinta el JavaScript de la ventana (nombre del atributo data-t-* → texto fuente en inglés). */
    public const LABELS = [
        'confirm' => 'Confirm order',
        'summary' => 'Your order',
        'total' => 'Total',
        'shipping' => 'Delivery method',
        'payment' => 'Payment method',
        'deliver' => 'Deliver to',
        'invoice' => 'Invoice to',
        'taxid' => 'Tax ID',
        'terms' => 'Terms and conditions',
        'accept' => 'By confirming you accept the terms and conditions.',
        'pay' => 'Pay now',
        'vieworder' => 'View my order',
        'add' => 'Add to cart',
        'view' => 'View',
        'options' => 'Choose options',
        'checkout' => 'Go to checkout',
        'from' => 'From',
        'error' => 'Something went wrong. Please try again.',
        'busy' => 'The assistant is writing',
        'featured' => 'Shop pick',
        'discount' => '{percent}% off',
        'rating' => '{score}/5 ({count} reviews)',
        'retry' => 'Try again in {seconds} seconds.',
    ];

    /** Palabras de función inglesas que no se escriben igual en castellano. */
    private const EN = [
        'the', 'and', 'but', 'is', 'are', 'was', 'were', 'be', 'been', 'i', 'im', 'ive', 'id', 'you', 'he', 'she', 'it', 'we', 'they',
        'my', 'your', 'our', 'to', 'of', 'in', 'on', 'for', 'with', 'from', 'by', 'this', 'that', 'these', 'those', 'please', 'would',
        'like', 'want', 'need', 'can', 'could', 'do', 'does', 'have', 'has', 'what', 'how', 'when', 'where', 'which', 'who', 'yes',
        'thanks', 'thank', 'hello', 'hi', 'send', 'cash', 'delivery', 'shipping', 'details', 'name', 'phone', 'email', 'address',
        'add', 'cart', 'order', 'buy', 'show', 'me', 'any', 'some', 'there', 'about', 'much', 'price', 'looking', 'also', 'not', 'dont',
        'before', 'after', 'will', 'just', 'its', 'if', 'or', 'so', 'am', 'an', 'at',
    ];

    /** Palabras de función castellanas que no se escriben igual en inglés. */
    private const ES = [
        'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'del', 'al', 'y', 'o', 'que', 'es', 'son', 'mi', 'mis', 'tu', 'tus', 'su',
        'para', 'por', 'con', 'en', 'quiero', 'busco', 'necesito', 'quisiera', 'hola', 'gracias', 'favor', 'envio', 'pedido', 'datos',
        'como', 'cuanto', 'cuesta', 'tienen', 'tiene', 'hay', 'algo', 'esto', 'este', 'esta', 'si', 'mas', 'muy', 'tambien', 'pero',
        'donde', 'cuando', 'tengo', 'puedo', 'lo', 'le', 'me', 'se', 'nos', 'vale', 'perfecto', 'direccion', 'telefono', 'correo',
        'carrito', 'comprar', 'quiere', 'dime', 'dame', 'ponme', 'anade', 'barato', 'rapido', 'contra', 'reembolso',
    ];

    /** Cuántas palabras tiene que tener un mensaje para decidir el idioma con él. */
    private const MIN_WORDS = 4;

    /**
     * El idioma de un mensaje: «en» o el de la tienda; null si no se puede decir (pocas palabras o ni una pista).
     */
    public static function detect(string $message, string $shop, ?\Aifec\Kernel\I18n $i18n = null): ?string
    {
        $n = RuleBrain::norm($message);
        // La pregunta tiene idioma propio aunque el nombre comercial esté escrito en el otro idioma.
        if (preg_match('/^(?:do you (?:have|sell)|where can i find|i am looking for|show me)\s+/', $n)) {
            return 'en';
        }
        if (preg_match('/^(?:busco|muestrame|me ensenas|estoy buscando)\s+/', $n)) {
            return 'es';
        }
        $n = preg_replace('/\S*@\S*/', ' ', $n);
        $words = $n === null || trim($n) === '' ? [] : preg_split('/[\s]+/', trim($n));
        $words = is_array($words) ? $words : [];
        // A button can be pressed after the conversation has switched language.
        if ($i18n !== null && count($words) <= 8) {
            $locale = $shop === 'en' ? 'es' : $shop;
            foreach (['Shipping costs', 'Go to checkout', 'Show my cart', 'Keep browsing', 'See sizes', 'See options',
                'Something cheaper', 'Similar ones', 'Returns and refunds', 'Payment methods', 'Where is my order?',
                'Add the first one to the cart', 'Place my order here', 'Yes, I confirm', 'Cancel', 'Continue my order'] as $source) {
                $english = RuleBrain::norm($source);
                if ($n === $english) {
                    return 'en';
                }
                if ($n === RuleBrain::norm($i18n->t($source, [], $locale))) {
                    return $locale;
                }
            }
        }
        if (count($words) < self::MIN_WORDS) {
            // Los botones y saludos inequívocos cambian el idioma incluso siendo una sola palabra.
            $short = ['hello' => 'en', 'thanks' => 'en', 'thank you' => 'en', 'more information' => 'en',
                'returns and refunds' => 'en', 'payment methods' => 'en', 'view my cart' => 'en',
                'hola' => 'es', 'gracias' => 'es', 'más información' => 'es', 'mas informacion' => 'es',
                'devoluciones y reembolsos' => 'es', 'formas de pago' => 'es', 'ver mi carrito' => 'es'];
            return $short[trim($n)] ?? null;
        }
        $en = 0;
        $es = 0;
        foreach ($words as $word) {
            $word = trim($word, ' .,-#');
            if ($word === 'me' || $word === 'o') {
                // Se escriben igual en los dos idiomas: no dicen nada.
                continue;
            }
            if (in_array($word, self::EN, true)) {
                ++$en;
            }
            if (in_array($word, self::ES, true)) {
                ++$es;
            }
        }
        if ($en >= 2 && $en > $es) {
            return 'en';
        }
        if ($es >= 1 && $es > $en) {
            return $shop === 'en' ? 'es' : $shop;
        }

        return null;
    }

    /** El nombre del idioma, en inglés, para decirle al modelo en cuál contestar. */
    public static function name(string $code): string
    {
        $names = [
            'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'it' => 'Italian', 'pt' => 'Portuguese',
            'ca' => 'Catalan', 'nl' => 'Dutch', 'pl' => 'Polish', 'sv' => 'Swedish', 'da' => 'Danish', 'no' => 'Norwegian',
        ];

        return isset($names[$code]) ? $names[$code] : 'the language with the ISO code "' . $code . '"';
    }
}

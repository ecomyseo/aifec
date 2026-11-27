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
 * Lo que el asistente le contesta a un mensaje, más lo que hay que apuntar de ello (la herramienta usada, si llegó al
 * carrito, si no supo contestar). Lo llenan los dos cerebros —el de la IA y el de reglas— y lo lee Assistant::reply().
 */
final class Answer
{
    /** Las fichas de producto que acompañan a una respuesta, como mucho. */
    public const MAX_CARDS = 6;

    /** Respuestas rápidas que se enseñan como mucho (más cuando se está eligiendo una opción: los colores, las tallas). */
    public const MAX_SUGGESTIONS = Replies::MAX;

    /** @var string */
    public $text = '';

    /** @var array<int,array<string,mixed>> */
    public $cards = [];

    /** @var array<int,array<string,mixed>> */
    public $actions = [];

    /** @var string[] */
    public $suggestions = [];

    /** @var string[] los botones que propuso el modelo (suggest_replies) y pasaron la validación de la tienda */
    public $proposed = [];

    /** @var array<string,array<string,mixed>> los botones que llevan a productos concretos (Replies): se guardan en el estado */
    public $chipMeta = [];

    /** @var bool el mensaje del cliente se queda sin respuesta (para el panel) */
    public $unanswered = false;

    /** @var bool la última búsqueda no devolvió ningún producto */
    public $emptySearch = false;

    /** @var array<int,array<string,mixed>> las herramientas que se usaron: name, ok, input (sin correos) */
    public $toolLog = [];

    /** @var bool */
    public $cartAdded = false;

    /** @var bool */
    public $checkout = false;

    /** @var string ai o rules */
    public $mode = 'rules';

    /** @var string lo que espera del cliente en el siguiente mensaje: postcode, order o nada */
    public $expect = '';

    /** @var array<string,mixed> lo que hay que recordar para el siguiente mensaje (por ejemplo, el número de pedido ya dicho) */
    public $remember = [];

    /** @var bool alguna herramienta de las que cambian el carrito salió bien */
    public $wrote = false;

    /** @var bool en esta respuesta se guardó una dirección de entrega nueva (calle, piso, código postal, ciudad o país) */
    public $addressChanged = false;

    /** @var array<string,mixed>|null la opción que hay que elegir (producto con variantes): VariantPick, se guarda en el estado */
    public $pick = null;

    /** @var int|null milisegundos que tardó la IA en esta respuesta (null = no se usó) */
    public $aiMs = null;

    /** @var int milisegundos que tardaron las herramientas */
    public $toolsMs = 0;

    /** @var int rondas de herramientas que hubo */
    public $rounds = 0;

    /** @var string[] las preferencias del cliente (ship, pay) que ya se han aplicado: dejan de ser pendientes */
    public $prefsUsed = [];

    /** @var string cómo acabó el camino con IA: text, rounds, timeout, empty, unsafe (vacío = no se usó) */
    public $ended = '';

    /**
     * @var array<string,mixed> cambios en el estado de la compra dentro del chat (clave «flow» de la conversación): active (hay un
     *                          pedido empezado), ship y pay (la persona ya eligió envío y forma de pago)
     */
    public $flow = [];

    /**
     * Añade una ficha a partir de un producto que devolvió una herramienta (search_products o get_product).
     *
     * @param array<string,mixed> $item
     */
    public function addCard(array $item): void
    {
        if (count($this->cards) >= self::MAX_CARDS || !isset($item['id'], $item['name'], $item['price'])) {
            return;
        }
        foreach ($this->cards as $card) {
            if ((int) $card['id'] === (int) $item['id']) {
                return;
            }
        }
        $availability = isset($item['availability']) && is_array($item['availability']) ? $item['availability'] : [];
        $status = (string) (isset($availability['status']) ? $availability['status'] : '');
        $hasVariants = !empty($item['has_variants']);
        $options = [];
        foreach (isset($item['option_values']) && is_array($item['option_values']) ? $item['option_values'] : [] as $option) {
            if (is_array($option) && isset($option['name'], $option['values']) && is_array($option['values']) && $option['values'] !== []) {
                $options[] = ['name' => (string) $option['name'], 'values' => array_values(array_map('strval', $option['values']))] + (!empty($option['swatches']) ? ['swatches' => $option['swatches']] : []);
            }
        }
        $this->cards[] = [
            'id' => (int) $item['id'],
            'name' => (string) $item['name'],
            'featured' => isset($item['featured_rank']),
            'matched_attributes' => $item['matched_attributes'] ?? [],
            'url' => (string) (isset($item['url']) ? $item['url'] : ''),
            'image' => (string) (isset($item['image']) ? $item['image'] : ''),
            // La tarjeta enseña dos fotos arriba y, debajo, el nombre, el precio y lo que se puede elegir (tallas, colores).
            'image2' => (string) (isset($item['image2']) ? $item['image2'] : ''),
            'options' => $options,
            'price' => (string) $item['price'],
            'price_cents' => (int) (isset($item['price_cents']) ? $item['price_cents'] : 0),
            'compare_price' => isset($item['compare_price']) && $item['compare_price'] !== null ? (string) $item['compare_price'] : null,
            'from' => !empty($item['from']),
            'availability' => (string) (isset($availability['label']) ? $availability['label'] : ''),
            'status' => $status,
            'has_variants' => $hasVariants,
            // «Añadir» solo cuando no hay nada que elegir antes (variantes) y se puede comprar.
            'can_add' => !$hasVariants && $status !== 'out_of_stock',
        ];
    }

    /** ¿Se usó alguna herramienta de la compra en el chat en esta respuesta? */
    public function usedCheckout(): bool
    {
        foreach ($this->toolLog as $entry) {
            if (strpos((string) $entry['name'], 'checkout_') === 0) {
                return true;
            }
        }

        return false;
    }

    /** @var bool El motor de reglas ha resuelto una consulta al fallar o tardar el proveedor. */
    public $fallbackNotice = false;

    /**
     * Las cuatro piezas de la respuesta, tal como salen hacia el navegador.
     *
     * @return array{text:string,cards:array<int,array<string,mixed>>,actions:array<int,array<string,mixed>>,suggestions:string[]}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'cards' => $this->cards,
            'actions' => $this->actions,
            'suggestions' => array_slice(array_values(array_unique($this->suggestions)), 0, $this->pick !== null ? VariantPick::MAX_CHIPS : self::MAX_SUGGESTIONS),
        ] + ($this->fallbackNotice ? ['notice' => t('Answered with the shop’s catalogue tools.')] : []);
    }
}

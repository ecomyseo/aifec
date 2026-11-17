<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Mcp;

use Aifec\Kernel\App;
use Aifec\Modules\Assistant\SchemaCheck;

/**
 * Las herramientas que el MCP ofrece: las del servicio «tools» del asistente (las mismas del chat y de la API), las que el
 * comerciante deja permitidas, sobre el carrito de la sesión. Dos cambian de forma en la puerta de los programas:
 *
 *   checkout_review  además del resumen devuelve el confirm_token, la ficha con la que se hará el pedido.
 *   checkout_place   pide esa ficha y buyer_confirmed (la declaración de que la persona ha dicho que sí), y además el código
 *                    que la tienda manda por correo al comprador al revisar (buyer_code) o una clave de la API con el ámbito
 *                    «orders:create», según el ajuste «Pedidos hechos por agentes». Sin eso no hace nada.
 *
 * El resto se llama tal cual: la misma lógica, el mismo carrito, los mismos totales.
 */
final class Agent
{
    /** Las herramientas permitidas si el comerciante no ha tocado la lista: todas menos order_status. */
    /** El argumento con el que un cliente que no guarda la cabecera Mcp-Session-Id manda su sesión. */
    public const SESSION_ARG = 'session_id';

    public const DEFAULT_TOOLS = [
        'search_products', 'get_product', 'list_categories', 'check_availability', 'shipping_quote', 'store_info', 'cart_view', 'cart_add', 'cart_update',
        'checkout_link', 'checkout_status', 'checkout_set_customer', 'checkout_shipping_options', 'checkout_set_shipping', 'checkout_payment_options',
        'checkout_set_payment', 'checkout_review', 'checkout_place',
    ];

    /** @var App */
    private $app;

    /** @var string|null el identificador de la sesión (Mcp-Session-Id) */
    private $session;

    /** @var array<string,mixed>|null la clave de la API que manda la petición */
    private $key;

    /** @var string|null la ficha del carrito de la sesión, ya puesta en la petición */
    private $cart;

    /** @var bool ¿se ha vaciado el carrito en esta petición? Una escritura más no sería segura (ver call()) */
    private $stale = false;

    /**
     * @param array<string,mixed>|null $key
     */
    public function __construct(App $app, ?string $session, ?array $key)
    {
        $this->app = $app;
        $this->session = $session;
        $this->key = $key;
    }

    /**
     * Los nombres de las herramientas que el comerciante deja usar.
     *
     * @return string[]
     */
    public function allowed(): array
    {
        $stored = $this->app->settings()->get('mcp.tools', self::DEFAULT_TOOLS);

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : self::DEFAULT_TOOLS;
    }

    /**
     * Las herramientas que se ofrecen: las permitidas que existen. Nombre => definición.
     *
     * @return array<string,array{description:string,schema:array<string,mixed>,writes:bool}>
     */
    public function tools(): array
    {
        $all = $this->app->service('tools')->all();
        $out = [];
        foreach ($this->allowed() as $name) {
            if (!isset($all[$name])) {
                continue;
            }
            $tool = $all[$name];
            $out[$name] = ['description' => $tool['description'], 'schema' => $tool['schema'], 'writes' => $tool['writes']];
        }
        // Cada herramienta admite «session_id» por si el cliente no guarda la cabecera de la sesión (ver call()).
        foreach ($out as $tool => $definition) {
            if (isset($definition['schema']['type']) && $definition['schema']['type'] === 'object') {
                $properties = isset($definition['schema']['properties']) && is_array($definition['schema']['properties']) ? $definition['schema']['properties'] : [];
                $properties[self::SESSION_ARG] = ['type' => 'string', 'description' => 'Only if your client does not keep the MCP session (the Mcp-Session-Id header): the session_id that an earlier answer of this server gave you. It keeps the same cart between calls.'];
                $out[$tool]['schema']['properties'] = $properties;
            }
        }
        if (isset($out['checkout_review'])) {
            $out['checkout_review']['description'] = 'The exact order that would be placed: lines, discounts, shipping, taxes, the total (total_cents and total formatted), the delivery address and the payment method, and the confirm_token that checkout_place needs. Read the total from here: never calculate it. If something is missing it says what. When no API key with the scope orders:create is sent, the shop emails a 6-digit verification code to the buyer for security and says so in "confirmation": tell the person, ask for the code and wait for it.';
        }
        if (isset($out['checkout_place'])) {
            $out['checkout_place'] = [
                'description' => 'Place the order. Needs the confirm_token of the last checkout_review, buyer_confirmed true (you declare that the person has seen the total and said yes) and one of two things: buyer_code, the 6-digit verification code that the shop emails to the buyer when you call checkout_review (ask the person for it and wait: never guess it), or an API key with the scope orders:create, sent as Authorization: Bearer. Without them nothing is placed. If anything changed after the review, the confirm_token is no longer valid: review again. The person pays: the answer says how.',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'confirm_token' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'description' => 'The confirm_token of the last checkout_review'],
                        'buyer_confirmed' => ['type' => 'boolean', 'description' => 'true: the person has seen the total and said yes'],
                        'buyer_code' => ['type' => 'string', 'minLength' => 6, 'maxLength' => 12, 'description' => 'The 6-digit code the shop emailed to the buyer after checkout_review. Not needed with an API key that has the scope orders:create'],
                    ],
                    'required' => ['confirm_token', 'buyer_confirmed'],
                    'additionalProperties' => false,
                ],
                'writes' => true,
            ];
        }

        return $out;
    }

    /**
     * Deja puesto el carrito de la sesión antes de procesar nada. Con $create, se crea si todavía no existe (hace falta cuando
     * alguna de las llamadas de la petición escribe en el carrito). Una vez por petición.
     */
    public function prepare(bool $create): void
    {
        $this->cart = $this->session !== null ? Sessions::bind($this->app, $this->session, $create) : null;
    }

    /**
     * ¿Escribe en el carrito la herramienta? Sirve para saber si hay que crearlo antes.
     */
    public function writes(string $name): bool
    {
        $tools = $this->tools();

        return isset($tools[$name]) && $tools[$name]['writes'] && $name !== 'checkout_place';
    }

    /**
     * Llama a una herramienta (que tiene que estar permitida). Nunca lanza: el motivo de un fallo sale en 'error'.
     *
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    public function call(string $name, array $arguments): array
    {
        $tools = $this->tools();
        $tool = $tools[$name];
        // Sesión por argumento, para los clientes que no guardan la cabecera Mcp-Session-Id: el de ChatGPT llamaba a cart_add sin
        // ella y recibía «There is no session» (17/11/2026, R78). La cabecera, si viene, manda.
        $given = isset($arguments[self::SESSION_ARG]) && is_string($arguments[self::SESSION_ARG]) ? strtolower(trim($arguments[self::SESSION_ARG])) : '';
        unset($arguments[self::SESSION_ARG]);
        $created = false;
        $stateless = false;
        if ($this->session === null && $given !== '' && Sessions::valid($given)) {
            $this->session = $given;
            $this->cart = Sessions::bind($this->app, $given, $tool['writes']);
            $stateless = true;
        } elseif ($this->session === null && $tool['writes'] && $name !== 'checkout_place') {
            $this->session = Sessions::newId();
            $this->cart = Sessions::bind($this->app, $this->session, true);
            $created = true;
            $stateless = true;
        }
        $result = $this->run($name, $arguments, $tool);
        if ($created && !isset($result['error'])) {
            $result['session_id'] = $this->session;
            $result['session_note'] = 'Your client does not keep the MCP session: send "session_id" with this value in the arguments of your next calls (cart_view, cart_update, checkout_link, checkout_*), so they use this same cart. checkout_url already opens this cart at the checkout.';
        }
        // Sin cabecera de sesión, la escritura devuelve ya la caja de ESE carrito: ChatGPT guarda la lista de herramientas del día
        // que se conectó y, si es de antes de session_id, su checkout_link no puede nombrar el carrito (17/11/2026, R79).
        $url = $this->resumeUrl();
        if ($stateless && $tool['writes'] && $name !== 'checkout_place' && !isset($result['error']) && $url !== null) {
            $result['checkout_url'] = $url;
            $result['checkout_note'] = 'This address opens this same cart at the checkout of the shop: when the person wants to buy, give it to them as it is (they type their details and pay there).';
        }

        return $result;
    }

    /**
     * La dirección que abre el carrito de esta sesión en la caja de la tienda, o null si no hay carrito o la tienda no la tiene.
     */
    private function resumeUrl(): ?string
    {
        if ($this->cart === null || !$this->app->router()->has('api.cart.resume')) {
            return null;
        }

        return $this->app->url()->origin() . $this->app->url()->route('api.cart.resume', ['token' => $this->cart], '');
    }

    /**
     * @param array<string,mixed> $arguments
     * @param array<string,mixed> $tool
     *
     * @return array<string,mixed>
     */
    private function run(string $name, array $arguments, array $tool): array
    {
        try {
            if ($name === 'checkout_review') {
                return $this->review($arguments);
            }
            if ($name === 'checkout_place') {
                return $this->place($arguments, $tool['schema']);
            }
            if ($tool['writes']) {
                if ($this->session === null || !Sessions::valid($this->session)) {
                    return ['error' => 'There is no session: call initialize first and send the Mcp-Session-Id header it returns, so the cart is yours'];
                }
                if ($this->stale) {
                    return ['error' => 'The cart was emptied earlier in this request: send this call in a new request'];
                }
            }
            $result = $this->app->service('tools')->call($name, $arguments);
            if ($tool['writes']) {
                $this->afterWrite();
            }
            if ($name === 'checkout_link' && !isset($result['error']) && $this->resumeUrl() !== null) {
                // La caja de ESTE carrito: la de la tienda a secas abre el carrito del navegador de la persona, que no es el que ha
                // llenado el asistente por MCP (17/11/2026).
                $result['url'] = $this->resumeUrl();
                $result['note'] = 'Give the person this address as it is: it opens this same cart at the checkout of the shop, where they type their details and pay.';
            }

            return $result;
        } catch (McpFailure $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'mcp.tool:' . $name);

            return ['error' => 'That did not work. Please try again.'];
        }
    }

    /**
     * Si la herramienta ha dejado el carrito sin su fila (era la última línea), se vuelve a crear vacío con la misma ficha; el
     * servicio «cart» ya no sabe de ella en esta petición, así que una escritura más no se atiende (ver $stale).
     */
    private function afterWrite(): void
    {
        if ($this->cart !== null && Sessions::keep($this->app, $this->cart)) {
            $this->stale = true;
        }
    }

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function review(array $arguments): array
    {
        if (!$this->app->service('tools')->checkoutEnabled()) {
            throw new McpFailure('This shop does not take orders here');
        }

        return Orders::review($this->app, $this->key);
    }

    /**
     * @param array<string,mixed> $arguments
     * @param array<string,mixed> $schema
     *
     * @return array<string,mixed>
     */
    private function place(array $arguments, array $schema): array
    {
        if (!$this->app->service('tools')->checkoutEnabled()) {
            throw new McpFailure('This shop does not take orders here');
        }
        if (!isset($arguments['buyer_confirmed']) || $arguments['buyer_confirmed'] !== true) {
            // Ni «true» como texto ni un 1: la declaración de que la persona ha dicho que sí es un verdadero de verdad.
            throw new McpFailure('buyer_confirmed must be true: the person has to have seen the total and said yes. No order was placed');
        }
        $checked = SchemaCheck::check($schema, $arguments);
        if (!$checked['ok']) {
            throw new McpFailure('The input is not valid: ' . implode('; ', array_slice($checked['errors'], 0, 3)) . '. No order was placed');
        }
        $input = is_array($checked['value']) ? $checked['value'] : [];

        return Orders::place($this->app, $this->key, $input);
    }
}

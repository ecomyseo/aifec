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

use Aifec\Ai\AiTool;
use Aifec\Kernel\App;

/**
 * Servicio «tools»: el juego de herramientas que comparten el asistente de la tienda, la API y el MCP.
 *
 * Cada herramienta es ['description', 'schema' (JSON Schema de la entrada), 'handler', 'writes']. Las de serie las monta
 * ShopTools; otro módulo añade, cambia o quita herramientas con el filtro «assistant.tools» (array $tools, App $app).
 * Un handler es una función (array $input, array $context, App $app): array que recibe la entrada ya validada y con
 * sus valores por defecto. $context['cart'] es el carrito sobre el que actúan las herramientas «cart_*» (el de la
 * sesión del navegador si no se da).
 *
 * call() nunca lanza hacia fuera: una entrada que no cumple el esquema, una herramienta desconocida o un fallo interno
 * salen como ['error' => texto]. Lo que devuelve es siempre un array plano listo para JSON. Ninguna herramienta
 * acepta un precio como entrada: los esquemas rechazan lo que no declaran.
 *
 * Con «assistant.checkout» encendido y el módulo de pedidos presente se suman las ocho de la compra en el chat (CheckoutTools:
 * checkout_status, checkout_set_customer, checkout_shipping_options, checkout_set_shipping, checkout_payment_options,
 * checkout_set_payment, checkout_review y checkout_place). El pedido solo se hace con la confirmación de la persona, que
 * recoge el motor del asistente y viaja en $context['checkout_turn']: sin ella (la API, el MCP) checkout_place no hace nada.
 */
final class Tools
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Todas las herramientas, con las que añadan los demás módulos.
     *
     * @return array<string,array{description:string,schema:array<string,mixed>,handler:callable,writes:bool}>
     */
    public function all(): array
    {
        $defs = (new ShopTools($this->app))->definitions();
        $defs += (new SubscriptionTools($this->app))->definitions();
        if ($this->checkoutEnabled()) {
            // La compra dentro del chat: se puede apagar con «assistant.checkout» y necesita el motor de la caja (módulo orders).
            $defs += (new CheckoutTools($this->app))->definitions();
        }
        $filtered = $this->app->hooks()->apply('assistant.tools', $defs, $this->app);
        $defs = is_array($filtered) ? $filtered : $defs;

        $out = [];
        foreach ($defs as $name => $def) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $name) !== 1 || !is_array($def)) {
                continue;
            }
            if (!isset($def['handler']) || !is_callable($def['handler'])) {
                continue;
            }
            $out[$name] = [
                'description' => isset($def['description']) && is_string($def['description']) ? $def['description'] : '',
                'schema' => isset($def['schema']) && is_array($def['schema'])
                    ? $def['schema']
                    : ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                'handler' => $def['handler'],
                'writes' => !empty($def['writes']),
            ];
        }

        return $out;
    }

    /** ¿Se puede hacer el pedido dentro de la conversación? Ajuste encendido y motor de la caja presente. */
    public function checkoutEnabled(): bool
    {
        return $this->app->settings()->bool('assistant.checkout', true) && $this->app->has('checkout');
    }

    /** ¿Cambia algo la herramienta (carrito)? Una herramienta que no existe no cambia nada. */
    public function writes(string $name): bool
    {
        $all = $this->all();

        return isset($all[$name]) && $all[$name]['writes'];
    }

    /**
     * Las herramientas tal como se le ofrecen a un modelo.
     *
     * @return AiTool[]
     */
    public function forModel(): array
    {
        $out = [];
        foreach ($this->all() as $name => $tool) {
            $out[] = new AiTool($name, $tool['description'], $tool['schema']);
        }

        return $out;
    }

    /**
     * Ejecuta una herramienta. Valida la entrada contra su esquema, la llama y devuelve datos planos y cortos.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $context cart (el carrito de quien llama), conversation_id
     *
     * @return array<string,mixed> el resultado, o ['error' => texto]
     */
    public function call(string $name, array $input, array $context = []): array
    {
        try {
            $all = $this->all();
            if (!isset($all[$name])) {
                return ['error' => t('Unknown tool: {name}', ['name' => mb_substr($name, 0, 60, 'UTF-8')])];
            }
            $tool = $all[$name];
            $checked = SchemaCheck::check($tool['schema'], $input);
            if (!$checked['ok']) {
                return ['error' => t('The input is not valid') . ': ' . implode('; ', array_slice($checked['errors'], 0, 3))];
            }
            $clean = is_array($checked['value']) ? $checked['value'] : [];
            $result = call_user_func($tool['handler'], $clean, $context, $this->app);

            return self::plain(is_array($result) ? $result : ['result' => $result]);
        } catch (\Throwable $e) {
            try {
                $this->app->log()->exception($e, 'assistant.tool:' . $name);
            } catch (\Throwable $ignored) {
                // Si ni siquiera se puede apuntar, el cliente recibe igualmente un error limpio.
            }

            return ['error' => t('That did not work. Please try again.')];
        }
    }

    /**
     * Deja un resultado como datos planos que se pueden pasar a JSON (sin objetos, sin texto mal codificado).
     *
     * @param array<mixed> $result
     *
     * @return array<string,mixed>
     */
    private static function plain(array $result): array
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $back = is_string($json) ? json_decode($json, true) : null;

        return is_array($back) ? $back : ['error' => t('That did not work. Please try again.')];
    }
}

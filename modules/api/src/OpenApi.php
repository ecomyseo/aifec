<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Api;

use Aifec\Kernel\App;

/**
 * El documento OpenAPI 3.1, generado de la tabla de rutas (Routes::table()): lo que está aquí existe y lo que existe está aquí.
 * Dice también el recorrido de la compra, de principio a fin, para quien programa un agente.
 */
final class OpenApi
{
    private const TAGS = ['read' => 'Catalogue', 'cart' => 'Cart', 'checkout' => 'Checkout', 'links' => 'Buying with links (GET only)'];

    /**
     * @return array<string,mixed>
     */
    public static function build(App $app): array
    {
        $paths = [];
        foreach (Routes::table($app) as $route) {
            if (!empty($route['root'])) {
                continue;
            }
            $paths[Routes::openapiPath((string) $route['path'])][strtolower((string) $route['method'])] = self::operation($route);
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $app->settings()->str('store.name', 'AIFEC') . ' API',
                'version' => '1.0.0',
                'description' => self::guide(),
            ],
            'servers' => [['url' => $app->url()->abs('/api/v1', [], '')]],
            'tags' => [
                ['name' => 'Catalogue', 'description' => 'Read the shop without a key. Prices are always in cents and, next to them, formatted.'],
                ['name' => 'Cart', 'description' => 'Prepare a cart. The token of POST /carts travels in the X-Cart-Token header.'],
                ['name' => 'Checkout', 'description' => 'Take the purchase to the end. The person always confirms the total.'],
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'A key made in the shop panel. It raises the usage limit; the scope orders:create lets it place orders without the code emailed to the buyer.'],
                    'cartToken' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Cart-Token', 'description' => 'The token that POST /carts gave you.'],
                ],
                'schemas' => [
                    'Error' => ['type' => 'object', 'required' => ['error'], 'properties' => ['error' => ['type' => 'object', 'required' => ['status', 'message'], 'properties' => [
                        'status' => ['type' => 'integer', 'description' => 'The HTTP status'], 'message' => ['type' => 'string', 'description' => 'What went wrong, in plain words'],
                    ]]]],
                    'Answer' => [
                        'type' => 'object',
                        'required' => ['data'],
                        'properties' => [
                            'data' => ['type' => 'object', 'description' => 'What was asked for. Prices come in cents (price, total_cents…) and, next to them, formatted for people (price_formatted, total_formatted…). Never calculate them yourself.', 'additionalProperties' => true],
                        ],
                    ],
                    'ListAnswer' => [
                        'type' => 'object',
                        'required' => ['data'],
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true], 'description' => 'The items of this page'],
                            'meta' => ['type' => 'object', 'description' => 'The page: page, per_page and total', 'properties' => [
                                'page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'total' => ['type' => 'integer'],
                            ], 'additionalProperties' => true],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** Los parámetros de consulta que son números enteros (páginas, cantidades, identificadores, precios en céntimos). */
    private const INTEGERS = ['page', 'per_page', 'limit', 'qty', 'id', 'product_id', 'variant_id', 'category_id', 'brand_id', 'carrier_id',
        'price_min', 'price_max', 'max_price_cents', 'min_price_cents'];

    /** Los que son sí o no. */
    private const BOOLEANS = ['in_stock', 'in_stock_only', 'on_sale'];

    private static function queryType(string $name): string
    {
        if (in_array($name, self::INTEGERS, true)) {
            return 'integer';
        }

        return in_array($name, self::BOOLEANS, true) ? 'boolean' : 'string';
    }

    /**
     * El recorrido de la compra, en texto, para la descripción del documento y para el llms.txt.
     */
    public static function guide(): string
    {
        return implode("\n", [
            'Everything a person can do in this shop, a program can do: look at the catalogue, prepare a cart and, when the person says yes, place the order.',
            'An assistant that buys here never pays and never sees payment details: placing the order only creates it. The person authorises it with a 6-digit code the shop emails them, and pays afterwards, themselves, on the page of the shop. '
                . 'So when a person asks you to buy something here for them, you can: you do not need their card, their PayPal or a key. If you would rather not send their details, prepare the cart and give them the checkout-link address: never just tell them you cannot.',
            '',
            'How to buy, step by step:',
            '1. Find the product: GET /search?q=… or GET /products, then GET /products/{id} for its variants and GET /products/{id}/availability.',
            '2. POST /carts gives you a cart token. From now on send it as X-Cart-Token.',
            '3. POST /carts/current/items adds products ({"product_id":12,"variant_id":0,"qty":1}). The price is always the shop\'s own.',
            '4. POST /carts/checkout/customer saves the person\'s contact and address (their own words in "text", or separate fields).',
            '5. POST /carts/checkout/shipping-options, then POST /carts/checkout/shipping {"carrier_id":…}.',
            '6. POST /carts/checkout/payment-options, then POST /carts/checkout/payment {"method":"…"}.',
            '7. POST /carts/checkout/review returns the exact order, its total (in cents and formatted) and a confirm_token. Show the person that total and the terms (terms_url) and wait for a clear yes. For security the shop emails a 6-digit verification code to the buyer at this step (see "confirmation" in the answer): tell the person, ask for the code and wait for it.',
            '8. POST /carts/checkout/place {"confirm_token":"…","buyer_confirmed":true} places the order. Add "buyer_code":"…", the code the buyer received by email; a key with the scope orders:create (Authorization: Bearer …) does not need it. buyer_confirmed is your declaration that the person has seen the total and said yes: without it nothing is placed.',
            '9. The answer has the order number, the order page and "payment": either "instructions" (bank transfer, cash on delivery) or "redirect", the address where the person pays. No gateway is paid without the person.',
            '',
            'If you can only open addresses (GET) and cannot send a POST or a header, buy with links: GET /go explains it and GET /go/start gives you a cart; every answer has the next addresses ready in "next".',
            'If the person prefers to pay on the web, GET /carts/current/checkout-link gives an address that opens this cart in their browser and takes them to the checkout.',
            'If anything changes after the review (a quantity, the address, the shipping, the payment, a coupon or the total), the confirm_token stops being valid and nothing is placed: review again.',
            'The shop sets a highest amount per order and a number of orders per key (or per address) and hour. Above them, or if the shop does not take orders from programs, send the person to the checkout page.',
        ]);
    }

    /**
     * @param array<string,mixed> $route
     *
     * @return array<string,mixed>
     */
    private static function operation(array $route): array
    {
        $parameters = [];
        if (preg_match_all('/\{([a-z_]+)(?::((?:[^{}]|\{[^{}]*\})+))?\}/', (string) $route['path'], $found) > 0) {
            foreach ($found[1] as $i => $name) {
                // Un identificador que solo admite cifras es un entero, no un texto.
                $digits = isset($found[2][$i]) && preg_match('/^(?:\[0-9\]|\\\\d)[+*{]/', (string) $found[2][$i]) === 1;
                $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => $digits ? 'integer' : 'string']];
            }
        }
        foreach (is_array($route['query']) ? $route['query'] : [] as $name => $description) {
            // El tipo y si es obligatorio salen de la misma descripción que lee la persona: «(required)» es obligatorio de verdad.
            $parameters[] = [
                'name' => (string) $name,
                'in' => 'query',
                'required' => strpos((string) $description, '(required)') !== false,
                'description' => (string) $description,
                'schema' => ['type' => self::queryType((string) $name)],
            ];
        }
        // Las que devuelven una lista en «data» (medido en producción el 17/11/2026: /products, /search y /categories).
        $list = preg_match('#^/(products|search|categories)$#', (string) $route['path']) === 1;
        $operation = [
            'operationId' => (string) $route['name'],
            'summary' => (string) $route['summary'],
            'tags' => [self::TAGS[(string) $route['kind']]],
            'responses' => ['200' => [
                'description' => $list ? 'Success: a list in "data" and its page in "meta"' : 'Success: the answer in "data"',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . ($list ? 'ListAnswer' : 'Answer')]]],
            ]],
        ];
        if ($route['tool'] !== '') {
            $operation['x-tool'] = (string) $route['tool'];
        }
        $security = [];
        if ($route['cart']) {
            $security[] = 'cartToken';
        }
        if ($route['scope'] !== null) {
            $operation['x-required-scope'] = (string) $route['scope'];
            $security[] = 'bearerAuth';
        }
        if ($security !== []) {
            $requirement = [];
            foreach ($security as $name) {
                $requirement[$name] = [];
            }
            $operation['security'] = [$requirement];
        }
        if (!empty($route['optional_scope'])) {
            // Dos formas de llamar: sin clave (con el código del comprador) o con una clave que tenga el ámbito.
            $operation['x-optional-scope'] = (string) $route['optional_scope'];
            $withKey = isset($operation['security'][0]) ? $operation['security'][0] : [];
            $withKey['bearerAuth'] = [];
            $operation['security'] = array_merge(isset($operation['security']) ? $operation['security'] : [], [$withKey]);
            $operation['responses']['403'] = ['description' => 'The buyer code is wrong or too old, or the shop does not take orders from programs', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        }
        if ($route['cart']) {
            $operation['responses']['401'] = ['description' => 'The cart token is missing or wrong', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        }
        if ($route['scope'] !== null) {
            $operation['responses']['403'] = ['description' => 'The key does not have the required scope', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        }
        $error = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]];
        // Lo que puede salir mal y cómo lo dice la tienda, operación por operación: un dato mal puesto (400) o algo que no existe (404).
        if ($parameters !== [] || (is_array($route['body']) && $route['body'] !== [])) {
            $operation['responses']['400'] = ['description' => 'A parameter or a field of the body is missing or not valid', 'content' => $error];
        }
        if (strpos((string) $route['path'], '{') !== false) {
            $operation['responses']['404'] = ['description' => 'What the address names does not exist (a product, a cart line…)', 'content' => $error];
        }
        $operation['responses']['429'] = ['description' => 'Too many requests: wait the seconds of Retry-After', 'content' => $error];
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }
        if (is_array($route['body']) && $route['body'] !== []) {
            $properties = [];
            $required = [];
            foreach ($route['body'] as $name => $def) {
                $properties[(string) $name] = ['type' => (string) $def[0], 'description' => (string) $def[1]];
                if (strpos((string) $def[1], '(required)') !== false) {
                    $required[] = (string) $name;
                }
            }
            $schema = ['type' => 'object', 'properties' => $properties];
            if ($required !== []) {
                $schema['required'] = $required;
            }
            $operation['requestBody'] = ['required' => $required !== [], 'content' => ['application/json' => ['schema' => $schema]]];
        }

        return $operation;
    }
}

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp;

use Aifec\Kernel\App;

/**
 * El documento OpenAPI 3.1, generado de la tabla de rutas (Routes::table()): lo que está aquí existe y lo que existe está aquí.
 */
final class OpenApi
{
    /**
     * @return array<string,mixed>
     */
    public static function build(App $app): array
    {
        $paths = [];
        foreach (Routes::table($app) as $route) {
            $path = Routes::openapiPath((string) $route['path']);
            $parameters = [];
            if (preg_match_all('/\{([a-z_]+)(?::[^}]+)?\}/', (string) $route['path'], $m) > 0) {
                foreach ($m[1] as $name) {
                    $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
                }
            }
            foreach (is_array($route['query']) ? $route['query'] : [] as $name => $description) {
                $parameters[] = ['name' => (string) $name, 'in' => 'query', 'required' => false, 'description' => (string) $description, 'schema' => ['type' => 'string']];
            }
            $operation = [
                'operationId' => (string) $route['name'],
                'summary' => (string) $route['summary'],
                'responses' => ['200' => ['description' => 'Success']],
            ];
            if ($parameters !== []) {
                $operation['parameters'] = $parameters;
            }
            if ($route['scope'] !== null) {
                $operation['security'] = [['bearerAuth' => []]];
                $operation['x-required-scope'] = (string) $route['scope'];
                $operation['responses'] += [
                    '401' => ['description' => 'Missing, wrong or revoked key', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                    '403' => ['description' => 'The key does not have the required scope', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                    '429' => ['description' => 'Too many requests', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                ];
            }
            if ((string) $route['method'] !== 'GET' && $route['scope'] !== null) {
                $properties = [];
                foreach (is_array($route['body']) ? $route['body'] : [] as $name => $def) {
                    $properties[(string) $name] = ['type' => (string) $def[0], 'description' => (string) $def[1]];
                }
                $operation['parameters'] = array_merge(isset($operation['parameters']) ? $operation['parameters'] : [], [
                    ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'description' => 'Repeating the request with the same key does nothing twice', 'schema' => ['type' => 'string']],
                ]);
                $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => (object) $properties]]]];
                $operation['responses'] += ['422' => ['description' => 'The data is not valid', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]]];
            }
            $paths[$path][strtolower((string) $route['method'])] = $operation;
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $app->settings()->str('store.name', 'AIFEC') . ' - ERP API',
                'version' => '1.0.0',
                'description' => 'Amounts are integers in cents and also decimal text (field_decimal). Dates are ISO 8601 in UTC. Errors are {"error":{"status","code","message","field"}}.',
            ],
            'servers' => [['url' => $app->url()->origin() . $app->url()->plain(Routes::PREFIX)]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'The key of a connection (Authorization: Bearer <key>)']],
                'schemas' => ['Error' => ['type' => 'object', 'properties' => ['error' => ['type' => 'object', 'properties' => [
                    'status' => ['type' => 'integer'], 'code' => ['type' => 'string'], 'message' => ['type' => 'string'], 'field' => ['type' => ['string', 'null']],
                ]]]]],
            ],
        ];
    }
}

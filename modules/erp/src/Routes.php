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
use Aifec\Kernel\Router;
use Aifec\Modules\Erp\Api\CustomersApi;
use Aifec\Modules\Erp\Api\DocsApi;
use Aifec\Modules\Erp\Api\InvoicesApi;
use Aifec\Modules\Erp\Api\OrdersApi;
use Aifec\Modules\Erp\Api\ProductsApi;

/**
 * LA tabla de rutas de la API para ERP: de ella salen las rutas que se dan de alta, el ámbito que exige cada una y el documento
 * OpenAPI. Lo documentado y lo que existe no pueden diferir porque es la misma lista.
 *
 * Cada fila: name, method, path (relativo a /api/v1/erp), scope (null = pública), handler, summary, needs (servicio que tiene que
 * estar cargado), query (parámetros de la dirección: nombre => descripción) y body (campos del cuerpo: nombre => [tipo, descripción]).
 */
final class Routes
{
    public const PREFIX = '/api/v1/erp';

    /**
     * Las rutas de la API. Por defecto solo las que se pueden atender ahora (la ruta de facturas solo si el módulo de facturas está
     * cargado); $everything = true da todas: así se dan de alta, porque al registrar las rutas puede que otros módulos aún no se hayan
     * cargado, y el intermediario contesta 404 a las de un módulo que falta.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function table(App $app, bool $everything = false): array
    {
        $cursor = ['cursor' => 'Opaque cursor: the next_cursor of the previous page', 'limit' => 'Items per page, 1 to 200 (50 by default)'];
        $rows = [
            self::row('erp.docs', 'GET', '/', null, [DocsApi::class, 'help'], 'Help page with examples', '', [], []),
            self::row('erp.openapi', 'GET', '/openapi.json', null, [DocsApi::class, 'openapi'], 'OpenAPI 3.1 document of this API', '', [], []),

            self::row('erp.orders.list', 'GET', '/orders', 'erp:orders', [OrdersApi::class, 'index'], 'List orders by cursor', 'orders',
                $cursor + ['status' => 'Only these order statuses, separated by commas', 'updated_since' => 'Only orders changed since this ISO 8601 date'], []),
            self::row('erp.orders.show', 'GET', '/orders/{number}', 'erp:orders', [OrdersApi::class, 'show'], 'One order with its lines, taxes, addresses and payment', 'orders', [], []),
            self::row('erp.orders.ack', 'POST', '/orders/{number}/ack', 'erp:orders:write', [OrdersApi::class, 'ack'], 'Confirm that the ERP has received the order', 'orders', [],
                ['external_id' => ['string', 'The identifier the ERP gave to the order (required)']]),
            self::row('erp.orders.status', 'POST', '/orders/{number}/status', 'erp:orders:write', [OrdersApi::class, 'status'], 'Change the status of an order', 'orders', [],
                ['status' => ['string', 'The new status code (required)'], 'note' => ['string', 'A note for the history of the order'], 'notify' => ['boolean', 'Email the customer (false by default)']]),
            self::row('erp.orders.tracking', 'POST', '/orders/{number}/tracking', 'erp:orders:write', [OrdersApi::class, 'tracking'], 'Add the tracking number of the shipment', 'orders', [],
                ['tracking_number' => ['string', 'The tracking number (required)'], 'carrier' => ['string', 'The name of the carrier'], 'url' => ['string', 'A tracking link']]),

            self::row('erp.products.list', 'GET', '/products', 'erp:products', [ProductsApi::class, 'index'], 'List products by cursor', 'catalog', $cursor, []),
            self::row('erp.products.show', 'GET', '/products/{sku:.+}', 'erp:products', [ProductsApi::class, 'show'], 'One product by SKU', 'catalog', [], []),
            self::row('erp.products.put', 'PUT', '/products/{sku:.+}', 'erp:products:write', [ProductsApi::class, 'put'], 'Create or update a product by SKU (it never deletes)', 'catalog', [],
                ['name' => ['string', 'Name (required to create)'], 'price' => ['integer', 'Price in cents'], 'compare_price' => ['integer', 'Previous price in cents'],
                    'sale_price' => ['integer', 'Offer price in cents'], 'prices_include_tax' => ['boolean', 'The prices carry the tax (the shop setting by default)'],
                    'tax_class' => ['string', 'Code of the tax class'], 'weight' => ['integer', 'Weight in grams'], 'status' => ['string', 'draft, published or archived'],
                    'gtin' => ['string', 'Barcode (GTIN, EAN, UPC)'], 'mpn' => ['string', 'Manufacturer part number'], 'parent_sku' => ['string', 'SKU of the product, to write a variant']]),
            self::row('erp.stock.list', 'GET', '/stock', 'erp:stock', [ProductsApi::class, 'stockIndex'], 'List the stock of every SKU by cursor', 'catalog', $cursor, []),
            self::row('erp.stock.update', 'POST', '/stock', 'erp:stock:write', [ProductsApi::class, 'stock'], 'Set or change the stock of up to 500 SKUs', 'catalog', [],
                ['items' => ['array', 'Up to 500 objects {sku, stock} or {sku, delta}']]),
            self::row('erp.prices.update', 'POST', '/prices', 'erp:products:write', [ProductsApi::class, 'prices'], 'Change the prices of up to 500 SKUs', 'catalog', [],
                ['prices_include_tax' => ['boolean', 'The prices carry the tax (the shop setting by default)'],
                    'items' => ['array', 'Up to 500 objects {sku, price, sale_price, sale_from, sale_to}']]),

            self::row('erp.customers.list', 'GET', '/customers', 'erp:customers', [CustomersApi::class, 'index'], 'List customers by cursor', 'customers',
                $cursor + ['updated_since' => 'Only customers changed since this ISO 8601 date'], []),
            self::row('erp.customers.show', 'GET', '/customers/{id:\d+}', 'erp:customers', [CustomersApi::class, 'show'], 'One customer with its addresses', 'customers', [], []),
            self::row('erp.customers.put', 'PUT', '/customers', 'erp:customers:write', [CustomersApi::class, 'put'], 'Create or update a customer by email (it never changes a password)', 'customers', [],
                ['email' => ['string', 'Email (required)'], 'first_name' => ['string', 'First name'], 'last_name' => ['string', 'Last name'], 'company' => ['string', 'Company'],
                    'vat_id' => ['string', 'Tax ID'], 'phone' => ['string', 'Phone'], 'locale' => ['string', 'Language code']]),

            self::row('erp.invoices.list', 'GET', '/invoices', 'erp:invoices', [InvoicesApi::class, 'index'], 'List invoices and credit notes by cursor', 'invoices', $cursor, []),
            self::row('erp.invoices.show', 'GET', '/invoices/{number}', 'erp:invoices', [InvoicesApi::class, 'show'], 'One invoice by its number', 'invoices', [], []),
            self::row('erp.invoices.pdf', 'GET', '/invoices/{number}/pdf', 'erp:invoices', [InvoicesApi::class, 'pdf'], 'The PDF of an invoice', 'invoices', [], []),
        ];

        return array_values(array_filter($rows, static function (array $row) use ($app, $everything): bool {
            return $everything || $row['needs'] === '' || $app->has((string) $row['needs']);
        }));
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(App $app, string $name): ?array
    {
        foreach (self::table($app, true) as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        return null;
    }

    /** Da de alta todas las rutas de la tabla. */
    public static function register(Router $router, App $app): void
    {
        foreach (self::table($app, true) as $row) {
            $path = $row['path'] === '/' ? self::PREFIX : self::PREFIX . $row['path'];
            $methods = $row['method'] === 'GET' ? ['GET', 'HEAD'] : [(string) $row['method']];
            $route = $router->add($methods, $path, $row['handler'])->name((string) $row['name']);
            if ($row['scope'] !== null) {
                $route->middleware('erp.api');
            }
        }
    }

    /** «/orders/{number}/ack» o «/products/{sku:.+}» → «/orders/{number}/ack» o «/products/{sku}» (la forma de OpenAPI). */
    public static function openapiPath(string $path): string
    {
        return (string) preg_replace('/\{([a-z_]+):[^}]+\}/', '{$1}', $path);
    }

    /**
     * @param string[]|array<string,string>      $query
     * @param array<string,array{0:string,1:string}> $body
     * @param array{0:string,1:string}|null       $handler
     *
     * @return array<string,mixed>
     */
    private static function row(string $name, string $method, string $path, ?string $scope, $handler, string $summary, string $needs, array $query, array $body): array
    {
        $needs = $needs === 'orders' || $needs === 'catalog' || $needs === 'customers' || $needs === 'invoices' ? $needs : '';

        return ['name' => $name, 'method' => $method, 'path' => $path, 'scope' => $scope, 'handler' => $handler, 'summary' => $summary, 'needs' => $needs, 'query' => $query, 'body' => $body];
    }
}

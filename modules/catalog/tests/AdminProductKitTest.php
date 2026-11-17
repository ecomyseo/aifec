<?php
/**
 * AIFEC — ayudas comunes de las pruebas del panel de productos. No contiene pruebas: las cargan con require_once las demás.
 *
 *   prodApp(array $ajustes = []): App
 *       Aplicación de prueba con el catálogo y los medios, en castellano e inglés, arrancada.
 *   prodForm(array $cambios = []): array
 *       Campos del formulario de un producto como los manda un navegador: los interruptores siempre viajan (0 o 1), las
 *       casillas de categorías que no se marcan no viajan, y cada texto por idioma va como f[campo][es].
 *   prodSave(TestClient $web, array $form, ?int $id = null, array $files = [], array $extra = []): Response
 *       Abre la pantalla de alta o de edición y envía el formulario con la ficha de esa página (y ficheros, si se dan).
 *   prodPost(TestClient $web, string $uri, array $post, array $files = []): Response
 *       POST con la ficha de la última página abierta y, si se piden, ficheros subidos como los manda PHP.
 *   prodMake(TestClient $web, string $nombre, array $cambios = []): int
 *       Crea un producto por el panel y devuelve su identificador.
 *   prodViewer(App $app): TestClient
 *       Persona que solo puede ver el catálogo (papel «viewer» con catalog.view).
 *   prodScalar(App $app, string $sql, array $params = []): int
 *       Primer valor de una consulta, como entero.
 *   prodFile(string $contenido, string $nombre): string
 *       Fichero de prueba en una carpeta temporal; devuelve su ruta.
 *   prodOrderItems(App $app): void
 *       Crea una tabla order_items mínima (el módulo de pedidos no se carga en estas pruebas) para probar que un producto o una
 *       variante que está en pedidos no se borra.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

require_once __DIR__ . '/helpers.php';

if (!function_exists('prodApp')) {
    /**
     * @param array<string,mixed> $settings
     */
    function prodApp(array $settings = []): App
    {
        $settings += ['i18n.languages' => ['es', 'en']];
        $app = testApp(['modules' => ['catalog', 'media'], 'settings' => $settings]);
        $app->boot();

        return $app;
    }

    /**
     * @param array<string,mixed> $over
     *
     * @return array<string,mixed>
     */
    function prodForm(array $over = []): array
    {
        return $over + [
            'name' => ['es' => 'Silla de roble', 'en' => ''], 'summary' => ['es' => '', 'en' => ''], 'description' => ['es' => '', 'en' => ''],
            'status' => 'draft', 'visibility' => 'everywhere', 'featured' => '0', 'brand_id' => '', 'family_id' => '', 'kind' => 'physical',
            'price' => '10,00', 'compare_price' => '', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '', 'cost' => '', 'tax_class_id' => '',
            'sku' => '', 'gtin' => '', 'mpn' => '', 'manage_stock' => '1', 'stock' => '0', 'stock_alert' => '0', 'backorders' => 'no',
            'min_qty' => '1', 'max_qty' => '0', 'weight' => '0', 'length' => '0', 'width' => '0', 'height' => '0',
            'delivery_min_days' => '', 'delivery_max_days' => '',
            'images' => '', 'main_category' => '', 'tags' => '', 'attrs_family' => '',
            'slug' => ['es' => '', 'en' => ''], 'meta_title' => ['es' => '', 'en' => ''], 'meta_description' => ['es' => '', 'en' => ''],
        ];
    }

    /**
     * @param array<string,mixed> $form
     * @param array<string,mixed> $files
     * @param array<string,mixed> $extra
     */
    function prodSave(TestClient $web, array $form, ?int $id = null, array $files = [], array $extra = []): Response
    {
        $web->get($id === null ? '/admin/r/products/new' : '/admin/r/products/' . $id);

        return prodPost($web, '/admin/r/products/' . ($id === null ? '' : $id . '/') . 'save', ['f' => $form] + $extra, $files);
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     */
    function prodPost(TestClient $web, string $uri, array $post, array $files = []): Response
    {
        $request = new Request('POST', $uri, [], $post + ['_token' => aifecToken($web)], $web->cookies, $web->headers, '', $files);
        $request->host = 'tienda.test';
        $request->secure = true;
        $response = $web->app->handle($request);
        foreach ($response->cookies as $name => $cookie) {
            if ($cookie['value'] === '' || ((int) $cookie['options']['expires'] !== 0 && (int) $cookie['options']['expires'] < $web->app->clock()->now())) {
                unset($web->cookies[$name]);
            } else {
                $web->cookies[$name] = $cookie['value'];
            }
        }
        $web->last = $response;

        return $response;
    }

    /**
     * @param array<string,mixed> $changes
     */
    function prodMake(TestClient $web, string $name, array $changes = []): int
    {
        $response = prodSave($web, prodForm($changes + ['name' => ['es' => $name, 'en' => '']]));
        same(302, $response->status, 'el producto «' . $name . '» se crea');

        return (int) $web->app->db()->value('SELECT MAX(id) FROM {products}');
    }

    function prodViewer(App $app): TestClient
    {
        $app->db()->insert('roles', ['code' => 'viewer', 'name' => 'Viewer', 'caps' => json_encode(['dashboard.view', 'catalog.view']), 'system' => 0]);

        return adminClient($app, 'viewer');
    }

    /**
     * @param array<int,mixed> $params
     */
    function prodScalar(App $app, string $sql, array $params = []): int
    {
        return (int) $app->db()->value($sql, $params);
    }

    function prodFile(string $content, string $name): string
    {
        $path = tmpDir('prod-file') . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    /** Los ficheros subidos de un envío, como los deja PHP para un campo «file_<nombre>». */
    function prodUpload(string $field, string $path, string $name): array
    {
        return ['file_' . $field => ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $path, 'error' => 0, 'size' => (int) filesize($path)]];
    }

    function prodOrderItems(App $app): void
    {
        $app->db()->pdo()->exec('CREATE TABLE IF NOT EXISTS ' . $app->db()->table('order_items') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, product_id INTEGER, variant_id INTEGER)');
    }

    /**
     * Un tipo de producto con sus atributos; devuelve su identificador.
     *
     * @param int[] $attributeIds
     */
    function prodFamily(App $app, string $name, array $attributeIds): int
    {
        $id = $app->db()->insert('families', ['name' => $name, 'position' => 0]);
        foreach (array_values($attributeIds) as $i => $attributeId) {
            $app->db()->insert('family_attributes', ['family_id' => $id, 'attribute_id' => $attributeId, 'position' => $i]);
        }

        return $id;
    }

    /** El identificador del valor de un atributo por su código. */
    function prodValue(App $app, int $attributeId, string $code): int
    {
        return (int) $app->db()->value('SELECT id FROM {attribute_values} WHERE attribute_id = ? AND code = ?', [$attributeId, $code]);
    }
}

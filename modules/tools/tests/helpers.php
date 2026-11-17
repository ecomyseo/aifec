<?php
/**
 * AIFEC — ayudas de prueba del módulo de herramientas. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   toolsApp(array $modules, array $settings, array $options): App
 *       Aplicación de prueba con el módulo de herramientas (y los que se pidan), ya arrancada.
 *   toolsLogFile(App $app, string $name, array $lines): string
 *       Escribe un fichero de registro en la carpeta de registro del sitio y devuelve su ruta.
 *   toolsLogLine(string $level, string $message, array $context, string $time): string
 *   toolsRole(App $app, string $code, array $caps): void
 *       Da de alta un papel con esos permisos.
 *   toolsPostFile(TestClient $web, string $uri, string $field, string $path, string $name): Response
 *       Envía un fichero como lo hace un formulario con enctype multipart.
 *   toolsJpeg(string $seed): string
 *       Los bytes de una imagen JPEG pequeña, distinta para cada semilla.
 *   toolsCsvApp(array $extra, array $settings, ?FakeHttpClient $http): App
 *       Aplicación con herramientas, catálogo, medios y tareas, en castellano e inglés.
 *   toolsSeedCatalog(App $app, int $n): void
 *       Un catálogo de prueba con variantes, atributos, categorías, marca e idiomas.
 *   toolsSummary(App $app, int $id, string $lang): array
 *       Lo que enseña la ficha de un producto, para comparar dos sitios.
 *   toolsWriteCsv(string $text, string $name): string
 *       Escribe un CSV de prueba y devuelve su ruta.
 *   toolsRunImport(App $app, string $path): array
 *       Sube, mira, empieza y termina una importación; devuelve el estado final.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpResponse;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Tools\ImportRuns;

if (!function_exists('toolsApp')) {
    /**
     * @param string[]            $modules
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $options  opciones de testApp (http, config…)
     */
    function toolsApp(array $modules = ['tools'], array $settings = [], array $options = []): App
    {
        $options['modules'] = $modules;
        if ($settings !== []) {
            $options['settings'] = $settings;
        }
        $app = testApp($options);
        $app->boot();

        return $app;
    }

    /**
     * @param string[] $lines cada línea ya escrita (toolsLogLine) o texto suelto
     */
    function toolsLogFile(App $app, string $name, array $lines): string
    {
        $dir = $app->site()->path('logs', true);
        $path = $dir . '/' . $name;
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    /**
     * @param array<string,mixed> $context
     */
    function toolsLogLine(string $level, string $message, array $context = [], string $time = '2026-11-12 10:00:00'): string
    {
        return (string) json_encode(['time' => $time, 'level' => $level, 'message' => $message, 'context' => $context], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param string[] $caps
     */
    function toolsRole(App $app, string $code, array $caps): void
    {
        $app->db()->insert('roles', ['code' => $code, 'name' => ucfirst($code), 'caps' => json_encode($caps), 'system' => 0]);
    }

    function toolsPostFile(TestClient $web, string $uri, string $field, string $path, string $name): Response
    {
        $files = [$field => ['name' => $name, 'type' => 'text/csv', 'tmp_name' => $path, 'error' => 0, 'size' => is_file($path) ? (int) filesize($path) : 0]];
        $request = new Request('POST', $uri, [], ['_token' => aifecToken($web)], $web->cookies, $web->headers, '', $files);
        $request->host = 'tienda.test';
        $request->secure = true;
        $response = $web->app->handle($request);
        foreach ($response->cookies as $cookie => $data) {
            if ($data['value'] === '' || ((int) $data['options']['expires'] !== 0 && (int) $data['options']['expires'] < $web->app->clock()->now())) {
                unset($web->cookies[$cookie]);
            } else {
                $web->cookies[$cookie] = $data['value'];
            }
        }
        $web->last = $response;

        return $response;
    }

    function toolsJpeg(string $seed): string
    {
        $n = hexdec(substr(md5($seed), 0, 6));
        $img = imagecreatetruecolor(40, 30);
        $bg = (int) imagecolorallocate($img, $n % 256, ($n >> 8) % 256, ($n >> 16) % 256);
        imagefilledrectangle($img, 0, 0, 40, 30, $bg);
        ob_start();
        imagejpeg($img, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /**
     * @param string[]            $extra    módulos que se añaden a herramientas, catálogo, medios y tareas
     * @param array<string,mixed> $settings
     */
    function toolsCsvApp(array $extra = [], array $settings = [], ?FakeHttpClient $http = null): App
    {
        require_once __DIR__ . '/../../catalog/tests/helpers.php';
        $http = $http !== null ? $http : new FakeHttpClient();
        $http->when('GET', '/media/', static function ($request): HttpResponse {
            return new HttpResponse(200, ['content-type' => 'image/jpeg'], toolsJpeg((string) $request->url));
        });

        return toolsApp(array_merge(['tools', 'catalog', 'media', 'jobs'], $extra), $settings + ['i18n.languages' => ['es', 'en']], ['http' => $http]);
    }

    /**
     * Un catálogo de prueba: $n productos, uno de cada cinco con variantes; categorías en dos niveles, marca, atributos,
     * un nombre en inglés y algunas imágenes. Las referencias son P1…Pn.
     */
    function toolsSeedCatalog(App $app, int $n = 30): void
    {
        require_once __DIR__ . '/../../catalog/tests/helpers.php';
        $furniture = aifecCategory($app, 'Muebles');
        $chairs = aifecCategory($app, 'Sillas', $furniture);
        $home = aifecCategory($app, 'Hogar');
        $brand = aifecBrand($app, 'Nordik');
        for ($i = 1; $i <= $n; ++$i) {
            $data = [
                'sku' => 'P' . $i,
                'name' => 'Producto ' . $i . ' & <b>' . ($i % 7 === 0 ? ' ñandú' : ''),
                'summary' => '<p>Resumen ' . $i . '</p>',
                'description' => '<p>Descripción ' . $i . ' con "comillas"; y punto y coma</p>',
                'meta_title' => 'Meta ' . $i,
                'price' => 1000 + $i * 7,
                'categories' => $i % 2 === 0 ? [$chairs, $home] : [$home],
                'i18n' => ['en' => ['name' => 'Product ' . $i . ' EN']],
                'tax_class_id' => $i % 3 === 0 ? 'reduced' : 'standard',
                'weight' => $i * 10,
                'gtin' => '84000000' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'status' => $i % 11 === 0 ? 'draft' : 'published',
            ];
            if ($i % 4 === 0) {
                $data['brand_id'] = $brand;
            }
            if ($i % 6 === 0) {
                $data['compare_price'] = 3000 + $i;
                $data['sale_price'] = 2500 + $i;
            }
            if ($i % 5 === 0) {
                $data['variants'] = [
                    ['sku' => 'P' . $i . '-S', 'price' => 1000 + $i * 7, 'stock' => 3 + $i, 'weight' => $i, 'values' => ['size' => 'S', 'color' => 'Rojo']],
                    ['sku' => 'P' . $i . '-L', 'price' => 1200 + $i * 7, 'stock' => 5, 'gtin' => '85000000' . $i, 'label' => 'Grande ' . $i, 'values' => ['size' => 'L', 'color' => 'Rojo']],
                ];
            } else {
                $data['stock'] = $i;
                $data['attributes'] = ['material' => $i % 2 === 0 ? 'Roble' : 'Pino'];
            }
            if ($i === 1 || $i === 2) {
                $data['images'] = 2;
            }
            if ($i === 3) {
                $data['name'] = '=SUMA(1;1) especial';
            }
            if ($i === 8) {
                $data['name'] = '-5% de oferta';
            }
            aifecProduct($app, $data);
        }
    }

    /**
     * Lo que la ficha de un producto enseña, listo para comparar dos sitios (los identificadores pueden ser otros).
     *
     * @return array<string,mixed>
     */
    function toolsSummary(App $app, int $id, string $lang = 'es'): array
    {
        $p = $app->service('catalog')->product($id, $lang, true);
        check($p !== null, 'el producto ' . $id . ' existe');
        $attributes = [];
        foreach ($p['attributes'] as $attribute) {
            $attributes[$attribute['code']] = $attribute['value'];
        }
        // Los códigos de los valores pueden ser otros en el otro sitio; lo que debe coincidir es lo que lee la persona.
        $labels = [];
        $options = [];
        foreach ($p['options'] as $option) {
            $options[$option['code']] = $option['name'];
            foreach ($option['values'] as $value) {
                $labels[$option['code']][$value['code']] = $value['label'];
            }
        }
        ksort($options);
        $variants = [];
        foreach ($p['variants'] as $variant) {
            $values = [];
            foreach ($variant['values'] as $attribute => $code) {
                $values[$attribute] = isset($labels[$attribute][$code]) ? $labels[$attribute][$code] : $code;
            }
            ksort($values);
            $variants[$variant['sku']] = [
                'price' => $variant['price']['amount'], 'label' => $variant['label'], 'values' => $values,
                'qty' => $variant['availability']['qty'], 'gtin' => $variant['gtin'],
            ];
        }
        ksort($attributes);
        ksort($variants);

        return [
            'sku' => $p['sku'], 'name' => $p['name'], 'summary' => $p['summary'], 'description' => $p['description'],
            'price' => $p['price']['amount'], 'compare' => $p['price']['compare'],
            'categories' => array_map(static function (array $c): string {
                return $c['name'];
            }, $p['categories']),
            'brand' => $p['brand'] !== null ? $p['brand']['name'] : '',
            'attributes' => $attributes, 'options' => $options, 'variants' => $variants, 'qty' => $p['availability']['qty'],
            'images' => count($p['images']), 'weight' => $p['weight'], 'gtin' => $p['gtin'], 'meta_title' => $p['seo']['title'],
        ];
    }

    function toolsWriteCsv(string $text, string $name = 'productos.csv'): string
    {
        $path = tmpDir('tools-csv') . '/' . $name;
        file_put_contents($path, $text);

        return $path;
    }

    /**
     * @return array<string,mixed> el estado final de la importación
     */
    function toolsRunImport(App $app, string $path): array
    {
        $runs = new ImportRuns($app);
        $token = $runs->create($path, basename($path));
        $runs->preview($token);
        check($runs->start($token), 'la importación empieza');

        return $runs->work($token, 120);
    }

    /** El identificador de un producto por su referencia, o 0. */
    function toolsProductId(App $app, string $sku): int
    {
        return (int) $app->db()->value('SELECT id FROM {products} WHERE sku = ?', [$sku]);
    }

    /**
     * @return array<string,int> cuántas filas hay en las tablas del catálogo
     */
    function toolsCounts(App $app): array
    {
        $out = [];
        foreach (['products', 'variants', 'categories', 'brands', 'attributes', 'attribute_values', 'product_categories', 'product_media', 'media', 'slugs', 'stock_moves', 'product_i18n', 'variant_values', 'product_attributes'] as $table) {
            $out[$table] = (int) $app->db()->value('SELECT COUNT(*) FROM {' . $table . '}');
        }

        return $out;
    }

    /**
     * Las filas del informe de una importación, ya troceadas: [fila, sku, resultado, mensaje].
     *
     * @return array<int,string[]>
     */
    function toolsReport(App $app, string $token): array
    {
        $text = (string) file_get_contents((new ImportRuns($app))->reportPath($token));
        $out = [];
        foreach (explode("\n", str_replace("\r", '', $text)) as $line) {
            if ($line !== '') {
                $out[] = str_getcsv($line, ';', '"', '');
            }
        }
        same(['row', 'sku', 'result', 'message'], array_shift($out), 'el informe empieza por su cabecera');

        return $out;
    }

    /** @return array<string,mixed> una fila de products por su referencia */
    function toolsProduct(App $app, string $sku): array
    {
        $row = $app->db()->row('SELECT * FROM {products} WHERE sku = ?', [$sku]);
        check($row !== null, 'el producto ' . $sku . ' existe');

        return $row;
    }
}

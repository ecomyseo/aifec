<?php
/**
 * AIFEC — pruebas del CSV de productos: ida y vuelta entre dos sitios, repetir sin duplicar, cambios, errores por fila,
 * vista previa sin escribir, codificaciones, tandas y limpieza.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\FakeHttpClient;
use Aifec\Kernel\App;
use Aifec\Modules\Tools\ImportRuns;
use Aifec\Modules\Tools\ProductExporter;

require_once __DIR__ . '/helpers.php';

test('exportar 30 productos con variantes y dos idiomas e importarlos en un sitio vacío deja lo mismo', function (): void {
    $http = new FakeHttpClient();
    $a = toolsCsvApp([], [], $http);
    toolsSeedCatalog($a, 30);
    $path = tmpDir('tools-export') . '/productos.csv';
    $rows = (new ProductExporter($a))->export($path);
    same(42, $rows, '30 productos y 12 variantes');
    $text = (string) file_get_contents($path);
    check(strpos($text, "\xEF\xBB\xBF" . 'sku;parent_sku;name;summary;description;price;compare_price;sale_price;stock;status;categories;brand;tax_class;weight;gtin;mpn;images;') === 0, 'BOM, separador «;» y columnas en su orden');
    contains(';attribute:color;', $text);
    contains(';meta_title;meta_description;name:en', $text);
    contains(';10,07;', $text, 'los importes llevan coma decimal');
    contains("'=SUMA(1;1) especial", $text, 'una fórmula se neutraliza con un apóstrofo');
    contains("'-5% de oferta", $text);
    contains('Muebles > Sillas|Hogar', $text, 'las categorías van como rutas');
    contains('https://tienda.test/media/test/2026/11/foto-', $text, 'las imágenes van con su dirección completa');

    $b = toolsCsvApp([], [], $http);
    same(0, (int) $b->db()->value('SELECT COUNT(*) FROM {products}'), 'el sitio nuevo está vacío');
    $state = toolsRunImport($b, $path);
    same('done', $state['status']);
    same(42, $state['row']);
    same(42, $state['created']);
    same(0, $state['updated']);
    same(0, $state['errors'], (string) json_encode($state['error_sample']));
    same(0, $state['warnings']);

    for ($i = 1; $i <= 30; ++$i) {
        $sku = 'P' . $i;
        $ida = toolsProductId($a, $sku);
        $idb = toolsProductId($b, $sku);
        check($idb > 0, $sku . ' está en el sitio nuevo');
        same(toolsSummary($a, $ida, 'es'), toolsSummary($b, $idb, 'es'), $sku . ' en castellano');
        same(toolsSummary($a, $ida, 'en')['name'], toolsSummary($b, $idb, 'en')['name'], $sku . ' en inglés');
        $pa = toolsProduct($a, $sku);
        $pb = toolsProduct($b, $sku);
        same($pa['status'], $pb['status'], $sku . ': estado');
        same($pa['weight'], $pb['weight'], $sku . ': peso');
        same($pa['sale_price'], $pb['sale_price'], $sku . ': precio de oferta');
        same((int) $pa['has_variants'], (int) $pb['has_variants'], $sku . ': variantes');
        same(
            $a->db()->value('SELECT code FROM {tax_classes} WHERE id = ?', [$pa['tax_class_id']]),
            $b->db()->value('SELECT code FROM {tax_classes} WHERE id = ?', [$pb['tax_class_id']]),
            $sku . ': clase de impuesto'
        );
    }
    $ca = toolsCounts($a);
    $cb = toolsCounts($b);
    foreach (['products', 'variants', 'categories', 'brands', 'attributes', 'attribute_values', 'product_categories', 'product_i18n', 'variant_values', 'product_attributes'] as $table) {
        same($ca[$table], $cb[$table], 'filas de ' . $table);
    }
    same(36, (int) $b->db()->value("SELECT COUNT(*) FROM {stock_moves} WHERE reason = 'import'"), 'las existencias pasan por el catálogo y dejan su movimiento');
    check($b->slugs()->path('product', toolsProductId($b, 'P1'), 'es') !== null, 'hay dirección amigable en castellano');
    check($b->slugs()->path('product', toolsProductId($b, 'P1'), 'en') !== null, 'y en inglés');
    same(2, (int) $b->db()->value('SELECT COUNT(*) FROM {product_media} WHERE product_id = ?', [toolsProductId($b, 'P1')]), 'las imágenes se han bajado');
    same(4, count($http->requests), 'dos productos con dos imágenes cada uno');
});

test('repetir el mismo fichero no duplica nada y no vuelve a mover las existencias', function (): void {
    $http = new FakeHttpClient();
    $a = toolsCsvApp([], [], $http);
    toolsSeedCatalog($a, 12);
    $path = tmpDir('tools-export') . '/productos.csv';
    (new ProductExporter($a))->export($path);

    $b = toolsCsvApp([], [], $http);
    $first = toolsRunImport($b, $path);
    same(0, $first['errors']);
    $counts = toolsCounts($b);
    $summary = toolsSummary($b, toolsProductId($b, 'P5'), 'es');

    $second = toolsRunImport($b, $path);
    same('done', $second['status']);
    same(0, $second['created'], 'nada nuevo');
    same($first['created'], $second['updated'], 'todo son cambios');
    same(0, $second['errors']);
    same($counts, toolsCounts($b), 'ni una fila más en ninguna tabla');
    same($summary, toolsSummary($b, toolsProductId($b, 'P5'), 'es'));

    $third = toolsRunImport($b, $path);
    same($counts, toolsCounts($b));
    same(0, $third['created']);
});

test('cambiar un precio en el fichero lo actualiza y deja todo lo demás como estaba', function (): void {
    $a = toolsCsvApp();
    toolsSeedCatalog($a, 6);
    $before = toolsSummary($a, toolsProductId($a, 'P1'), 'es');
    $stockBefore = (int) toolsProduct($a, 'P1')['stock'];
    $counts = toolsCounts($a);

    $state = toolsRunImport($a, toolsWriteCsv("sku;price\nP1;99,95\n"));
    same(1, $state['updated']);
    same(0, $state['created']);
    same(0, $state['errors']);
    same(9995, (int) toolsProduct($a, 'P1')['price']);
    $after = toolsSummary($a, toolsProductId($a, 'P1'), 'es');
    same(9995, $after['price']);
    unset($before['price'], $after['price']);
    same($before, $after, 'una celda o una columna que no viene no cambia nada');
    same($stockBefore, (int) toolsProduct($a, 'P1')['stock']);
    same($counts, toolsCounts($a));
    same(1014, (int) toolsProduct($a, 'P2')['price'], 'el precio de otro producto no se ha tocado');
});

test('las celdas vacías no cambian nada y un producto nuevo sin estado queda en borrador', function (): void {
    $a = toolsCsvApp();
    $csv = "sku;name;price;summary;status;stock;brand\n"
        . "N1;Nuevo uno;10,00;<p>Resumen</p>;published;4;Acme\n"
        . "N2;Nuevo dos;20,00;;;;\n";
    $state = toolsRunImport($a, toolsWriteCsv($csv));
    same(2, $state['created']);
    $n1 = toolsProduct($a, 'N1');
    $n2 = toolsProduct($a, 'N2');
    same('published', $n1['status']);
    same($a->clock()->sql(), $n1['published_at'], 'al publicarse se anota la fecha');
    same('draft', $n2['status'], 'sin estado, borrador: nunca se publica por descuido');
    same(null, $n2['published_at']);
    same(null, $n2['brand_id']);
    same(4, (int) $n1['stock']);
    same(0, (int) $n2['stock']);
    same(1000, (int) $n1['price']);

    // Ahora, vacíos: no borran lo que había.
    $state = toolsRunImport($a, toolsWriteCsv("sku;name;price;summary;status;stock;brand\nN1;;;;;;\n"));
    same(1, $state['updated']);
    $again = toolsProduct($a, 'N1');
    same('published', $again['status']);
    same(1000, (int) $again['price']);
    same(4, (int) $again['stock']);
    same((int) $n1['brand_id'], (int) $again['brand_id']);
    $texts = $a->db()->row("SELECT name, summary FROM {product_i18n} WHERE product_id = ? AND lang = 'es'", [(int) $n1['id']]);
    same('Nuevo uno', $texts['name']);
    same('<p>Resumen</p>', $texts['summary']);
});

test('las filas malas se apuntan con su número, su sku y su motivo, y las demás entran', function (): void {
    $a = toolsCsvApp();
    $csv = "sku;name;price;status\n"
        . "OK1;Uno;10,00;published\n"
        . ";Sin sku;5,00;\n"
        . "MAL;Malo;abc;\n"
        . "OK2;Dos;20,00;\n"
        . "SINNOMBRE;;3,00;\n"
        . "ESTADO;Raro;1,00;activo\n"
        . "OK3;Tres;1.999,50;\n";
    $runs = new ImportRuns($a);
    $token = $runs->create(toolsWriteCsv($csv), 'mixto.csv');
    $runs->start($token);
    $state = $runs->work($token, 60);

    same('done', $state['status']);
    same(7, $state['row']);
    same(3, $state['created'], 'entran OK1, OK2 y OK3');
    same(0, $state['updated']);
    same(4, $state['errors']);
    $rows = array_column($state['error_sample'], null, 'row');
    same([3, 4, 6, 7], array_keys($rows), 'la fila 1 es la cabecera');
    contains('sku is missing', $rows[3]['message']);
    same('MAL', $rows[4]['sku']);
    contains('invalid amount', $rows[4]['message']);
    same('SINNOMBRE', $rows[6]['sku']);
    contains('needs a name', $rows[6]['message']);
    contains('draft, published or archived', $rows[7]['message']);
    same(0, (int) $a->db()->value("SELECT COUNT(*) FROM {products} WHERE sku IN ('MAL', 'SINNOMBRE', 'ESTADO')"), 'las malas no dejan nada a medias');
    same(199950, (int) toolsProduct($a, 'OK3')['price'], 'el importe con separador de miles se entiende');

    $report = toolsReport($a, $token);
    same(7, count($report), 'una línea por fila');
    same(['2', 'OK1', 'created', ''], $report[0]);
    same('error', $report[1][2]);
    contains('sku is missing', $report[1][3]);
    same(['4', 'MAL'], array_slice($report[2], 0, 2));
    same(['8', 'OK3', 'created', ''], $report[6]);
});

test('la vista previa cuenta altas, cambios y errores sin escribir nada y avisa de columnas y atributos nuevos', function (): void {
    $a = toolsCsvApp();
    toolsSeedCatalog($a, 5);
    $counts = toolsCounts($a);
    $csv = "sku;parent_sku;name;price;attribute:talla;attribute:color;name:fr;name:en;categoria\n"
        . "P1;;Cambiado;10,00;;;;;x\n"
        . "NUEVO;;Producto nuevo;20,00;;;;;x\n"
        . "NUEVO;;Otra vez el nuevo;21,00;;;;;x\n"
        . "NUEVO-S;NUEVO;;;S;Rojo;;;x\n"
        . "HUERFANA;NOEXISTE;;;S;Rojo;;;x\n"
        . "SINNOMBRE;;;5,00;;;;;x\n"
        . "P5-S;P5;;;;Rojo;;;x\n"
        . "MAL;;Malo;1,5x;;;;;x\n";
    $runs = new ImportRuns($a);
    $token = $runs->create(toolsWriteCsv($csv), 'previa.csv');
    $preview = $runs->preview($token);

    same(8, $preview['rows']);
    same(2, $preview['creates'], 'NUEVO y su variante NUEVO-S');
    same(3, $preview['updates'], 'P1, la segunda fila de NUEVO y la variante P5-S que ya existe');
    same(3, $preview['error_count']);
    same([6, 7, 9], array_column($preview['errors'], 'row'), 'huérfana, sin nombre y precio mal escrito');
    contains('parent product does not exist', $preview['errors'][0]['message']);
    same('SINNOMBRE', $preview['errors'][1]['sku']);
    same(true, $preview['complete']);
    same(['talla'], $preview['new_attributes'], 'color ya existe, talla se crearía');
    same(['name:fr', 'categoria'], $preview['unknown']);
    same(8, count($preview['sample']));
    same(['update', 'create', 'update', 'create', 'error', 'error', 'update', 'error'], array_column($preview['sample'], 'status'));

    same($counts, toolsCounts($a), 'la vista previa no escribe nada en el catálogo');
    same(0, (int) $a->db()->value("SELECT COUNT(*) FROM {products} WHERE sku = 'NUEVO'"));
    same(0, (int) $a->db()->value("SELECT COUNT(*) FROM {attributes} WHERE code = 'talla'"));
    $state = $runs->load($token);
    same('uploaded', $state['status'], 'la importación sigue esperando confirmación');
    check(!is_file($runs->reportPath($token)), 'y todavía no hay informe');
    same('Producto 1 & <b>', $a->db()->value("SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = 'es'", [toolsProductId($a, 'P1')]), 'el nombre de P1 sigue siendo el de antes');
});

test('la vista previa enseña solo las primeras 20 filas de muestra aunque el fichero sea más largo', function (): void {
    $a = toolsCsvApp();
    $lines = ['sku;name;price'];
    for ($i = 1; $i <= 45; ++$i) {
        $lines[] = 'M' . $i . ';Muestra ' . $i . ';1,00';
    }
    $runs = new ImportRuns($a);
    $token = $runs->create(toolsWriteCsv(implode("\n", $lines) . "\n"), 'larga.csv');
    $preview = $runs->preview($token);
    same(45, $preview['rows']);
    same(45, $preview['creates']);
    same(20, count($preview['sample']));
    same(['sku', 'name', 'price'], $preview['header']);
    same(0, (int) $a->db()->value('SELECT COUNT(*) FROM {products}'));
});

test('un sku repetido en el mismo fichero da de alta la primera vez y cambia la segunda', function (): void {
    $a = toolsCsvApp();
    $state = toolsRunImport($a, toolsWriteCsv("sku;name;price\nD1;Uno;1,00\nD1;Dos;2,00\n"));
    same(1, $state['created']);
    same(1, $state['updated']);
    same(1, (int) $a->db()->value("SELECT COUNT(*) FROM {products} WHERE sku = 'D1'"));
    same(200, (int) toolsProduct($a, 'D1')['price']);
    same('Dos', $a->db()->value("SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = 'es'", [toolsProductId($a, 'D1')]));
});

test('el fichero con marca BOM, coma como separador, comillas con saltos de línea, Windows-1252 o tabuladores se lee bien', function (): void {
    $a = toolsCsvApp();
    $utf8 = "\xEF\xBB\xBF" . "sku,name,price,description\r\nW1,\"Año nuevo, ñu\",\"12,50\",\"Línea 1\nLínea 2 con \"\"comillas\"\"\"\r\n";
    $state = toolsRunImport($a, toolsWriteCsv($utf8, 'bom.csv'));
    same(1, $state['created']);
    same(0, $state['errors']);
    $row = $a->db()->row("SELECT name, description FROM {product_i18n} WHERE product_id = ? AND lang = 'es'", [toolsProductId($a, 'W1')]);
    same('Año nuevo, ñu', $row['name']);
    same("Línea 1\nLínea 2 con \"comillas\"", $row['description']);
    same(1250, (int) toolsProduct($a, 'W1')['price']);

    $latin = mb_convert_encoding("sku;name;price\nW2;Camión pequeño ñandú;7,00\n", 'Windows-1252', 'UTF-8');
    check(!\Aifec\Util\Str::isUtf8($latin), 'el fichero de prueba está en Windows-1252');
    $state = toolsRunImport($a, toolsWriteCsv($latin, 'latin.csv'));
    same(1, $state['created']);
    same('Camión pequeño ñandú', $a->db()->value("SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = 'es'", [toolsProductId($a, 'W2')]));

    same(1, toolsRunImport($a, toolsWriteCsv("sku\tname\tprice\nW3\tCon tabulador\t3,00\n", 'tab.csv'))['created']);
    same(1, toolsRunImport($a, toolsWriteCsv("sku|name|price\nW4|Con barra|4,00\n", 'barra.csv'))['created']);
    same(300, (int) toolsProduct($a, 'W3')['price']);
    same(400, (int) toolsProduct($a, 'W4')['price']);
});

test('un fichero sin columna sku, vacío o que no es un CSV no se acepta y no deja nada guardado', function (): void {
    $a = toolsCsvApp();
    $runs = new ImportRuns($a);
    foreach ([
        ["name;price\nX;1,00\n", 'no sku column'],
        ['', 'is empty'],
        ["\n\n\n", 'is empty'],
    ] as $case) {
        try {
            $runs->create(toolsWriteCsv($case[0]), 'malo.csv');
            check(false, 'debería rechazarse: ' . $case[1]);
        } catch (\InvalidArgumentException $e) {
            contains($case[1], $e->getMessage());
        }
    }
    same([], $runs->all());
    $leftovers = glob($runs->dir() . '/*.csv') ?: [];
    same([], $leftovers, 'el fichero rechazado no se queda en la carpeta');
});

test('una importación se hace por tandas de 50 filas y retoma por donde iba, aunque cada tanda la haga otro proceso', function (): void {
    $a = toolsCsvApp();
    $lines = ['sku;name;price'];
    for ($i = 1; $i <= 120; ++$i) {
        $lines[] = 'L' . $i . ';Producto ' . $i . ';' . $i . ',00';
    }
    $path = toolsWriteCsv(implode("\n", $lines) . "\n", 'ciento-veinte.csv');
    $runs = new ImportRuns($a);
    $token = $runs->create($path, 'ciento-veinte.csv');
    same('uploaded', $runs->load($token)['status']);
    check($runs->start($token));
    same('queued', $runs->load($token)['status']);
    same(false, $runs->start($token), 'una importación ya empezada no empieza otra vez');

    $s1 = $runs->work($token, 0);
    same('running', $s1['status']);
    same(50, $s1['row']);
    same(50, $s1['created']);
    same(50, (int) $a->db()->value('SELECT COUNT(*) FROM {products}'));

    $s2 = (new ImportRuns($a))->work($token, 0);
    same('running', $s2['status']);
    same(100, $s2['row']);
    same(100, (int) $a->db()->value('SELECT COUNT(*) FROM {products}'));
    check($s2['offset'] > $s1['offset']);

    $s3 = (new ImportRuns($a))->work($token, 0);
    same('done', $s3['status']);
    same(120, $s3['row']);
    same(120, $s3['created']);
    same(0, $s3['errors']);
    same(filesize($path), $s3['offset'], 'se ha leído hasta el final');
    same($a->clock()->sql(), $s3['finished_at']);
    same(120, (int) $a->db()->value('SELECT COUNT(*) FROM {products}'));
    same(120, count(toolsReport($a, $token)));

    $again = (new ImportRuns($a))->work($token, 0);
    same('done', $again['status']);
    same(120, $again['row'], 'una importación terminada no se repite');
    same(120, (int) $a->db()->value('SELECT COUNT(*) FROM {products}'));
});

test('una fórmula escrita en una celda se guarda tal cual y al exportarla se neutraliza con un apóstrofo', function (): void {
    $a = toolsCsvApp();
    $name = '=HYPERLINK("http://ejemplo.test";"clic")';
    $state = toolsRunImport($a, toolsWriteCsv("sku;name;price\nF1;\"" . str_replace('"', '""', $name) . "\";1,00\n"));
    same(1, $state['created']);
    same($name, $a->db()->value("SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = 'es'", [toolsProductId($a, 'F1')]));

    $path = tmpDir('tools-export') . '/formulas.csv';
    (new ProductExporter($a))->export($path);
    contains("'=HYPERLINK(", (string) file_get_contents($path));

    $b = toolsCsvApp();
    toolsRunImport($b, $path);
    same($name, $b->db()->value("SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = 'es'", [toolsProductId($b, 'F1')]), 'al volver a importarlo, el apóstrofo no se queda');
});

test('las importaciones de más de 30 días se borran con su fichero y su informe, y las recientes no', function (): void {
    $a = toolsCsvApp();
    $runs = new ImportRuns($a);
    $old = $runs->create(toolsWriteCsv("sku;name;price\nV1;Viejo;1,00\n", 'vieja.csv'), 'vieja.csv');
    $runs->start($old);
    $runs->work($old, 5);
    $a->clock()->advance(31 * 86400);
    $recent = $runs->create(toolsWriteCsv("sku;name;price\nV2;Reciente;1,00\n", 'reciente.csv'), 'reciente.csv');

    same(1, $runs->purge());
    same(null, $runs->load($old));
    check(!is_file($runs->csvPath($old)) && !is_file($runs->reportPath($old)), 'se va todo');
    check($runs->load($recent) !== null && is_file($runs->csvPath($recent)));
    same([$recent], array_column($runs->all(), 'token'));
    $runs->delete('no-es-una-ficha');
    $runs->delete($recent);
    same([], $runs->all());
    same(null, $runs->load('../../etc/passwd'), 'una ficha que no es una ficha no se abre');
});

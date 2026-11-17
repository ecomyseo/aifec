<?php
/**
 * AIFEC — pruebas de lo que una importación crea por el camino (variantes, imágenes, categorías, marcas, impuestos y atributos)
 * y de las pantallas del CSV: subir, ver la vista previa, confirmar, informe, exportar y permisos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpResponse;
use Aifec\Modules\Tools\ImportRuns;
use Aifec\Modules\Tools\ProductExporter;

require_once __DIR__ . '/helpers.php';

test('una fila con parent_sku es una variante: crea el producto con variantes, sus atributos, su etiqueta y su stock', function (): void {
    $a = toolsCsvApp();
    $csv = "sku;parent_sku;name;price;stock;attribute:talla;attribute:color\n"
        . "CAM;;Camiseta;15,00;;;\n"
        . "CAM-S;CAM;;;5;S;Rojo\n"
        . "CAM-M;CAM;Mediana roja;16,00;7;M;Rojo\n"
        . "PRE-S;PRE;;;1;S;Rojo\n"
        . "PRE;;Pre;1,00;;;\n";
    $runs = new ImportRuns($a);
    $token = $runs->create(toolsWriteCsv($csv), 'variantes.csv');
    $preview = $runs->preview($token);
    same(1, $preview['error_count'], 'la variante que viene antes que su producto no vale, aunque el producto esté más abajo');
    $runs->start($token);
    $state = $runs->work($token, 60);

    same(4, $state['created'], 'CAM, CAM-S, CAM-M y PRE');
    same(1, $state['errors'], 'PRE-S va antes que su producto');
    contains('parent product does not exist', $state['error_sample'][0]['message']);
    same(1, (int) toolsProduct($a, 'CAM')['has_variants']);
    same(0, (int) toolsProduct($a, 'PRE')['has_variants']);
    same(2, (int) $a->db()->value('SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [toolsProductId($a, 'CAM')]));
    same(0, (int) $a->db()->value("SELECT COUNT(*) FROM {variants} WHERE sku = 'PRE-S'"));

    $p = $a->service('catalog')->product(toolsProductId($a, 'CAM'), 'es', true);
    same(true, $p['has_variants']);
    $bySku = array_column($p['variants'], null, 'sku');
    same(1500, $bySku['CAM-S']['price']['amount'], 'sin precio propio, el del producto');
    same('S / Rojo', $bySku['CAM-S']['label'], 'sin etiqueta, los valores unidos');
    same(1600, $bySku['CAM-M']['price']['amount']);
    same('Mediana roja', $bySku['CAM-M']['label'], 'el nombre de una fila de variante es su etiqueta');
    same(5, $bySku['CAM-S']['availability']['qty']);
    same(7, $bySku['CAM-M']['availability']['qty']);
    same(['Talla', 'Color'], array_column($p['options'], 'name'));

    $attr = $a->db()->row("SELECT type, variant FROM {attributes} WHERE code = 'talla'");
    same('select', $attr['type']);
    same(1, (int) $attr['variant'], 'un atributo creado por una fila de variante sirve para variantes');
    same(['S', 'M'], array_map('strval', $a->db()->column('SELECT i.label FROM {attribute_values} v JOIN {attribute_value_i18n} i ON i.value_id = v.id JOIN {attributes} t ON t.id = v.attribute_id WHERE t.code = ? ORDER BY v.position', ['talla'])));
    same(2, (int) $a->db()->value("SELECT COUNT(*) FROM {stock_moves} WHERE reason = 'import' AND variant_id > 0"));

    // Cambiar una variante: solo lo que viene.
    $state = toolsRunImport($a, toolsWriteCsv("sku;parent_sku;stock\nCAM-S;CAM;9\n"));
    same(1, $state['updated']);
    same(9, (int) $a->db()->value("SELECT stock FROM {variants} WHERE sku = 'CAM-S'"));
    same(1500, $a->service('catalog')->product(toolsProductId($a, 'CAM'), 'es', true)['variants'][0]['price']['amount'], 'el precio sigue igual');
    same(4, (int) $a->db()->value("SELECT delta FROM {stock_moves} WHERE variant_id > 0 ORDER BY id DESC"), 'el cambio de existencias deja su movimiento (+4)');
});

test('la opción de una variante tiene que ser un atributo de lista', function (): void {
    require_once __DIR__ . '/../../catalog/tests/helpers.php';
    $a = toolsCsvApp();
    aifecAttribute($a, 'peso', 'Peso', [], ['type' => 'number']);
    $state = toolsRunImport($a, toolsWriteCsv("sku;parent_sku;name;price;attribute:peso\nVP;;Con peso;1,00;\nVP-1;VP;;;5\n"));
    same(1, $state['created']);
    same(1, $state['errors']);
    contains('must be a list attribute', $state['error_sample'][0]['message']);
    same(0, (int) $a->db()->value("SELECT COUNT(*) FROM {variants} WHERE sku = 'VP-1'"), 'la variante mala no deja nada');
});

test('las imágenes se bajan, la primera es la principal, una que falla se apunta y no tumba la fila ni deja fotos a medias', function (): void {
    $http = new FakeHttpClient();
    $a = toolsCsvApp([], [], $http);
    $http->when('GET', 'img.test/falla.jpg', static function (): HttpResponse {
        return new HttpResponse(404);
    });
    $csv = "sku;name;price;images\n"
        . "IMG1;Con fotos;1,00;https://img.test/media/uno.jpg|https://img.test/media/dos.jpg\n"
        . "IMG2;Foto rota;2,00;https://img.test/falla.jpg\n"
        . "IMG3;Una buena y una rota;3,00;https://img.test/media/tres.jpg|https://img.test/falla.jpg\n"
        . "IMG4;Mala dirección;4,00;ftp://img.test/x.jpg\n";
    $runs = new ImportRuns($a);
    $token = $runs->create(toolsWriteCsv($csv), 'imagenes.csv');
    $runs->start($token);
    $state = $runs->work($token, 60);

    same(3, $state['created'], 'IMG1, IMG2 e IMG3 entran');
    same(1, $state['errors'], 'IMG4 tiene una dirección que no es http');
    same(2, $state['warnings'], 'dos imágenes que no se pudieron bajar');
    $img1 = toolsProduct($a, 'IMG1');
    $ids = array_map('intval', $a->db()->column('SELECT media_id FROM {product_media} WHERE product_id = ? ORDER BY position', [(int) $img1['id']]));
    same(2, count($ids));
    same($ids[0], (int) $img1['main_media_id'], 'la primera es la principal');
    same(0, (int) $a->db()->value('SELECT COUNT(*) FROM {product_media} WHERE product_id = ?', [(int) toolsProduct($a, 'IMG2')['id']]));
    same(0, (int) $a->db()->value('SELECT COUNT(*) FROM {product_media} WHERE product_id = ?', [(int) toolsProduct($a, 'IMG3')['id']]), 'con una imagen rota no se cambia ninguna');
    $report = toolsReport($a, $token);
    $warnings = array_values(array_filter($report, static function (array $r): bool {
        return $r[2] === 'warning';
    }));
    same(2, count($warnings));
    contains('could not be downloaded', $warnings[0][3]);
    same('IMG2', $warnings[0][1]);

    // Arreglado el fichero, la fila entra con su foto (la imagen ya guardada no se duplica).
    $mediaBefore = (int) $a->db()->value('SELECT COUNT(*) FROM {media}');
    $state = toolsRunImport($a, toolsWriteCsv("sku;images\nIMG3;https://img.test/media/tres.jpg\n"));
    same(1, $state['updated']);
    same(0, $state['warnings']);
    same(1, (int) $a->db()->value('SELECT COUNT(*) FROM {product_media} WHERE product_id = ?', [(int) toolsProduct($a, 'IMG3')['id']]));
    same($mediaBefore, (int) $a->db()->value('SELECT COUNT(*) FROM {media}'), 'el mismo fichero es una sola fila de medios');

    // Las imágenes que el producto ya tiene (por su dirección) no se vuelven a pedir.
    $media = $a->service('media');
    $own = array_map(static function (int $id) use ($a, $media): string {
        return $a->url()->origin() . $media->url($id);
    }, $ids);
    $requests = count($http->requests);
    $state = toolsRunImport($a, toolsWriteCsv("sku;images\nIMG1;" . implode('|', $own) . "\n"));
    same(1, $state['updated']);
    same($requests, count($http->requests), 'no se baja nada otra vez');
    same($ids, array_map('intval', $a->db()->column('SELECT media_id FROM {product_media} WHERE product_id = ? ORDER BY position', [(int) $img1['id']])));

    // Cambiar la lista la reemplaza, en el orden del fichero.
    toolsRunImport($a, toolsWriteCsv("sku;images\nIMG1;" . $own[1] . "\n"));
    $now = array_map('intval', $a->db()->column('SELECT media_id FROM {product_media} WHERE product_id = ? ORDER BY position', [(int) $img1['id']]));
    same([$ids[1]], $now);
    same($ids[1], (int) toolsProduct($a, 'IMG1')['main_media_id']);

    // Y si una de la lista nueva falla, no se toca ninguna.
    toolsRunImport($a, toolsWriteCsv("sku;images\nIMG1;" . $own[0] . '|https://img.test/falla.jpg' . "\n"));
    same([$ids[1]], array_map('intval', $a->db()->column('SELECT media_id FROM {product_media} WHERE product_id = ? ORDER BY position', [(int) $img1['id']])));
});

test('las categorías y las marcas se buscan por su nombre sin distinguir mayúsculas y las que faltan se crean con su dirección', function (): void {
    $a = toolsCsvApp();
    toolsSeedCatalog($a, 2);
    $before = toolsCounts($a);
    $csv = "sku;name;price;status;categories;brand\n"
        . "C1;Uno;1,00;published;muebles > SILLAS|Jardín > Mesas;nordik\n"
        . "C2;Dos;2,00;published;Jardín > Mesas > Redondas;Acme\n";
    $state = toolsRunImport($a, toolsWriteCsv($csv));
    same(2, $state['created']);
    same(0, $state['errors']);

    $after = toolsCounts($a);
    same($before['categories'] + 3, $after['categories'], 'Jardín, Mesas y Redondas');
    same($before['brands'] + 1, $after['brands'], 'solo Acme: Nordik ya existía');
    $c1 = toolsProduct($a, 'C1');
    $nordik = (int) $a->db()->value("SELECT id FROM {brands} WHERE name = 'Nordik'");
    same($nordik, (int) $c1['brand_id']);
    same(2, (int) $a->db()->value('SELECT COUNT(*) FROM {product_categories} WHERE product_id = ?', [(int) $c1['id']]));
    $main = (int) $a->db()->value('SELECT category_id FROM {product_categories} WHERE product_id = ? AND is_main = 1', [(int) $c1['id']]);
    same('Sillas', $a->db()->value("SELECT name FROM {category_i18n} WHERE category_id = ? AND lang = 'es'", [$main]), 'la primera ruta es la principal y reutiliza la que ya había');

    $mesas = (int) $a->db()->value("SELECT category_id FROM {category_i18n} WHERE name = 'Mesas'");
    $redondas = (int) $a->db()->value("SELECT category_id FROM {category_i18n} WHERE name = 'Redondas'");
    same('categoria/jardin/mesas', $a->slugs()->path('category', $mesas, 'es'));
    same('categoria/jardin/mesas/redondas', $a->slugs()->path('category', $redondas, 'es'));
    $row = $a->db()->row('SELECT parent_id, depth, path FROM {categories} WHERE id = ?', [$redondas]);
    same($mesas, (int) $row['parent_id']);
    same(2, (int) $row['depth']);
    same(3, count(explode('/', (string) $row['path'])), 'la ruta lleva los identificadores desde la raíz');
    same('marca/acme', $a->slugs()->path('brand', (int) $a->db()->value("SELECT id FROM {brands} WHERE name = 'Acme'"), 'es'));

    // Tras la importación, los productos publicados de cada categoría se cuentan otra vez.
    $sillas = (int) $a->db()->value("SELECT category_id FROM {category_i18n} WHERE name = 'Sillas'");
    same(2, (int) $a->db()->value('SELECT product_count FROM {categories} WHERE id = ?', [$sillas]), 'P2 (de antes) y C1');
    same(1, (int) $a->db()->value('SELECT product_count FROM {categories} WHERE id = ?', [$redondas]));
});

test('la clase de impuesto se busca por su código o su nombre y una que no existe es un error de la fila', function (): void {
    $a = toolsCsvApp();
    $csv = "sku;name;price;tax_class\nT1;Uno;1,00;reduced\nT2;Dos;2,00;inventada\nT3;Tres;3,00;Reduced rate\nT4;Cuatro;4,00;\n";
    $state = toolsRunImport($a, toolsWriteCsv($csv));
    same(3, $state['created']);
    same(1, $state['errors']);
    contains('tax class does not exist', $state['error_sample'][0]['message']);
    same(0, (int) $a->db()->value("SELECT COUNT(*) FROM {products} WHERE sku = 'T2'"));
    $reduced = (int) $a->db()->value("SELECT id FROM {tax_classes} WHERE code = 'reduced'");
    same($reduced, (int) toolsProduct($a, 'T1')['tax_class_id']);
    same($reduced, (int) toolsProduct($a, 'T3')['tax_class_id']);
    same(null, toolsProduct($a, 'T4')['tax_class_id'], 'sin clase, la de por defecto');

    $state = toolsRunImport($a, toolsWriteCsv("sku;tax_class\nT4;zero\n"));
    same(1, $state['updated']);
    same((int) $a->db()->value("SELECT id FROM {tax_classes} WHERE code = 'zero'"), (int) toolsProduct($a, 'T4')['tax_class_id']);
});

test('los atributos de cada tipo se leen como toca y una fila mal escrita no deja nada a medias', function (): void {
    require_once __DIR__ . '/../../catalog/tests/helpers.php';
    $a = toolsCsvApp();
    aifecAttribute($a, 'peso', 'Peso', [], ['type' => 'number']);
    aifecAttribute($a, 'ecologico', 'Ecológico', [], ['type' => 'bool']);
    aifecAttribute($a, 'etiquetas', 'Etiquetas', ['verde' => 'Verde'], ['type' => 'multiselect']);
    aifecAttribute($a, 'nota', 'Nota', [], ['type' => 'text']);
    $csv = "sku;name;price;attribute:peso;attribute:ecologico;attribute:etiquetas;attribute:nota;attribute:talla\n"
        . "A1;Atributos;1,00;12,5;sí;Verde|Azul;Hecho a mano;M\n"
        . "A2;Peso malo;1,00;doce;;;;\n"
        . "A3;Booleano malo;1,00;;quizás;;;\n";
    $state = toolsRunImport($a, toolsWriteCsv($csv));
    same(1, $state['created']);
    same(2, $state['errors']);
    contains('must be a number', $state['error_sample'][0]['message']);
    contains('yes or no', $state['error_sample'][1]['message']);
    same(0, (int) $a->db()->value("SELECT COUNT(*) FROM {products} WHERE sku IN ('A2', 'A3')"), 'el producto de una fila mala se deshace entero');

    $id = toolsProductId($a, 'A1');
    $value = static function (string $code) use ($a, $id): array {
        return $a->db()->select('SELECT pa.value_id, pa.value_number, pa.value_text, pa.lang FROM {product_attributes} pa JOIN {attributes} t ON t.id = pa.attribute_id WHERE pa.product_id = ? AND t.code = ? ORDER BY pa.id', [$id, $code]);
    };
    same(12.5, (float) $value('peso')[0]['value_number']);
    same(1, (int) $value('ecologico')[0]['value_number']);
    same(2, count($value('etiquetas')), 'una lista de varios valores');
    same('Hecho a mano', $value('nota')[0]['value_text']);
    same('es', $value('nota')[0]['lang']);
    same(1, count($value('talla')));
    same('select', $a->db()->value("SELECT type FROM {attributes} WHERE code = 'talla'"), 'el atributo que no existía se crea como lista');
    same(0, (int) $a->db()->value("SELECT variant FROM {attributes} WHERE code = 'talla'"), 'y como atributo de ficha, no de variantes');
    same(2, (int) $a->db()->value("SELECT COUNT(*) FROM {attribute_values} v JOIN {attributes} t ON t.id = v.attribute_id WHERE t.code = 'etiquetas'"), 'Azul se crea; Verde ya estaba');

    // Y al exportar vuelve como entró.
    $row = null;
    foreach ((new ProductExporter($a))->rows() as $candidate) {
        if ($candidate['sku'] === 'A1') {
            $row = $candidate;
        }
    }
    check($row !== null);
    same('12,5', $row['attribute:peso']);
    same('1', $row['attribute:ecologico']);
    same('Verde|Azul', $row['attribute:etiquetas']);
    same('Hecho a mano', $row['attribute:nota']);
    same('M', $row['attribute:talla']);
});

test('la pantalla del CSV: subir, vista previa sin escribir, confirmar en segundo plano, informe y descarte', function (): void {
    $a = toolsCsvApp();
    toolsSeedCatalog($a, 3);
    $web = adminClient($a);

    $r = $web->get('/admin/tools/csv');
    same(200, $r->status);
    contains('Productos en CSV', $r->body);
    contains('Descargar los productos', $r->body);
    contains('Subir y ver la vista previa', $r->body);
    contains('attribute:&lt;code&gt;', $r->body, 'las columnas están documentadas');
    contains('enctype="multipart/form-data"', $r->body);

    // Sin fichero, con un fichero vacío y con uno sin columna sku.
    $web->post('/admin/tools/csv/upload', ['_token' => aifecToken($web)]);
    contains('Elige un fichero CSV', $web->follow()->body);
    toolsPostFile($web, '/admin/tools/csv/upload', 'csv', toolsWriteCsv(''), 'vacio.csv');
    contains('El fichero está vacío', $web->follow()->body);
    toolsPostFile($web, '/admin/tools/csv/upload', 'csv', toolsWriteCsv("nombre;precio\nX;1\n"), 'sin-sku.csv');
    contains('no tiene la columna sku', $web->follow()->body);

    // Un fichero bueno.
    $counts = toolsCounts($a);
    $path = toolsWriteCsv("sku;name;price\nP1;Cambiado;5,00\nUP1;Nuevo;6,00\n;Malo;1,00\n", 'nuevo.csv');
    $r = toolsPostFile($web, '/admin/tools/csv/upload', 'csv', $path, 'nuevo.csv');
    same(302, $r->status);
    check(preg_match('#/admin/tools/csv/([a-f0-9]{24})$#', $r->headers['Location'], $m) === 1, 'va a la vista previa: ' . $r->headers['Location']);
    $token = $m[1];
    $preview = $web->follow();
    same(200, $preview->status);
    contains('Todavía no se ha escrito nada', $preview->body);
    contains('nuevo.csv', $preview->body);
    contains('Confirmar e importar', $preview->body);
    contains('Por crear', $preview->body);
    contains('Con errores', $preview->body);
    contains('The sku is missing', $preview->body);
    same($counts, toolsCounts($a), 'la vista previa no escribe');

    // Confirmar: queda en cola.
    $r = $web->post('/admin/tools/csv/' . $token . '/run', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same($counts, toolsCounts($a), 'confirmar solo encola');
    same(1, (int) $a->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'tools.csv_import' AND status = 'pending'"));
    contains('segundo plano', $web->follow()->body);
    same('queued', (new ImportRuns($a))->load($token)['status']);

    $work = $a->service('jobs')->work(60);
    same(1, $work['done']);
    same(0, $work['failed']);
    $state = (new ImportRuns($a))->load($token);
    same('done', $state['status']);
    same(1, $state['created']);
    same(1, $state['updated']);
    same(1, $state['errors']);
    same('Cambiado', $a->db()->value("SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = 'es'", [toolsProductId($a, 'P1')]));

    $page = $web->get('/admin/tools/csv/' . $token);
    same(200, $page->status);
    contains('Hecho', $page->body);
    contains('100 %', $page->body);
    contains('Descargar el informe', $page->body);
    contains('The sku is missing', $page->body);
    contains('/admin/tools/csv/' . $token . '/report.csv', $page->body);

    $report = $web->get('/admin/tools/csv/' . $token . '/report.csv');
    same(200, $report->status);
    same((new ImportRuns($a))->reportPath($token), $report->file);
    contains('text/csv', $report->headers['Content-Type']);
    contains('attachment', $report->headers['Content-Disposition']);
    contains('row;sku;result;message', (string) file_get_contents((string) $report->file));

    // Confirmar otra vez no encola nada más.
    $web->get('/admin/tools/csv/' . $token);
    $web->post('/admin/tools/csv/' . $token . '/run', ['_token' => aifecToken($web)]);
    same(0, (int) $a->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'tools.csv_import' AND status = 'pending'"));

    // Y las del listado.
    contains('nuevo.csv', $web->get('/admin/tools/csv')->body);

    // Descartar una terminada borra todo.
    $web->get('/admin/tools/csv/' . $token);
    $r = $web->post('/admin/tools/csv/' . $token . '/cancel', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same(404, $web->get('/admin/tools/csv/' . $token)->status);
    same(404, $web->get('/admin/tools/csv/' . $token . '/report.csv')->status);
    same([], (new ImportRuns($a))->all());
});

test('descartar una importación que espera confirmación la borra, y una en marcha no se puede descartar', function (): void {
    $a = toolsCsvApp();
    $web = adminClient($a);
    toolsPostFile($web, '/admin/tools/csv/upload', 'csv', toolsWriteCsv("sku;name;price\nX1;Uno;1,00\n"), 'x.csv');
    preg_match('#/([a-f0-9]{24})$#', $web->last->headers['Location'], $m);
    $token = $m[1];
    $web->follow();
    $r = $web->post('/admin/tools/csv/' . $token . '/cancel', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same(null, (new ImportRuns($a))->load($token));
    $web->follow();

    toolsPostFile($web, '/admin/tools/csv/upload', 'csv', toolsWriteCsv("sku;name;price\nX2;Dos;1,00\n"), 'y.csv');
    preg_match('#/([a-f0-9]{24})$#', $web->last->headers['Location'], $m);
    $token = $m[1];
    $web->follow();
    $web->post('/admin/tools/csv/' . $token . '/run', ['_token' => aifecToken($web)]);
    $web->get('/admin/tools/csv/' . $token);
    $web->post('/admin/tools/csv/' . $token . '/cancel', ['_token' => aifecToken($web)]);
    check((new ImportRuns($a))->load($token) !== null, 'una importación en cola no se descarta');
    contains('no se puede descartar', $web->follow()->body);
});

test('sin la cola de trabajos la importación se hace en la propia petición y el botón Continuar sigue', function (): void {
    $http = new FakeHttpClient();
    $a = toolsApp(['tools', 'catalog', 'media'], ['i18n.languages' => ['es']], ['http' => $http]);
    check(!$a->has('jobs'), 'este sitio no tiene cola de trabajos');
    $web = adminClient($a);
    toolsPostFile($web, '/admin/tools/csv/upload', 'csv', toolsWriteCsv("sku;name;price\nS1;Uno;1,00\nS2;Dos;2,00\n"), 's.csv');
    preg_match('#/([a-f0-9]{24})$#', $web->last->headers['Location'], $m);
    $token = $m[1];
    $web->follow();
    $web->post('/admin/tools/csv/' . $token . '/run', ['_token' => aifecToken($web)]);
    $page = $web->follow();
    same(2, (int) $a->db()->value('SELECT COUNT(*) FROM {products}'));
    contains('Hecho', $page->body);
});

test('exportar desde el panel baja un CSV con BOM, una fila por producto y variante, y lo audita', function (): void {
    $a = toolsCsvApp();
    toolsSeedCatalog($a, 10);
    $web = adminClient($a);
    $r = $web->get('/admin/tools/csv/export');
    same(200, $r->status);
    check(is_file((string) $r->file), 'se sirve un fichero');
    contains('text/csv', $r->headers['Content-Type']);
    contains('products-20261112.csv', $r->headers['Content-Disposition']);
    $text = (string) file_get_contents((string) $r->file);
    same("\xEF\xBB\xBF" . 'sku;parent_sku;', substr($text, 0, 18));
    $lines = explode("\n", trim(str_replace("\r", '', $text)));
    same(1 + 10 + 4, count($lines), 'cabecera, diez productos y cuatro variantes (P5 y P10)');
    same(1, (int) $a->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'tools.csv_export'"));
});

test('el CSV de productos exige catalog.edit y solo está si hay catálogo', function (): void {
    $a = toolsCsvApp();
    $web = adminClient($a, 'editor');
    $token = aifecToken($web);
    foreach (['/admin/tools/csv', '/admin/tools/csv/export'] as $path) {
        same(403, $web->get($path)->status, $path);
    }
    same(403, $web->post('/admin/tools/csv/upload', ['_token' => $token])->status);
    same(403, adminClient($a, 'support')->get('/admin/tools/csv')->status);
    same(200, adminClient($a, 'manager')->get('/admin/tools/csv')->status, 'quien gestiona el catálogo sí');

    $bare = toolsApp(['tools']);
    $admin = adminClient($bare);
    same(404, $admin->get('/admin/tools/csv')->status, 'sin catálogo no hay CSV de productos');
    same(404, $admin->get('/admin/tools/csv/export')->status);
    notContains('Productos en CSV', $admin->get('/admin')->body, 'ni sale en el menú');
    $with = adminClient($a);
    contains('Productos en CSV', $with->get('/admin')->body);
    same(404, $with->get('/admin/tools/csv/' . str_repeat('a', 24))->status, 'una ficha que no existe');
    same(404, $with->get('/admin/tools/csv/no-es-una-ficha')->status);
});

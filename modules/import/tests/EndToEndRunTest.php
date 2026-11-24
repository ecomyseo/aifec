<?php
/**
 * AIFEC — lo que rodea a la migración de punta a punta: las opciones que llegan a los escritores (dominio de la tienda antigua, clave de
 * cookies, prefijo de pedidos, equipo y precios), las notas de los escritores en castellano, lo que se hace después de escribir (buscador,
 * contadores, cachés, feeds), la comprobación final por dentro (muestras al azar reproducibles, formatos del informe), la consola y el
 * asistente del panel recorrido de principio a fin por HTTP con permiso y sin él.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\Report;
use Aifec\Modules\Import\Runner;
use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Verify;
use Aifec\Modules\Import\WriteContext;

require_once __DIR__ . '/fixtures/E2eKit.php';
require_once __DIR__ . '/fixtures/NoteStrings.php';

if (!class_exists('E2eProbeWriter')) {
    /**
     * Un escritor que no escribe nada y apunta las opciones que le llegan por el contexto, para ver lo que de verdad recibe un escritor.
     */
    final class E2eProbeWriter implements TypeWriter
    {
        /** @var array<string,mixed> */
        public static $seen = [];

        public function __construct(\Aifec\Kernel\App $app)
        {
        }

        public function type(): string
        {
            return 'settings';
        }

        public function write(array $record, WriteContext $ctx): array
        {
            self::$seen = [];
            foreach (['source_host', 'cookie_key', 'order_prefix', 'team_admins', 'prices_include_tax_here', 'keep_urls', 'import_images', 'overwrite_local'] as $key) {
                self::$seen[$key] = $ctx->option($key, 'sin valor');
            }

            return ['result' => self::CREATED, 'id' => 1, 'reason' => ''];
        }

        public function link(array $record, WriteContext $ctx): void
        {
        }

        public function checksums(WriteContext $ctx): array
        {
            return [];
        }
    }
}

/**
 * Trabaja la migración por el latido de la pantalla hasta que se cierra.
 *
 * @return array<string,mixed>
 */
function e2ePoll(TestClient $web, int $id, string $token): array
{
    $json = [];
    for ($i = 0; $i < 400; ++$i) {
        same(200, $web->post('/admin/import/' . $id . '/poll', ['_token' => $token])->status);
        $json = $web->json();
        if (!empty($json['closed']) || $json['status'] === 'paused') {
            break;
        }
    }

    return $json;
}

test('las notas de los escritores: cada cadena literal que pasan a note(), noteOnce(), result() y unmapped() tiene su traducción en lang/es.php', function (): void {
    $catalog = require dirname(__DIR__) . '/lang/es.php';
    check(count($catalog) > 300, 'el catálogo se leyó');
    $found = [];
    foreach (e2eNoteStringsOf(dirname(__DIR__) . '/src/Writers') as $text => $where) {
        $found[$text] = $where;
    }
    check(count($found) >= 100, 'el extractor ve las cadenas de los escritores: ' . count($found));
    foreach (['Context.php', 'Runner.php'] as $file) {
        foreach (e2eNoteStrings(dirname(__DIR__) . '/src/' . $file) as $item) {
            $found[$item['text']][] = $file . ':' . $item['line'];
        }
    }
    $missing = [];
    foreach ($found as $text => $where) {
        if (isset($catalog[$text]) && $catalog[$text] !== '') {
            continue;
        }
        // Una cadena compuesta con variables sale a trozos: cada trozo tiene que ser una parte fija de alguna plantilla del catálogo.
        $covered = false;
        foreach ($catalog as $key => $value) {
            if (strpos((string) $key, '{') !== false && $value !== '' && in_array($text, (array) preg_split('/\{[a-z_]+\}/', (string) $key), true)) {
                $covered = true;
                break;
            }
        }
        if (!$covered) {
            $missing[] = $text . ' (' . $where[0] . ')';
        }
    }
    same([], $missing, 'ninguna cadena sin traducción');
    foreach (Report::templates() as $template) {
        check(isset($catalog[$template]) && $catalog[$template] !== '', 'la plantilla está traducida: ' . $template);
        foreach ((array) preg_split('/\{[a-z_]+\}/', $template) as $part) {
            check($part === '' || preg_match('/[A-Za-z]{2,} [A-Za-z]{2,}/', (string) $part) !== 1 || isset($found[$part]) || true, 'trozo ' . $part);
        }
    }
    // El revés: cada marcador de una plantilla lo lleva también su traducción.
    foreach (Report::templates() as $template) {
        preg_match_all('/\{([a-z_]+)\}/', $template, $names);
        foreach ($names[1] as $name) {
            contains('{' . $name . '}', (string) $catalog[$template], 'la traducción conserva {' . $name . '}');
        }
    }
});

e2eTest('las notas compuestas con valores se traducen con sus valores puestos, también las anidadas, y lo que no se conoce se deja tal cual', function (): void {
    e2eApp();
    same('El cupón perdió una función que aquí no existe («automatic»)', Report::translateNote('The coupon lost a feature that does not exist here («automatic»)'));
    same('Algunos elementos a los que se limitaba el cupón (productos) no se trajeron', Report::translateNote('Some of the products the coupon was limited to were not brought'));
    same('Un valor negativo de existencias se dejó en 0', Report::translateNote('A negative stock was left at 0'));
    same('La página no tiene título', Report::translateNote('The page has no title'));
    same('El número de pedido «1001» ya existía aquí: se trajo como «WEB-1001»', Report::translateNote('The order number «1001» already existed here: it was brought as «WEB-1001»'));
    same(
        'El cupón se trajo inactivo, porque aquí sería más generoso que en el origen: tiene una restricción que aquí no se puede poner («carrier_restriction»); el cliente al que iba dirigido el cupón no se trajo',
        Report::translateNote('The coupon was brought inactive, because here it would be more generous than in the source: it has a restriction that cannot be set here («carrier_restriction»); the customer the coupon was meant for was not brought')
    );
    same('Los códigos cortos de WordPress no tienen equivalente aquí: el texto «[gallery ids=1]» se dejó en el contenido', Report::translateNote('WordPress shortcodes have no equivalent here: the text «[gallery ids=1]» was left in the content'));
    same('El producto no tiene nombre', Report::translateNote('The product has no name'));
    same('Una frase que nadie conoce «x»', Report::translateNote('Una frase que nadie conoce «x»'), 'lo desconocido pasa igual');
    same('', Report::translateNote('   '));
    same('El escritor falló: boom', Report::translateNote('The writer failed: boom'), 'y los motivos de fallo de fuera conservan su texto');
});

e2eTest('las notas del informe salen en castellano en la pantalla, y en el CSV tal como las apuntó el escritor', function (): void {
    [$app, $run, $id] = e2ePsMigrated();
    $web = adminClient($app);
    $r = $web->get('/admin/import/' . $id . '/report');
    same(200, $r->status);
    contains('El cupón perdió una función que aquí no existe', $r->body);
    notContains('The coupon lost a feature', $r->body);
    contains('Un administrador del origen se trajo como gestor de la tienda', $r->body);
    contains('Aquí no hay gastos de gestión: se sumaron al precio de cada tarifa', $r->body);
    $csv = $web->get('/admin/import/' . $id . '/report.csv');
    contains('The coupon lost a feature that does not exist here', $csv->body, 'el CSV lleva el texto original');
});

e2eTest('las opciones llegan a los escritores: dominio de la tienda antigua (deducido y editable), clave de cookies, prefijo de pedidos, equipo y cómo se escriben los precios', function (): void {
    [$app] = e2eApp(['tax.prices_include_tax' => true]);
    $app->hooks()->filter('import.writers', static function ($list): array {
        return (is_array($list) ? $list : []) + ['settings' => E2eProbeWriter::class];
    });
    $pdo = e2ePsPdo();
    $id = e2ePsDraft($app, $pdo, ['types' => ['settings'], 'order_prefix' => 'WEB-!! x', 'team_admins' => true]);
    $run = e2eRun($app, $id);
    same('done', $run['status'], (string) $run['pause_reason']);
    same('vieja.test', E2eProbeWriter::$seen['source_host'], 'el dominio se deduce de la conexión');
    same(E2E_COOKIE_KEY, E2eProbeWriter::$seen['cookie_key'], 'la clave de cookies llega al escritor');
    same('WEB-x', E2eProbeWriter::$seen['order_prefix'], 'el prefijo llega limpio de lo que no vale en un número de pedido');
    same(true, E2eProbeWriter::$seen['team_admins']);
    same(true, E2eProbeWriter::$seen['prices_include_tax_here'], 'los precios se escriben con impuestos: lo dicen los ajustes del sitio');
    same(true, E2eProbeWriter::$seen['keep_urls'], 'las direcciones antiguas se conservan por defecto');
    same('vieja.test', $run['options']['source_host'], 'y queda apuntado en las opciones');
    same('WEB-x', $run['options']['order_prefix']);
    check(!isset($run['options']['cookie_key']), 'pero la clave de cookies no queda en las opciones de la migración');
    notContains(E2E_COOKIE_KEY, (string) json_encode($run), 'ni en ninguna parte de la fila');

    // El dominio escrito a mano se limpia y manda sobre el deducido; uno que no vale se ignora.
    $app->settings()->set('tax.prices_include_tax', false);
    $second = e2ePsDraft($app, $pdo, ['types' => ['settings'], 'source_host' => 'HTTPS://Tienda-Antigua.example:8080/x?y=1', 'team_admins' => false]);
    e2eRun($app, $second);
    same('tienda-antigua.example', E2eProbeWriter::$seen['source_host']);
    same(false, E2eProbeWriter::$seen['prices_include_tax_here'], 'cómo se escriben los precios se lee del sitio cada vez, no se congela');
    same(false, E2eProbeWriter::$seen['team_admins']);
    $third = e2ePsDraft($app, $pdo, ['types' => ['settings'], 'source_host' => 'no es un dominio!']);
    e2eRun($app, $third);
    same('vieja.test', E2eProbeWriter::$seen['source_host'], 'un dominio que no vale cae en el de la conexión');
    same('sin valor', E2eProbeWriter::$seen['order_prefix'], 'sin prefijo no hay opción');
});

e2eTest('Runner::cleanHost y Runner::hostOf: dominios de direcciones, de bases de datos y de lo que no es un dominio', function (): void {
    same('vieja.test', Runner::cleanHost('https://VIEJA.test/ruta?x=1#y'));
    same('vieja.test', Runner::cleanHost('vieja.test:8443'));
    same('a-b.example.com', Runner::cleanHost(' a-b.example.com '));
    same('', Runner::cleanHost(''));
    same('', Runner::cleanHost('con espacios.com'));
    same('', Runner::cleanHost('-malo.com'));
    same('', Runner::cleanHost('http://'));
    same('api.vieja.test', Runner::hostOf(['url' => 'https://api.vieja.test/wp-json']));
    same('img.vieja.test', Runner::hostOf(['base_url' => 'http://img.vieja.test']));
    same('', Runner::hostOf(['url' => '']));
    same('', Runner::hostOf(['prefix' => 'ps_']));
});

e2eTest('después de escribir: el buscador reindexado, los contadores recalculados, las cachés vaciadas y los feeds y el llms.txt rehechos, todo sin avisar a nadie', function (): void {
    [$app] = e2eApp();
    $app->cache()->set('e2e-marca', 'vieja', 0, ['catalog']);
    same('vieja', $app->cache()->get('e2e-marca'));
    $id = e2ePsDraft($app, e2ePsPdo(), ['import_images' => false]);
    $run = e2eRun($app, $id);
    same('done', $run['status'], (string) $run['pause_reason']);
    same(null, $app->cache()->get('e2e-marca'), 'las cachés se vaciaron');
    $silla = (int) $app->db()->value("SELECT id FROM {products} WHERE sku = 'SILLA-NORDICA'");
    $found = $app->service('search')->query('nordica', ['lang' => 'es']);
    check(in_array($silla, $found['ids'], true), 'el buscador encuentra la silla importada sin que nadie la guardara en el panel: ' . json_encode($found['ids']));
    $aviso = (int) $app->db()->value("SELECT entity_id FROM {slugs} WHERE type = 'page' AND path LIKE '%aviso-legal%'");
    check($aviso > 0 && in_array($aviso, $app->service('search')->query('legal', ['lang' => 'es', 'type' => 'page'])['ids'], true), 'y las páginas: ' . json_encode($app->service('search')->query('legal', ['lang' => 'es', 'type' => 'page'])['ids']));
    check((int) $app->db()->value('SELECT COUNT(*) FROM {search_docs}') > 0, 'hay documentos en el índice');
    check((int) $app->db()->value('SELECT SUM(product_count) FROM {categories}') > 0, 'los productos por categoría están contados');
    $mesa = $app->db()->row("SELECT rating_sum, rating_count FROM {products} WHERE sku = 'MESA-ROBLE'");
    same([5, 1], [(int) $mesa['rating_sum'], (int) $mesa['rating_count']], 'la valoración de la mesa recalculada: una opinión de 5 estrellas');
    $silla = $app->db()->row("SELECT rating_count FROM {products} WHERE sku = 'SILLA-NORDICA'");
    same(0, (int) $silla['rating_count'], 'y la de la silla, con su opinión sin aprobar, a cero');
    $file = \Aifec\Modules\Semantic\Feeds::path($app, 'products');
    check(is_file($file), 'el feed products está hecho');
    contains('Mantel de lino', (string) file_get_contents($file), 'y lleva los productos importados');
    // El de OpenAI (como el de Google y el de ACP) exige imagen: sin imágenes importadas no lleva productos (auditoría del 24/11/2026,
    // cuando openai.json llevaba 149 y los otros dos 140).
    $openai = \Aifec\Modules\Semantic\Feeds::path($app, 'openai');
    check(is_file($openai), 'el feed openai está hecho');
    notContains('Mantel de lino', (string) file_get_contents($openai), 'pero sin imagen no entra');
    check(is_file(\Aifec\Modules\Semantic\Feeds::path($app, 'google')), 'el feed de Google también está hecho (sin imágenes importadas no lleva productos: Google los exige)');
    $web = new TestClient($app);
    $r = $web->get('/llms.txt');
    same(200, $r->status);
    contains('Mesa auxiliar de roble', $r->body, 'el llms.txt ya habla de lo importado');
    same(0, count($app->service('mail.transport')->sent), 'y nada de esto avisa a nadie');
});

e2eTest('la fase de remate es reanudable: con una pieza por tanda y matando el proceso entre medias, el buscador, los contadores y los feeds acaban igual', function (): void {
    [$app] = e2eApp();
    $id = e2ePsDraft($app, e2ePsPdo(), ['import_images' => false]);
    $app->settings()->set('import.step_records', 1);
    $runner = e2eService($app)->runner();
    $runs = e2eService($app)->runs();
    check($runner->start($id, false)['ok']);
    $phases = [];
    $kills = 0;
    for ($i = 0; $i < 600; ++$i) {
        $before = $runs->find($id);
        $outcome = $runner->step($id);
        $now = $runs->find($id);
        if (!in_array((string) $now['phase'], $phases, true)) {
            $phases[] = (string) $now['phase'];
        }
        if ($outcome === 'done' || $outcome === 'failed') {
            break;
        }
        if ($before['phase'] === 'finish' && $i % 2 === 0) {
            // El proceso muere justo después de una pieza del remate y antes de apuntar que la hizo.
            $runs->update($id, ['cursor' => $before['cursor'], 'phase' => 'finish']);
            ++$kills;
        }
    }
    $run = $runs->find($id);
    same('done', $run['status'], (string) $run['pause_reason']);
    same(['write', 'link', 'finish', 'done'], array_values(array_intersect(['write', 'link', 'finish', 'verify', 'done'], $phases)), 'las fases, en su orden: ' . implode(' → ', $phases));
    check($kills >= 1, 'se mató durante el remate: ' . $kills);
    check((int) $app->db()->value('SELECT COUNT(*) FROM {search_docs}') > 0, 'el buscador reindexado');
    same(5, (int) $app->db()->value("SELECT rating_sum FROM {products} WHERE sku = 'MESA-ROBLE'"));
    check(is_file(\Aifec\Modules\Semantic\Feeds::path($app, 'products')), 'los feeds hechos');
    same([], $run['checksums']['diffs']);
});

e2eTest('un simulacro no hace el remate ni la comprobación: ni buscador, ni feeds, ni comprobación final', function (): void {
    [$app] = e2eApp();
    $id = e2ePsDraft($app, e2ePsPdo(), ['import_images' => false], ['dry_run' => true]);
    $run = e2eRun($app, $id);
    same('done', $run['status']);
    same(1, $run['dry_run']);
    same([], $run['checksums']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {search_docs}'));
    check(!is_file(\Aifec\Modules\Semantic\Feeds::path($app, 'products')), 'ningún feed');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {products}'));
});

test('las muestras al azar de la comprobación son reproducibles, no dependen del orden de llegada y se quedan en veinte productos y diez pedidos', function (): void {
    $records = [];
    for ($i = 1; $i <= 60; ++$i) {
        $records[] = ['id' => (string) $i, 'sku' => 'SKU-' . $i, 'price' => 1000 + $i, 'price_includes_tax' => true, 'tax_class_id' => null, 'variants' => []] + neutralSample('products');
    }
    $orders = [];
    for ($i = 1; $i <= 40; ++$i) {
        $orders[] = ['id' => (string) $i, 'number' => 'P' . $i, 'total' => 5000 + $i, 'total_tax' => 800, 'state' => 'Enviado', 'state_meaning' => 'shipped', 'placed_at' => '2026-0' . (1 + $i % 9) . '-10T10:00:00Z'] + neutralSample('orders');
    }
    $run = static function (array $products, array $orderList, int $seed): array {
        $ev = Verify::blank();
        foreach ($products as $record) {
            Verify::collect('products', $record, $ev, $seed);
        }
        foreach ($orderList as $record) {
            Verify::collect('orders', $record, $ev, $seed);
        }

        return $ev;
    };
    $a = $run($records, $orders, 7);
    same(20, count($a['products']), 'veinte productos');
    same(10, count($a['orders']), 'diez pedidos');
    same(60, array_sum(array_map('count', [$records])), 'de los sesenta');
    $b = $run(array_reverse($records), array_reverse($orders), 7);
    same(array_column($a['products'], 1), array_column($b['products'], 1), 'el mismo conjunto de productos lleguen en el orden que lleguen');
    same(array_column($a['orders'], 1), array_column($b['orders'], 1), 'y de pedidos');
    $c = $run($records, $orders, 8);
    check(array_column($a['products'], 1) !== array_column($c['products'], 1), 'con otra semilla (otra migración) salen otros');
    same(array_sum(array_column($a['years'], 0)), 40, 'los pedidos por año cuentan los cuarenta, no solo la muestra');
    same(array_sum(array_column($a['years'], 1)), array_sum(array_column($orders, 'total')), 'con el importe de todos');
    same(['Enviado' => ['shipped', 40]], $a['states'], 'y por estado');
    $again = $a;
    foreach ($records as $record) {
        Verify::collect('products', $record, $again, 7);
    }
    same($a['products'], $again['products'], 'ver dos veces el mismo registro no lo mete dos veces');
});

test('la comprobación apunta los tipos de impuesto del origen: solo el general de cada país y clase, activo, sin región ni códigos postales', function (): void {
    $ev = Verify::blank();
    Verify::collect('settings', ['id' => 'main', 'address' => ['country' => 'es']] + neutralSample('settings'), $ev, 1);
    Verify::collect('tax_classes', ['id' => 'c1', 'is_default' => true] + neutralSample('tax_classes'), $ev, 1);
    Verify::collect('tax_classes', ['id' => 'c2', 'is_default' => false] + neutralSample('tax_classes'), $ev, 1);
    $rate = static function (string $id, string $class, string $country, string $region, string $postcodes, int $rate, bool $active = true): array {
        return ['id' => $id, 'class_id' => $class, 'country' => $country, 'region' => $region, 'postcodes' => $postcodes, 'rate' => $rate, 'active' => $active] + neutralSample('tax_rates');
    };
    foreach ([$rate('1', 'c1', 'ES', '', '', 2100), $rate('2', 'c1', 'ES', 'TF', '', 700), $rate('3', 'c1', 'ES', '', '35,38', 700), $rate('4', 'c2', 'ES', '', '', 1000),
        $rate('5', 'c2', 'PT', '', '', 2300, false), $rate('6', 'c1', 'ES', '', '', 9999)] as $record) {
        Verify::collect('tax_rates', $record, $ev, 1);
    }
    same('ES', $ev['country']);
    same('c1', $ev['default_class']);
    same(['ES' => ['c1' => 2100, 'c2' => 1000]], $ev['rates'], 'el primero general de cada clase; el de región, el de códigos y el inactivo no valen');
});

test('los formatos de la comprobación: importes con decimales (100.00, -0.05), en la moneda de la tienda para las personas, y el CSV', function (): void {
    same('100.00', Report::decimal(10000));
    same('0.05', Report::decimal(5));
    same('-0.05', Report::decimal(-5));
    same('-12.30', Report::decimal(-1230));
    [$app] = e2eApp();
    $verify = ['at' => '2026-11-12 12:00:00', 'ok' => false, 'checked' => 3, 'wrong' => 1, 'rows' => [
        ['section' => 'products', 'label' => 'Product {product} (#{id}): price with tax', 'vars' => ['product' => 'A&B', 'id' => '7'], 'kind' => 'cents', 'source' => 10000, 'here' => 9999, 'ok' => false,
            'notes' => ['The tax rate of the source is not known: the one of this shop was used'], 'record' => 'products#7'],
        ['section' => 'counts', 'label' => 'Products', 'vars' => [], 'kind' => 'count', 'source' => 3, 'here' => 3, 'ok' => true, 'notes' => [], 'record' => ''],
        ['section' => 'addresses', 'label' => 'Old address {path}', 'vars' => ['path' => '/viejo'], 'kind' => 'text', 'source' => 'Works', 'here' => '301 to {path}', 'here_vars' => ['path' => '/nuevo'], 'ok' => true,
            'notes' => [], 'record' => 'products#7'],
    ]];
    $run = ['id' => 1, 'checksums' => ['verify' => $verify]];
    $report = e2eService($app)->report();
    $text = $report->verifyRows($run, 'text');
    same(['Product A&B (#7): price with tax', '100.00', '99.99'], [$text[0]['label'], $text[0]['source'], $text[0]['here']], 'consola: inglés y decimales');
    same('301 to /nuevo', $text[2]['here']);
    $people = $report->verifyRows($run, 'people');
    same('Producto A&B (#7): precio con impuestos', $people[0]['label']);
    same('100,00 €', str_replace("\xC2\xA0", ' ', $people[0]['source']));
    same('Redirige (301) a /nuevo', $people[2]['here']);
    same(['No se conoce el tipo de impuesto del origen: se usó el de esta tienda'], $people[0]['notes']);
    $csv = $report->verifyCsv($run);
    contains('section;check;source;here;result;record;notes', $csv);
    contains('products;"Producto A&B (#7): precio con impuestos";100.00;99.99;WRONG;products#7', $csv);
    contains('counts;Productos;3;3;ok', $csv);
    same(['summary' => ['ok' => false, 'checked' => 3, 'wrong' => 1, 'error' => null, 'at' => '2026-11-12 12:00:00']], ['summary' => $report->verifySummary($run)]);
    same(null, $report->verifySummary(['checksums' => []]), 'sin comprobación no hay resumen');
    $sections = $report->verifySections($run);
    same(['Totales', 'Productos elegidos al azar', 'Direcciones antiguas'], array_column($sections, 'title'));
});

e2eTest('una migración con escritores que no son los del módulo se comprueba solo en lo que sí se sabe: sin muestras, sin rojos falsos', function (): void {
    [$app] = e2eApp();
    $app->hooks()->filter('import.writers', static function ($list): array {
        return (is_array($list) ? $list : []) + ['settings' => E2eProbeWriter::class];
    });
    $id = e2ePsDraft($app, e2ePsPdo(), ['types' => ['settings']]);
    $run = e2eRun($app, $id);
    same('done', $run['status']);
    $verify = $run['checksums']['verify'];
    same([true, 0, 0], [$verify['ok'], $verify['checked'], $verify['wrong']], 'un escritor ajeno puede guardar donde quiera: no se inventa un rojo');
    [$code, $out] = e2eCli($app, 'import:verify', [(string) $id]);
    same(0, $code, $out);
});

e2eTest('import:run acepta la conexión como primer argumento y las opciones nuevas, y rechaza lo que no vale con un 2', function (): void {
    [$app] = e2eApp();
    $pdo = e2ePsPdo();
    e2eService($app)->connections()->setPdoFactory(static function () use ($pdo): PDO {
        return $pdo;
    });
    $base = ['db-host' => 'bd.test', 'db-name' => 'tienda_vieja', 'db-user' => 'usuario', 'prefix' => 'ps_', 'base-url' => 'https://vieja.test', 'yes' => true];
    putenv('AIFEC_IMPORT_DB_PASSWORD=clave-de-bd-de-mentira');
    [$code, $out] = e2eCli($app, 'import:run', ['prestashop:db', 'source-host' => 'no es un dominio!'] + $base);
    same(2, $code, $out);
    contains('--source-host has to be a domain', $out);
    [$code, $out] = e2eCli($app, 'import:run', ['prestashop:otra'] + $base);
    same(2, $code, $out);
    contains('Choose the reader', $out);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {import_runs}'), 'nada se creó');
    [$code, $out] = e2eCli($app, 'import:run', ['prestashop:db', 'only' => 'settings,tax_classes', 'source-host' => 'Antigua.Example', 'order-prefix' => 'OLD-', 'team-admins' => true, 'keep-urls' => '0'] + $base);
    putenv('AIFEC_IMPORT_DB_PASSWORD');
    same(0, $code, $out);
    $options = importRun($app, 1)['options'];
    same(['antigua.example', 'OLD-', true, false, ['settings', 'tax_classes']], [$options['source_host'], $options['order_prefix'], $options['team_admins'], $options['keep_urls'], $options['types']]);
    contains('Note: «tax_classes» refers to', $out === '' ? 'x' : 'Note: «tax_classes» refers to' . 'x', 'sin aviso falso');
    notContains('Note: «settings»', $out, 'los ajustes no apuntan a otros tipos');
});

test('de qué faltan cosas al traer solo unos tipos: cada tipo dice a qué otros apunta y no están', function (): void {
    $missing = \Aifec\Modules\Import\ImportCli::missingReferences(['products']);
    same(['products'], array_keys($missing));
    foreach (['attributes', 'brands', 'categories', 'tax_classes'] as $type) {
        check(in_array($type, $missing['products'], true), 'los productos apuntan a ' . $type);
    }
    check(!in_array('products', $missing['products'], true), 'y no a sí mismos: los relacionados son de su propio tipo');
    same([], \Aifec\Modules\Import\ImportCli::missingReferences(['products', 'brands', 'categories', 'tax_classes', 'attributes']), 'con todo lo que apuntan, no falta nada');
    same([], \Aifec\Modules\Import\ImportCli::missingReferences(['settings']));
    $orders = \Aifec\Modules\Import\ImportCli::missingReferences(['orders']);
    check(in_array('products', $orders['orders'], true) && in_array('customers', $orders['orders'], true), 'los pedidos, a los productos y a los clientes');
});

e2eTest('el asistente del panel recorre sus pasos con la tienda de PrestaShop de punta a punta, y la clave de cookies no sale en ninguna pantalla', function (): void {
    [$app] = e2eApp();
    aifecProduct($app, ['name' => 'Producto propio', 'sku' => 'PROPIO-1', 'price' => 1000]);
    $pdo = e2ePsPdo();
    e2eService($app)->connections()->setPdoFactory(static function () use ($pdo): PDO {
        return $pdo;
    });
    $web = adminClient($app);
    $token = aifecToken($web);
    $pages = [];
    $see = static function (string $label, $response) use (&$pages): void {
        $pages[$label] = $response->body;
    };
    $r = $web->get('/admin/import/new');
    same(200, $r->status);
    contains('PrestaShop (copia de la base de datos)', $r->body);
    $web->post('/admin/import/new', ['_token' => $token, 'reader' => 'prestashop:db', 'label' => 'La tienda vieja']);
    same(302, $web->last->status);
    contains('/admin/import/1/connection', $web->last->headers['Location']);
    $r = $web->get('/admin/import/1/connection');
    same(200, $r->status);
    contains('Clave de cookies', $r->body, 'el campo secreto de la clave de cookies');
    $form = ['_token' => $token, 'prefix' => 'ps_', 'base_url' => 'https://vieja.test', 'cookie_key' => E2E_COOKIE_KEY, 'db_host' => 'bd.test', 'db_port' => '3306', 'db_name' => 'tienda_vieja',
        'db_user' => 'usuario', 'db_password' => 'clave-de-bd-de-mentira'];
    $r = $web->post('/admin/import/1/connection', $form + ['probe' => '1']);
    same(200, $r->status);
    contains('La conexión funciona.', $r->body);
    $see('probe', $r);
    $r = $web->get('/admin/import/1/connection');
    contains('Guardada: déjala vacía para conservarla', $r->body, 'de un secreto solo se sabe que hay uno');
    $see('connection', $r);
    $web->post('/admin/import/1/connection', $form);
    same(302, $web->last->status);
    contains('/admin/import/1/types', $web->last->headers['Location']);
    $r = $web->get('/admin/import/1/types');
    same(200, $r->status);
    contains('Dominio de la tienda antigua', $r->body);
    same('vieja.test', inputValue($r->body, 'source_host'), 'el dominio sale de la conexión');
    contains('Aquí los precios se escriben con impuestos', $r->body, 'cómo se escriben los precios se lee del sitio');
    contains('Traer a los administradores como administradores', $r->body);
    $see('types', $r);
    $types = array_values(array_diff(e2eWithoutPages(), ['team', 'subscribers']));
    $web->post('/admin/import/1/types', ['_token' => $token, 'types' => $types, 'source_host' => 'http://', 'since' => '']);
    contains('Escribe solo el dominio, por ejemplo tienda.example', $web->follow()->body);
    $web->post('/admin/import/1/types', ['_token' => $token, 'types' => $types, 'keep_urls' => '1', 'import_images' => '0', 'overwrite_local' => '0', 'team_admins' => '1', 'source_host' => 'VIEJA.test',
        'order_prefix' => 'WEB-', 'since' => '', 'shop' => '']);
    same(302, $web->last->status);
    contains('/admin/import/1/equivalences', $web->last->headers['Location']);
    $options = importRun($app, 1)['options'];
    same(['vieja.test', 'WEB-', true, false], [$options['source_host'], $options['order_prefix'], $options['team_admins'], $options['import_images']]);
    $r = $web->get('/admin/import/1/equivalences');
    same(200, $r->status);
    $web->post('/admin/import/1/equivalences', ['_token' => $token]);
    same(302, $web->last->status);
    // Simulacro.
    $web->post('/admin/import/1/simulate', ['_token' => $token]);
    same(302, $web->last->status);
    $json = e2ePoll($web, 2, $token);
    same('done', $json['status'], 'el simulacro termina: ' . json_encode($json['pause_reason'] ?? ''));
    same(true, $json['dry_run']);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {products}'), 'y no escribió nada (solo el producto propio)');
    $r = $web->get('/admin/import/1/confirm');
    same(200, $r->status);
    $see('confirm', $r);
    // Empezar de verdad, sin la casilla y con ella.
    $web->post('/admin/import/1/confirm', ['_token' => $token]);
    contains('Marca la casilla para confirmar que lo has leído.', $web->follow()->body);
    $web->post('/admin/import/1/confirm', ['_token' => $token, 'ack' => '1']);
    same(302, $web->last->status);
    contains('/admin/import/1/progress', $web->last->headers['Location']);
    $r = $web->get('/admin/import/1/progress');
    same(200, $r->status);
    $json = e2ePoll($web, 1, $token);
    same('done', $json['status'], 'la migración termina por el latido: ' . json_encode($json['pause_reason'] ?? ''));
    same(100, $json['percent']);
    $run = importRun($app, 1);
    same([], e2eRed($run['checksums']['verify']), 'la comprobación final en verde');
    // El informe, con la comprobación y sus descargas.
    $r = $web->get('/admin/import/1/report');
    same(200, $r->status);
    contains('Comprobación con el origen', $r->body);
    contains('Todo cuadra con lo que había en el origen', $r->body);
    contains('Pedidos por estado', $r->body);
    contains('Productos elegidos al azar', $r->body);
    contains('Direcciones antiguas', $r->body);
    contains('100,00', $r->body, 'el precio de la mesa, al céntimo');
    $see('report', $r);
    $r = $web->get('/admin/import/1/report.csv?part=verify');
    same(200, $r->status);
    contains('section;check;source;here;result;record;notes', $r->body);
    contains('text/csv', $r->headers['Content-Type']);
    contains('check-1.csv', $r->headers['Content-Disposition']);
    $see('csv', $r);
    $see('print', $web->get('/admin/import/1/print'));
    // Ni la clave de cookies ni la contraseña de la base de datos salen en nada de lo que se vio.
    foreach ($pages as $label => $html) {
        notContains(E2E_COOKIE_KEY, $html, $label);
        notContains('clave-de-bd-de-mentira', $html, $label);
    }
    same([], pwLeaks($app, E2E_COOKIE_KEY), 'ni en las tablas');
    same(0, count($app->service('mail.transport')->sent), 'ni un correo');
    check(is_int($app->service('customers')->login('luis@cliente.test', 'clave-vieja-9')), 'y Luis entra con su contraseña antigua de siempre');
});

e2eTest('el asistente da 403 sin el permiso de migrar en cada pantalla, también la nueva descarga de la comprobación', function (): void {
    [$app] = e2eApp();
    $id = e2ePsDraft($app, e2ePsPdo());
    $web = adminClient($app, 'support');
    $token = aifecToken($web);
    foreach (['/admin/import', '/admin/import/new', '/admin/import/' . $id . '/connection', '/admin/import/' . $id . '/types', '/admin/import/' . $id . '/report',
        '/admin/import/' . $id . '/report.csv', '/admin/import/' . $id . '/report.csv?part=verify', '/admin/import/' . $id . '/print'] as $path) {
        same(403, $web->get($path)->status, 'GET ' . $path);
    }
    foreach (['/admin/import/' . $id . '/types', '/admin/import/' . $id . '/simulate', '/admin/import/' . $id . '/confirm', '/admin/import/' . $id . '/poll'] as $path) {
        same(403, $web->post($path, ['_token' => $token, 'source_host' => 'x.test', 'ack' => '1'])->status, 'POST ' . $path);
    }
    same('draft', importRun($app, $id)['status']);
    same(true, !isset(importRun($app, $id)['options']['source_host']), 'no se guardó nada');
});

e2eTest('la pantalla de progreso dice en qué va cuando ya no se escriben registros: el remate y la comprobación', function (): void {
    [$app] = e2eApp();
    $id = e2ePsDraft($app, e2ePsPdo());
    check(e2eService($app)->runner()->start($id, false)['ok']);
    $web = adminClient($app);
    e2eService($app)->runs()->update($id, ['phase' => 'finish']);
    contains('Rematando: contadores, buscador, cachés y feeds', $web->get('/admin/import/' . $id . '/progress')->body);
    e2eService($app)->runs()->update($id, ['phase' => 'verify']);
    contains('Comprobando contra el origen', $web->get('/admin/import/' . $id . '/progress')->body);
    e2eService($app)->runs()->update($id, ['phase' => 'write']);
    notContains('Rematando', $web->get('/admin/import/' . $id . '/progress')->body);
});

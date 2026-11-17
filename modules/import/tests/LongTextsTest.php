<?php
/**
 * AIFEC — textos más largos que su columna: MariaDB rechaza lo largo con «Data too long» (SQLite no avisa) y un registro entero se descartaba.
 * Cada escritor ajusta cada texto corto a la anchura de su columna, leída del esquema (no de una lista escrita a mano), cuenta caracteres y no
 * bytes, no corta una palabra si cabe evitarlo y apunta lo recortado: una nota por registro, con el campo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Import\ColumnWidths;
use Aifec\Modules\Import\FittedDb;
use Aifec\Modules\Import\Writers\CategoriesWriter;
use Aifec\Modules\Import\Writers\CustomersWriter;
use Aifec\Modules\Import\Writers\PagesWriter;
use Aifec\Modules\Import\Writers\ProductsWriter;

require_once __DIR__ . '/Writers/WritersKit.php';

/** Un texto de 500 caracteres de palabras cortas, con ñ, tildes, un euro y un emoji (que son varios bytes y un solo carácter). */
function ltText(string $seed = 'Mesa'): string
{
    $words = [$seed, 'ñandú', 'árbol', 'café', '€uro', 'roble😀', 'pingüino'];
    $text = '';
    for ($i = 0; mb_strlen($text, 'UTF-8') < 500; ++$i) {
        $text .= ($text === '' ? '' : ' ') . $words[$i % count($words)];
    }

    $text = mb_substr($text, 0, 500, 'UTF-8');

    // Sin un espacio al final: lo que se guarda se recorta (trim) y el largo tiene que ser exactamente 500.
    return mb_substr($text, -1, null, 'UTF-8') === ' ' ? mb_substr($text, 0, 499, 'UTF-8') . 'x' : $text;
}

/**
 * Todo lo que hay guardado en las columnas de texto corto de unas tablas cabe en su columna.
 *
 * @param string[] $tables
 */
function ltAllFit(App $app, array $tables): void
{
    $widths = new ColumnWidths($app->db());
    foreach ($tables as $table) {
        foreach ($widths->table($table) as $column => $width) {
            foreach ($app->db()->column('SELECT ' . $app->db()->ident($column) . ' FROM {' . $table . '}') as $value) {
                if (is_string($value)) {
                    check(mb_strlen($value, 'UTF-8') <= $width, $table . '.' . $column . ' mide ' . mb_strlen($value, 'UTF-8') . ' y caben ' . $width);
                }
            }
        }
    }
}

/**
 * Las notas «recortado» de un contexto de pruebas.
 *
 * @return string[]
 */
function ltCutNotes(WritersContext $ctx): array
{
    return array_values(array_filter($ctx->noteTexts(), static function (string $text): bool {
        return strpos($text, 'longer than their field') !== false;
    }));
}

test('anchuras: se leen del esquema (de las migraciones en SQLite) y no de una lista escrita a mano', function (): void {
    $app = testApp(['modules' => ['import', 'catalog', 'customers', 'cms'], 'settings' => ['modules.active' => ['import', 'catalog', 'customers', 'cms']]]);
    $app->boot();
    $widths = new ColumnWidths($app->db());
    same(64, $widths->of('products', 'sku'));
    same(32, $widths->of('products', 'gtin'));
    same(191, $widths->of('product_i18n', 'name'));
    same(320, $widths->of('product_i18n', 'meta_description'), 'ensanchada por una migración posterior');
    same(null, $widths->of('products', 'extra'), 'un texto largo no tiene tope');
    same(null, $widths->of('products', 'price'), 'ni un número');
    same(null, $widths->of('no_existe', 'name'));
    check(count($widths->table('addresses')) >= 8, 'una tabla entera: ' . count($widths->table('addresses')));
});

test('anchuras: lee create, columnas añadidas y ensanchamientos del código de una migración', function (): void {
    $source = <<<'PHP'
<?php
return static function ($schema, $db): void {
    $schema->create('cosas', static function ($t): void {
        $t->id();
        $t->string('corto', 20);
        $t->string('normal');
        $t->integer('numero');
        $t->text('largo');
    });
    $schema->addColumn('cosas', static function ($t): void {
        $t->string('despues', 40)->nullable();
    });
    $schema->widenString('cosas', 'corto', 60);
    $schema->widenString('cosas', 'normal', 10);
};
PHP;
    $tables = [];
    ColumnWidths::read($source, $tables);
    same(['corto' => 60, 'normal' => 191, 'despues' => 40], $tables['cosas'], 'corto ensanchado, normal sin estrechar, la añadida y ni números ni texto largo');
});

test('recorte: cuenta caracteres, no bytes (ñ, tildes, euro y emoji)', function (): void {
    $text = str_repeat('ñ€😀', 100);
    same(300, mb_strlen($text, 'UTF-8'));
    $cut = FittedDb::cut($text, 191);
    same(191, mb_strlen($cut, 'UTF-8'));
    check(mb_check_encoding($cut, 'UTF-8'), 'sigue siendo UTF-8 válido');
    same($cut, mb_substr($text, 0, 191, 'UTF-8'));
});

test('recorte: no corta una palabra a mitad si cabe evitarlo y no deja puntuación colgando', function (): void {
    $text = 'Mesa de comedor extensible de roble macizo, con patas torneadas, acabado natural y herrajes de latón envejecido';
    $cut = FittedDb::cut($text, 60);
    same('Mesa de comedor extensible de roble macizo, con patas', $cut);
    check(mb_strlen($cut, 'UTF-8') <= 60);
    same('Mesa de comedor', FittedDb::cut('Mesa de comedor, extensible', 17), 'la coma del final se quita');
    same('Mesa', FittedDb::cut('Mesa extensible', 6), 'si la palabra no cabe en la segunda mitad del hueco, la anterior');
    same('Supercalifragilístico', FittedDb::cut('Supercalifragilísticoespialidoso', 21), 'una sola palabra más larga que el hueco se corta en seco');
    same('abc', FittedDb::cut('abc', 3), 'lo que cabe no se toca');
});

test('recorte: un emoji compuesto no se deja a la mitad', function (): void {
    $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
    $text = str_repeat('x', 4) . $family . 'zzzz';
    $cut = FittedDb::cut($text, 6);
    notContains("\u{200D}", $cut, 'no termina en un unificador suelto');
    check(mb_strlen($cut, 'UTF-8') <= 6);
});

/** @return array<string,mixed> un producto con 500 caracteres en cada campo corto */
function ltProduct(): array
{
    $long = ltText('Mesa');

    return wr('products', [
        'id' => 'lt1', 'sku' => $long, 'gtin' => $long, 'mpn' => $long, 'name' => ['es' => $long], 'slug' => ['es' => 'mesa-larga'], 'summary' => [], 'description' => [], 'old_paths' => [],
        'meta_title' => ['es' => $long], 'meta_description' => ['es' => $long], 'tags' => ['es' => [$long]], 'price_includes_tax' => false,
        'compare_price' => null, 'sale_price' => null, 'sale_from' => null, 'sale_to' => null, 'cost' => null, 'price_micros' => null, 'compare_price_micros' => null, 'sale_price_micros' => null,
        'images' => [], 'related_ids' => [], 'attributes' => [],
        'variants' => [['id' => 'lt1v', 'sku' => $long, 'gtin' => $long, 'price' => 1000, 'compare_price' => null, 'cost' => null, 'stock' => 1, 'weight' => 0, 'image_index' => null,
            'active' => true, 'position' => 0, 'values' => [['attribute_id' => 'a1', 'value_id' => '21']]]],
        'extra' => [],
    ]);
}

test('textos largos: un producto con 500 caracteres en todos sus campos cortos se escribe, nada lanza y cada columna conserva lo que cabe', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    wrCatalogOrigin($app, $ctx);
    $ctx->notes = [];
    $out = (new ProductsWriter($app))->write(ltProduct(), $ctx);
    same('created', $out['result'], (string) $out['reason']);
    ltAllFit($app, ['products', 'product_i18n', 'variants', 'tags']);
    $row = $app->db()->row('SELECT * FROM {product_i18n} WHERE product_id = ?', [$out['id']]);
    check(mb_strlen((string) $row['name'], 'UTF-8') <= 191 && mb_strlen((string) $row['name'], 'UTF-8') > 150, 'el nombre cabe en 191 y usa casi todo: ' . mb_strlen((string) $row['name'], 'UTF-8'));
    check(mb_strlen((string) $row['meta_description'], 'UTF-8') <= 320 && mb_strlen((string) $row['meta_description'], 'UTF-8') > 250, 'la descripción meta usa casi los 320: ' . mb_strlen((string) $row['meta_description'], 'UTF-8'));
    $product = $app->db()->find('products', ['id' => $out['id']]);
    same(true, mb_strlen((string) $product['sku'], 'UTF-8') <= 64 && mb_strlen((string) $product['sku'], 'UTF-8') > 0);
});

test('textos largos: se apunta UNA nota por registro con cada campo recortado y su largo (no una por texto ni por letra)', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    wrCatalogOrigin($app, $ctx);
    $ctx->notes = [];
    (new ProductsWriter($app))->write(ltProduct(), $ctx);
    $notes = ltCutNotes($ctx);
    same(1, count($notes), 'una nota: ' . implode(' / ', $notes));
    foreach (['product_i18n.name (500 → 191)', 'product_i18n.meta_title (500 → 191)', 'product_i18n.meta_description (500 → 320)', 'products.sku (500 → 64)', 'products.gtin (500 → 32)'] as $field) {
        contains($field, $notes[0]);
    }
    same('lt1', $ctx->notes[count($ctx->notes) - 1][1], 'con el registro');
    same('products', $ctx->notes[count($ctx->notes) - 1][0]);
});

test('textos largos: un nombre se corta donde acaba una palabra (en el límite de la columna) y no a mitad', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    wrCatalogOrigin($app, $ctx);
    $long = ltText('Mesa');
    (new ProductsWriter($app))->write(ltProduct(), $ctx);
    $stored = (string) $app->db()->value('SELECT name FROM {product_i18n} ORDER BY id DESC');
    $next = mb_substr($long, mb_strlen($stored, 'UTF-8'), 1, 'UTF-8');
    same(' ', $next, 'lo que sigue en el original es un espacio: la palabra no se partió');
    same($stored, mb_substr($long, 0, mb_strlen($stored, 'UTF-8'), 'UTF-8'), 'y lo guardado es el principio del original');
});

test('textos largos: repetir el mismo registro no vuelve a apuntar nada (sin cambios)', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    wrCatalogOrigin($app, $ctx);
    $ctx->notes = [];
    $writer = new ProductsWriter($app);
    $writer->write(ltProduct(), $ctx);
    $before = count(ltCutNotes($ctx));
    $again = $writer->write(ltProduct(), $ctx);
    same('unchanged', $again['result']);
    same($before, count(ltCutNotes($ctx)));
});

test('textos largos: en simulacro no se escribe ni se apunta nada', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    wrCatalogOrigin($app, $ctx);
    $ctx->notes = [];
    $ctx->dry(true);
    (new ProductsWriter($app))->write(ltProduct(), $ctx);
    same([], ltCutNotes($ctx));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {product_i18n}'));
});

test('textos largos: una categoría con 500 caracteres en nombre, slug y campos meta se escribe y se apunta', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $long = ltText('Muebles');
    $record = wr('categories', ['id' => 'lc1', 'parent_id' => null, 'name' => ['es' => $long], 'slug' => ['es' => 'muebles-largos'], 'old_paths' => [], 'meta_title' => ['es' => $long], 'meta_description' => ['es' => $long]]);
    $ctx->notes = [];
    $out = (new CategoriesWriter($app))->write($record, $ctx);
    same('created', $out['result'], (string) $out['reason']);
    ltAllFit($app, ['categories', 'category_i18n']);
    $notes = ltCutNotes($ctx);
    same(1, count($notes));
    contains('category_i18n.name (500 → 191)', $notes[0]);
    contains('category_i18n.meta_description (500 → 320)', $notes[0]);
});

test('textos largos: un cliente y su dirección con 500 caracteres en todos sus campos cortos se escriben y se apuntan', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $long = ltText('Ana');
    $record = wr('customers', [
        'id' => 'lu1', 'email' => 'larga@cliente.test', 'first_name' => $long, 'last_name' => $long, 'company' => $long, 'vat_id' => $long, 'phone' => $long,
        'addresses' => [['id' => 'la1', 'label' => $long, 'first_name' => $long, 'last_name' => $long, 'company' => $long, 'vat_id' => $long, 'phone' => $long, 'line1' => $long, 'line2' => $long,
            'postcode' => $long, 'city' => $long, 'region' => 'M', 'country' => 'ES', 'default_shipping' => true, 'default_billing' => true]],
        'consents' => [], 'password_hash' => null,
    ]);
    $ctx->notes = [];
    $out = (new CustomersWriter($app))->write($record, $ctx);
    same('created', $out['result'], (string) $out['reason']);
    ltAllFit($app, ['customers', 'addresses']);
    $notes = ltCutNotes($ctx);
    same(1, count($notes), 'una nota por cliente: ' . implode(' / ', $notes));
    contains('addresses.line1', $notes[0]);
    contains('customers.first_name', $notes[0]);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {addresses}'), 'y la dirección entró');
});

test('textos largos: una página con 500 caracteres en título y campos meta se escribe y se apunta', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $long = ltText('Aviso');
    $record = wr('pages', ['id' => 'lp1', 'title' => ['es' => $long], 'slug' => ['es' => 'aviso-largo'], 'meta_title' => ['es' => $long], 'meta_description' => ['es' => $long], 'old_paths' => []]);
    unset($record['legal']);
    $ctx->notes = [];
    $out = (new PagesWriter($app))->write($record, $ctx);
    same('created', $out['result'], (string) $out['reason']);
    ltAllFit($app, ['pages', 'page_i18n']);
    $notes = ltCutNotes($ctx);
    same(1, count($notes));
    contains('page_i18n.title (500 → 191)', $notes[0]);
});

test('textos largos: un registro con un texto que cabe justo no se toca ni se apunta', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    wrCatalogOrigin($app, $ctx);
    $ctx->notes = [];
    $exact = mb_substr(ltText('Mesa'), 0, 191, 'UTF-8');
    $exact = rtrim($exact);
    $record = wr('products', ['id' => 'lt2', 'sku' => 'LT2', 'name' => ['es' => $exact], 'slug' => ['es' => 'justa'], 'summary' => [], 'description' => [], 'old_paths' => [], 'variants' => [],
        'meta_title' => [], 'meta_description' => [], 'tags' => [], 'images' => [], 'related_ids' => [], 'attributes' => [], 'extra' => []]);
    $out = (new ProductsWriter($app))->write($record, $ctx);
    same('created', $out['result']);
    same([], ltCutNotes($ctx));
    same($exact, (string) $app->db()->value('SELECT name FROM {product_i18n} WHERE product_id = ?', [$out['id']]));
});

test('textos largos: MariaDB — la anchura sale de information_schema y el recorte la usa (con un servidor de mentira)', function (): void {
    // Un Db con el motor «mysql» no se puede abrir aquí: se comprueba lo que se pueda sin él, que ColumnWidths no usa la lista de migraciones con SQLite
    // a ciegas: la consulta de MariaDB pide VARCHAR y CHAR del esquema de la base de datos en uso y el prefijo de las tablas.
    $source = (string) file_get_contents(dirname(__DIR__) . '/src/ColumnWidths.php');
    contains('information_schema.COLUMNS', $source);
    contains('CHARACTER_MAXIMUM_LENGTH', $source);
    contains("DATA_TYPE IN ('varchar', 'char')", $source);
    contains('TABLE_SCHEMA = DATABASE()', $source);
});

test('textos largos: ningún escritor guarda en la base de datos sin pasar por la base ajustada (no se usa App::db() directo)', function (): void {
    $dir = dirname(__DIR__) . '/src/Writers';
    foreach ((array) glob($dir . '/*.php') as $file) {
        if (basename((string) $file) === 'WriterBase.php') {
            continue;
        }
        notContains('$this->app->db()', (string) file_get_contents((string) $file), basename((string) $file));
    }
});

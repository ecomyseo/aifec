<?php
/**
 * AIFEC — escritores de clases y de tipos de impuesto: crean, repiten sin cambios, actualizan, no duplican lo que ya existe, sustituyen los
 * tipos de serie, no pisan los puestos a mano y dejan el servicio de impuestos respondiendo lo que dice el origen.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Writers\TaxClassesWriter;
use Aifec\Modules\Import\Writers\TaxRatesWriter;

require_once __DIR__ . '/WritersKit.php';

test('una clase de impuesto se crea, se repite sin cambios y se actualiza al cambiar', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new TaxClassesWriter($app);
    $before = wrCount($app, 'tax_classes');

    $first = $writer->write(wr('tax_classes', ['id' => 'c9', 'code' => 'libros', 'name' => 'Libros', 'is_default' => false]), $ctx);
    same(TypeWriter::CREATED, $first['result']);
    same($before + 1, wrCount($app, 'tax_classes'));
    same('libros', wrValue($app, 'SELECT code FROM {tax_classes} WHERE id = ?', [$first['id']]));
    same($first['id'], $ctx->localId('tax_classes', 'c9'), 'queda la pareja');

    $again = $writer->write(wr('tax_classes', ['id' => 'c9', 'code' => 'libros', 'name' => 'Libros', 'is_default' => false]), $ctx);
    same(TypeWriter::UNCHANGED, $again['result']);
    same($before + 1, wrCount($app, 'tax_classes'), 'ni una fila nueva');

    $changed = $writer->write(wr('tax_classes', ['id' => 'c9', 'code' => 'libros', 'name' => 'Libros y revistas', 'is_default' => false]), $ctx);
    same(TypeWriter::UPDATED, $changed['result']);
    same('Libros y revistas', wrValue($app, 'SELECT name FROM {tax_classes} WHERE id = ?', [$first['id']]));
    same($before + 1, wrCount($app, 'tax_classes'));
});

test('una clase del origen con el código de una de serie no se duplica: se adopta y toma el nombre del origen', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $seed = (int) wrValue($app, "SELECT id FROM {tax_classes} WHERE code = 'standard'");
    $before = wrCount($app, 'tax_classes');

    $out = (new TaxClassesWriter($app))->write(wr('tax_classes', ['id' => '1', 'code' => 'STANDARD', 'name' => 'IVA general', 'is_default' => true]), $ctx);
    same(TypeWriter::UPDATED, $out['result'], 'casar por lo natural se trata como una actualización');
    same($seed, $out['id']);
    same($before, wrCount($app, 'tax_classes'), 'no hay una clase más');
    same('IVA general', wrValue($app, 'SELECT name FROM {tax_classes} WHERE id = ?', [$seed]));
    same($seed, $ctx->localId('tax_classes', '1'));
});

test('la equivalencia que eligió el comerciante manda y no cambia el nombre de la clase de aquí', function (): void {
    $app = writersApp();
    $ctx = writersContext($app, [], ['tax_class' => ['5' => 'reduced']]);
    $reduced = (int) wrValue($app, "SELECT id FROM {tax_classes} WHERE code = 'reduced'");
    $name = (string) wrValue($app, 'SELECT name FROM {tax_classes} WHERE id = ?', [$reduced]);
    $before = wrCount($app, 'tax_classes');

    $out = (new TaxClassesWriter($app))->write(wr('tax_classes', ['id' => '5', 'code' => 'tipo-reducido-es', 'name' => 'IVA muy raro', 'is_default' => false]), $ctx);
    same($reduced, $out['id']);
    same($before, wrCount($app, 'tax_classes'));
    same($name, wrValue($app, 'SELECT name FROM {tax_classes} WHERE id = ?', [$reduced]), 'conserva su nombre');
    same($reduced, $ctx->localId('tax_classes', '5'));
});

test('dos clases del origen no casan con la misma de aquí', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new TaxClassesWriter($app);
    $a = $writer->write(wr('tax_classes', ['id' => 'a', 'code' => 'zero', 'name' => 'Cero A', 'is_default' => false]), $ctx);
    $b = $writer->write(wr('tax_classes', ['id' => 'b', 'code' => 'zero', 'name' => 'Cero B', 'is_default' => false]), $ctx);
    check($a['id'] !== $b['id'], 'la segunda es otra clase');
    same(TypeWriter::CREATED, $b['result']);
    $codes = array_map('strval', $app->db()->column('SELECT code FROM {tax_classes} ORDER BY id'));
    same(count($codes), count(array_unique($codes)), 'y su código es único');
});

test('los tipos se crean y TaxService responde para seis destinos lo que dice el origen', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $class = wrTaxOrigin($app, $ctx);
    $tax = $app->service('tax');

    same(3, wrCount($app, 'tax_rates', 'tax_class_id = ?', [$class]));
    same(['rate' => 2100, 'name' => 'IVA 21 %'], $tax->rate($class, wrDest('ES', '28013')), 'Madrid');
    same(['rate' => 2100, 'name' => 'IVA 21 %'], $tax->rate($class, wrDest('ES', '08001')), 'Barcelona');
    same(['rate' => 700, 'name' => 'IGIC 7 %'], $tax->rate($class, wrDest('ES', '38001')), 'Tenerife: IGIC por código postal');
    same(['rate' => 700, 'name' => 'IGIC 7 %'], $tax->rate($class, wrDest('ES', '35001')), 'Las Palmas');
    same(['rate' => 0, 'name' => 'Ceuta y Melilla'], $tax->rate($class, wrDest('ES', '51001')), 'Ceuta');
    same(['rate' => 0, 'name' => ''], $tax->rate($class, wrDest('FR', '75001')), 'un país sin tipo');
});

test('un tipo se repite sin cambios, se actualiza al cambiar y el servicio lo nota enseguida', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $class = wrTaxOrigin($app, $ctx);
    $writer = new TaxRatesWriter($app);
    $rows = wrCount($app, 'tax_rates');
    $base = ['id' => 'r1', 'class_id' => 'c1', 'country' => 'ES', 'region' => '', 'postcodes' => '', 'rate' => 2100, 'name' => 'IVA 21 %', 'priority' => 0, 'active' => true, 'compound' => false, 'applies_to_shipping' => true];

    same(TypeWriter::UNCHANGED, $writer->write(wr('tax_rates', $base), $ctx)['result']);
    same($rows, wrCount($app, 'tax_rates'), 'ni una fila nueva');
    same(TypeWriter::UPDATED, $writer->write(wr('tax_rates', ['rate' => 2200, 'name' => 'IVA 22 %'] + $base), $ctx)['result']);
    same($rows, wrCount($app, 'tax_rates'));
    same(['rate' => 2200, 'name' => 'IVA 22 %'], $app->service('tax')->rate($class, wrDest('ES', '28013')));
    same(['rate' => 700, 'name' => 'IGIC 7 %'], $app->service('tax')->rate($class, wrDest('ES', '35001')), 'los demás siguen igual');
});

test('los tipos de serie de una clase de serie se sustituyen por los del origen (el IGIC de serie ya no se aplica)', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $seed = (int) wrValue($app, "SELECT id FROM {tax_classes} WHERE code = 'standard'");
    $tax = $app->service('tax');
    same(['rate' => 700, 'name' => 'IGIC 7 %'], $tax->rate($seed, wrDest('ES', '35001')), 'antes: el IGIC que siembra la instalación');

    (new TaxClassesWriter($app))->write(wr('tax_classes', ['id' => '1', 'code' => 'standard', 'name' => 'General', 'is_default' => true]), $ctx);
    $out = (new TaxRatesWriter($app))->write(wr('tax_rates', ['id' => '10', 'class_id' => '1', 'country' => 'ES', 'region' => '', 'postcodes' => '', 'rate' => 1800, 'name' => 'IVA 18 %', 'priority' => 0, 'active' => true, 'compound' => false, 'applies_to_shipping' => true]), $ctx);

    same(TypeWriter::CREATED, $out['result']);
    same(1, wrCount($app, 'tax_rates', 'tax_class_id = ? AND country = ?', [$seed, 'ES']), 'solo queda el del origen');
    same(['rate' => 1800, 'name' => 'IVA 18 %'], $tax->rate($seed, wrDest('ES', '28013')));
    same(['rate' => 1800, 'name' => 'IVA 18 %'], $tax->rate($seed, wrDest('ES', '35001')), 'Canarias cae en el tipo del origen, no en el de serie');
    same(9, wrCount($app, 'tax_rates', 'tax_class_id <> ?', [$seed]), 'las otras clases de serie conservan sus tipos');
});

test('si la clase ya tiene tipos puestos a mano hay conflicto y no se toca nada; overwrite_local los pisa', function (): void {
    $app = writersApp();
    $ctx = writersContext($app, [], ['tax_class' => ['c1' => 'mia']]);
    $classId = $app->db()->insert('tax_classes', ['code' => 'mia', 'name' => 'Mía', 'is_default' => 0, 'position' => 9]);
    $app->db()->insert('tax_rates', ['tax_class_id' => $classId, 'country' => 'ES', 'region' => '', 'postcodes' => '', 'rate' => 1500, 'name' => 'Mi tipo', 'priority' => 0, 'active' => 1]);
    (new TaxClassesWriter($app))->write(wr('tax_classes', ['id' => 'c1', 'code' => 'otra', 'name' => 'Otra', 'is_default' => false]), $ctx);
    $record = wr('tax_rates', ['id' => 'r1', 'class_id' => 'c1', 'country' => 'ES', 'region' => '', 'postcodes' => '', 'rate' => 2100, 'name' => 'IVA 21 %', 'priority' => 0, 'active' => true, 'compound' => false, 'applies_to_shipping' => true]);
    $writer = new TaxRatesWriter($app);
    $counts = wrCounts($app);

    $out = $writer->write($record, $ctx);
    same(TypeWriter::CONFLICT, $out['result']);
    contains('by hand', $out['reason']);
    same($counts, wrCounts($app), 'ni una fila cambia');
    same(1500, (int) wrValue($app, 'SELECT rate FROM {tax_rates} WHERE tax_class_id = ?', [$classId]));

    $over = writersContext($app, ['overwrite_local' => true], ['tax_class' => ['c1' => 'mia']]);
    same(TypeWriter::CREATED, $writer->write($record, $over)['result']);
    same(1, wrCount($app, 'tax_rates', 'tax_class_id = ?', [$classId]));
    same(2100, (int) wrValue($app, 'SELECT rate FROM {tax_rates} WHERE tax_class_id = ?', [$classId]));
});

test('un tipo compuesto entra como normal y se apunta; uno sin clase o con códigos postales inválidos se descarta con su motivo', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    wrTaxOrigin($app, $ctx);
    $writer = new TaxRatesWriter($app);
    $base = ['class_id' => 'c1', 'country' => 'PT', 'region' => '', 'postcodes' => '', 'rate' => 2300, 'name' => 'IVA PT', 'priority' => 0, 'active' => true, 'compound' => true, 'applies_to_shipping' => true];

    $out = $writer->write(wr('tax_rates', ['id' => 'r9'] + $base), $ctx);
    same(TypeWriter::CREATED, $out['result']);
    same(1, $ctx->unmappedCount('review', 'Compound tax'), 'se apunta para revisarlo');
    same(2300, (int) wrValue($app, 'SELECT rate FROM {tax_rates} WHERE id = ?', [$out['id']]));

    $orphan = $writer->write(wr('tax_rates', ['id' => 'r10', 'class_id' => 'no-existe'] + $base), $ctx);
    same(TypeWriter::SKIPPED, $orphan['result']);
    contains('tax class', $orphan['reason']);

    $bad = $writer->write(wr('tax_rates', ['id' => 'r11', 'postcodes' => '51000-519'] + $base), $ctx);
    same(TypeWriter::SKIPPED, $bad['result']);
    contains('postcodes', $bad['reason']);
});

test('la letra de la provincia del origen se guarda como la guarda AIFEC (GC es 35)', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $class = wrTaxOrigin($app, $ctx);
    $out = (new TaxRatesWriter($app))->write(wr('tax_rates', ['id' => 'r12', 'class_id' => 'c1', 'country' => 'ES', 'region' => 'GC', 'postcodes' => '', 'rate' => 500, 'name' => 'IGIC 5 %', 'priority' => 5, 'active' => true, 'compound' => false, 'applies_to_shipping' => true]), $ctx);
    same('35', wrValue($app, 'SELECT region FROM {tax_rates} WHERE id = ?', [$out['id']]));
    same(['rate' => 500, 'name' => 'IGIC 5 %'], $app->service('tax')->rate($class, wrDest('ES', '', '35')));
});

test('simulacro de clases y tipos: se decide lo mismo y no cambia ninguna tabla', function (): void {
    $app = writersApp();
    $dry = writersContext($app)->dry(true);
    $counts = wrCounts($app);

    $class = (new TaxClassesWriter($app))->write(wr('tax_classes', ['id' => 'c1', 'code' => 'nueva', 'name' => 'Nueva', 'is_default' => false]), $dry);
    same(TypeWriter::CREATED, $class['result']);
    same(null, $class['id']);
    $rate = (new TaxRatesWriter($app))->write(wr('tax_rates', ['id' => 'r1', 'class_id' => 'c1']), $dry);
    same(TypeWriter::SKIPPED, $rate['result'], 'sin su clase (que en un simulacro no existe) se descarta, como en la migración real');
    same($counts, wrCounts($app), 'todas las tablas, iguales');
    same(0, wrCount($app, 'import_map'));
});

test('la comprobación final cuenta los tipos traídos', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    wrTaxOrigin($app, $ctx);
    same(['tax_rates_count' => 3], (new TaxRatesWriter($app))->checksums($ctx));
});

test('clases y tipos de impuesto se actualizan aunque se hayan editado aquí (son configuración: nunca hay conflicto)', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $class = wrTaxOrigin($app, $ctx);
    $rate = (int) $ctx->localId('tax_rates', 'r1');
    $app->clock()->advance(600);
    $app->db()->update('tax_classes', ['name' => 'Editada aquí'], ['id' => $class]);
    $app->db()->update('tax_rates', ['rate' => 999], ['id' => $rate]);

    $classes = new TaxClassesWriter($app);
    $rates = new TaxRatesWriter($app);
    same(TypeWriter::UPDATED, $classes->write(wr('tax_classes', ['id' => 'c1', 'code' => 'iva-general', 'name' => 'IVA general (origen)', 'is_default' => false]), $ctx)['result']);
    same(TypeWriter::UPDATED, $rates->write(wr('tax_rates', ['id' => 'r1', 'class_id' => 'c1', 'country' => 'ES', 'region' => '', 'postcodes' => '', 'rate' => 2200, 'name' => 'IVA 22 %', 'priority' => 0, 'active' => true, 'compound' => false, 'applies_to_shipping' => true]), $ctx)['result']);
    same('IVA general (origen)', wrValue($app, 'SELECT name FROM {tax_classes} WHERE id = ?', [$class]));
    same(2200, (int) wrValue($app, 'SELECT rate FROM {tax_rates} WHERE id = ?', [$rate]));
});

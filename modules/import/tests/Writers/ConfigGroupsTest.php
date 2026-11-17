<?php
/**
 * AIFEC — escritor de grupos de clientes: nombre en el idioma principal, descuento y exención de impuestos. El grupo por defecto del origen casa
 * con el de aquí y un grupo que ya existe no se duplica.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Writers\CustomerGroupsWriter;

require_once __DIR__ . '/WritersKit.php';

test('un grupo se crea, se repite sin cambios y se actualiza aunque se haya editado aquí (es un tipo de configuración)', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new CustomerGroupsWriter($app);
    $groups = wrCount($app, 'customer_groups');

    $first = $writer->write(wr('customer_groups'), $ctx);
    same(TypeWriter::CREATED, $first['result']);
    same($groups + 1, wrCount($app, 'customer_groups'));
    $row = $app->db()->find('customer_groups', ['id' => $first['id']]);
    same(['Profesionales', 1000, 0, 0], [$row['name'], (int) $row['discount'], (int) $row['tax_exempt'], (int) $row['is_default']]);

    same(TypeWriter::UNCHANGED, $writer->write(wr('customer_groups'), $ctx)['result']);
    same($groups + 1, wrCount($app, 'customer_groups'), 'ni una fila nueva');

    $app->clock()->advance(120);
    $app->db()->update('customer_groups', ['discount' => 50], ['id' => $first['id']]);
    $changed = $writer->write(wr('customer_groups', ['discount' => 1500, 'tax_exempt' => true]), $ctx);
    same(TypeWriter::UPDATED, $changed['result'], 'sin conflicto');
    $row = $app->db()->find('customer_groups', ['id' => $first['id']]);
    same([1500, 1], [(int) $row['discount'], (int) $row['tax_exempt']]);
});

test('el grupo por defecto del origen casa con el de aquí: no se crea otro y conserva su nombre', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $default = (int) wrValue($app, 'SELECT id FROM {customer_groups} WHERE is_default = 1');
    $groups = wrCount($app, 'customer_groups');
    $out = (new CustomerGroupsWriter($app))->write(wr('customer_groups', ['id' => '1', 'name' => ['es' => 'Clientes registrados'], 'discount' => 0, 'tax_exempt' => false, 'is_default' => true]), $ctx);

    same($default, $out['id']);
    same(TypeWriter::UPDATED, $out['result']);
    same($groups, wrCount($app, 'customer_groups'));
    same('Customers', wrValue($app, 'SELECT name FROM {customer_groups} WHERE id = ?', [$default]));
    same($default, $ctx->localId('customer_groups', '1'));
    same(1, (int) wrValue($app, 'SELECT is_default FROM {customer_groups} WHERE id = ?', [$default]), 'sigue siendo el por defecto');
});

test('un grupo con el mismo nombre no se duplica, y dos del origen no casan con el mismo de aquí', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new CustomerGroupsWriter($app);
    $mine = $app->db()->insert('customer_groups', ['name' => 'Profesionales', 'discount' => 0, 'tax_exempt' => 0, 'is_default' => 0]);
    $groups = wrCount($app, 'customer_groups');

    $a = $writer->write(wr('customer_groups', ['id' => '3']), $ctx);
    same($mine, $a['id']);
    same($groups, wrCount($app, 'customer_groups'));
    same(1000, (int) wrValue($app, 'SELECT discount FROM {customer_groups} WHERE id = ?', [$mine]));

    $b = $writer->write(wr('customer_groups', ['id' => '4']), $ctx);
    same(TypeWriter::CREATED, $b['result'], 'el segundo del mismo nombre es otro grupo');
    check($b['id'] !== $mine);
});

test('la equivalencia que eligió el comerciante manda y no cambia el nombre del grupo de aquí', function (): void {
    $app = writersApp();
    $mine = $app->db()->insert('customer_groups', ['name' => 'VIP', 'discount' => 0, 'tax_exempt' => 0, 'is_default' => 0]);
    $ctx = writersContext($app, [], ['customer_group' => ['g5' => (string) $mine]]);
    $out = (new CustomerGroupsWriter($app))->write(wr('customer_groups', ['id' => 'g5', 'name' => ['es' => 'Mayoristas'], 'discount' => 2000]), $ctx);

    same($mine, $out['id']);
    same('VIP', wrValue($app, 'SELECT name FROM {customer_groups} WHERE id = ?', [$mine]));
    same(2000, (int) wrValue($app, 'SELECT discount FROM {customer_groups} WHERE id = ?', [$mine]));
});

test('el nombre sale del idioma principal y, si falta, del primero que haya (y se apunta)', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $out = (new CustomerGroupsWriter($app))->write(wr('customer_groups', ['id' => '8', 'name' => ['en' => 'Wholesale']]), $ctx);

    same('Wholesale', wrValue($app, 'SELECT name FROM {customer_groups} WHERE id = ?', [$out['id']]));
    check(count($ctx->noteTexts('customer_groups')) >= 1, 'se apunta que no había nombre en el idioma principal');
});

test('simulacro de grupos: se decide y no cambia ninguna tabla', function (): void {
    $app = writersApp();
    $dry = writersContext($app)->dry(true);
    $counts = wrCounts($app);
    $out = (new CustomerGroupsWriter($app))->write(wr('customer_groups'), $dry);

    same(TypeWriter::CREATED, $out['result']);
    same(null, $out['id']);
    same($counts, wrCounts($app), 'todas las tablas, iguales');
});

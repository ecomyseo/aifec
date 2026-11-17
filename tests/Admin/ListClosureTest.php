<?php
/**
 * AIFEC — el listado de un recurso se puede declarar como función (contrato del panel, «list»).
 *
 * Fallo que cubre: con «list» como función, el listado respondía 500 porque se leía «export» de la
 * definición antes de resolverla (lo vieron los módulos de impuestos y de clientes al construirse).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Db\Schema;
use Aifec\Db\TableDef;
use Aifec\Kernel\App;

test('recurso: el listado declarado como función se pinta, exporta y puede quitar la exportación', function (): void {
    $app = testApp();
    aifecAdmin($app);
    $schema = new Schema($app->db());
    $schema->create('t_notes', static function (TableDef $t): void {
        $t->id();
        $t->string('name', 80);
        $t->string('colour', 20)->nullable();
        $t->timestamps();
    });
    $now = $app->clock()->sql();
    $app->db()->insert('t_notes', ['name' => 'Nota & uno', 'colour' => 'rojo', 'created_at' => $now, 'updated_at' => $now]);

    $calls = 0;
    $app->hooks()->filter('admin.definitions', static function (array $definitions) use (&$calls): array {
        $definitions['testnotes'] = [
            'caps' => ['testnotes.view' => 'See the dashboard', 'testnotes.edit' => 'Change the settings'],
            'resources' => [
                'tnotes' => [
                    'table' => 't_notes', 'title' => 'Content',
                    'list' => static function (App $app) use (&$calls): array {
                        ++$calls;

                        return ['columns' => ['name' => ['label' => 'Name', 'link' => true], 'colour' => ['label' => 'Code']], 'search' => ['name']];
                    },
                    'form' => ['fields' => ['name' => ['label' => 'Name', 'rules' => 'required|max:80']]],
                ],
                'tnotesfixed' => [
                    'table' => 't_notes', 'title' => 'Content',
                    'list' => static function (App $app): array {
                        return ['columns' => ['name' => ['label' => 'Name']], 'export' => false];
                    },
                    'form' => ['fields' => ['name' => ['label' => 'Name', 'rules' => 'required|max:80']]],
                ],
            ],
        ];

        return $definitions;
    });

    $web = new TestClient($app);
    aifecLogin($web);

    $r = $web->get('/admin/r/tnotes');
    same(200, $r->status, 'el listado con «list» como función responde');
    contains('Nota &amp; uno', $r->body);
    contains('rojo', $r->body, 'pinta las columnas que devuelve la función');
    contains('/admin/r/tnotes/export.csv', $r->body, 'sin «export» a false, hay botón de exportar');
    check($calls >= 1, 'la función del listado se ha llamado');

    $csv = $web->get('/admin/r/tnotes/export.csv');
    same(200, $csv->status);
    contains('Nota & uno', $csv->body);

    $fixed = $web->get('/admin/r/tnotesfixed');
    same(200, $fixed->status);
    notContains('/admin/r/tnotesfixed/export.csv', $fixed->body, 'con «export» a false en lo que devuelve la función, no hay botón');
});

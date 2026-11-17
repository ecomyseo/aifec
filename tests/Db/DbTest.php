<?php
/**
 * AIFEC — pruebas de la capa de datos, del esquema y de las migraciones (sobre SQLite en memoria).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Db\Db;
use Aifec\Db\Migrator;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;

function aifecTestDb(): Db
{
    $db = Db::connect(['driver' => 'sqlite', 'path' => ':memory:', 'prefix' => 't_']);
    (new Schema($db))->create('things', static function (TableDef $t): void {
        $t->id();
        $t->string('code', 40);
        $t->string('name')->nullable();
        $t->integer('price')->default(0);
        $t->boolean('active')->default(1);
        $t->text('data')->nullable();
        $t->datetime('created_at')->nullable();
        $t->unique(['code']);
        $t->index(['active', 'price']);
    });

    return $db;
}

test('crear tabla, insertar, leer y tipos', function (): void {
    $db = aifecTestDb();
    $id = $db->insert('things', ['code' => 'A', 'name' => 'Año & «ñ»', 'price' => 4999, 'data' => null]);
    same(1, $id);
    $row = $db->row('SELECT * FROM {things} WHERE id = ?', [$id]);
    same('Año & «ñ»', $row['name']);
    same(4999, (int) $row['price']);
    same(1, (int) $row['active'], 'valor por defecto');
    same(null, $row['data'], 'un null se guarda como NULL, no como cadena vacía');
    same(1, (int) $db->value('SELECT COUNT(*) FROM {things} WHERE data IS NULL'));
    same([], $db->select('SELECT * FROM {things} WHERE id = ?', [99]), 'sin filas, array vacío');
    same(null, $db->row('SELECT * FROM {things} WHERE id = 99'));
    same(null, $db->value('SELECT name FROM {things} WHERE id = 99'));
});

test('actualizar, borrar, IN y where con null', function (): void {
    $db = aifecTestDb();
    foreach (['A', 'B', 'C'] as $i => $code) {
        $db->insert('things', ['code' => $code, 'price' => ($i + 1) * 100]);
    }
    same(1, $db->update('things', ['price' => 150, 'name' => 'x'], ['code' => 'A']));
    same(2, count($db->select('SELECT * FROM {things} WHERE name IS NULL')));
    $params = [];
    $in = $db->in(['A', 'C'], $params);
    same(2, count($db->select('SELECT * FROM {things} WHERE code IN (' . $in . ')', $params)));
    $params = [];
    same([], $db->select('SELECT * FROM {things} WHERE code IN (' . $db->in([], $params) . ')', $params), 'IN vacío no coincide con nada');
    same(2, $db->delete('things', ['code' => ['B', 'C']]));
    same(['A'], $db->column('SELECT code FROM {things}'));
    throws(function () use ($db): void {
        $db->delete('things', []);
    }, \InvalidArgumentException::class, 'borrar sin condición no se permite');
});

test('upsert e insertIgnore', function (): void {
    $db = aifecTestDb();
    $db->upsert('things', ['code' => 'A', 'price' => 100], ['code']);
    $db->upsert('things', ['code' => 'A', 'price' => 250], ['code']);
    same(1, (int) $db->value('SELECT COUNT(*) FROM {things}'));
    same(250, (int) $db->value('SELECT price FROM {things} WHERE code = ?', ['A']));
    same(false, $db->insertIgnore('things', ['code' => 'A', 'price' => 1]));
    same(true, $db->insertIgnore('things', ['code' => 'B', 'price' => 1]));
    same(250, (int) $db->value('SELECT price FROM {things} WHERE code = ?', ['A']), 'insertIgnore no pisa');
});

test('actualización condicional: solo el primero gana', function (): void {
    $db = aifecTestDb();
    $id = $db->insert('things', ['code' => 'P', 'name' => 'pending']);
    $first = $db->run("UPDATE {things} SET name = 'paid' WHERE id = ? AND name <> 'paid'", [$id]);
    $second = $db->run("UPDATE {things} SET name = 'paid' WHERE id = ? AND name <> 'paid'", [$id]);
    same(1, $first);
    same(0, $second, 'la segunda confirmación no afecta a ninguna fila');
});

test('transacciones anidadas: lo de dentro se deshace sin perder lo de fuera', function (): void {
    $db = aifecTestDb();
    $db->transaction(function (Db $db): void {
        $db->insert('things', ['code' => 'fuera']);
        try {
            $db->transaction(function (Db $db): void {
                $db->insert('things', ['code' => 'dentro']);
                throw new \RuntimeException('falla');
            });
        } catch (\RuntimeException $e) {
            // esperado
        }
    });
    same(['fuera'], $db->column('SELECT code FROM {things}'));
    throws(function () use ($db): void {
        $db->transaction(function (Db $db): void {
            $db->insert('things', ['code' => 'nada']);
            throw new \LogicException('todo fuera');
        });
    }, \LogicException::class);
    same(1, (int) $db->value('SELECT COUNT(*) FROM {things}'));
    same(false, $db->inTransaction());
});

test('esquema: repetir no falla, añadir columna e índice', function (): void {
    $db = aifecTestDb();
    $schema = new Schema($db);
    $schema->create('things', static function (TableDef $t): void {
        $t->id();
    });
    same(true, $schema->hasColumn('things', 'code'), 'create() sobre una tabla que existe no la toca');
    $schema->addColumn('things', static function (TableDef $t): void {
        $t->string('extra', 20)->default('x');
    });
    $schema->addColumn('things', static function (TableDef $t): void {
        $t->string('extra', 20)->default('x');
    });
    same(true, $schema->hasColumn('things', 'extra'));
    $schema->addIndex('things', ['extra']);
    $schema->addIndex('things', ['extra']);
    same(true, $schema->hasIndex('things', $schema->indexName('things', ['extra'], false)));
    throws(function () use ($schema): void {
        $schema->addColumn('things', static function (TableDef $t): void {
            $t->integer('obligatoria');
        });
    }, \InvalidArgumentException::class);
    throws(function () use ($db): void {
        $db->table('things; DROP TABLE x');
    }, \InvalidArgumentException::class);
});

test('migraciones: se aplican una vez, por orden y por ámbito', function (): void {
    $db = Db::connect(['driver' => 'sqlite', 'path' => ':memory:', 'prefix' => 'm_']);
    $dir = tmpDir();
    file_put_contents($dir . '/0002_segunda.php', "<?php\nreturn function (\$schema, \$db): void { \$db->insert('demo', ['v' => 2]); };\n");
    file_put_contents($dir . '/0001_primera.php', "<?php\nreturn function (\$schema, \$db): void { \$schema->create('demo', function (\$t): void { \$t->id(); \$t->integer('v'); }); \$db->insert('demo', ['v' => 1]); };\n");
    $migrator = new Migrator($db);
    same(['0001_primera', '0002_segunda'], $migrator->run('core', $dir, '2026-11-12 10:00:00'));
    same([], $migrator->run('core', $dir, '2026-11-12 10:00:01'), 'la segunda pasada no aplica nada');
    same([1, 2], array_map('intval', $db->column('SELECT v FROM {demo} ORDER BY id')));
    same(2, count($migrator->applied()));
    $before = Migrator::fingerprint(['core' => $dir]);
    file_put_contents($dir . '/0003_tercera.php', "<?php\nreturn function (\$schema, \$db): void {};\n");
    check($before !== Migrator::fingerprint(['core' => $dir]), 'la huella cambia al llegar una migración nueva');
});

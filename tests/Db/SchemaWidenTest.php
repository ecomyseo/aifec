<?php
/**
 * AIFEC — pruebas de la anchura de las columnas de texto corto y de su ensanchado en MySQL/MariaDB.
 *
 * El fallo que las motiva: el esquema recortaba toda columna a 191 caracteres sin decirlo. SQLite no mira longitudes, así
 * que ninguna prueba lo veía; en el servidor (MariaDB) una descripción meta de 300 caracteres daba «Data too long».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;

/** La longitud con la que queda declarada una columna de texto corto. */
function aifecStringLength(?int $asked): int
{
    $def = new TableDef();
    if ($asked === null) {
        $def->string('c');
    } else {
        $def->string('c', $asked);
    }

    return (int) $def->columns[0]['length'];
}

test('esquema: una columna de texto corto conserva la longitud que se le pide', function (): void {
    same(191, aifecStringLength(null), 'sin longitud son 191');
    same(40, aifecStringLength(40));
    same(255, aifecStringLength(255), 'lo que antes se recortaba a 191 en silencio');
    same(320, aifecStringLength(320), 'la descripción meta del catálogo y de las páginas');
    same(768, aifecStringLength(5000), 'el tope es lo que cabe en un índice de InnoDB');
    same(1, aifecStringLength(0));
});

test('esquema: la definición ensanchada sale de la que guarda el servidor y conserva todo lo demás', function (): void {
    // Tal como lo devuelve SHOW CREATE TABLE en MariaDB y en MySQL 8.
    $ddl = "CREATE TABLE `af_product_i18n` (\n"
        . "  `product_id` bigint(20) NOT NULL,\n"
        . "  `lang` varchar(8) NOT NULL,\n"
        . "  `name` varchar(191) NOT NULL,\n"
        . "  `meta_title` varchar(191) DEFAULT NULL,\n"
        . "  `meta_description` varchar(191) DEFAULT NULL,\n"
        . "  `reason` varchar(191) NOT NULL DEFAULT '',\n"
        . "  `url` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,\n"
        . "  `path` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'a, b',\n"
        . "  `path_full` varchar(100) NOT NULL,\n"
        . "  `notes` mediumtext DEFAULT NULL,\n"
        . "  `wide` varchar(320) DEFAULT NULL,\n"
        . "  `last` varchar(50) NOT NULL\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    same('`meta_description` varchar(320) DEFAULT NULL', Schema::widenedDefinition($ddl, 'meta_description', 320));
    same("`reason` varchar(255) NOT NULL DEFAULT ''", Schema::widenedDefinition($ddl, 'reason', 255), 'el valor por defecto se conserva');
    same('`url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL', Schema::widenedDefinition($ddl, 'url', 255), 'el cotejamiento se conserva');
    same("`path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'a, b'", Schema::widenedDefinition($ddl, 'path', 255), 'ni path_full ni la coma del valor por defecto confunden');
    same('`last` varchar(80) NOT NULL', Schema::widenedDefinition($ddl, 'last', 80), 'la última columna no lleva coma');

    same(null, Schema::widenedDefinition($ddl, 'wide', 320), 'igual de ancha: no se toca');
    same(null, Schema::widenedDefinition($ddl, 'wide', 255), 'nunca se estrecha');
    same(null, Schema::widenedDefinition($ddl, 'notes', 255), 'solo VARCHAR');
    same(null, Schema::widenedDefinition($ddl, 'no_existe', 255));
    same(null, Schema::widenedDefinition($ddl, 'name` varchar(191) NOT NULL, `x', 255), 'un nombre de columna raro no entra en la expresión');
    same(null, Schema::widenedDefinition($ddl, 'meta_.*', 255), 'ni un patrón: el nombre es el de una columna, no una expresión');
    same('`name` varchar(768) NOT NULL', Schema::widenedDefinition($ddl, 'name', 9000), 'el tope es 768');
});

test('esquema: ensanchar en SQLite no hace nada y no falla, y la migración del núcleo corre', function (): void {
    $db = Db::connect(['driver' => 'sqlite', 'path' => ':memory:', 'prefix' => 't_']);
    $schema = new Schema($db);
    $schema->create('wide_things', static function (TableDef $t): void {
        $t->id();
        $t->string('meta_description', 320)->nullable();
    });
    same(false, $schema->widenString('wide_things', 'meta_description', 320));
    same(false, $schema->widenString('no_existe', 'meta_description', 320));
    same(false, $schema->widenString('wide_things', 'no_existe', 320));
    // SQLite guarda lo que le den: la longitud solo la hace cumplir MySQL/MariaDB.
    $db->insert('wide_things', ['meta_description' => str_repeat('ñ', 300)]);
    same(300, mb_strlen((string) $db->value('SELECT meta_description FROM {wide_things}')));

    $migration = require dirname(__DIR__, 2) . '/core/migrations/0002_wider_strings.php';
    $migration($schema, $db);
    check(true, 'la migración no lanza con tablas que no existen');
});

test('esquema: ninguna migración declara un texto corto más ancho que el tope', function (): void {
    // Lo que pase de 768 se recortaría sin avisar: tiene que ser text().
    $root = dirname(__DIR__, 2);
    $files = array_merge(glob($root . '/core/migrations/*.php') ?: [], glob($root . '/modules/*/migrations/*.php') ?: [], glob($root . '/plugins/*/migrations/*.php') ?: []);
    check(count($files) > 10, 'hay migraciones que mirar');
    $bad = [];
    foreach ($files as $file) {
        if (preg_match_all('/->string\(\'([a-z_0-9]+)\',\s*(\d+)\)/', (string) file_get_contents($file), $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $hit) {
                if ((int) $hit[2] > 768) {
                    $bad[] = basename(dirname($file, 2)) . ':' . $hit[1] . '(' . $hit[2] . ')';
                }
            }
        }
    }
    same([], $bad);
});

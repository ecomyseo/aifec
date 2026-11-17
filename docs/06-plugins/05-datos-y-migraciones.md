# Datos y migraciones

Un plugin guarda sus datos en tablas propias, que crea con migraciones, y los lee y escribe a través de la capa de datos del núcleo (`$app->db()`). Esta página enseña a crear y cambiar tablas, la API de datos, las reglas del dinero y de las fechas, los textos por idioma y qué se borra al desinstalar. Los ejemplos salen del plugin de [Tu primer plugin](02-tu-primer-plugin.md).

## Migraciones

Una migración es un fichero de `migrations/` que devuelve una función. Se llama `NNNN_nombre.php` y se aplican por orden, **una sola vez cada una**:

```php
<?php

declare(strict_types=1);

use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;

return static function (Schema $schema, Db $db): void {
    $schema->create('consejos_producto_tips', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('product_id');
        $t->string('body', 300);
        $t->datetime('created_at');
        $t->datetime('updated_at');
        $t->index(['product_id']);
    });
};
```

Se aplican solas al activar el plugin, y `php aifec migrate` aplica las pendientes de todo lo activo:

```
$ php aifec migrate
Applied: consejos-producto/0002_position
$ php aifec migrate
Nothing to migrate.
```

**Una migración ya aplicada no se edita.** Para cambiar una tabla publicada, añade otra:

```php
<?php

declare(strict_types=1);

use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;

return static function (Schema $schema, Db $db): void {
    $schema->addColumn('consejos_producto_tips', static function (TableDef $t): void {
        $t->integer('position')->default(0);
    });
};
```

`addColumn()` no hace nada si la columna ya existe. Una columna nueva en una tabla con filas tiene que ser `nullable()` o llevar `default()`: sin eso lanza «A column added to an existing table must be nullable or have a default».

Esta segunda migración se probó con el plugin: tras `php aifec migrate`, `contract:check` sigue en 100/100.

### Qué columnas hay

La tabla se define con `TableDef`. **Toda columna es `NOT NULL`** salvo que la marques con `nullable()`.

| Método | Columna |
|---|---|
| `id()` | Clave primaria entera autoincremental (`id`). |
| `string($nombre, $largo)` | Texto corto, de hasta 768 caracteres (191 por defecto). |
| `text($nombre)` | Texto largo (hasta 16 MB): descripciones, JSON. |
| `integer()`, `bigInteger()` | Enteros. |
| `boolean()` | Sí o no. |
| `decimal()`, `float()` | Decimales. **No los uses para dinero.** |
| `datetime()`, `date()` | Fecha y hora, o fecha. |
| `blob()` | Binarios. |
| `timestamps()` | `created_at` y `updated_at`. |
| `nullable()`, `default($valor)` | Se aplican a la última columna declarada. |
| `index([...])`, `unique([...])`, `primary([...])` | Índices y claves. |

Además de `create()` y `addColumn()`, `Schema` tiene `drop()`, `hasTable()`, `hasColumn()`, `addIndex()` y `hasIndex()`. Todo funciona igual con SQLite y con MySQL o MariaDB: no escribas `CREATE TABLE` a mano.

## Las reglas del plugin

- **Los nombres de las tablas llevan el id del plugin** (`consejos_producto_…`) y se declaran en `contract.tables` de `plugin.json`. Una tabla creada y no declarada es un fallo (C9).
- **No escribas en tablas ajenas.** Ni `INSERT`, ni `UPDATE`, ni `DELETE` sobre `products`, `orders`, `customers`… (C1). Leerlas sí puedes; si lees clientes, direcciones o pedidos, decláralo en `contract.personal_data`.
- Un plugin no puede declarar una tabla del sistema ni de otro módulo.

## La API de datos

`$app->db()` da la conexión. **Las tablas van entre llaves y sin prefijo; los valores, siempre como parámetros `?`.** Nunca pegues un valor en la consulta.

```php
$db = $app->db();
$now = $app->clock()->sql();

$id = $db->insert('consejos_producto_tips', ['product_id' => 5, 'body' => 'Uno', 'created_at' => $now, 'updated_at' => $now]);   // devuelve el id: 1
$db->update('consejos_producto_tips', ['body' => 'Dos'], ['id' => $id]);       // devuelve las filas cambiadas: 1
$row = $db->row('SELECT * FROM {consejos_producto_tips} WHERE id = ?', [$id]); // un array, o null si no existe
$db->value('SELECT COUNT(*) FROM {consejos_producto_tips}');                   // un valor suelto
$db->column('SELECT body FROM {consejos_producto_tips} WHERE product_id = ?', [5]);   // una lista: ['Dos']
$db->select('SELECT * FROM {consejos_producto_tips} WHERE product_id = ?', [77]);     // siempre un array: []
$db->find('consejos_producto_tips', ['product_id' => 5]);                      // una fila por sus columnas, o null
$db->delete('consejos_producto_tips', ['id' => $id]);                          // devuelve las filas borradas: 1
```

| Método | Qué hace |
|---|---|
| `select($sql, $params)` | Todas las filas, como array de arrays. Sin resultados, `[]`. |
| `row()` | La primera fila o `null`. |
| `value()` | El primer valor de la primera fila. |
| `column()` | La primera columna de todas las filas. |
| `insert($tabla, $datos)` | Inserta y devuelve el id. `null` se guarda como NULL. |
| `insertIgnore()` | Inserta salvo que choque con una clave única. Devuelve `true` si insertó. |
| `upsert($tabla, $datos, $clavesÚnicas)` | Inserta o, si ya existe, actualiza. |
| `update($tabla, $datos, $donde)` | Actualiza. Exige `$donde`: sin condición lanza una excepción. |
| `delete($tabla, $donde)` | Borra. También exige `$donde`. |
| `run($sql, $params)` | Ejecuta una sentencia y devuelve las filas afectadas. |
| `in($valores, &$params)` | Devuelve `?, ?, ?` para un `IN` y añade los valores a `$params`. |
| `transaction($funcion)` | Todo o nada. Si la función lanza, se deshace y se relanza. Se puede anidar. |

```php
$params = [];
$marks = $db->in([5, 6], $params);
$rows = $db->select('SELECT id FROM {consejos_producto_tips} WHERE product_id IN (' . $marks . ')', $params);

$db->transaction(function ($db) use ($now): void {
    $db->insert('consejos_producto_tips', ['product_id' => 6, 'body' => 'Tres', 'created_at' => $now, 'updated_at' => $now]);
    throw new \RuntimeException('boom');   // la fila de arriba no queda guardada
});
```

Solo SQL corriente: sin funciones de fecha, `CONCAT`, JSON ni `RAND`, para que valga igual con SQLite y con MySQL. Lo que no puedas expresar así, hazlo en PHP.

**«Solo el primero gana».** Si necesitas que algo ocurra una única vez (cobrar, descontar stock), no leas y escribas: usa una sola sentencia condicionada y mira cuántas filas cambió.

```php
$first = $db->run("UPDATE {mi_tabla} SET estado = 'hecho' WHERE id = ? AND estado <> 'hecho'", [$id]) === 1;
```

## Dinero y fechas

- **El dinero son céntimos enteros**, siempre: columnas `integer`, variables enteras, JSON. Nunca `float`. Se pinta con `money($centimos)`.
- **Las fechas van en UTC** con el formato `Y-m-d H:i:s`. Para «ahora», `$app->clock()->sql()`, nunca `date()` ni `time()` sueltos: así las pruebas controlan el reloj.
- Tipos de impuesto y descuentos, en centésimas de punto: `2100` es el 21 %.

## Textos por idioma

Un dato que se traduce (un título, una descripción) va en una tabla aparte con una fila por idioma. El nombre es `<tabla>_i18n` en el núcleo; para tu plugin, la misma idea con tu prefijo:

```php
$schema->create('consejos_producto_names', static function (TableDef $t): void {
    $t->id();
    $t->bigInteger('tip_id');
    $t->string('lang', 8);
    $t->string('title', 120);
    $t->unique(['tip_id', 'lang']);
});
```

Se lee con el idioma de la página y el principal como respaldo:

```php
$row = $db->row('SELECT title FROM {consejos_producto_names} WHERE tip_id = ? AND lang IN (?, ?) ORDER BY CASE WHEN lang = ? THEN 0 ELSE 1 END',
    [1, $app->lang(), $app->defaultLang(), $app->lang()]);
```

Para guardar una fila por idioma sin duplicar: `$db->upsert('consejos_producto_names', ['tip_id' => 1, 'lang' => 'es', 'title' => 'Hola'], ['tip_id', 'lang'])`.

En un recurso del panel, un recurso con `'i18n' => ['table' => '…', 'fk' => '…', 'lang' => 'lang']` guarda en esa tabla los campos marcados con `'i18n' => true`, una fila por idioma.

## Qué se borra al desinstalar

Al desinstalar sin conservar los datos, el sistema borra:

1. Las tablas de `contract.tables` (solo esas, y nunca una del sistema).
2. Los ajustes cuya clave empieza por el id del plugin con punto (`consejos-producto.`) o con guion bajo (`consejos_producto.`).
3. Su registro de migraciones.

El validador lo comprueba ejecutando el ciclo entero en un sitio de pruebas (regla C9): si el plugin deja una tabla, deja un ajuste, cambia las columnas de una tabla o quita algo que no era suyo, falla. Por eso los ajustes llevan siempre el prefijo del plugin.

## Si algo no sale

- **«Crea la tabla … sin declararla en contract.tables».** Añade el nombre de la tabla al manifiesto.
- **«Deja la tabla … sin borrar al desinstalar» o «Deja el ajuste … sin borrar al desinstalar».** Quedó algo tras desinstalar: normalmente la tabla no está en `contract.tables`, o el ajuste no empieza por el id del plugin.
- **Un `update()` o un `delete()` lanza una excepción.** Falta el tercer argumento (el `$donde`). Una sentencia sin condición no se permite.
- **La columna nueva da error en producción.** La migración añade una columna sin `nullable()` ni `default()` a una tabla con filas.

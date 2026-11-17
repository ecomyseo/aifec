# Pruebas

Un plugin no se da por terminado hasta que sus pruebas se ejecutan y pasan. Las pruebas de AIFEC corren **dentro del proceso**: montan un sitio nuevo y vacío, mandan peticiones a la aplicación sin servidor web ni navegador y tardan segundos. Esta página explica cómo se escriben, cómo se lanzan y qué debe comprobar toda prueba de un plugin.

## Dónde van y cómo se lanzan

Los ficheros acaban en `Test.php` y van en `plugins/<id>/tests/`. Se lanzan con el lanzador del proyecto:

```
php tests/run.php plugins/consejos-producto
```

El argumento es un filtro: se ejecutan los ficheros cuya ruta lo contiene. Sin argumentos, todas las pruebas del proyecto. `--lista` enseña qué ficheros se ejecutarían sin correrlos:

```
$ php tests/run.php --lista consejos-producto
plugins/consejos-producto/tests/ConsejosProductoTest.php
1 ficheros
```

Solo imprime los fallos y una línea final, `PRUEBAS: 8 OK, 0 FALLAN`. Sale con código 1 si falla algo, y también si el filtro no encuentra ninguna prueba: **cero pruebas no es un verde**. Un aviso de PHP dentro de una prueba cuenta como fallo.

Lánzalas con **la versión más baja de PHP que declares** (`requires.php`) y con la más alta que quieras soportar:

```
C:\ruta\a\php-7.4\php.exe tests/run.php plugins/consejos-producto
C:\ruta\a\php-8.4\php.exe tests/run.php plugins/consejos-producto
```

Un fichero que en 8.3 está bien puede ser un error de sintaxis en 7.4 (propiedades promocionadas, `match`, tipos unión, `mixed`…).

## Las funciones de comprobación

| Función | Comprueba |
|---|---|
| `test('nombre', function (): void { … })` | Declara una prueba. El nombre, en castellano y diciendo qué debe pasar. |
| `same($esperado, $obtenido, $mensaje)` | Igualdad estricta (`===`). |
| `check($condición, $mensaje)` | Que algo es verdadero. |
| `near($esperado, $obtenido, $margen)` | Igualdad de decimales con margen. |
| `contains($trozo, $texto)`, `notContains(…)` | Que un texto lleva o no lleva un trozo. |
| `throws(function () { … }, Clase::class)` | Que el código lanza esa excepción. |
| `tmpDir()` | Una carpeta temporal que se borra sola. |

## Un sitio de pruebas: testApp()

```php
$app = testApp(['modules' => ['plugins', 'catalog', 'consejos-producto'], 'settings' => ['modules.active' => [...]]]);
$app->migrate(true);
$app->boot();
```

Crea un sitio **nuevo y vacío**, con SQLite, el reloj parado en el 12/11/2026 a las 12:00 UTC y sin red. Opciones:

| Opción | Qué hace |
|---|---|
| `modules` | Los módulos que se cargan, más los que declaran en `requires`. Sin esta opción solo existe el núcleo: así el trabajo a medias de otro módulo no rompe tus pruebas. `'*'` los carga todos. |
| `settings` | Ajustes iniciales. Para un plugin, `'modules.active'` con los ids activos. |
| `http` | Un cliente HTTP de mentira (`FakeHttpClient`). |
| `clock` | Otro reloj. |
| `base_path` | Un sitio en subcarpeta (`/demos/x`). |
| `config` | Se funde con la configuración del sitio. |

`$app->clock()->advance(3600)` mueve la hora; `$app->clock()->sql()` da «ahora» en el formato de la base de datos.

## Un navegador de mentira: TestClient

```php
$web = new TestClient($app);
$r = $web->get('/carrito');                            // $r es una Response
$r = $web->post('/carrito/add', ['id' => 5]);
$r = $web->postJson('/api/v1/...', ['clave' => 'valor']);
$web->follow();                                        // sigue la redirección de la última respuesta
$web->cookies;                                         // las cookies se conservan entre peticiones
```

`$r->status`, `$r->body`, `$r->headers`, `$r->cacheTtl` y `$r->cacheTags` dicen todo lo que necesitas.

## El panel

```php
$web = adminClient($app);                // persona «admin» dada de alta, sesión iniciada y una página abierta
$web = adminClient($app, 'support');     // lo mismo con otro papel
$web->post('/admin/r/consejos_producto_tips/save', ['_token' => aifecToken($web), 'f' => ['body' => '…']]);
```

- `aifecToken($web)` lee la ficha de la última página del panel abierta: abre una pantalla antes de cada envío.
- Los campos de un formulario viajan como `f[campo]`. Un campo por idioma, como `f[campo][es]`. **Una casilla desmarcada no viaja; un interruptor (`toggle`) siempre viaja, con `0` o con `1`.**
- Un formulario inválido responde 422; guardado bien, 302.
- Para comprobar un permiso, un papel sin él: `same(403, adminClient($app, 'support')->get('/admin/…')->status)`.

Otras ayudas de `tests/support.php`: `aifecAdmin($app, $papel, $correo, $nombre)`, `aifecLogin($web, $correo)` e `inputValue($html, 'f[campo]')`.

## Ayudas de los módulos

Los módulos dejan las suyas en `modules/<slug>/tests/helpers.php`, y el lanzador **no las carga solo**: se hace con `require_once`.

```php
require_once dirname(__DIR__, 3) . '/modules/catalog/tests/helpers.php';   // aifecProduct(), aifecCatalog()
require_once dirname(__DIR__, 3) . '/modules/orders/tests/helpers.php';    // aifecCartApp(), aifecCartRun()
```

Para una IA de mentira, `FakeProvider` (se registra con `$app->bind('ai.provider.economy', …)`); para llamadas externas, `FakeHttpClient` (`$http->queue(new HttpResponse(200, [], '{}'))`). Ver [Tareas, correo e IA](07-tareas-correo-e-ia.md) y [El Contrato AIFEC](08-el-contrato-aifec.md).

## Un oyente que lanza hace fallar la prueba

En producción, un oyente de un gancho que lanza una excepción se anota y la tienda sigue. En las pruebas eso escondería un gancho que no hace nada, así que **la prueba falla**. Si lanzas a propósito dentro de un oyente (o esperas un error de gancho, como la concesión de `pricing` que falta), empieza la prueba con `expectHookErrors();`. Lo que lanzaron queda en `$app->hooks()->errors()`.

## Los nombres de las ayudas

Toda la batería corre en **un solo proceso**. Si dos ficheros de pruebas declaran una función con el mismo nombre, PHP corta con «Cannot redeclare» aunque cada uno, por separado, pase. Ponle a tus ayudas el prefijo del plugin (`consejosSite()`, `ecotasaCart()`, `avisosOrder()`) o decláralas dentro de `if (!function_exists('…')) { … }`.

## Qué debe comprobar la prueba de un plugin

1. **Se activa y se carga**: `isLoaded()`, sin entrada en `failed()`, su tabla existe.
2. **Hace lo suyo**, con datos reales y los casos raros: vacío, `null`, un texto con «&», comillas, tildes, eñes y emojis.
3. **Escapa**: un dato con `<` y `&` sale como `&lt;` y `&amp;`.
4. **Guarda de verdad**: relee **la base de datos**, no el HTML.
5. **Permisos**: se abre con permiso y da 403 sin él.
6. **Se desactiva**: tras `disable()`, `isLoaded()` es falso.
7. **Pasa el contrato**: `$plugins->validate('<id>')` con `ok`, `dynamic` y nota 100.

## Una prueba que no falla no vale

Antes de darlo por bueno, rompe a propósito una línea del plugin, comprueba que alguna prueba salta y deshaz el cambio. Con el plugin de [Tu primer plugin](02-tu-primer-plugin.md) y los de esta guía se hizo así:

| Qué se rompió | Quién saltó |
|---|---|
| Quitar el `e()` de `views/front/tip.php` | La prueba del hueco y el validador del contrato (aviso C4). |
| Quitar `Prompt::fence()` en Avisos de pedido | «la nota del cliente va cercada». |
| Cambiar el `source` de la línea de ajuste de Ecotasa | «la tasa sale como una línea de ajuste con su nombre y su origen». |

Si al reponer el fallo ninguna prueba salta, esa prueba no comprueba nada.

## Datos de ejemplo: ni teléfonos ni documentos de nadie

Un número «de relleno» con forma de móvil o de DNI puede ser el de una persona. En el proyecto no se escribe ninguno, y dos pruebas lo vigilan (`tests/Privacy/NoPhonesTest.php` y `tests/Privacy/NoRealIdsTest.php`): si tu plugin trae uno, fallan.

| Dato | Qué se escribe | Si hace falta variedad |
|---|---|---|
| Correo | `ana@cliente.test`, `tienda@ejemplo.test` (dominios `.test`) | Otros nombres en esos dominios |
| Teléfono | Del rango 999, que no está atribuido a nadie: `999000001`, `+34 999 123 456`. De otro país, `+44 7700 900123` | Súmale al final: `999000002`… |
| DNI | `12345678Z`. También `00000000T`, `99999999R` y los de cifras repetidas o en escalera | `fakeDni()` da uno al azar con su letra |
| NIE | `X1234567L` | `fakeNie()`, o `fakeNie('Y')` |
| NIF de entidad | `B12345674`, `P1234567D` | `fakeCompanyTaxId()`, o `fakeCompanyTaxId('A')` |
| Nombre | Ana Ruiz, Luis Peña: nombres corrientes inventados | — |

Las tres ayudas están en `tests/support.php`. Calculan la letra o el dígito de control, así que lo que devuelven pasa `Spain::validTaxId()`; con un número fijo dan siempre lo mismo (`fakeDni(12345678)` es `12345678Z`). Los que salen al azar se generan al ejecutar la prueba y no quedan escritos: si la prueba falla, que el mensaje enseñe el documento con el que ha fallado.

```php
test('el pedido guarda el NIF del cliente', function (): void {
    $dni = fakeDni();
    // … haz el pedido con $dni …
    same($dni, $order['tax_id'], 'con ' . $dni);
    check(!Spain::validTaxId(substr($dni, 0, 8) . ($dni[8] === 'A' ? 'B' : 'A')), 'con otra letra no vale');
});
```

## Si algo no sale

- **«Cannot redeclare …».** Dos ficheros de pruebas con una ayuda del mismo nombre. Cámbiale el nombre.
- **«Call to undefined function aifecProduct».** Falta el `require_once` de las ayudas del catálogo.
- **La prueba pasa sola y falla con las demás.** Estado compartido: una constante, una variable estática o una función global. Cada prueba empieza con un sitio nuevo; lo que no sea el sitio, se reinicia a mano.
- **«un oyente de un gancho ha lanzado y la prueba no lo esperaba (expectHookErrors())».** Algún oyente falla durante la prueba. Arregla el oyente o, si es lo que quieres probar, empieza con `expectHookErrors()`.

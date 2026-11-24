# Publicar y vender

Esta página dice qué hace falta para entregar un plugin a otra persona y cómo lo instala ella, y qué NO existe todavía en AIFEC alrededor de la distribución. Lo que no está escrito aquí no lo hace el sistema.

## La versión

El número está en `plugin.json`, campo `version`: tres números separados por puntos (`1.0.0`) y, si quieres, un sufijo (`1.1.0-beta.1`). Sube el número en cada entrega: el gestor enseña la versión instalada, y el informe del contrato se guarda con la versión y la huella de los ficheros que se validaron.

Un criterio práctico: el tercer número para arreglos, el segundo para funciones nuevas que no rompen nada, el primero cuando algo deja de ser compatible (una tabla que cambia de forma, un ajuste que se renombra).

## Compatibilidad

Declárala en `requires`; el sistema la comprueba al activar:

```json
"requires": {
    "php": ">=7.4",
    "aifec": ">=1.0.0",
    "modules": ["catalog"]
}
```

- `php` y `aifec` admiten `>=`, `<=`, `>`, `<`, `==`, `!=` y varias condiciones separadas por comas (`>=7.4,<9`).
- `modules` son los módulos que tienen que estar activos. Si falta uno, el plugin no se activa y dice cuál: «Necesita que el módulo «catalog» esté activo».
- Prueba el plugin con la versión más baja de PHP que declares y con la más alta: ver [Pruebas](09-pruebas.md).

AIFEC corre en PHP 7.4 a 8.4 y sin Composer: tu plugin no puede depender de librerías que no estén ahí.

## Antes de entregar

Una lista corta, todo comprobable:

1. `php aifec contract:check <id>` da `score 100/100, 0 errors, 0 warnings`.
2. `php tests/run.php plugins/<id>` da `0 FALLAN` con PHP 7.4 y con 8.x.
3. `php aifec i18n:check plugins/<id>` da `0 missing`.
4. Los ficheros de datos de ejemplo llevan datos inventados con pinta de inventados (`ana@ejemplo.test`, «Calle Mayor 1», `clave-de-mentira`): nunca un dato real.
5. Ninguna clave, contraseña ni dirección privada dentro del plugin. Un secreto lo escribe el comerciante en un ajuste de tipo `secret`.
6. Un `LEEME.md` en la carpeta del plugin: qué hace, qué ajustes tiene, qué declara en el contrato.

## El paquete

Un plugin se entrega como un **ZIP con una sola carpeta dentro**: la del plugin, con su `plugin.json`.

```
consejos-producto.zip
  consejos-producto/
    plugin.json
    hooks.php
    …
```

El instalador rechaza el ZIP si no cumple, antes de escribir nada en el sitio:

| Regla | Mensaje |
|---|---|
| Una sola carpeta raíz con el plugin dentro. | «El ZIP debe contener una sola carpeta con el plugin dentro» |
| Rutas sin `..` ni absolutas. | «El ZIP tiene una ruta que no se permite: «…»» |
| Sin enlaces simbólicos. | «El ZIP contiene un enlace simbólico: «…»» |
| Sin ficheros fuera de la carpeta del plugin. | «El ZIP tiene ficheros fuera de la carpeta del plugin: «…»» |
| `plugin.json` válido. | «El plugin.json del ZIP no es válido» |
| Hasta 2.000 ficheros y 20 MB ya descomprimido. | «El ZIP tiene más de … ficheros» |

Después pasa el validador estático y, solo si todo está bien, se mueve a `plugins/<id>/`. El servidor necesita la extensión `zip` de PHP. Esto está en el código del instalador y en sus pruebas del proyecto; en esta guía **no se ha instalado ningún ZIP real**.

## Instalar

Quien lo recibe tiene dos caminos:

- En el panel: «Módulos y plugins» › «Subir un plugin» › «Subir ZIP».
- En consola: `php aifec plugin:install consejos-producto.zip`.

Instalar **no activa**. Activar es otro paso, y antes de activar pasa el contrato completo (con su parte dinámica). Un plugin que viene de fuera y declara `pricing` además necesita que el administrador le conceda la capacidad: ver [Ganchos](03-ganchos.md).

## Actualizar a una versión nueva

El instalador no sobrescribe: si el plugin ya está instalado, dice ««…» ya está instalado; desinstálalo antes». Para actualizar sin perder datos, según lo que hacen esas órdenes:

```
php aifec plugin:disable consejos-producto
php aifec plugin:uninstall consejos-producto --keep-data
php aifec plugin:install consejos-producto-1.1.0.zip
php aifec plugin:enable consejos-producto
```

Con `--keep-data` se quedan las tablas, los ajustes y el registro de migraciones; al activar la versión nueva, solo se aplican las migraciones nuevas. Recuerda que `plugin:uninstall` borra la carpeta del plugin: ten copia. Este camino sale de leer el código; **no se ha probado de punta a punta con un ZIP**.

## Licencia

AIFEC se publica con la licencia **GNU AGPL v3 con términos adicionales de origen** (el `LICENSE.md` del proyecto, desde la 1.3.0; hasta la 1.2.6 fue Apache 2.0): se puede usar, cambiar, redistribuir y vender, siempre que se mantenga el aviso visible «Creado con AIFEC», la autoría de Ecom Experts y la marca del generador, y que las versiones modificadas se publiquen con la misma licencia. Hay una licencia comercial aparte para quien no pueda cumplirlo.

La licencia de **tu** plugin es tuya: el sistema no la comprueba ni la exige. El generador (`plugin:create --license=…`) la escribe en la cabecera `@license` de cada fichero, y si no la pasas pone «See LICENSE». Si publicas el plugin con un código distinto, ponle tu `LICENSE.md` dentro de la carpeta y la misma línea en las cabeceras.

## Vender

El núcleo de AIFEC **no incluye** un mercado de plugins, ni actualizaciones automáticas, ni cobro por plugin. Lo que se vende y cómo se entrega lo resuelves tú fuera del sistema: un ZIP y las instrucciones de esta página. Una tienda hecha con AIFEC puede vender ficheros descargables como productos digitales, pero eso no se ha probado para repartir plugins y no es una función pensada para ello.

Aparte, en la carpeta `plugins/` del proyecto hay un plugin, `gm-licencias`, cuyo `plugin.json` dice que gestiona «licencias del software que vende la tienda: una clave por unidad comprada, una API de verificación y el panel para gestionarlas». Es un plugin como el tuyo, no una función del núcleo, y aquí no se ha probado: míralo como ejemplo de un plugin más grande, no como una promesa.

## Si algo no sale

- **«El ZIP debe contener una sola carpeta con el plugin dentro».** Comprimiste el contenido de la carpeta y no la carpeta. Comprime la carpeta entera.
- **«… ya está instalado; desinstálalo antes».** Sigue los cuatro pasos de actualizar.
- **«Necesita PHP 8.1 y este servidor tiene 7.4».** El `requires.php` pide más de lo que hay. Baja la exigencia si tu código lo permite, o avisa a quien instala.
- **«Este servidor no puede abrir ficheros ZIP (falta la extensión zip)».** Pide al alojamiento que active la extensión `zip` de PHP, o copia la carpeta del plugin a `plugins/` por FTP.

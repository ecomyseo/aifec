# Consola

Todo lo que se puede hacer desde el panel y mucho de lo que no, se hace también desde la línea de órdenes: `php aifec <orden>`. Esta página explica cómo se usa la consola y cómo leer la tabla de órdenes, que no se copia aquí porque sale de lo que el sistema declara: [Órdenes de consola](_generado/consola.md).

## Cómo se usa

```
php aifec <orden> [argumentos] [--site=<id>] [--opción=valor]
```

Se lanza desde la carpeta del proyecto, donde está el fichero `aifec`. Con `php aifec help` sale la lista de órdenes con una línea cada una.

- Los **argumentos sin nombre** van por posición: `php aifec plugin:enable consejos-producto`.
- Las **opciones** llevan dos guiones: `--name="Escaparate"`, `--keep-data` (sin valor, vale «sí»).
- `--site=<id>` elige el sitio sobre el que actúa la orden cuando la instalación tiene varios. Sin él, actúa sobre el primer sitio de la lista (`php aifec sites`).
- `--data=<carpeta>` apunta a otra carpeta de datos distinta de la normal.
- Una orden que termina bien sale con código 0. Con un fallo, 1; con un uso incorrecto (falta un argumento, una orden o un sitio que no existe), 2. Por eso valen en un guion.

Ejemplos de la guía:

```
php aifec modules                                   # qué módulos y plugins hay y si están cargados
php aifec plugin:create mi-plugin --name="Mi plugin"
php aifec contract:check mi-plugin
php aifec theme:check escaparate
php aifec i18n:check plugins/mi-plugin
php aifec setting:set theme.active escaparate
php aifec cache:clear
php aifec cron --seconds=50
```

## Cómo leer la tabla

La tabla generada tiene tres columnas:

| Columna | Qué dice |
|---|---|
| **Orden** | El nombre que se escribe tras `php aifec`. Los dos puntos agrupan por tema: `plugin:enable`, `jobs:work`, `import:run`. |
| **Qué hace** | Una frase en inglés, y a continuación el modo de uso: `<…>` es un argumento obligatorio, `[…]` es opcional y `a\|b` es una elección. |
| **Módulo** | Quién declara la orden: `core` (el núcleo) o el módulo o plugin que la aporta. |

La lista depende de lo que esté **cargado**. Una orden de un plugin solo sale si el plugin está activo. Y hay órdenes que no pertenecen a ningún sitio (crear, borrar y configurar sitios) y las aporta el instalador.

## Dar una orden a tu plugin

Un módulo o plugin declara órdenes en `cli.php`. Es lo que enseña `plugin:create --features=command`:

```php
return [
    'mi-plugin:hola' => ['help' => 'Says hello (example command)', 'run' => static function (App $app, array $args, Console $io): int {
        $io->line('Hello from mi-plugin');

        return 0;
    }],
];
```

La orden recibe la aplicación, los argumentos y la consola (`$io->line()`, `$io->error()`), y devuelve su código de salida. El texto `help` va en inglés. Nómbrala con el id de tu plugin como prefijo para no chocar con otras.

## Regenerar la tabla

La tabla se escribe con una orden: `php aifec docs:reference` (con `--out=` para elegir la carpeta). No se edita a mano; se rehace cuando cambian los módulos.

## Si algo no sale

- **«Unknown command: … (php aifec help)».** La orden no existe en lo que está cargado (sale con código 2). Mira `php aifec modules`: si es de un plugin, está apagado.
- **La orden actúa sobre el sitio equivocado.** Sin `--site`, usa el primero de la lista. Pasa `--site=<id>`.
- **«Unknown site: …».** El id de `--site` no existe; `php aifec sites` lista los ids.
- **El código de salida es 0 y creo que falló.** Detrás de una tubería (`| head`), el código es el de la última orden, no el de `php aifec`. Guarda la salida en un fichero y mira el código antes de recortar nada.

# Actualizar AIFEC

Actualizar es sustituir el código, dejar que la base de datos se ponga al día y vaciar las cachés. Los datos, las imágenes y la configuración no se tocan. Esta página da el orden seguro y dice cómo volver atrás si algo sale mal.

## Qué se sustituye y qué no

| Se sustituye | No se toca |
|---|---|
| `core/`, `modules/`, `themes/` de serie, `plugins/` de serie | La carpeta de datos (`sites.php`, `<sitio>/config.php`, bases, cachés, copias) |
| `aifec` (la orden de consola) y `public/index.php` y `public/.htaccess` | `public/media/` (tus imágenes) |
| `LICENSE.md`, `README.md` | `data-path.php` y `public/aifec-root.php`, si existen |
| | Tus temas y plugins propios |

`public/assets/` se vuelve a generar con una orden; no se copia a mano.

## Pasos

1. **Haz una copia completa.** La base de datos (`php aifec backup`), `config.php`, `public/media/` y la carpeta `private/`. Detalle en [Copias y restaurar](06-copias-y-restaurar.md). Sin esto no hay vuelta atrás.
2. **Pon el modo mantenimiento.** Las visitas verán una página «Volvemos enseguida» mientras trabajas. Por el panel: «Sistema» → «Caché y mantenimiento» → «Poner el modo mantenimiento». Por consola:

```
$ php aifec setting:set tools.maintenance true
tools.maintenance = true
```

Con el modo puesto, una petición a la portada contesta `503` con `Retry-After: 3600` y `Cache-Control: no-store`: los buscadores entienden que es temporal. Siguen funcionando el panel, `/assets/`, `/cron/` y los avisos de las pasarelas de pago. Quien ha entrado al panel ve la tienda con una franja de aviso.

3. **Sustituye el código.** Sube el código nuevo a una carpeta aparte y cámbiala por la vieja (renombrando), o sincroniza con `rsync`, que escribe en un fichero temporal y lo renombra. No pises encima ficheros estáticos que ya se están sirviendo: nginx los mantiene abiertos unos segundos y puede entregar un fichero con el tamaño viejo y el contenido nuevo.
4. **Pon la base de datos al día.** Una vez por sitio:

```
$ php aifec migrate --site=main
Nothing to migrate.
```

Cuando hay migraciones pendientes, la orden escribe «Applied: » y la lista de las que ha aplicado. Si dice «Nothing to migrate.» no es un fallo: las migraciones también se aplican solas al arrancar, en cuanto cambia la huella de los ficheros de migración, y una petición o una pasada del cron puede haberlas aplicado un momento antes. Una migración aplicada no se edita ni se deshace: si hay que cambiar una tabla, el código nuevo trae otra migración.

5. **Vuelve a publicar los recursos y vacía las cachés:**

```
$ php aifec assets:publish
Published 47 files, build e76740ca0c
$ php aifec cache:clear
Cache cleared.
```

Cada publicación estrena una carpeta de versión (`public/assets/…/<versión>/`), así que las páginas que ya estaban en el navegador no se rompen. Si PHP tiene OPcache con `validate_timestamps` apagado, reinicia PHP-FPM para que lea el código nuevo.

6. **Comprueba.**

```
$ php aifec version
AIFEC 1.0.0 · PHP 8.3.15
$ php aifec modules
```

`modules` lista cada módulo y plugin con su estado: `loaded`, `off` o `FAILED: <motivo>`. Un plugin tuyo en `FAILED` es el primero que hay que mirar; `php aifec contract:check <id>` explica qué no cumple. Después, `php aifec doctor` y abre la portada, una ficha de producto y el panel.

7. **Quita el modo mantenimiento:** `php aifec setting:set tools.maintenance false` o el botón «Quitar el modo mantenimiento».

## Volver atrás

No hay una orden de deshacer: las migraciones solo van hacia delante. Volver atrás es restaurar las dos cosas a la vez:

1. Pon el modo mantenimiento.
2. Devuelve el código anterior (la carpeta que renombraste en el paso 3).
3. Restaura la base de datos de la copia del paso 1 ([Copias y restaurar](06-copias-y-restaurar.md)).
4. `php aifec cache:clear` y `php aifec assets:publish`, y quita el mantenimiento.

Los pedidos y los cambios hechos después de la copia se pierden: por eso se pone el modo mantenimiento **antes** de la copia, si la tienda vende mucho. Con código viejo y base nueva el sistema puede dar errores de columnas que no existen, así que no vuelvas solo el código.

## Si algo no sale

### Revisar la codificación antes y después de publicar

Los ficheros de texto y el HTML servido deben ser UTF-8 válido. Comprueba la portada, una categoría, un producto y las pantallas cambiadas; una respuesta HTTP correcta no garantiza que las tildes estén bien.

El código contiene cinco líneas con caracteres intencionales que un detector sencillo puede confundir con corrupción: el ejemplo y el patrón de reparación de `modules/assistant/src/TextGuard.php`, la tabla multilingüe de transliteración de `modules/copilot/src/Reader.php` y las pruebas de la ligadura latina de códigos Unicode U+00C6/U+00E6 en `tests/Pdf/PdfEncodingTest.php` y `tests/Util/StrTest.php`. Estas excepciones corresponden a líneas concretas; no justifican excluir esos ficheros ni ignorar otras coincidencias. El proceso de publicación debe conservar una huella de cada línea revisada y detenerse si cambia o aparece otro aviso.

- **Tras subir el código, todas las páginas dan 500.** Mira el registro (`<datos>/<sitio>/logs/`) o activa `debug` solo un momento (`php aifec site:config main debug=1`, y vuelve a `debug=0`). Suele ser PHP más viejo de lo que pide la versión nueva.
- **La portada sigue enseñando lo de antes.** Es la caché de páginas o OPcache. Pasa `cache:clear` y reinicia PHP-FPM.
- **Faltan estilos o scripts.** No se publicaron los recursos: `php aifec assets:publish`.
- **«FAILED» en un plugin tras actualizar.** El plugin usa algo que cambió. Pasa `contract:check` y actualiza el plugin.
- **El doctor avisa «Migraciones pendientes».** Pasa `php aifec migrate` en ese sitio.

# Arquitectura

AIFEC es una tienda en PHP 7.4 a 8.4, sin Composer ni librerías externas, que se instala como un WordPress: unos ficheros, una carpeta de datos y una base de datos (SQLite o MySQL/MariaDB). Esta página sigue una petición desde el primer fichero hasta la respuesta y explica de qué piezas está hecho el sistema y cómo conviven varios sitios en una instalación.

## Las piezas

```
aifec/
  aifec                      orden de consola (php aifec …)
  public/                    raíz web: index.php, assets/ (publicado), media/<sitio>/
  core/                      el núcleo: arranque, enrutador, ganchos, datos, vistas, panel
  modules/<slug>/            módulos incluidos: catálogo, pedidos, correo, SEO, IA…
  plugins/<slug>/            plugins de terceros
  themes/<slug>/             temas
  docs/, tests/
  sites/                     datos de los sitios (o la carpeta que diga data-path.php)
```

| Pieza | Qué es |
|---|---|
| **Núcleo** (`core/`) | Lo mínimo común: `Kernel` (aplicación, petición, respuesta, enrutador, ganchos, ajustes, sesión, caché, i18n), `Db` (datos y migraciones), `View` (plantillas), `Admin` (panel declarativo), `Cli` (consola) y librerías puras (correo, PDF, búsqueda, IA, imágenes…). |
| **Módulos** (`modules/`) | Las funciones de la tienda: cada uno trae sus rutas, ajustes, tablas, plantillas y traducciones. Algunos son esenciales (no se apagan) y otros se encienden o se apagan. |
| **Plugins** (`plugins/`) | Ampliaciones de terceros. Misma forma y misma API que un módulo; pasan el [Contrato AIFEC](../06-plugins/08-el-contrato-aifec.md) antes de activarse. |
| **Temas** (`themes/`) | El aspecto: envoltorio, piezas, hojas y guion. Un tema hijo hereda de su padre. Ver [Cómo funciona un tema](../05-temas/01-como-funciona-un-tema.md). |

La idea de fondo es que **módulos incluidos y plugins usan la misma API de extensión** (ganchos, ajustes, rutas, panel): es la prueba de que esa API basta. Y que lo que pinta una página, el JSON-LD, la versión en texto, los feeds, la API y el asistente salen de las mismas funciones: una sola verdad por producto.

## El recorrido de una petición

```
navegador ──► servidor web ──► public/index.php
                                   │
                                   ▼
                        core/bootstrap.php  (cargador de clases)
                                   │
                                   ▼
                          Front::run(raíz, datos, pública)
                                   │
            ┌──────────────────────┼───────────────────────┐
            ▼                      ▼                       ▼
      /assets/…              sin sitios aún           resolver el SITIO
   AssetServer sirve         instalador               (dominio + prefijo)
   el fichero y acaba
                                                           │
                                                           ▼
                                           ¿hay copia en la caché de página?
                                            (GET sin cookies de sesión)
                                             │ sí                  │ no
                                             ▼                     ▼
                                      se sirve ya,          App::handle(petición)
                                      sin módulos                  │
                                                                   ▼
                                                  boot: ajustes, base de datos,
                                                  módulos y plugins activos
                                                  (hooks.php, routes.php, settings,
                                                  admin), tema (lang, functions.php),
                                                  acción app.boot
                                                                   │
                                                                   ▼
                                       idioma · filtro request.start · enrutador
                                                                   │
                                      ┌────────────────────────────┼───────────────┐
                                      ▼                            ▼               ▼
                                 ruta fija                dirección amigable   404 (tras el
                                 + intermediarios         de una entidad       filtro
                                 + controlador            (tabla slugs)        request.not_found)
                                      │                            │
                                      └────────────┬───────────────┘
                                                   ▼
                                               Response
                                                   │
                                                   ▼
                          finish: sesiones, cookies, cabeceras de seguridad,
                          filtro response, acción request.end,
                          caché de página (si es cacheable) → send()
```

Paso a paso:

1. **`public/index.php`** carga `core/bootstrap.php` (el cargador de clases; no hay Composer) y la ruta de la carpeta de datos (`data-path.php`, o `sites/` junto al código). Llama a `Front::run()`.
2. **Recursos.** Una ruta que empieza por `/assets/` la sirve `AssetServer` desde el disco (temas, módulos, plugins, núcleo) y termina. En producción, `php aifec assets:publish` copia esos ficheros a `public/assets/` y los sirve el servidor web sin pasar por PHP.
3. **Instalador.** Si todavía no hay ningún sitio, responde el instalador.
4. **El sitio.** Se elige el sitio por el dominio y el prefijo de la ruta. La ruta se vuelve relativa al sitio (`/demos/moda/carrito` pasa a `/carrito`).
5. **Caché de página.** Si es un `GET` sin las cookies del panel, de la cuenta de cliente o de «sin caché», y hay una copia vigente, se sirve **sin arrancar nada** (ni base de datos ni módulos). Aquí se resuelve casi todo el tráfico de una tienda.
6. **Arranque** (`App::boot()`). Ajustes, base de datos, módulos y plugins activos (cada uno registra sus ganchos, rutas, ajustes y panel), el tema (su `lang/` y su `functions.php`, del base al activo) y la acción `app.boot`. Las migraciones pendientes se miran al entrar al panel, por consola y en el cron; una página de la tienda no lo hace en cada visita. Lo que se lee del disco en un arranque (manifiestos, módulos activos, rutas compiladas) se guarda en una caché de arranque.
7. **Despacho.** El idioma (el principal no lleva prefijo; los demás, `/en/…`), el filtro `request.start` (puede devolver una respuesta y cortar), y el enrutador:
   - una **ruta fija**, con sus intermediarios (`admin`, `customer`, `csrf`, `api`) y su controlador;
   - si no hay ruta fija y es un `GET`, una **dirección amigable** de la tabla `slugs`: se resuelve a una entidad (un producto, una categoría, una página) y se llama a quien la pinta, con redirección 301 si la dirección no es la canónica;
   - el filtro `request.not_found` (redirecciones manuales, direcciones antiguas) y, si nadie responde, un 404.
8. **Respuesta.** Los controladores devuelven siempre una `Response`; nadie escribe con `echo` ni con `header()`. Al final se guardan las sesiones, se ponen las cookies pendientes y las cabeceras de seguridad, se pasa la respuesta por el filtro `response`, se dispara `request.end` y, si el controlador la marcó como cacheable y no pone cookies, se guarda en la caché de página. La cabecera `Server-Timing` dice cuánto tardó cada fase.

Un fallo en un controlador o en un oyente no tumba el sitio: se anota en el registro y se pinta una página de error (con el detalle solo si la depuración está encendida).

## Ganchos, ajustes, datos y plantillas

Cuatro mecanismos atraviesan todo el recorrido:

- **Ganchos**: acciones, filtros y huecos de plantilla. Cada oyente queda atribuido a su módulo y se mide su tiempo. Ver [Ganchos](../06-plugins/03-ganchos.md).
- **Ajustes**: claves por sitio (`tax.base_country`) que declara cada módulo en su `settings.php`. Ver [Ajustes](04-ajustes.md).
- **Datos**: una capa común (`$app->db()`) con SQL portable entre SQLite y MySQL. Las tablas se crean con migraciones que aplica el sistema. Ver [Datos y migraciones](../06-plugins/05-datos-y-migraciones.md).
- **Plantillas**: PHP puro con `$this->layout()`, `partial()` y `section()`; el tema puede sustituir cualquier plantilla de un módulo.

## Varios sitios en una instalación

Una instalación puede alojar varios **sitios**, cada uno con su propia carpeta de datos:

```
sites/
  sites.php                 la lista de sitios (dominio, prefijo)
  <sitio>/
    config.php              configuración: dirección, secreto, base de datos
    db.sqlite               la base de datos, si es SQLite
    cache/ logs/ private/ tmp/
```

- El sitio se elige por **dominio** y por **prefijo de ruta** (`/demos/moda`): así las demostraciones son sitios de la misma instalación que cuelgan de una subcarpeta.
- Cada sitio tiene su base de datos (o su prefijo de tablas, por defecto `af_`), sus ajustes, sus usuarios y sus archivos.
- Las cookies llevan el identificador del sitio en el nombre, para que dos sitios del mismo dominio no compartan sesión ni carrito.
- La carpeta de datos puede estar **fuera de la raíz web**: la raíz solo debe contener `index.php`, los recursos publicados y los medios públicos. Los datos privados (copias, registros, ficheros subidos) no deben servirse nunca.
- En consola, `php aifec sites` lista los sitios y `--site=<id>` elige uno; `site:create`, `site:config` y `site:delete` los gestionan.

## Si algo no sale

- **Cambio código y la tienda enseña lo anterior.** Hay dos cachés: la de arranque y la de páginas. `php aifec cache:clear` vacía los datos y las páginas; abrir el panel o correr el cron revisan los ficheros.
- **Un módulo nuevo no aparece.** El arranque está en caché y solo lo revisa el panel, la consola y el cron: lanza cualquier orden de `php aifec` o abre el panel.
- **Veo «No site is configured for this address».** El dominio o el prefijo no coinciden con ningún sitio de `sites.php`. `php aifec sites` los lista.

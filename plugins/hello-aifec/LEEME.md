# Hello AIFEC

Plugin de ejemplo del sistema AIFEC. Es el que enseña la guía de desarrollo: pequeño, completo y con su prueba. Viene **desactivado**.

## Qué hace

Añade a cada producto el dato «Hecho en» (el país donde se fabrica) y lo enseña en tres sitios:

| Punto de extensión | Dónde está | Qué hace |
|---|---|---|
| Dato tipado en el producto (`product.extra`) | `hooks.php` | Sale en `ProductData`, en la API y en el JSON-LD con su etiqueta, su tipo (`text`) y `public: true`. |
| Hueco de plantilla (`product.summary.after`) | `hooks.php`, `views/front/made-in.php` | Pinta «Fabricado en: España» bajo el resumen, ya escapado. |
| Campo en el panel (`admin.definitions`) | `hooks.php` | Añade el campo al formulario de productos cuando ese recurso existe. Lo guarda el propio plugin en su tabla. |
| Ajuste | `settings.php` | «País por defecto», para los productos que no tienen el suyo. |

Su tabla, `hello_aifec_made_in`, está declarada en `plugin.json` y la crea `migrations/0001_made_in.php`. Al desinstalar sin conservar los datos se borran la tabla y el ajuste.

## Cómo se usa

1. Panel, «Módulos y plugins», pestaña «Plugins»: pulsa «Activar». Antes pasa el validador del Contrato AIFEC.
2. Por consola: `php aifec plugin:enable hello-aifec` y `php aifec contract:check hello-aifec`.
3. Pruebas: `php tests/run.php plugins/hello-aifec`.
4. Traducciones: `php aifec i18n:check plugins/hello-aifec`.

## Qué declara en el contrato

Ningún servicio externo, ningún dato personal, una tabla propia y ninguna capacidad: no toca precios, impuestos ni plazos.

Nota para quien copia el ejemplo: en el catálogo real, el `form` del recurso de productos es una **función** `(App $app, ?int $id)` que devuelve el formulario, no un array. El plugin la envuelve (llama a la que había y añade su campo al resultado, con su valor guardado como `default`), como hacen los `gm-*`. La prueba abre la ficha del panel con el catálogo real y comprueba que el campo sale, se guarda y se pinta en la tienda.

---
name: aifec-crear-demo
description: "Crear el paquete de demostración de un sector para AIFEC (carpeta demos/<sector>/): catálogo creíble de 24 a 60 productos con variantes y atributos, imágenes propias, páginas, entradas de blog, menús, tema con sus opciones, envíos, cupones y valoraciones, de modo que pase `php aifec demo:check` a la primera. Usar cuando se pida «haz una demo de <sector>», «crea un paquete de demostración», «monta una tienda de ejemplo de <sector>». No sirve para crear un tema (fábrica de temas), un plugin (fábrica de plugins) ni para cargar los datos de una tienda real."
---

# Crear un paquete de demo de AIFEC

Un paquete es una carpeta `demos/<sector>/` con JSON y una carpeta `media/`. El módulo `sites` la comprueba (`demo:check`) y
la carga en un sitio de pruebas (`demo:create`). Este skill te lleva de «un sector» a «un paquete que pasa a la primera».

**Se usa** para sectores nuevos o para rehacer un catálogo de demo. **No se usa** para: un arreglo de dos líneas en un
paquete (se arregla y se pasa `demo:check`), cambiar el diseño de un tema, ni importar los productos de una tienda real
(una demo es siempre inventada).

## Antes de empezar (5 minutos, no más)

1. Lee `referencias/formato.md` (el formato, resumido) y, si dudas de un campo, el original `demos/_esquema/LEEME.md`.
2. Abre un paquete ya hecho como modelo: `demos/moda/` (completo) o `demos/minimo/` (el más corto).
3. Mira qué temas hay: `ls themes/` y elige uno (ver fase 5). Un tema que no existe hace fallar `demo:check`.
4. Tu guion generador, tus notas y tus pruebas viven **fuera** del paquete y del proyecto
   (por ejemplo `utiles/aifec/demos/<sector>/`): el paquete solo lleva sus JSON, `media/` y, si el director la hace, `preview.png`.

## Las nueve fases

**1. El sector.** Escribe en tus notas, con números: qué vende, a quién, rango de precios (en céntimos), 4-6 atributos que
importan en ese sector (moda: talla, color, material; papelería: formato, gramaje, material), qué variantes tiene, 4-6
categorías de primer nivel con 2-4 hijas, 4-6 marcas inventadas y el tema. Rellena `plantillas/hoja-de-catalogo.md`.

**2. El catálogo (24 a 60 productos).** Por cada producto: nombre concreto y único («Libreta cosida Alba A5», no «Producto 3»),
resumen de una o dos frases (distinto en cada uno), descripción de **60 palabras o más** escrita para quien compra y
terminada en «Producto de ejemplo.», 3 o más datos clave (`facts`), 2 o más preguntas (`faqs`), atributos, `sku`
`DEMO-<SECTOR>-NNN`, `gtin` inventado que empieza por `299` con su cifra de control (`herramientas/` no lo genera: copia la
función `gtin()` de `plantillas/hoja-de-catalogo.md`), peso en gramos y plazo de entrega. Reparto mínimo: 3 ofertas, 4
destacados, 3 con variantes, 1 agotado y 1 con pocas existencias. Precios en **céntimos enteros** con el IVA dentro. En un
producto con variantes, el `stock` y el `gtin` van en cada variante, no en el producto. Enlaza siempre **por código**
(categoría, marca, atributo, valor), nunca por número.

**3. Las imágenes.** Una portada **propia** por producto (la primera de `images`) y de 2 a 3 vistas. Opciones, por orden:
(a) SVG dibujados por programa con `herramientas/ilustrar.py` (15 formas de papelería listas; para otro sector añade tus
formas en tu guion con el mismo molde); (b) fotos **con licencia clara**, anotando el origen de cada una en tus notas
(fuera del paquete); **nunca** imágenes de una tienda real ni de un banco de imágenes sin licencia. Reglas medidas: cada SVG
con `viewBox`, menos de 20 KB, sin `<script>`, sin enlaces ni imágenes incrustadas; el guion no usa azar (misma salida
siempre); dos productos no pueden dar el mismo fichero. Nombres: `<producto>-<vista>.svg`, solo minúsculas, cifras, `.` `_` `-`.
Además: imagen de portada de la tienda, de «quiénes somos» y de cada entrada del blog.

**4. Los contenidos y los menús.** `pages.json`: `inicio` (`home: true`, solo una) con cabecera (`cover` con `media_file`),
productos destacados, una categoría, bloque de confianza (`columns`) y las últimas entradas (`posts`); `quienes-somos`,
`envios-y-devoluciones`, `contacto` (bloque `form` con `form_id: "contact"`) y `aviso-legal`. `posts.json`: 2 entradas de 200
palabras o más, cada una con un bloque `products` dentro. `menus.json`: `main` (3 elementos o más), `footer` y `legal`.
Dentro de un bloque se enlaza por código: `category_code`, `product_skus`, `media_file`. **Nunca** un enlace escrito a mano
(`href="/categoria/…"`): la demo vive en una subcarpeta y llevaría al sitio maestro. Todo texto dice o deja claro que es de ejemplo.
Bloques válidos: `paragraph`, `heading` (con `level`), `list`, `image`, `cover`, `columns`/`column`, `products`, `posts`,
`form`, `table`, `buttons`, `quote`, `details`, `separator`, `spacer`, `gallery`.

**5. El tema y sus opciones.** `theme` es el `slug` de una carpeta de `themes/`: `atelier` (moda), `taller` (bricolaje),
`despensa` (gourmet), `base` (cualquier otro sector). Las opciones que puedes poner en `theme_options` son las que declara
`themes/<tema>/theme.json` en `settings.<grupo>.fields.<opción>` (con su tipo y su valor por defecto): pon solo las que
cambies. Un color de acento oscuro con `accent_ink` claro; textos de la cabecera y de la franja en castellano y en tono de ejemplo.
`store`: nombre, correo `@ejemplo.test`, **sin teléfono** (un número «de relleno» es el de alguien), «Calle Mayor 1 (dirección de ejemplo)», moneda EUR, IVA dentro.
`carriers.json`: un envío por tramos de peso con zonas `Peninsula`, `Balearic Islands`, `Canary Islands` y gratis desde un
importe, más «Recogida en tienda» (`handler: pickup`). `coupons.json`: 2 cupones. `reviews.json`: 8 o más opiniones de nombres
de pila inventados, **no todas de cinco estrellas**, cada una empezando por «Opinión inventada para la demostración:».

**6. Pedidos y clientes de ejemplo.** El formato actual **no tiene fichero de pedidos ni de clientes** (`files` solo admite
los diez ficheros de `referencias/formato.md`; un `orders.json` hace fallar `demo:check`). No lo inventes. Se hacen a mano
en la fase 8, comprando en el sitio de pruebas con el pago simulado y una persona inventada (Ana Ejemplo, `ana@cliente.test`,
sin teléfono). Si hace falta que viajen con el paquete, se pide un cambio al módulo `sites`; no se improvisa.

**7. Validar.** Desde la raíz del proyecto, con el paquete ya en `demos/<sector>/`:
`php aifec demo:check <sector>` hasta `DEMO <sector>: 0 problems`. Si estás trabajando fuera de `demos/`:
`php skills/aifec-crear-demo/herramientas/demo_check.php <carpeta del paquete>` (misma clase, mismo veredicto).
Después `python skills/aifec-crear-demo/herramientas/comprobar_extra.py <carpeta del paquete>` hasta `0 errores, 0 avisos`
(nombres y portadas repetidos, descripciones cortas, SVG, datos personales o de pago, enlaces a mano, contenidos mínimos).

**8. Cargarla y mirarla.** Solo si tienes permiso para lanzar procesos y abrir un navegador; si no, sáltala y dilo en la
entrega. `php aifec demo:create <sector> <carpeta> --name="…" --site=main` en el sitio maestro; recorre portada, categoría,
ficha con variantes, buscador, carrito, compra con pago simulado, blog y contacto, en escritorio y móvil; mira que ninguna
imagen salga cortada ni repetida. Cada fallo que veas se arregla en el paquete **y** se apunta en `referencias/fallos-frecuentes.md`.

**9. Entrega.** Pasa la lista de `plantillas/lista-de-comprobacion.md`. Entrega: la carpeta `demos/<sector>/`, la salida de
`demo:check` y de `comprobar_extra.py`, y lo que no hayas podido hacer. No hay `preview.png` si no has abierto la tienda.

## Puertas bloqueantes (cada una con su medida)

| Puerta | Cómo se mide |
|---|---|
| El paquete es válido | `demo:check` → `0 problems` |
| Nada repetido: nombres, resúmenes, portadas, códigos de barras | `comprobar_extra.py` → 0 errores |
| Cada producto con su portada propia | `demo:check` (nombre) y `comprobar_extra.py` (contenido idéntico) |
| Cero datos personales reales | `comprobar_extra.py`: correos solo `@ejemplo.test`/`@cliente.test`/`example.*`, ningún teléfono, ni NIF ni IBAN ni IP |
| Cero ficheros ni claves de pago reales | `comprobar_extra.py` (ficheros `.key`/`.pem`/`.env`…, textos con forma de clave) y `demo:check` (módulos `payments-*` y ajustes `payments.*`, `mail.*` prohibidos) |
| Ninguna dirección externa ni enlace escrito a mano | `comprobar_extra.py` |
| Imágenes ligeras y limpias | SVG ≤ 20 KB, con `viewBox`, sin script ni enlaces |
| Peso del paquete | el esquema no fija tope; este skill pide **5 MB o menos** (`moda` pesa 0,74 MB) |
| Ortografía castellana | `python C:\dev-tools\ortografia\revisar_ortografia.py <carpeta del paquete>` → `TOTAL: 0` (sin `--arreglar`) |

## Ficheros del skill

- `plantillas/package-json.md`: el `package.json` mínimo explicado campo a campo.
- `plantillas/hoja-de-catalogo.md`: la hoja para planificar el catálogo, un producto completo y la función `gtin()`.
- `plantillas/lista-de-comprobacion.md`: la lista de la fase 9.
- `referencias/formato.md`: el formato resumido. `referencias/fallos-frecuentes.md`: lo que ya ha fallado.
- `herramientas/ilustrar.py`, `demo_check.php`, `comprobar_extra.py`: dibujar, validar fuera de `demos/`, validar lo que `demo:check` no mira.
- `herramientas/autoprueba.py <paquete válido> --php <php>`: rompe una copia del paquete de 23 formas y exige que los
  comprobadores las cacen todas (`CAZADAS: 23 de 23`). Pásala si tocas un comprobador.

## Reglas de código que sí te tocan

PHP 7.4 a 8.4 y cero dependencias (las herramientas de aquí ya lo cumplen). Tus guiones en Python usan solo la biblioteca
estándar. Nada de `python -c "…"` ni de heredocs para escribir ficheros: usa las herramientas de escribir y editar.

# Descomposición de «Métrica» (metrica)

Tema de AIFEC para una ferretería en línea. El diseño se trae de la plantilla de PrestaShop `ecom_ferreteria` («Métrica»,
arquitectura `FER-H29-MEDIDA-Y-TRABAJO`): se ha rehecho como tema de AIFEC, sin copiar su código (aquello es Smarty sobre
Hummingbird). El contrato visual es su `preview.png`.

| Superficie | Cómo es (elementos, orden y anidado) | En qué se distingue del tema base | En qué se distingue de los demás temas |
|---|---|---|---|
| Cabecera (`af-header`) | «Placa y banco». Franja de avisos (un párrafo, si la opción trae texto). Debajo, una rejilla de dos columnas: a la izquierda la placa amarilla de la marca (botón del menú en el móvil y logotipo con la tuerca), a todo el alto; a la derecha dos filas apiladas: la de las secciones en línea (lista con sus sublistas) con las herramientas (lista: buscar, cuenta, carrito con contador y rótulo) y, en tinta, el banco de trabajos (`nav` con su pregunta y la lista de trabajos, cada uno con su pictograma). | La de base es una sola fila dentro del contenedor (menú, logotipo, navegación, formulario de búsqueda y acciones); esta no tiene formulario (la lupa abre la lámina del buscador) ni contenedor, y reparte todo en placa y dos filas. | `taller` lleva arriba logotipo, buscador grande, teléfono y carrito con importe, y debajo una barra de categorías con el botón «Todas»; aquí no hay buscador a la vista, la marca es una placa de color a todo el alto y la segunda fila no son categorías sino trabajos (otro menú). `atelier`, `despensa` y `floristeria-petalo` centran el logotipo. |
| Tarjeta (`af-product-card`) | «Etiqueta de cajón». Enlace con la foto cuadrada que llena su hueco; bloque de etiqueta con la línea de medidas en letra de máquina (un párrafo, solo con dato), el nombre (`h3` con su enlace), marca y valoración (si las hay) y la fila del precio (la pieza `price` más las marcas en `em`); al pie, un párrafo con «Elegir medida» y cuántas hay, o un formulario con cantidad y «Añadir», o «Agotado». | La de base es un único enlace que envuelve imagen (con insignias encima) y cuerpo, y debajo las opciones del catálogo; aquí la foto va limpia, las marcas bajan a la fila del precio, hay línea de medidas y el pie de compra es propio. | `taller` lista tres características y pone disponibilidad, precio y botón en un bloque de compra; aquí no hay lista de características sino una sola línea de medidas, ni disponibilidad, y el pie cambia según la pieza tenga medidas o no. `atelier` es retrato sin borde; `despensa`, tintada con origen y precio por kilo. |
| Listado (`af-listing`) | «Matriz de filtros». La sección contiene su propia cabecera (título, texto y las subcategorías en fichas dentro de un `nav`), la matriz de filtros (un grupo por faceta: rótulo y lista de conmutadores, que son casillas del formulario GET; después precio y disponibilidad; al pie, «Aplicar» y «Quitar») y los resultados (barra con recuento y orden, y la rejilla como lista de cinco columnas). Sin columna lateral. | En base el título va fuera, los filtros son una columna a la izquierda con casillas apiladas y la rejilla es de cuatro; aquí todo va a lo ancho, los filtros son filas de conmutadores sobre la rejilla y la rejilla es una lista. | `taller` tiene columna técnica de filtros y conmutador rejilla/lista; `atelier`, una fila de filtros sin lateral pero con desplegables; `floristeria-petalo`, píldoras de ocasión y panel que se abre con un botón. Ninguno tiene la matriz siempre abierta ni la cabecera dentro del listado. |
| Ficha (`af-product`) | «Pieza y tabla de medidas». Columna de galería: un escenario con la galería (el carril de miniaturas va antes que la foto, a su izquierda) y, debajo, la sección de la tabla de medidas (`table` con cabecera: medida, referencia, existencias y precio; una fila por variante; la medida es el enlace que la elige). Columna de compra: referencia (lista: marca, Ref., valoración), nombre, entradilla, precio entre filetes, formulario (opciones, cantidad y botón), disponibilidad, líneas de confianza y datos clave. Debajo, fuera del artículo, descripción, características y preguntas como filas con su rótulo a un lado. | En base la galería solo lleva foto y miniaturas debajo, el título abre la columna de compra y el resumen va al final; aquí hay tabla de variantes bajo la foto, carril lateral, la referencia va antes del nombre y la entradilla justo después. | `taller` también pone las miniaturas en vertical, pero su tabla es la de características a todo el ancho; aquí la tabla es de variantes (referencia, existencias y precio por medida) y vive en la columna de la galería. `despensa` elige formatos en tarjetas; `floristeria-petalo`, el tamaño en tarjetas; `atelier`, columna de compra pegajosa junto a una galería de dos columnas. |
| Pie (`af-footer`) | Banda grafito con filete amarillo. Un cuerpo de dos zonas: la marca (sección con la placa amarilla y un `address` con los datos de contacto) y las columnas de menús (`nav` con su título: categorías, trabajos, información; sección de formas de pago; hueco de módulos). Debajo, la franja legal en letra de máquina (© y navegación legal). | El de base es una rejilla de columnas iguales sobre fondo claro y una línea final; este separa marca y menús, añade el menú de trabajos y es oscuro. | `taller`, `atelier` y `despensa` heredan el pie de base; `floristeria-petalo` usa tres bandas (presentación, menús, legal) sin placa. |

## La idea del tema, en una frase

Dos hilos con datos de verdad: **la medida** (métrica y largo, en letra de máquina: línea de medidas en la tarjeta, matriz
de filtros en el listado, tabla de medidas en la ficha y mostrador «por medida» en la portada) y **el trabajo** (colgar,
fijar, cortar: el menú «jobs» en el banco de la cabecera, en el mostrador «por trabajo» y en el pie).

## De dónde sale cada dato (sin dato, la pieza no se pinta)

| Pieza | Dato |
|---|---|
| Línea de medidas de la tarjeta | Las opciones de las variantes del producto (`buy.options` de la tarjeta o `options` de la ficha): una escala se escribe como tramo («M5 – M10 · 20 – 60 mm»). Sin variantes, sus atributos numéricos con unidad («Diámetro 6 mm · Longitud 30 mm»). |
| «Elegir medida · 16 medidas» | El número de variantes del producto. |
| Tabla de medidas de la ficha | `variants` del producto: etiqueta, referencia, disponibilidad y precio de cada una. La elegida es `$initial`; cada medida enlaza a `?variante=<id>` (lo resuelve el catálogo) y, con JavaScript, marca las opciones. |
| Matriz de filtros | Las facetas de `catalog->listing()` del listado, tal cual. |
| Mostrador «por medida» | Las facetas de la primera categoría de primer nivel que tenga alguno de los atributos de la opción `measure_facets` (por defecto `metrica,largo`), de la caché del sitio. |
| Banco de trabajos y mostrador «por trabajo» | El menú `jobs` (ubicación que declara este tema): cada elemento es un trabajo; sus hijos, su lista de material. |
| Franja de avisos | La opción `strip_text` (vacía de serie). |

## Lo que se conserva sin negociar

- Ficha en escritorio: galería a la izquierda, compra a la derecha (y `af-product-gallery` antes que `af-product-buy`).
- Móvil: cabecera pegajosa (la franja de avisos se va al desplazar y queda la fila de la marca), barra inferior con Buscar y
  Carrito reales, Filtros solo en el listado. La barra y su contenido reservado son los del tema base, vestidos en grafito.
- Todos los datos y todos los huecos de las plantillas de módulo que se sustituyen (`product.gallery.before`,
  `product.gallery.after`, `product.summary.before`, `product.price.after`, `product.buy.after`, `product.summary.after`,
  `product.tabs`, `product.bottom`, las marcas WebMCP de la compra y de la búsqueda).
- El amarillo va siempre de fondo con la letra en tinta: la hoja no lo usa como color de letra y corrige las reglas del
  tema base y de los módulos que sí lo hacían (enlaces, menú actual, iconos de confianza, «ver más» de las opiniones).

## Opciones de estructura del comerciante

| Clave | Qué cambia | Valores |
|---|---|---|
| `strip_text` | La franja de avisos de encima de la cabecera | texto (vacío la oculta) |
| `bench_show` | El banco de trabajos en la cabecera | sí / no |
| `listing_cols` | Columnas de la rejilla del listado | 5 / 4 |
| `card_measures` | La línea de medidas de la tarjeta | sí / no |
| `card_qty` | Cantidad y «Añadir» en las tarjetas de las piezas sin medidas | sí / no |
| `gallery_rail` | Miniaturas de la ficha: carril a la izquierda o debajo | `side` / `below` |
| `sizes_table` | La tabla de medidas de la ficha | sí / no |
| `counters_show` | Los dos mostradores de la portada | sí / no |
| `measure_facets` | Qué atributos son «medidas» en el mostrador de la portada | códigos separados por comas |

## En qué se aparta del preview y por qué

- **Mostrador «por medida»**: en el original se elige métrica y largo, se dibuja el tornillo a escala y salen debajo las
  piezas de esa medida. Aquí son los mismos conmutadores, pero llevan al listado ya filtrado (no hay módulo que busque en
  la portada), y el dibujo del tornillo es fijo: explica qué es la métrica y qué es el largo, no cambia de escala.
- **Mostrador «por trabajo»**: en el original son pestañas con casillas, cantidades, importe y «añadir todo al carrito».
  Aquí es la lista de trabajos del menú, cada uno desplegable con su lista de material (enlaces a las piezas) y el enlace
  a todo lo del trabajo: AIFEC no tiene listas de material con cantidades.
- **Herramienta «Por medida» de la cabecera**: no está; su sitio es el enlace «Buscar por medida» de la portada y la
  matriz de filtros del listado.
- **Tabla de medidas**: en el original enseña solo los largos de la métrica elegida; aquí, una fila por variante (hasta 48).
- **Ficha**: los «trabajos en los que entra la pieza» de la columna de compra no están (no hay ese dato en el producto).
- **Tipografías**: Outfit, Figtree y JetBrains Mono eran fuentes servidas por el tema; aquí son pilas del sistema (sans
  geométrica para titulares, sans del sistema para el texto y monoespaciada para las medidas), sin descargar nada.

## Temas que ya existen y se han mirado

- `base`: una fila de cabecera, tarjeta-enlace, filtros en columna, ficha de dos columnas, pie en rejilla.
- `taller` (bricolaje y ferretería, el más cercano por sector): cabecera en dos filas con buscador grande y barra de
  categorías, tarjeta compacta con tres características, columna de filtros, tabla de características a todo el ancho.
- `atelier`, `despensa`, `gmartos`, `aifec`, `floristeria-petalo`: otros sectores; se han medido con la puerta de estructura.

## Comprobado y no comprobado

Comprobado con las puertas de la fábrica y, además, con el paquete de demostración `metrica` cargado en un banco propio
desechable (34 páginas pintadas dentro de PHP, sin servidor): ver `utiles/aifec/temas-hijos/metrica/`.
No se ha abierto en ningún navegador: el aspecto (proporciones, saltos de línea, contraste real, desbordes) y el
JavaScript del tema (filtrar al pulsar un conmutador y elegir una medida desde la tabla) están sin ver.

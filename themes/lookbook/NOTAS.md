# Descomposición de «Lookbook» (lookbook)

Tema hijo de `base`. El diseño viene de la plantilla de PrestaShop `ecom_moda` (su `preview.png` y su `ARCHITECTURE.md`);
no se ha copiado nada de su código: aquí todo son plantillas de AIFEC y una hoja nueva. Prefijo de clases propias: `lb-`.

| Superficie | Cómo es (elementos, orden y anidado) | En qué se distingue del tema base | En qué se distingue de los demás temas |
|---|---|---|---|
| Cabecera (`af-header`) | Un párrafo con la línea de temporada y una sola fila en rejilla de tres zonas: el botón «Menú» (dos rayas finas y la palabra), la marca centrada y una lista de acciones escritas («Buscar», «Cuenta», idiomas, «Carrito (n)»). El formulario de búsqueda va dentro del propio elemento de «Buscar» y se abre bajo la fila, a todo el ancho. | No hay menú en línea, ni caja de búsqueda, ni un solo icono; el buscador es un formulario plegado dentro de la lista de acciones. | `atelier` también centra la marca, pero lleva el menú desplegable a la izquierda e iconos a la derecha; `taller` y `despensa` tienen dos pisos. Aquí solo hay palabras y el menú vive en el panel lateral. |
| Tarjeta (`af-product-card`) | `article` con una `figure` (la foto 3:4 y, si la hay, la segunda vista) y, fuera de ella, una línea (`div`) con el `h3` del nombre —que es el enlace y cubre toda la pieza— y el precio; después, una lista de definiciones con colores y tallas y un párrafo con los avisos escritos. | No hay enlace envolvente ni cuerpo de tarjeta, ni marca ni estrellas; las insignias no van sobre la foto sino escritas debajo; no hay compra desde la tarjeta. | En `atelier` todo va dentro de un enlace con `figure` y `figcaption` y lleva el formulario de compra; aquí la figura solo tiene fotos y no hay formulario. |
| Listado (`af-listing`) | `section` con: cabecera de capítulo centrada (línea pequeña, `h1` y las subcategorías como palabras), la línea de índice (el panel de filtros, donde cada faceta es un `details` cuya lista se abre bajo la línea a todo el ancho, y una lista de definiciones con el recuento y el orden), los resultados en una lista ordenada que alterna dos piezas grandes con cuatro, y la descripción como cierre. | Sin columna lateral: el panel de filtros está dentro de la línea de índice, antes del recuento; los filtros son `details` con `fieldset`, no bloques con casillas a la vista; la descripción va al final. | `atelier` también quita la columna, pero deja el título fuera del listado y usa desplegables sueltos sobre una rejilla uniforme; aquí el capítulo, el índice y la secuencia 2 + 4 forman una sola pieza. |
| Ficha (`af-product`) | `article` con dos zonas: una `section` galería cuya lista lleva la portada (a todo el ancho) y, como elementos hermanos, las demás fotos en parejas; y un `aside` de compra con un `hgroup` (colección y referencia en pequeño, `h1`), la valoración, el precio, la entradilla, y el formulario en rejilla (variantes, disponibilidad como `output`, plazo, cantidad y botón). Fuera del artículo, descripción junto a los detalles en dos columnas, preguntas y propuestas. | La galería es una sola lista con todas las fotos grandes (no una foto y miniaturas); la disponibilidad va dentro del formulario, junto al botón; los datos clave y las características salen juntos, debajo, al lado de la descripción. | `atelier` mete descripción y características en acordeones dentro de la columna de compra; `despensa` y `floristeria-petalo` eligen formato en tarjetas. Aquí la columna de compra es corta y lo demás baja a dos columnas. |
| Pie (`af-footer`) | La marca escrita en grande (un párrafo con su enlace), una lista de definiciones como índice (colecciones, información, atención al cliente y formas de pago: cada rótulo con sus enlaces en línea), el hueco de columnas y una línea legal con el © y la navegación legal. | No hay rejilla de columnas con listas verticales: son filas de un índice; la marca abre el pie. | Los demás heredan la rejilla de `base` o apilan bandas; ninguno usa un índice en filas. |

## La idea del tema, en una frase

Se decide por la foto: la foto vertical manda, el texto es poco y va escrito —nunca en iconos ni en cajas—, con titulares en serifa fina y cursiva.

## Lo que se conserva sin negociar

- Ficha en escritorio: galería a la izquierda, compra a la derecha (`af-product-gallery` antes que `af-product-buy`).
- Móvil: cabecera pegajosa, barra inferior con Buscar y Carrito reales (con su contador), Filtros solo en el listado, Cuenta si existe.
- Todos los datos y todos los huecos de las plantillas de módulo que se sustituyen (`product.summary.before`, `product.gallery.before`, `product.gallery.after`, `product.price.after`, `product.buy.after`, `product.summary.after`, `product.tabs`, `product.bottom`, `webmcp.form` y `webmcp.param`), y los de la portada (`home.top`, `home.bottom`), la cabecera (`header.actions`) y el pie (`footer.columns`).

## Opciones de estructura del comerciante

| Clave | Qué cambia | Valores |
|---|---|---|
| `season_show`, `season_text`, `season_place` | La línea de temporada: si sale, qué dice y si va encima o debajo de la fila | interruptor, texto, `above` / `below` |
| `header_style` | La marca, centrada o a la izquierda (es la opción del tema base, con otro significado) | `centered` / `classic` |
| `opening_first`, `opening_kicker`, `opening_text_1`, `opening_text_2` | La apertura partida de la portada: si las dos colecciones principales abren la página y sus textos | interruptor y textos |
| `listing_rhythm` | La secuencia del listado: dos piezas grandes y cuatro, o todas iguales | `sequence` / `even` |
| `card_ratio`, `card_second`, `show_brand_in_card` | La foto de la pieza (3:4, 2:3 o cuadrada), la segunda vista al pasar el ratón y la marca | selección e interruptores |
| `gallery_layout` | Las fotos de la ficha: portada y parejas, o todas a todo el ancho | `pairs` / `column` |
| `buy_sticky` | Si la columna de compra acompaña al recorrer las fotos | interruptor |
| `column` | El ancho de la columna editorial (listado, ficha y bloques de portada) | 880 a 1400 px |

## Decisiones tomadas donde el encargo no decía nada

- **Tipografías del sistema.** El original usa Bodoni Moda y Jost; el encargo pide las del sistema. Las pilas ponen esas dos primero (por si el equipo las tiene) y después Bodoni/Didot y Century Gothic/Avenir/Futura. No se incluye ningún fichero de fuente.
- **La línea de temporada va encima de la fila** (como dice el encargo y como está en la plantilla de PrestaShop); en `preview.png` aparece debajo: se puede poner así con `season_place`.
- **La cabecera no enseña el menú en la fila** (en `preview.png` sí hay cuatro categorías y un icono de hamburguesa): el encargo pide la palabra «Menú» y el menú como índice lateral, sin iconos.
- **Sin compra desde la tarjeta.** Aunque la tienda tenga encendido «elegir opciones en la tarjeta», la pieza no pinta ese formulario (el encargo: «sin botón de carrito»). De ese dato solo se leen los colores y las tallas para escribirlos bajo el nombre.
- **Sin estrellas en la tarjeta.** La valoración sale en la ficha.
- **La foto de la pieza se recorta** (3:4 con `object-fit: cover`, recorte centrado: es la decisión editorial de la plantilla original). En la ficha el marco es cuadrado y la foto se ve entera (`contain`).
- **Las fotos de la ficha.** Todas están a la vista; pulsar una de la pared la sube a la portada y la que estaba vuelve a la pared (es el comportamiento de `data-src` de `theme.js`; no hay ampliación con lupa).
- **Los filtros se aplican con «Aplicar»** (cada lista tiene el suyo): sin JavaScript funciona igual porque las palabras son `<details>`.
- **La apertura de la portada es la sección de categorías** del catálogo; el cierre («dos accesos en grande») son la tercera y la cuarta colección. Con dos colecciones o menos no hay cierre.
- **Nada redondo**: las muestras de color y el punto de disponibilidad son cuadrados.
- **Sin `preview.png`**: no se ha abierto ningún navegador; la captura la tiene que hacer quien lo revise.

## Qué se ha comprobado y qué no

- **Comprobado sin navegador**: las puertas de la fábrica; `php -l` en 7.4, 8.3 y 8.4; y el HTML que sirve el tema con el
  paquete de demostración `lookbook` cargado en una copia privada de AIFEC (portada, categoría, categoría con
  subcategorías, listado filtrado, listado vacío, ficha con tallas, ficha en oferta, ficha agotada, búsqueda, página de
  contenido, carrito y 404): todas responden, sin avisos de PHP, con un solo `h1`, sin enlaces vacíos y con las opciones
  del paquete aplicadas.
- **Sin comprobar**: todo lo que solo se ve en un navegador. El aspecto real (tipografías del sistema, recortes de las
  fotos, alturas de la apertura), el desbordamiento horizontal entre 360 y 430 px, el buscador que se abre bajo la fila,
  las listas de filtros a todo el ancho, la segunda foto al pasar el ratón, el intercambio de fotos de la ficha y la
  columna de compra pegajosa están escritos según el contrato del tema base, pero nadie los ha visto pintados.

## Temas que ya existen y se han mirado

- `base`: cabecera en una fila con menú, buscador e iconos; tarjeta con enlace envolvente; filtros en columna; ficha con miniaturas.
- `atelier`: moda editorial; marca centrada con menú desplegable e iconos, mosaico de categorías, tarjetas verticales dentro de un enlace, fila de desplegables y acordeones en la columna de compra.
- `despensa`, `taller`, `gmartos`, `aifec`, `floristeria-petalo`: otros sectores; se han medido con la puerta de estructura (ver el informe).
- `cafe` y `metrica`: se están fabricando a la vez que este, a partir del mismo esqueleto.

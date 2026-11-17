# Descomposición de «Café» (cafe)

El diseño se trae de la plantilla de PrestaShop `ecom_cafe` (su `preview.png` y su `ARCHITECTURE.md`); el código es
nuevo. El encargo de la fábrica manda donde difiere de aquella plantilla (el listado lleva los filtros en fila, no en una
columna a la derecha).

| Superficie | Cómo es (elementos, orden y anidado) | En qué se distingue del tema base | En qué se distingue de los demás temas |
|---|---|---|---|
| Cabecera (`af-header`) | «Barra y regleta». Antes de la cabecera, la franja de avisos en tostado (`aside` con una lista; se va al bajar). Dentro, dos pisos: una barra sobre papel con el botón del menú (solo móvil), la marca (emblema dibujado y nombre), las secciones del menú como enlaces de texto sueltos dentro de un `nav` (un elemento con hijos es un `details`) y una lista de herramientas (`ul`): «Tu cafetera», buscar, cuenta, idiomas, cesta y el hueco `header.actions`; debajo, la regleta (`nav` > `ol`) sobre tinta café con los siete primeros sistemas y un botón «Todas las cafeteras». Después de la cabecera, el panel «Tu cafetera». | El base tiene un solo piso con buscador en formulario y las acciones en un `div`; aquí no hay formulario, las herramientas son una lista y hay un segundo piso que sale de datos del catálogo. | `taller` tiene dos filas con un buscador grande y una barra de categorías; `despensa` y `atelier` centran el logotipo; `gmartos` lleva un botón de contacto. Ninguno ordena la cabecera por un atributo del producto. |
| Tarjeta (`af-product-card`) | «Cápsula y su veredicto». Sin caja. Una `figure` con la foto cuadrada sobre el tono leche y, como `figcaption` pegada a su borde inferior y a todo el ancho, la cinta con el sistema para el que vale. Debajo, y como hijos directos del artículo: el nombre (`h3`, enlace que cubre la tarjeta), las insignias en línea (`mark`), la escala de intensidad (`p` con la cifra), la línea de taza y tueste, la valoración y un `footer` con el precio, el precio por unidad o por kilo y «Añadir» (un formulario) o «Elegir» (un enlace, con variantes). | La del base es un enlace que envuelve imagen y cuerpo dentro de una caja con borde; aquí no hay caja ni cuerpo, la cinta va dentro de la figura y el pie lleva la compra. | `despensa` tiñe la tarjeta por categoría y lleva un botón redondo; `taller` enseña tres datos clave en lista; `atelier` es retrato sin borde; `floristeria-petalo` es redondeada. Ninguna lleva cinta de compatibilidad ni escala. |
| Listado (`af-listing`) | «Estante por cafetera». A todo el ancho. Una cabecera en dos columnas: nombre en grande y texto a la izquierda; a la derecha, la caja de resumen (`dl`: cuántos productos, entre qué precios y para cuántas cafeteras). Debajo, las subcategorías como pestañas de texto (`nav` de enlaces), los filtros en fila (`ul`: el del sistema, abierto y con fichas con pictograma; los demás, cada uno en su `details`; precio y casillas al final) y los resultados: orden, rejilla como `ol` de cuatro y paginación. En el móvil los filtros son el panel de la barra inferior. | El base pone los filtros en una columna a la izquierda y el título dentro de los resultados; aquí la superficie entera incluye cabecera, caja y pestañas, y no hay columna. | `atelier` también lleva los filtros en fila, pero todos como desplegables y sin caja ni pestañas; `despensa` y `floristeria-petalo` usan píldoras y un panel; `taller` y `gmartos`, columna lateral. |
| Ficha (`af-product`) | «La taza y su cafetera». Izquierda (`af-product-gallery`): la foto cuadrada grande con las vistas en una columna a su derecha (lista ordenada; en el móvil, en fila debajo) y, debajo, la ficha de cata (`aside` con una tabla: intensidad en escala, tueste en cuatro granos, tazas y notas) y los datos clave (`dl`). Derecha (`af-product-buy`): sección y marca, nombre (`h1`), valoración, entradilla, «Compatible con» (un `nav` con un enlace por cafetera), precio con el precio por unidad o por kilo, formulario (variantes, cantidad y botón) y una lista con disponibilidad, plazo y referencia. Debajo, la descripción junto a la tabla de datos, las preguntas y los relacionados. Sin pegajosos. | En el base el título y los datos clave van en la columna de compra y la galería lleva las miniaturas debajo; aquí la columna izquierda lleva además la ficha de cata y los datos, y la derecha empieza por la sección. | `taller` lleva las miniaturas en vertical a la izquierda y una tabla a todo el ancho; `atelier`, galería en dos columnas y compra pegajosa; `despensa`, formatos elegibles; `floristeria-petalo`, cabecera sobre las dos columnas. |
| Pie (`af-footer`) | Sobre tinta café. Una fila flexible: a la izquierda la tienda (emblema y nombre, el texto de su tostado y sus datos en un `address`); a su lado, una columna (`nav` con título) por cada elemento del menú del pie que tenga hijos, y los sueltos juntos en «Información»; el hueco `footer.columns`. Debajo, la franja legal como lista de definición: el año, los enlaces legales y las formas de pago que ofrece la tienda (las de verdad, de `StoreInfo`). | El del base es una rejilla clara de columnas iguales con el copyright en un párrafo; aquí es oscuro, la tienda manda a la izquierda y la franja legal lleva las formas de pago. | `gmartos` es azul marino con otra composición; `aifec` lleva los principios en una línea; ninguno saca columnas de los hijos del menú ni enseña las formas de pago. |

## La idea del tema, en una frase

La tienda se ordena por la cafetera del cliente: cada café dice para qué sistema vale, su intensidad y lo que cuesta cada
cápsula o cada kilo, y todo sale de los atributos reales del producto.

## De dónde sale cada dato (sin dato, la pieza no se pinta)

El tema reconoce los atributos por su código y su nombre (`cafe_attr_kind()` en `functions.php`):

| Pieza | Atributo que la alimenta | Cómo se reconoce |
|---|---|---|
| Regleta, panel «Tu cafetera», hilera de la portada, cinta de la tarjeta, «Compatible con» de la ficha, fichas del filtro | El atributo de sistema: lista (`select` o `multiselect`) **filtrable** | Su código, en la opción «Código del atributo de la cafetera»; vacía, el primero cuyo código o nombre diga cafetera, sistema, system, machine, brewer o compatib… |
| Escala de intensidad | Un número (o una lista de cifras) | intensidad, intensity |
| Tueste en cuatro granos | Una lista | tueste, roast; el escalón sale del nombre del valor (claro, medio, oscuro, muy oscuro) |
| Tazas | Una lista | taza, cup |
| Notas | Un texto | nota, notes, cata, tasting |
| Precio por unidad | Un número: las piezas del paquete | unidades, units, cápsulas, capsules, piezas, dosis |
| Precio por kilo | Un número con unidad `g` o `kg`: el peso neto | la unidad, y que su código o su nombre diga neto, net, contenido o content (un «Peso máximo» no cuenta) |

La regleta se saca de la faceta de ese atributo en cada categoría principal (hasta seis) y enlaza cada sistema al listado
de la que más cafés tiene para él, ya filtrado; se guarda diez minutos en la caché del sitio (etiqueta `catalog`). Con
variantes de precios distintos («desde») no se da precio por unidad.

## «Tu cafetera» (guion del tema, `assets/js/theme.js`)

Pulsar un sistema lo guarda en el navegador (`localStorage`, clave `cf.cafetera`) y sigue el enlace. Con una cafetera
guardada, la cinta de cada tarjeta dice «Vale para tu cafetera» o «No vale para tu cafetera», la ficha da el veredicto,
la caja del listado cuenta cuántos le valen (con las cuentas de la faceta) y el botón de la cabecera enseña su nombre.
El HTML es el mismo para todos: nada personal viaja en la página. Sin el guion, la tienda dice con qué es compatible cada
café, sin juzgar.

## Lo que se conserva sin negociar

- Ficha en escritorio: galería a la izquierda, compra a la derecha.
- Móvil: cabecera pegajosa (en escritorio no lo es: mide dos pisos), barra inferior del tema base con Buscar y Carrito
  reales y Filtros solo en el listado, vestida sobre tinta café.
- Todos los datos y todos los huecos de las plantillas de módulo que se sustituyen (listado, filtros, ficha y las dos
  secciones de portada del catálogo).

## Opciones de estructura del comerciante

| Clave | Qué cambia | Valores |
|---|---|---|
| `strip_show`, `strip_text` | La franja de avisos y sus textos (separados por «\|») | sí / no, texto |
| `rail_show` | La regleta de cafeteras bajo la cabecera | sí / no |
| `home_makers` | La hilera «¿Qué cafetera tienes?» de la portada | sí / no |
| `listing_cols` | Columnas de la rejilla | 3 o 4 |
| `card_scale` | La escala de intensidad en las tarjetas | sí / no |
| `unit_price_show` | El precio por unidad o por kilo en las tarjetas | sí / no |
| `card_add` | Añadir al carrito desde la tarjeta | sí / no |
| `scale_max` | El tope de la escala de intensidad | de 5 a 13 |
| `system_attr` | El código del atributo de sistema | texto |
| `home_kicker`, `footer_text` | La línea sobre el titular de la portada y el texto del pie | texto |

## En qué se aparta del preview y por qué

- **Sin marcas.** El preview nombra los sistemas por su marca; aquí los nombres salen del catálogo de cada tienda y el
  paquete de demostración usa nombres descriptivos (cono clásico, cúpula, disco…).
- **«Tu cafetera» no es un módulo.** El botón, el panel y la regleta son del tema y salen de un atributo; lo recordado
  vive en el navegador.
- **Regleta.** Enseña los siete primeros sistemas y un botón «Todas las cafeteras» que abre el panel, en vez de dos grupos
  («Cápsulas» y «Cafeteras»): el tema no sabe qué valores son cápsulas y cuáles cafeteras.
- **Listado.** Filtros en fila sobre la rejilla (lo pide el encargo), no en una columna a la derecha.
- **Ficha.** Sin «cajas» de varios paquetes ni tabla de molienda: AIFEC no tiene precios por cantidad en la tarjeta ni el
  módulo de cata. La molienda se elige como variante.
- **Portada.** Sin «La carta» (la pizarra por tazas) ni la sección de molienda del módulo: la portada lleva la apertura,
  la hilera de cafeteras, las ventajas y las secciones de los módulos (página de portada, destacados, categorías,
  novedades y ofertas). La tabla de molienda va en la página de portada del paquete de demostración.
- **Tipografía.** Serif y sans del sistema (sin fuentes de otro dominio) en vez de Fraunces y Figtree.
- **Cabecera en escritorio.** No es pegajosa (dos pisos ocuparían demasiado); en el móvil sí.

## Temas que ya existen y se han mirado

`base` (padre), `despensa` (cómo saca datos del producto para la tarjeta), `atelier` (filtros en fila), `taller`,
`gmartos`, `aifec` y `floristeria-petalo` (hecho desde el mismo esqueleto).

## Lo que no se ha comprobado

No se ha abierto en ningún navegador: la composición, los recortes, el contraste real, la escala (usa `mask` de CSS) y el
desbordamiento a 390 px están sin ver. El guion «Tu cafetera» está probado con Node y un documento de mentira, no en un
navegador. No hay `preview.png`.

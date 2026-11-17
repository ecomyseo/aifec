# Tema `gmartos` — notas

Hijo de `base` con `"css": "replace"`. Reproduce la tienda actual de gmartos.es (padre `gmartos-pro` + hijo activo `ecom-experts`, que es el que manda). Todo se ha medido en `style.css` y `ecom-experts.css` de origen y en las capturas de `capturas\aifec\referencia-gmartos\`; nada a ojo.

## 1. Descomposición (de las capturas y del CSS de origen)

**Paleta** (`ecom-experts.css`, `:root`): tinta y azul noche `#10233f`, azul `#2563eb` (hover `#174dc1`), azul pálido `#dce9ff`, azul suave `#e8f0fd`, papel `#f5f8fe`, texto secundario `#51647e`, línea `#d9e3f2`, secundario `#32578d`. Texto sobre oscuro `#b8cae5`.
**Tipografía**: «Segoe UI», Arial (pila del sistema; ningún `@font-face` ni fuente de Google en el origen). Mono de las etiquetas: Consolas. Cuerpo 16/1.6; titulares de página `clamp(2.4rem, 5vw, 4.3rem)`, portada 72/79 px, secciones 43/48 px, tarjetas de servicio 35/41 px.
**Medidas**: ancho 1280 px con 40 px a cada lado (24 px en tableta, 20 px en móvil); radios 8 (botones), 12 (buscador), 16 (tarjetas), 18-20 (paneles); sin sombras salvo el hover de las tarjetas; separaciones 24 px entre tarjetas.

| Pieza | Origen medido |
|---|---|
| Franja de aviso | 36 px, fondo azul noche; etiqueta en mono «NUEVA IDENTIDAD» (11 px), texto 12 px, dominio a la derecha. En móvil: 33 px y solo el texto. |
| Cabecera | Fila de 101 px (79 en móvil) sobre papel con filete inferior del ancho del contenedor; logotipo 248 px; menú a la derecha con 37 px entre elementos (15 px); «Mi cuenta» en gris 14 px; carrito con contador azul; botón oscuro «Hablemos» de 155 px. No es pegajosa en escritorio. En móvil: logotipo, carrito y hamburguesa. |
| Portada | 1) Titular 72 px con raya azul de 8 px debajo, entradilla 20 px, botón azul y botón con borde; a la derecha, diagrama de 535×485 con cinco nodos (tienda oscura, ERP, módulos en círculo azul, B2B, automatización) y rejilla de puntos. 2) «Encuentra tu solución»: título a la izquierda, buscador de 67 px con botón oscuro y cuatro píldoras. 3) «Módulos propios»: titular 43 px, enlace «Ver todos», cuatro tarjetas. 4) «Desarrollo y soporte»: tarjeta oscura (758 fr) y tarjeta azul pálido (498 fr), 340 px de alto mínimo. 5) Presentación en dos columnas (645/540) y nota sobre fondo azul suave. |
| Tarjeta de portada | Borde 1 px, radio 16, 10 px de relleno; recuadro de imagen de 132 px en azul suave, etiqueta mono 10 px, título 25/29, descripción 15/22, precio 22 px negrita y «Ver módulo ↗» 12 px, separados por un filete. |
| Tarjeta de listado | Borde, radio 15, relleno 13; imagen 1,25:1 sobre fondo `#f7f9fc`; insignia «¡Oferta!» oscura arriba a la izquierda; título 16 px negrita; extracto de 18 palabras 12,6 px; precio negrita (anterior más pequeño y tachado); botón azul pequeño «Añadir al carrito». |
| Listado | Migas pequeñas; panel de título (azul suave, borde, radio 18, 42 px de relleno); «Mostrando 1–12 de 146 resultados» a la izquierda y el orden a la derecha; rejilla de cuatro con 20 px; paginación en cajas con la actual enmarcada en azul. Sin filtros. |
| Ficha | Migas; rejilla `1fr / 330–390 px`, 28 px de hueco: galería con marco (borde, radio 16, fondo suave) y miniaturas en cinco columnas debajo; derecha: título `clamp(2rem, 3.5vw, 2.8rem)`, precio, resumen, cantidad de 74 px y botón azul que se estira. Debajo: tira de tres razones (1 px entre ellas), pestañas «Descripción / Valoraciones», productos relacionados y banda oscura «¿Necesitas algo diferente?». |
| Blog | Archivo: etiqueta mono, título 56 px, dos columnas de tarjetas (radio 18, imagen 1,8:1, «Leer artículo ↗»). Entrada: etiqueta «Guías», título 56 px, imagen de hasta 560 px, texto a 850 px, 19 px / 1,8. |
| Pie | Azul noche; rejilla 2,2/.8/.8/1 con logotipo inverso y frase, tres columnas de enlaces, y una fila inferior con © y enlaces legales de 11 px. |

## 2. Original → aquí

| Original | Aquí |
|---|---|
| `header.php` (franja + cabecera) | `templates/partials/header.php` |
| `footer.php` | `templates/partials/footer.php` |
| `front-page.php` | `templates/home.php` (+ `modules/catalog/front/home/products.php` y `categories.php` para las tarjetas y los recuadros) |
| `template-parts/card.php` y la tarjeta de WooCommerce | `templates/partials/product-card.php` (variante `home` para la portada) |
| `get_price_html()` | `templates/partials/price.php` |
| Plantilla de la tienda / categoría | `templates/modules/catalog/front/listing.php` (+ `partials/filters.php`, el del catálogo con el cierre también en escritorio) |
| Plantilla de producto + `gmartos_pro_wc_product_proof` y `_after_product` | `templates/modules/catalog/front/product.php` |
| `archive.php`, `single.php` | `templates/modules/cms/front/blog.php`, `post-list.php`, `post-card.php`, `post.php` |
| — (en el móvil el origen abre un menú desplegable) | `templates/partials/mobile-bar.php` + el panel del menú del tema base |
| `ecom-experts.css` + `main.css` | `assets/css/theme.css` (44 KB, un solo fichero) |
| `assets/ecom-experts-principal.svg` / `-inverso.svg` | `assets/img/logo.svg` / `logo-inverse.svg` |
| Personalizador `ee_*` | Opciones de `theme.json` (grupos «Structure» y «Home page») |

## 3. Opciones (`theme.json`)

Los colores, el radio y el ancho de origen son los valores de fábrica. Cada texto de la portada, el aviso, el pie y las tarjetas de servicios tiene su opción (vacía = el de fábrica, traducido). Los enlaces que el origen escribía a mano (`/contacta/`, `/desarrollo-modulos-medida-prestashop/`…) son opciones: **vacías = no hay botón**, para no dejar nunca un enlace roto. El director debe rellenarlas en la tienda de gmartos: `header_button_link`, `shop_link`, `home_button2_link`, `dev_link`, `support_link`, `cta_link`, `cta_link2`.

## 4. Diferencias conocidas y por qué

- **Contrato de móvil de AIFEC**: el origen no tiene barra inferior. Aquí hay una tira blanca fija con Buscar, Filtros (solo en el listado), Carrito con contador y Cuenta, con un filete azul arriba; la cabecera es pegajosa en móvil (en escritorio no lo es, como en el origen). El menú de móvil abre el panel lateral del tema base y no el desplegable del origen. El carrito sigue en la cabecera, además de en la barra.
- **Filtros**: el origen no tenía filtros. AIFEC los exige donde funcionan: van en un panel lateral que abre el botón «Filtros» (escritorio) o el de la barra (móvil). El listado queda visualmente igual que el del origen.
- **Galería a la izquierda y compra a la derecha** en escritorio: ya era así en el origen.
- **Pestañas**: el origen tiene «Descripción» y «Valoraciones». Aquí son Descripción, Características y Preguntas (lo que el catálogo de AIFEC trae); las valoraciones las aporta el módulo de opiniones por el hueco `product.tabs`.
- **Tarjetas de portada**: el origen pone una ilustración dibujada por producto (`product-sync.svg`…) y una categoría escrita a mano. Son recursos de esos cuatro productos (no del tema) y no se copian; la tarjeta usa la imagen del producto y su marca como etiqueta.
- **Categorías en la tienda**: en el origen las categorías salían como recuadros mezclados con los productos (con un recuento resaltado en amarillo). Aquí son las subcategorías del listado, con su imagen, y el recuento va en azul pálido (el amarillo es un estilo por defecto de WooCommerce).
- **Diagrama de la portada en móvil**: el origen reposiciona cinco nodos absolutos; aquí es una rejilla de dos columnas con el mismo orden visual y sin las líneas de conexión.
- **Pie**: las tres columnas salen del menú «footer», la cuenta y los datos de contacto de la tienda, no de enlaces escritos a mano. Se omite la nota «Las descripciones de los productos…» (la pone un plugin de WordPress, no el tema).
- **Radios**: se expresan con `calc()` sobre `--radius` (para que el personalizador los mueva a la vez); en algunos casos salen 1-2 px distintos a los del origen.
- **Tarjetas**: la tarjeta del listado y los recuadros llevan el enlace «estirado» sobre el nombre (como el bloque entero enlazable del contrato) y el botón de añadir queda por encima; las rejillas son listas (`ul`/`li`).
- **Tipografía**: nada que alojar; el origen ya usa «Segoe UI», Arial del sistema.
- **Logotipo**: sin logotipo subido en Ajustes → Identidad, se usa el del tema (`Ecom Experts`, del propio usuario). Con uno subido, manda el de la tienda.
- **Imagen de vista previa**: `preview.png` es un recorte (1200 × 900) de la captura de portada del origen, no una captura del tema; el director la sustituirá por la de AIFEC.
- Carrito, caja y cuenta usan las plantillas de sus módulos con las clases del contrato; no hay plantilla propia (el origen tampoco las rehacía: eran las de WooCommerce con estilos).
- No lleva `theme.js` propio: todo el comportamiento (paneles, pestañas, galería, variantes, contador) es el del tema base.

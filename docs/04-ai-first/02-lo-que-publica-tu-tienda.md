# Lo que publica tu tienda

Tu tienda no solo enseña páginas a personas: ofrece también direcciones pensadas para buscadores, asistentes de IA y programas. Vienen con el módulo «Datos para máquinas» y los demás módulos. Aquí tienes cada una, para quién es y qué contiene; todas se han comprobado contra el sitio de demostración. Las direcciones son relativas: añade tu dominio delante.

## La tabla

| Dirección | Para quién | Qué contiene |
|---|---|---|
| [`/llms.txt`](/llms.txt) | Asistentes de IA | Un resumen de la tienda en texto: una frase sobre qué es, las categorías, los productos con su precio y si hay existencias, las entradas del blog y los enlaces a la API, al servidor MCP y al mapa del sitio. Cada enlace va a la versión en texto de la página. |
| [`/llms-full.txt`](/llms-full.txt) | Asistentes de IA | Lo mismo que `/llms.txt` y, a continuación, el texto de cada categoría, producto y entrada. Tiene un tamaño máximo que se cambia en los ajustes. |
| Cualquier página pública con `.md` al final, por ejemplo [`/producto/silla-ergo-pro.md`](/producto/silla-ergo-pro.md) | Asistentes de IA | La versión en texto limpio de esa página: título, precio, disponibilidad, datos clave, variantes, preguntas frecuentes y envío. También se obtiene pidiendo la página normal con la cabecera `Accept: text/markdown`. |
| Dentro de cada página: datos estructurados (JSON-LD) | Buscadores y asistentes | Datos con un formato común: en la portada, la organización y el buscador del sitio; en un producto, el producto con sus variantes, ofertas y envío (y valoraciones, si las hay) y sus preguntas frecuentes; en una entrada, el artículo; en el resto, las migas de pan. No se ven en la página: van en su código. |
| [`/feeds/google.xml`](/feeds/google.xml) | Google Merchant Center | Los productos en el formato de Google para publicidad y fichas de compra. |
| [`/feeds/products.json`](/feeds/products.json) | Quien quiera cargar el catálogo | Todos los productos en JSON: precio en céntimos y sin impuestos, existencias, variantes, atributos, preguntas frecuentes, imágenes y categorías. |
| [`/feeds/openai.json`](/feeds/openai.json) | Asistentes de compra | Una versión resumida de los productos, con precio, disponibilidad, imagen y dos indicadores: si el asistente puede enseñar el producto y si puede iniciar la compra. |
| [`/sitemap.xml`](/sitemap.xml) | Buscadores | El mapa del sitio: un índice con un mapa por tipo (páginas, entradas, productos, categorías y marcas) y la fecha de la última modificación de cada dirección. |
| [`/robots.txt`](/robots.txt) | Buscadores y robots de IA | Qué zonas no deben leer los robots y quién puede leer la tienda. Ver [robots de IA](03-robots-de-ia.md). |
| [`/blog/feed.xml`](/blog/feed.xml) | Lectores de noticias | Las últimas entradas del blog. |
| [`/.well-known/aifec.json`](/.well-known/aifec.json) | Programas | Una ficha de la tienda (nombre, idiomas, moneda) y la lista de las puertas que tiene abiertas: llms, API, MCP y mapa del sitio. |
| [`/api/openapi.json`](/api/openapi.json) y `/api/v1/…` | Programas y asistentes | La API pública. El documento OpenAPI describe cada dirección. Leer el catálogo, buscar, ver existencias y calcular un envío no necesita clave. |
| `/mcp` | Asistentes de IA conectados | El servidor MCP. Es una puerta para clientes de IA, no una página: solo contesta a peticiones de programas. |
| [`/webmcp/tools.json`](/webmcp/tools.json) | Agentes en el navegador | La lista de herramientas (buscar, ver un producto, carrito, caja) que un agente puede usar desde el navegador. |

Todas las salidas parten de los mismos datos de la ficha ([una sola verdad](01-una-sola-verdad.md)). La API, el MCP y WebMCP se explican en [API y MCP para no técnicos](05-api-y-mcp-para-no-tecnicos.md).

## Dónde se ajustan

| Qué | Dónde |
|---|---|
| Resumen de `/llms.txt`, tamaño de `/llms-full.txt`, datos de la organización, política de devoluciones y cada feed | «Ajustes» → «Datos para máquinas» |
| Quién puede leer la tienda, el `robots.txt` y los códigos de verificación | «Ajustes» → «SEO» |
| La API y sus límites | «Ajustes» → «API» |
| El servidor MCP y las herramientas que ofrece | «Ajustes» → «MCP» |
| WebMCP | «Ajustes» → «WebMCP» |

Cada feed se enciende o se apaga por separado en «Ajustes» → «Datos para máquinas», pestaña «Feeds». Allí también decides si los asistentes pueden «enseñar los productos en sus respuestas» y si pueden «iniciar la compra».

## Cuándo se actualizan

- `/llms.txt`, las versiones `.md`, los datos estructurados, el mapa del sitio y la API se calculan al pedirlos: siempre reflejan lo guardado.
- Los **feeds** son ficheros que se rehacen en segundo plano: unos minutos después de que cambie un precio o unas existencias, y también por una tarea programada. Hasta que se crean por primera vez dan «Página no encontrada».

## Si algo no sale

- **Un feed da «Página no encontrada».** Todavía no se ha creado, o lo has apagado en «Ajustes» → «Datos para máquinas». Las tareas en segundo plano hacen falta para crearlos; mira «Sistema» → «Tareas programadas».
- **`/mcp` da «Method not allowed» al abrirlo en el navegador.** Es normal: es una puerta para programas, que se conectan a ella de otra forma.
- **La API, el MCP o WebMCP dan 404.** Si los apagas en sus ajustes, sus direcciones dejan de contestar.
- **La versión `.md` de una página no existe.** Solo la tienen las páginas públicas y publicadas.

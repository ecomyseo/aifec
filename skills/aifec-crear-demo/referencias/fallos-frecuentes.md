# Fallos frecuentes al crear un paquete de demo

Cada fila es algo que ha fallado de verdad al construir o probar el skill (sobre un paquete de papelería de prueba y sobre
`demos/moda` y `demos/minimo`). Al encontrar uno nuevo, se apunta aquí **antes de seguir**, con su causa y quién lo caza.

| Qué pasó | Cómo se ve | Causa | Arreglo | Quién lo caza |
|---|---|---|---|---|
| Código de barras con el prefijo equivocado | `the barcode must be an invented EAN-13 starting with 299` | El paquete P054 decía prefijo `0000000`; el validador exige `299` (`Package::GTIN_PREFIX`) | Usar siempre `299` y la función `gtin()` de `plantillas/hoja-de-catalogo.md` | `demo:check` |
| GTIN de un ejemplo de la plantilla con la cifra de control mal | `demo:check` rechaza el producto de ejemplo | Escrito a mano sin calcular el último dígito | No escribir GTIN a mano: generarlos con `gtin(n)`. La plantilla se prueba metiendo el ejemplo en un paquete real | `demo:check` y la prueba de plantillas |
| Descripciones de 55-59 palabras | `la descripción tiene 59 palabras (mínimo 60)` | Textos escritos «a ojo» | Contar con `comprobar_extra.py` y alargar con un dato útil, no con relleno | `comprobar_extra.py` |
| Entradas de blog de menos de 200 palabras | `entrada «…»: menos de 200 palabras` | Dos párrafos y una lista no bastan | Añadir un párrafo con consejo concreto antes del bloque de productos | `comprobar_extra.py` |
| `orders.json` o `customers.json` en el paquete | `«files» lists «orders.json», which is not a known data file` | El encargo habla de pedidos y clientes de ejemplo, pero el formato no los tiene | No inventarlos: se hacen comprando en el sitio de pruebas (fase 8) | `demo:check` |
| Campo `related` en un producto | Nada: se ignora | `demos/moda` lo trae, pero `CatalogLoader` no lo lee | No contar con productos relacionados en el paquete | Nadie: se descubre leyendo el cargador |
| Un número de teléfono «encontrado» en un SVG | `lleva un teléfono` en un dibujo | Las coordenadas de un dibujo parecen un número de 9 cifras | En `media/*.svg` solo se miran correos, direcciones y claves (corregido en `comprobar_extra.py`) | `comprobar_extra.py` (falso positivo, ya arreglado) |
| Ficheros del generador dentro del paquete | `«generar.py» no es un fichero de paquete` | El guion se dejó en `demos/<sector>/` | Guion, notas y pruebas viven fuera del paquete y del proyecto | `comprobar_extra.py` |
| Dos productos con el mismo dibujo | `su portada es idéntica a la de DEMO-…` | Misma forma, color y `etiqueta` en `ilustrar.py`, con otro nombre de fichero | Cambiar la forma, la paleta o la `etiqueta`; `demo:check` solo mira el nombre, no el contenido | `comprobar_extra.py` |
| Atributo de variante mal usado | `«material» is not an attribute marked as «variant»` | Una variante con un atributo que no lleva `"variant": true` | Marcar `variant: true` en `attributes.json` solo para color, talla, formato… y usar solo esos en `variants[].values` | `demo:check` |
| Variantes y `stock`/`gtin` también en el producto | Nada: `demo:check` lo acepta | El esquema dice que las existencias de un producto con variantes son las de cada variante, pero no lo impone | En un producto con variantes, ni `stock` ni `gtin` a nivel de producto | Nadie: es una convención del esquema, se cumple a mano |
| Precio con decimales | `«price» must be a whole number of cents` | `12.5` en lugar de `1250` | Todo importe en céntimos enteros, también `price` de variante y `amount` de cupón | `demo:check` |
| Enlace a mano `href="/categoria/…"` | La demo enlaza al sitio maestro | Una demo vive en `/demos/<nombre>`; la ruta absoluta se sale | Menús (`type: category`) o bloques (`category_code`, `product_skus`) | `comprobar_extra.py` |
| Imagen con espacios o mayúsculas en el nombre | `the image «Mi imagen.svg» does not exist in media/` | El nombre ha de casar con `[A-Za-z0-9][A-Za-z0-9._-]*` | Nombres en minúsculas con guiones; el generador los saca de un `slug()` | `demo:check` |
| Tema inexistente | `«theme» must be the slug of an installed theme` | Se inventó un nombre de tema (`papeleria`) | `ls themes/` y usar uno que exista; `base` sirve para cualquier sector | `demo:check` |
| Paquete fuera de `demos/` no validable con la orden oficial | `the package does not exist: demos/<x>/package.json was not found` | `demo:check` busca en `demos/` (o en `sites.packages_dir`) | `herramientas/demo_check.php <carpeta>` comprueba cualquier carpeta con la misma clase | — |
| Una valoración mal hecha pasa desapercibida | Todas de cinco estrellas | Se escriben todas positivas | Alguna de 2-4 estrellas con un reparo creíble | `comprobar_extra.py` |
| `python -c "…"` o un heredoc para escribir JSON | Una puerta de entrega bloquea la orden | Convención del proyecto | Escribir el guion con la herramienta de escribir ficheros y lanzarlo con `python guion.py` | Hook del proyecto |

## Cómo se probó este skill

Se siguió al pie de la letra con una papelería («Cuaderno y Tinta», 26 productos, 56 imágenes) construida fuera del
proyecto, y se comprobó que:

- `demo:check` (vía `demo_check.php`) termina en `0 problems` y `comprobar_extra.py` en `0 errores, 0 avisos`;
- `autoprueba.py` rompe el paquete de 23 formas distintas y los comprobadores cazan las 23, tanto sobre la papelería como sobre `demos/moda`;
- las plantillas (`package.json` y producto de ejemplo) pasan `demo:check` metidas en un paquete real.

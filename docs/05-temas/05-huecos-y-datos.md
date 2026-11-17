# Huecos y datos

Un **hueco** es un punto de una plantilla donde los módulos y los plugins pueden poner su HTML: `<?= hook('product.summary.after', $product) ?>`. Si tu tema sustituye una plantilla y se deja un hueco, lo que pintaba ahí (las valoraciones, un aviso de un plugin, el asistente) desaparece sin ningún error. Esta página lista los huecos que tienes que conservar y los datos que recibe cada plantilla. La lista sale de buscar `hook('…')` en el tema base y en las plantillas de los módulos, y los datos, de la cabecera `@var` de cada una.

## Huecos del envoltorio y de las piezas

Son de tu tema: tienes que ofrecerlos.

| Hueco | Dónde va | Argumentos |
|---|---|---|
| `body.start` | Justo después de `<body>`, antes del enlace «Saltar al contenido». | ninguno |
| `header.before` | Antes de la cabecera. | ninguno |
| `header.actions` | Dentro de la cabecera, junto al carrito. | ninguno |
| `header.after` | Después de la cabecera. | ninguno |
| `footer.before` | Antes del pie. | ninguno |
| `footer.columns` | Dentro del pie, para columnas extra. | ninguno |
| `footer.after` | Después del pie. | ninguno |
| `body.end` | Al final del `<body>`. Ahí se cuelgan el asistente y el aviso de cookies. | ninguno |
| `home.top`, `home.bottom` | Principio y final de la portada. | ninguno |

Los del envoltorio están en `layout.php`; `header.actions` en `partials/header`; `footer.columns` en `partials/footer`; `home.top` y `home.bottom` en `home.php`.

## Huecos de las plantillas de los módulos

Si sustituyes una de estas plantillas, **conserva sus huecos** con los mismos argumentos.

| Plantilla | Huecos (con su argumento) |
|---|---|
| `catalog::front/product` | `product.gallery.before`, `product.gallery.after`, `product.summary.before`, `product.price.after`, `product.buy.after`, `product.summary.after`, `product.tabs`, `product.bottom`. Todos reciben el producto (`$product`, un ProductData). **Las insignias de la ficha («Nuevo», «−15 %») salen en `product.gallery.before`, que tu plantilla tiene que pintar DENTRO del contenedor de la foto principal** (así quedan sobre la foto y nunca encima del título); `product.summary.before` queda libre para los plugins. Bajo el botón de compra, la pieza común `catalog::front/partials/trust` pinta el envío, la devolución y el pago con los datos de la tienda, y al final de la ficha salen los relacionados («También te puede interesar») con la tarjeta de tu tema. |
| `orders::front/cart` | `cart.before`, `cart.lines.after`, `cart.totals.after`, `cart.after`. Todos reciben el carrito (`$cart`). |
| `orders::front/checkout` | `checkout.before`, `checkout.review.after`, `checkout.after`. Reciben el resumen del carrito (`$c['summary']`). |
| `orders::front/order` | `order.thanks`, con el pedido y un booleano (`$order`, `$v['first']`). |
| `customers::front/account` | `account.dashboard`, con el cliente (`$customer`). |

Hay además huecos para agentes de IA (`webmcp.form`, `webmcp.param`) en la cabecera, la ficha, el carrito y el selector de cantidad. Cópialos tal cual de la plantilla original: marcan los formularios que un agente puede usar.

La lista completa de ganchos, huecos incluidos, está en [Referencia de ganchos](../07-referencia/03-ganchos.md).

## La portada

La portada no llama a un hueco por sección: recibe `$sections`, una lista de `['id', 'order', 'html']` donde cada `html` ya está pintado por un módulo (las categorías, los productos destacados, la página de inicio). Tu `home.php` los recorre en orden y los imprime. El comerciante cambia el orden y la visibilidad en «Diseño» › «Portada».

```php
<?php foreach ($af_sections as $af_section) : ?>
    <div class="af-home-section af-container" data-section="<?= e($af_section['id']) ?>">
        <?= $af_section['html'] ?>
    </div>
<?php endforeach; ?>
```

Ese `html` es HTML ya montado y escapado por el módulo que lo creó: se imprime sin `e()`. Lo mismo vale para lo que devuelve `hook()`. Cualquier otro dato, siempre con `e()`.

## Qué datos recibe cada plantilla

Toda página de la tienda recibe `$page`: título, descripción, canónica, robots, tipo, migas, datos estructurados y `body_class`. El envoltorio lo completa con `page_meta($page)`.

| Plantilla | Variables |
|---|---|
| `home` | `$page`, `$sections` |
| `error` | `$status` (código HTTP), `$title` y `$message` (en inglés, se traducen en la plantilla) |
| `catalog::front/product` | `$page`, `$product`, `$crumbs`, `$related`, `$can_buy`, `$initial`, `$price`, `$sku`, `$stock`, `$stock_class`, `$delivery`, `$sold_out`, `$summary_html`, `$description_html`, `$faqs`, `$variants_data` |
| `catalog::front/listing` | `$page`, `$crumbs`, `$kind` (`category`, `brand` o `search`), `$heading`, `$description`, `$children`, `$result`, `$facets`, `$query`, `$base`, `$clear_url`, `$url` |
| `catalog::front/search` | `$page`, `$crumbs`, `$action`, `$max` |
| `orders::front/cart` | `$page`, `$cart`, `$flash` |
| `orders::front/checkout` | `$page`, `$c` |
| `orders::front/order` | `$page`, `$order`, `$v` |
| `cms::front/page` | `$page`, `$content`, `$crumbs`, `$template` (`default`, `wide` o `landing`), `$script` |
| `customers::front/account` | `$page`, `$customer`, `$name`, `$recent`, `$hidden`, `$unverified`, … |
| `partials/product-card` | `$product` (un ProductCard) |
| `partials/price` | `$price`, `$size`, `$live` |

Para una plantilla que no está aquí, abre la original: la cabecera lista cada variable.

## Las formas de los datos

**ProductCard**, lo que recibe `partials/product-card`: `id`, `name`, `url`, `image` (o `null`), `price`, `availability` (`status` y `label`), `brand`, `rating`, `badges` (`sale`, `new`, `out`) y `has_variants`.

**Bloque de precio**, lo que recibe `partials/price`: `amount`, `with_tax`, `net`, `tax`, `gross`, `tax_rate`, `tax_name`, `currency`, `compare`, `on_sale`, `discount_percent`, `sale_until` y `from`. Los importes son **céntimos enteros**: se pintan con `money()`. `compare` solo existe si es mayor que `amount`.

**Disponibilidad**: `status` vale `in_stock`, `low_stock`, `out_of_stock` o `backorder`.

**ProductData**, lo que recibe la ficha: `id`, `kind`, `sku`, `gtin`, `mpn`, `name`, `url`, `url_abs`, `summary`, `description`, `facts`, `faqs`, `brand`, `categories`, `main_category`, `tags`, `images`, `image`, `price`, `availability`, `has_variants`, `options`, `variants`, `attributes`, `rating`, `weight`, `dimensions`, `min_qty`, `max_qty`, `related`, `seo`, `published_at`, `updated_at` y `extra` (los datos tipados que añaden los plugins).

El precio, el stock y los impuestos los calcula el sistema. Tu plantilla los pinta; nunca los recalcula.

## Si algo no sale

- **Un plugin dice que no se ve en la ficha.** Mira si tu plantilla de producto conserva el hueco `product.summary.after`.
- **El carrito del móvil no cuenta.** Falta `data-af-cart-count` en tu cabecera.
- **«Undefined array key» al pintar una tarjeta.** Una tarjeta puede venir sin imagen (`image` es `null`), sin marca (`''`) o sin valoración (`null`): comprueba antes de leer.

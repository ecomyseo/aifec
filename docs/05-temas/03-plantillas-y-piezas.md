# Plantillas y piezas

Las páginas de la tienda se montan con tres clases de plantillas: el **envoltorio** que rodea a todas, las **piezas** comunes que define el tema (cabecera, pie, tarjeta, precio…) y las **plantillas de los módulos** (ficha, listado, carrito, caja), que se escriben con las piezas del tema. Un tema puede sustituir cualquiera de ellas. Aquí ves qué recibe cada una, cómo se sustituye y qué funciones tienes en una plantilla.

## El envoltorio

`templates/layout.php` envuelve todas las páginas. Cada plantilla de módulo empieza con:

```php
<?php $this->layout('layout', ['page' => $page]); ?>
```

y pinta solo su contenido. El envoltorio lo recibe con `$this->section('content')`. Esto es lo esencial del de `base`:

```php
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?= page_head($af_page) ?>
<?= theme_styles() ?>
<?= $this->stack('styles') ?>
<?= theme_scripts() ?>
</head>
<body class="<?= e($af_classes) ?>">
<?= hook('body.start') ?>
<a class="af-skip" href="#main"><?= e(t('Skip to content')) ?></a>
<?= hook('header.before') ?>
<?= $this->partial('partials/header') ?>
<?= hook('header.after') ?>
<main id="main" class="af-main" tabindex="-1">
<?= $this->section('content') ?>
</main>
<?= hook('footer.before') ?>
<?= $this->partial('partials/footer') ?>
<?= hook('footer.after') ?>
<?= $this->partial('partials/mobile-bar', ['filters' => $af_filters]) ?>
<?= $this->partial('partials/drawers') ?>
<?= $this->stack('scripts') ?>
<?= hook('body.end') ?>
```

Si escribes un envoltorio propio, el validador exige tres cosas: que llame a `page_head()`, que tenga `<main id="main">` y que incluya `partials/mobile-bar`. `page_head()` pinta título, descripción, canónica, robots, idiomas, datos para compartir, enlace a la versión en texto, JSON-LD y las variables del tema. Si no la llamas, la tienda sale sin canónica ni datos estructurados.

Métodos de `$this` en una plantilla: `layout()`, `partial()`, `start()` y `stop()` (rellenan una sección), `section()`, `push()` (apila algo, por ejemplo un `<script>`, para el final de la página) y `stack()` (lo pinta).

## Las piezas

Están en `templates/partials/`. Las define el tema; las usan las plantillas de los módulos. El validador exige las catorce plantillas de la lista, propias o heredadas: `layout`, `home`, `error` y las piezas de la tabla.

| Pieza | Recibe | Pinta |
|---|---|---|
| `partials/header` | nada | Logotipo, menú `main`, buscador, cuenta y carrito. Solo pinta los accesos cuya ruta existe. |
| `partials/footer` | nada | Menús `footer` y `legal`, datos de la tienda y el hueco `footer.columns`. |
| `partials/mobile-bar` | `filters` (bool) | Barra fija inferior del móvil: Buscar, Carrito, Cuenta y, con `filters`, Filtros. |
| `partials/product-card` | `product` (ProductCard) | Tarjeta de producto. |
| `partials/price` | `price`, `size` (`sm`, `md`, `lg`), `live` (bool) | Precio actual, precio anterior tachado, porcentaje y nota de impuestos. |
| `partials/breadcrumbs` | `crumbs` | Migas de pan. |
| `partials/pagination` | `page`, `pages`, `url` (función que da la dirección de una página) | Paginación con `rel="prev"` y `rel="next"`. |
| `partials/messages` | `messages` (`[['ok'\|'bad'\|'info', texto]]`) | Avisos. |
| `partials/qty` | `name`, `value`, `min`, `max`, `tool` | Selector de cantidad con botones − y +. |
| `partials/nav` | `items`, `level` | Un menú con sus submenús. |
| `partials/drawers` | nada | Los paneles laterales: menú, carrito y buscador del móvil. |

Se pintan con `$this->partial('partials/price', ['price' => $price, 'size' => 'sm'])`. El nombre se busca en el tema activo y en sus padres.

## Sustituir una plantilla de un módulo

Una plantilla de módulo se llama `<módulo>::front/<nombre>`. Para sustituirla, crea el mismo camino dentro del tema:

| Plantilla del módulo | Fichero en tu tema |
|---|---|
| `catalog::front/product` | `templates/modules/catalog/front/product.php` |
| `catalog::front/listing` | `templates/modules/catalog/front/listing.php` |
| `catalog::front/home/categories` | `templates/modules/catalog/front/home/categories.php` |

Las más habituales son las del catálogo (`product`, `listing`, `search`, `home/categories`, `home/products`, `partials/filters`), las de pedidos (`cart`, `checkout`, `order`, `totals`), las de contenido (`page`, `post`, `blog`) y las de la cuenta del cliente (`account`, `login`, `register`, `orders`). Para ver todas las que hay: `ls modules/*/views/front`.

Reglas al sustituir una:

- Recibe **los mismos datos** que la original. Abre la original y copia su cabecera `@var`.
- Conserva **sus huecos** (`hook('product.summary.after', …)`). Si los quitas, los plugins que pintan ahí dejan de verse. Ver [Huecos y datos](05-huecos-y-datos.md).
- Si sustituyes la ficha de producto, la galería (`af-product-gallery`) va **antes** que el bloque de compra (`af-product-buy`): en escritorio, la galería a la izquierda y la compra a la derecha. El validador lo comprueba.

## Funciones disponibles

Todas están disponibles en cualquier plantilla.

| Función | Para qué |
|---|---|
| `e($valor)` | Escapa un dato para HTML. **Todo dato se pinta con `e()`.** |
| `t('Texto')`, `tn('{n} uso', '{n} usos', $n)` | Traducen. El texto fuente va en inglés. |
| `url('/ruta')`, `route('nombre')` | Direcciones. Nunca escribas una a mano. |
| `asset('css/mi.css')` | Dirección de un recurso del tema (busca en la cadena de padres). |
| `money(4999)` | Un importe en céntimos con el formato y la moneda de la tienda. |
| `hook('nombre', …)` | Pinta un hueco. |
| `setting('clave', $defecto)` | Un ajuste del sitio. |
| `attrs(['class' => 'a', 'disabled' => true])` | Atributos HTML escapados; `true` pinta el atributo solo; `false` y `null` lo omiten. |
| `json_html($dato)` | Un dato como JSON para un `<script type="application/json">`. |
| `page_head($page)` | **Obligatoria** en el `<head>` del envoltorio. |
| `page_meta($page)` | El array completo de la página (`type`, `body_class`, `breadcrumbs`…). |
| `shop_logo($atributos)` | El logotipo elegido en Ajustes o, si no hay, el nombre de la tienda ya escapado. |
| `theme_styles()`, `theme_scripts()` | Las etiquetas de la hoja y del guion del tema y de sus padres. |
| `theme_option('clave')` | Una opción del tema. |
| `menu('main')` | Los elementos de un menú: `[['label', 'url', 'current', 'children' => […]]]`. Puede venir vacío. |
| `breadcrumbs($page['breadcrumbs'])` | Migas accesibles. |
| `image_tag($imagen, 'card', ['class' => '…'])` | Un `<img>` con ancho, alto, texto alternativo y carga diferida; sin imagen, un hueco del mismo tamaño. La primera imagen de la página lleva `['loading' => 'eager', 'fetchpriority' => 'high']`. |
| `base_theme_can('cart')`, `base_theme_link('cart')` | Las define el tema base: si existe esa ruta con nombre y su dirección. |

## Nada personal en el HTML

Las páginas se guardan en caché y se sirven a todo el mundo. No pintes en una plantilla el nombre del cliente ni lo que lleva en el carrito: el contador y el saludo los rellena `theme.js`, que lee una cookie legible.

## Ver todas las piezas juntas

Con la depuración encendida en el sitio, el tema base ofrece la ruta `/tema/muestra`: una página con todas sus piezas, sin necesidad de módulos. Sirve para revisar una tarjeta o una cabecera sin montar datos. La depuración se enciende con `php aifec site:config <sitio> debug=1`; apágala en producción.

## Si algo no sale

- **Falla el validador por «Template … is missing».** Falta una de las plantillas obligatorias. Si tu tema es hijo de `base` las hereda: revisa que `parent` esté bien escrito.
- **Mi plantilla de módulo no se usa.** El camino debe ser exacto: `templates/modules/catalog/front/product.php` para `catalog::front/product`. El nombre del módulo es el de su carpeta, con guiones (`payments-simulated`).
- **«Undefined variable» en mi plantilla sustituida.** Falta un dato que la original sí recibe. Compara con su cabecera `@var`.

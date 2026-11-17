# El formato de un paquete, resumido

Resumen de `demos/_esquema/LEEME.md` (el original manda: si algo no coincide, tiene razón el original) más lo que el
validador (`modules/sites/src/PackageCheck.php`) exige de verdad. Un fallo siempre dice el fichero, el elemento y el motivo:
`products.json: product #3 («DEMO-MESA-02»): the price is required, in cents, as a whole number`.

## Reglas de oro

Todo inventado · todo en el idioma del paquete con su ortografía · imágenes propias · dinero en **céntimos enteros** ·
descuentos en centésimas de punto (`1000` = 10 %) · códigos de barras EAN-13 que empiezan por `299` con su cifra de
control · enlazar **por código**, nunca por número · ningún enlace escrito a mano con la ruta de la tienda.

## Estructura

```
demos/<sector>/
  package.json      obligatorio
  categories.json  brands.json  attributes.json  products.json  pages.json
  posts.json       menus.json   carriers.json    coupons.json   reviews.json
  media/            las imágenes (nombres: [A-Za-z0-9][A-Za-z0-9._-]*, hasta 120 caracteres)
  preview.png       captura de la portada (1200 × 900), la hace el director
```

`files` de `package.json` solo admite esos diez ficheros de datos. **No existen** ficheros de pedidos ni de clientes.
Los códigos (`code`) son `^[a-z0-9][a-z0-9-]{0,62}$`; las referencias (`sku`) `^[A-Za-z0-9][A-Za-z0-9._-]{0,62}$`.

## Qué lleva cada fichero

| Fichero | Elemento | Campos |
|---|---|---|
| `categories.json` | lista | `code`, `name`, `description?`, `parent?` (código de la madre, sin ciclos), `image?`, `position?`, `meta_title?`, `meta_description?` |
| `brands.json` | lista | `code`, `name`, `description?`, `website?` (`https://tienda.test`), `image?` |
| `attributes.json` | lista | `code`, `name`, `type?` (`select` por defecto, `multiselect`, `text`, `number`, `bool`, `color`), `unit?`, `filterable?`, `visible?`, `variant?`, `values` (lista de códigos o de `{code, label, color?}`; `color` como `#aabbcc`) |
| `products.json` | lista | ver abajo |
| `pages.json` | lista | `code`, `title`, `blocks`, `excerpt?`, `meta_title?`, `meta_description?`, `home?` (solo una página) |
| `posts.json` | lista | lo de las páginas más `date` (`2026-11-12` o `2026-11-12 09:30:00`) e `image?` (nombre en `media/`) |
| `menus.json` | lista | `code` (ubicación del tema: `main`, `footer`, `legal`), `name`, `items` |
| `carriers.json` | objeto | `{zones?, carriers}` |
| `coupons.json` | lista | `code` (mayúsculas, cifras y guiones), `kind` (`percent` con `percent`, o `fixed` con `amount`), `note?`, `min_subtotal?`, `max_uses?`, `starts_at?`, `ends_at?`, `exclude_sale?` |
| `reviews.json` | lista | `sku`, `name`, `rating` (1 a 5), `title?`, `text`, `date?` |

**Producto:** `sku` y `name` y `price` (céntimos, entero) obligatorios. Opcionales: `summary`, `description` (HTML sencillo),
`compare_price`, `sale_price` (menor que `price`; con `sale_from`/`sale_to`), `stock`, `manage_stock`, `gtin`, `mpn`, `weight` (g),
`delivery_min_days`, `delivery_max_days`, `categories` (la primera es la principal), `brand`, `attributes` (`{código: valor}`: el
código del valor en `select`, una lista en `multiselect`, un número en `number`, `true`/`false` en `bool`, un texto en `text`),
`facts` (`[{label, value}]`), `faqs` (`[{question, answer}]`), `variants`, `images` (al menos una; la primera es la portada y
no puede ser portada de otro producto), `tags`, `featured`, `kind` (`physical` o `service`; no hay descargas), `status`
(`published` o `draft`), `tax_class`, `slug`, `meta_title`, `meta_description`.
**Variante:** `sku` propio, `values` (`{atributo de variante: valor}`), `price?`, `stock?`, `gtin?`, `image?`, `label?`.

**Bloques** (`{type, attrs, children?}`): `paragraph` (`html`), `heading` (`level` 1-6, `html`), `list` (`ordered`, `items`),
`image` (`media_file` o `src`, `alt`), `cover` (`media_file`, `overlay`, `dim`, `min_height`, `class_hint`, con `children`),
`columns` con `column` hijos, `products` (`mode`: `manual`|`category`|`latest`|`featured`|`sale`; `product_skus`,
`category_code`, `limit`, `columns`), `posts` (`limit`), `form` (`form_id`: `contact`), `table`, `buttons`, `quote`,
`details`, `group`, `gallery`, `embed`, `video`, `separator`, `spacer`, `toc`. Dentro de un bloque: `category_code` → `category_id`,
`product_skus` → `ids`, `media_file` → `media_id`.

**Menú:** `{type, label, ref?, url?, blank?, children?}`; `type`: `home`, `page`, `post`, `category`, `product` o `url`;
`ref` es el código de lo que enlaza (el `sku` si es un producto); `children` solo en el primer nivel.

**Envíos:** `zones: [{name, locations: [{country, region?, postcodes?}]}]` (el sitio ya trae `Peninsula`, `Balearic Islands`,
`Canary Islands` y `Ceuta and Melilla`); `carriers: [{code, name, delay?, handler?, free_from?, max_weight?, min_days?, max_days?,
tax_class?, tracking_url?, rates}]`; `handler`: `rates` (por defecto, necesita al menos una tarifa) o `pickup` (sin tarifas);
`rates: [{zone, basis, min, max, price}]` con `zone` el nombre de una zona o `*`, `basis` `weight` (gramos) o `price`
(céntimos) y `max: 0` sin tope.

## Cómo se carga

Módulos y ajustes → tema y opciones → medios (cada imagen una sola vez, por su contenido) y envíos → atributos, marcas,
categorías y productos → páginas, entradas, menús, cupones y valoraciones → reindexa el buscador. Repetir la carga **no
duplica nada**. En un sitio de demostración: el correo queda retenido, solo hay pago simulado, todo el sitio lleva `noindex`
y se da de alta una persona del panel con el papel `demo`.

## Órdenes

```
php aifec demo:check <sector>                    valida sin construir nada (el paquete en demos/)
php aifec demo:create <sector> <carpeta> --name="…" --site=main
php aifec demo:list --site=main
php aifec demo:reset <id> --site=main
```

# Paquetes de demostración de AIFEC

Un **paquete de demostración** es una carpeta `demos/<sector>/` con todo lo necesario para construir una tienda de ejemplo
completa: el tema, el catálogo, los contenidos, los envíos y los ajustes. El módulo `sites` lo carga en un sitio nuevo,
dentro de una subcarpeta del sitio maestro (`/demos/<nombre>`), y lo vuelve a cargar cada noche para que la tienda recupere
su estado.

`demos/minimo/` es un paquete pequeño y completo que sirve de modelo (y de datos de las pruebas). Las carpetas cuyo nombre
empieza por `_` no son paquetes.

## Reglas de oro

- **Todo inventado.** Nombres, marcas, correos (`ana@cliente.test`, dominios reservados), teléfonos, direcciones, opiniones:
  nada real. Los textos de ejemplo lo dicen donde haga falta («Producto de ejemplo»).
- **Todo en el idioma del paquete** (`locale`). Las cadenas van con su ortografía (tildes y eñes).
- **Imágenes propias**: SVG, PNG o WebP hechos para el paquete. Nada con derechos de terceros.
- **Dinero en céntimos enteros** (`4990` = 49,90 €). Los tipos de descuento, en centésimas de punto (`1000` = 10 %).
- **Códigos de barras inventados con prefijo de demostración**: EAN-13 que empieza por `299` (rango de uso interno, que no
  tiene ningún producto real) y con su cifra de control correcta.
- Enlazar siempre **por código** (`"categories": ["sillas"]`), nunca por identificador: cada tienda numera lo suyo.
- Ningún enlace escrito a mano con la ruta de la tienda (`/categoria/…`): una demostración vive en una subcarpeta y el
  enlace llevaría al sitio maestro. Se enlaza con menús o con bloques (`category_code`).

## Estructura

```
demos/<sector>/
  package.json        identidad, tema, ajustes, módulos y lista de ficheros de datos (obligatorio)
  categories.json     categorías (opcional)
  brands.json         marcas
  attributes.json     atributos y sus valores
  products.json       productos, con variantes e imágenes
  pages.json          páginas (una es la portada)
  posts.json          entradas del blog
  menus.json          menús
  carriers.json       zonas y transportistas con sus tarifas
  coupons.json        cupones
  reviews.json        valoraciones
  media/              las imágenes
  preview.png         captura de la portada (1200 × 900), para la página pública /demos (opcional)
```

Al cargarlo se comprueba todo antes de tocar ningún sitio. Un fallo dice el fichero, el elemento y el motivo:
`products.json: product #3 («DEMO-MESA-02»): the price is required, in cents, as a whole number`.

## package.json

```json
{
    "name": "Taller de ejemplo (paquete mínimo)",
    "description": "Una tienda de sillas y mesas de madera con datos inventados.",
    "sector": "muebles",
    "locale": "es",
    "theme": "base",
    "theme_options": {"accent": "#b94a17"},
    "store": {
        "name": "Taller de ejemplo", "email": "tienda@ejemplo.test", "currency": "EUR", "currency_symbol": "€",
        "country": "ES", "prices_include_tax": true, "display_with_tax": true
    },
    "settings": {"catalog.per_page": 12},
    "modules": ["catalog", "cms", "media", "orders", "search", "seo", "shipping", "tax", "themes", "coupons", "customers"],
    "files": ["categories.json", "products.json", "pages.json"]
}
```

| Clave | Qué es |
|---|---|
| `name`, `description`, `sector` | Lo que enseña la página pública de demos. `sector`: letras minúsculas, cifras y guiones. |
| `locale` | Idioma de todos los textos (dos letras). |
| `theme`, `theme_options` | Tema instalado en `themes/` y sus opciones (las que declara su `theme.json`). |
| `store` | Nombre, correo, teléfono, dirección, razón social, moneda, símbolo, país de la tienda y cómo se escriben y enseñan los precios (`prices_include_tax`: el IVA va dentro; `display_with_tax`). |
| `settings` | Otros ajustes (`clave => valor`). **Prohibidos**: `mail.*`, `plugins.*`, `modules.*`, `sites.*`, `payments.*`, `api.*`, `jobs.*`, `seo.noindex_site`, `theme.active`, `theme.options`: los pone el sistema. |
| `modules` | Módulos que se encienden. Se añaden solos los que piden los datos (catálogo, contenidos, envíos…), el tema, los pedidos y el pago simulado. **Prohibidos**: `sites`, `plugins` y cualquier módulo de pago real. |
| `files` | Los ficheros de datos que trae el paquete. Uno listado que no existe es un error. |

Los **impuestos** no se inventan: los tipos de cada país son los que trae de serie el módulo de impuestos. El paquete solo
elige ajustes (`store.country`, `prices_include_tax`) y, por producto, la clase (`tax_class`: `standard`, `reduced`…).

## Ficheros de datos

**categories.json** — lista de `{code, name, description?, parent?, image?, position?, meta_title?, meta_description?}`. `code`
es la dirección de la categoría (`/categoria/<code>`); `parent` es el código de la madre (sin ciclos).

**brands.json** — lista de `{code, name, description?, website?, image?}`.

**attributes.json** — lista de `{code, name, type?, unit?, filterable?, visible?, variant?, values}`. `type`: `select` (por
defecto), `multiselect`, `text`, `number`, `bool` o `color`. `variant: true` marca los que sirven para crear variantes (talla, color).
`values`: lista de códigos o de `{code, label, color?}` (`color` como `#aabbcc`).

**products.json** — lista de productos:

| Campo | |
|---|---|
| `sku` | Referencia, única en todo el paquete (obligatoria). Es lo que casa al repetir la carga. |
| `name`, `summary`, `description` | Nombre (obligatorio) y textos (HTML sencillo). |
| `price` | Céntimos, entero (obligatorio). Con `compare_price` (precio anterior) y `sale_price` (oferta, menor que el precio) con `sale_from` y `sale_to` opcionales. |
| `stock`, `manage_stock` | Existencias. En un producto con variantes, las de cada variante. |
| `gtin`, `mpn`, `weight` | Código de barras inventado (`299…`), referencia del fabricante y peso en gramos. |
| `categories` | Códigos; la primera es la principal. `brand`: código de marca. |
| `attributes` | `{código del atributo: valor}`: el código del valor (`select`), una lista (`multiselect`), un número, `true`/`false` o un texto. |
| `facts` | Datos clave comprobables: `[{label, value}]`. |
| `faqs` | Preguntas frecuentes: `[{question, answer}]`. |
| `variants` | `[{sku, values: {atributo: valor}, price?, stock?, gtin?, image?, label?}]`. Los atributos tienen que ser de variante. |
| `images` | Al menos una (la primera es la portada): `"fichero.svg"` o `{file, alt}`. **Cada producto tiene su propia portada: no se repite entre productos.** |
| `tags`, `featured`, `kind`, `status`, `tax_class`, `slug`, `meta_title`, `meta_description` | Opcionales. `kind`: `physical` o `service`. `status`: `published` o `draft`. |

**pages.json** y **posts.json** — listas de `{code, title, blocks, excerpt?, meta_title?, meta_description?}`. En las páginas, `home: true`
marca la portada (solo una). En las entradas, `date` (`2026-11-12`). `blocks` son los bloques del editor (`paragraph`, `heading`, `list`,
`products`, `image`…). Dentro de un bloque se enlaza por código: `category_code` (se convierte en `category_id`), `product_skus`
(en `ids`) y `media_file` (en `media_id`).

**menus.json** — lista de `{code, name, items}`. `code` es la ubicación del tema (`main`, `footer`…). Cada elemento es
`{type, label, ref?, url?, blank?, children?}`; `type`: `home`, `page`, `post`, `category`, `product` o `url`; `ref` es el código
de lo que enlaza (el `sku` en un producto). Un elemento de primer nivel puede llevar `children`.

**carriers.json** — objeto `{zones?, carriers}`. `zones`: `[{name, locations: [{country, region?, postcodes?}]}]` (el sitio ya trae
cuatro de serie: `Peninsula`, `Balearic Islands`, `Canary Islands`, `Ceuta and Melilla`). `carriers`: `{code, name, delay?, handler?, free_from?,
max_weight?, min_days?, max_days?, tax_class?, tracking_url?, rates}`; `handler`: `rates` (por defecto) o `pickup`; `rates`:
`[{zone, basis, min, max, price}]` con `zone` el nombre de una zona o `*` (todas), `basis` `weight` (gramos) o `price` (céntimos) y
`max: 0` sin tope.

**coupons.json** — lista de `{code, kind, percent|amount, note?, min_subtotal?, max_uses?, starts_at?, ends_at?, exclude_sale?}`.
`kind`: `percent` (con `percent` en centésimas) o `fixed` (con `amount` en céntimos). El código, en mayúsculas.

**reviews.json** — lista de `{sku, name, rating, title?, text, date?}`: `rating` de 1 a 5, `name` inventado. La media y el recuento
de cada producto se escriben siempre; el texto de cada opinión solo si el módulo de valoraciones está instalado.

## Cómo se carga (DemoBuilder)

1. Módulos y ajustes (antes de arrancar el sitio).
2. Tema y sus opciones.
3. Medios (cada imagen una sola vez, por su contenido), envíos.
4. Atributos, marcas, categorías, productos con variantes e imágenes.
5. Páginas (una de portada), entradas, menús, cupones y valoraciones.
6. Reindexa el buscador y genera los feeds si el sitio los tiene.

Repetir la carga sobre un sitio ya construido **no duplica nada**: casa el atributo y el cupón por su código, la marca, la categoría, la
página y la entrada por su dirección, el producto por su referencia, y los menús se guardan enteros.

En un sitio de demostración, además: el correo queda retenido (ni los avisos del sistema salen), solo se ofrece el pago simulado,
todo el sitio lleva `noindex`, y se da de alta una persona del panel con el papel `demo` (`demo@<dominio>` y una contraseña fija que
se enseña en la página pública). Ese papel, en ese sitio, no puede cambiar personas, ajustes (el del correo incluido), plugins ni sitios.

## Crear, restablecer y mirar

```
php aifec demo:create minimo moda --name="Tienda de moda" --site=main
php aifec demo:list --site=main
php aifec demo:reset demo-moda --site=main
```

El sitio maestro (su `config.php` lleva `'master' => true`) tiene además la pantalla «Sitios y demostraciones» y la tarea
`sites.reset_demos` (cada noche a las 04:30; se apaga en esa misma pantalla). El ajuste `sites.packages_dir` permite guardar los
paquetes fuera de `demos/`.

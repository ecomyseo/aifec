# `package.json` de un paquete de demo, campo a campo

JSON no admite comentarios, así que aquí va el fichero mínimo y debajo, la explicación de cada campo. Copia el bloque,
cambia los valores y quita lo que no uses.

```json
{
    "name": "Cuaderno y Tinta (demostración de papelería)",
    "description": "Papelería de ejemplo con 26 productos inventados, dibujos propios y datos de mentira.",
    "sector": "papeleria",
    "locale": "es",
    "theme": "base",
    "theme_options": {"accent": "#2f5d8a", "accent_ink": "#ffffff"},
    "store": {
        "name": "Cuaderno y Tinta",
        "email": "tienda@ejemplo.test",
        "address": "Calle Mayor 1 (dirección de ejemplo)",
        "legal_name": "Cuaderno y Tinta, S. L. (ejemplo)",
        "currency": "EUR",
        "currency_symbol": "€",
        "country": "ES",
        "prices_include_tax": true,
        "display_with_tax": true
    },
    "settings": {"catalog.per_page": 12},
    "modules": ["catalog", "cms", "media", "orders", "search", "seo", "shipping", "tax", "themes", "coupons", "customers", "reviews"],
    "files": ["categories.json", "brands.json", "attributes.json", "products.json", "pages.json", "posts.json", "menus.json", "carriers.json", "coupons.json", "reviews.json"]
}
```

| Campo | Obligatorio | Qué poner | Qué lo rompe |
|---|---|---|---|
| `name` | sí | Título que ve el público en la página de demos. 120 caracteres como mucho. | Vacío o demasiado largo. |
| `description` | sí | Una o dos frases sobre qué es la demo y que los datos son inventados. 500 caracteres como mucho. | Vacío. |
| `sector` | sí | Letras minúsculas, cifras y guiones, de 2 a 40 (`papeleria`, `moda`). | Mayúsculas, espacios o tildes. |
| `locale` | sí | Idioma de TODOS los textos: dos letras (`es`). | Más de dos letras. |
| `theme` | sí | `slug` de una carpeta de `themes/` (`base`, `atelier`, `taller`, `despensa`). | Un tema que no existe en `themes/`. |
| `theme_options` | no | Objeto `opción: valor`. Las opciones válidas están en `themes/<tema>/theme.json`, en `settings.<grupo>.fields`. | Una lista en vez de un objeto. |
| `store` | no, pero ponlo | Datos de la tienda de ejemplo. Claves permitidas: `name`, `email`, `phone`, `address`, `legal_name`, `currency`, `currency_symbol`, `country`, `prices_include_tax`, `display_with_tax`. | Una clave desconocida; `email` que no sea un correo; `country` que no sean dos letras en mayúsculas. |
| `settings` | no | Otros ajustes `clave: valor` (`catalog.per_page`). | Claves que empiezan por `mail.`, `plugins.`, `modules.`, `sites.`, `payments.`, `api.`, `jobs.`, y `seo.noindex_site`, `theme.active`, `theme.options`: las pone el sistema. |
| `modules` | no | Módulos que se encienden. Los que piden tus datos se añaden solos. | `sites`, `plugins` o cualquier `payments-*` que no sea el simulado; un módulo que no existe. |
| `files` | sí | Los ficheros de datos que trae el paquete, de esta lista cerrada: `categories.json`, `brands.json`, `attributes.json`, `products.json`, `pages.json`, `posts.json`, `menus.json`, `carriers.json`, `coupons.json`, `reviews.json`. | Listar uno que no existe, o uno que no está en la lista (`orders.json`, `customers.json`). |

Los impuestos no se inventan: los tipos de cada país los trae el módulo de impuestos. El paquete solo elige `store.country`,
`prices_include_tax` y, por producto, `tax_class` (`standard`, `reduced`…).

Datos de la tienda de ejemplo: correos con dominio reservado (`@ejemplo.test`, `@cliente.test`), ningún teléfono
(`store` no lleva `phone`), «Calle Mayor 1», nada que pueda existir.

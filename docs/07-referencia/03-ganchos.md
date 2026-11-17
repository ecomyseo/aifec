# Ganchos

Un gancho es un punto del sistema donde otro código puede engancharse. Esta página enseña a leer la tabla de ganchos, que sale de analizar el código y no se copia aquí: [Referencia de ganchos](_generado/ganchos.md). Cómo se escucha cada clase, con ejemplos, está en [Ganchos (plugins)](../06-plugins/03-ganchos.md).

## Las columnas

| Columna | Qué dice |
|---|---|
| **Gancho** | El nombre, en minúsculas con puntos: `order.paid`, `product.summary.after`. |
| **Tipo** | `action` (algo ha pasado, no devuelve nada), `filter` (recibe un valor y lo devuelve) o `slot` (devuelve HTML para un punto de una plantilla). |
| **Se dispara en** | Dónde se lanza: `fichero:línea`. Es el sitio donde mirar qué argumentos recibe. |
| **Se escucha en** | Quién se ha enganchado, con su `fichero:línea`. Vacío si nadie lo escucha todavía. |
| **Sellado con** | La capacidad que hace falta para engancharse (hoy, `pricing`). Vacío si es libre. |

## Cómo se obtiene

La tabla sale de un análisis estático del código, sin ejecutar nada: `fire` dispara una acción, `apply` pasa un valor por los filtros y `render` pinta un hueco; `on`, `filter` y `slot` se enganchan a uno; `seal` lo sella con una capacidad. Solo salen los nombres escritos como texto literal. Los que se montan con una variable van a una fila `dynamic`: son los ganchos que no se pueden listar de antemano.

Eso tiene dos consecuencias:

- Un gancho que se dispara desde una plantilla de un tema aparece también (`themes/base/templates/layout.php`).
- Un gancho que **no** está en la tabla puede existir igual si su nombre se construye en el código. Si el que buscas parece que debería estar, busca la fila `dynamic` y el código que la origina.

## Cómo usarla

**Quiero reaccionar a algo.** Busca el verbo en la columna del gancho (`order.`, `product.`, `cart.`, `customer.`, `admin.`). Mira su tipo: una acción para reaccionar, un filtro para cambiar un valor, un hueco para pintar. Abre el fichero de «Se dispara en» y mira los argumentos que le pasa.

Ejemplo, la fila de `order.paid`:

```
| order.paid | action | modules/orders/src/Orders.php:345 | modules/datalayer/hooks.php:55 … |  |
```

Es una acción que lanza `Orders.php` en la línea 345 y que ya escuchan la medición, el ERP, las facturas y el panel de pedidos. Tu plugin se suma a esos oyentes.

**Quiero cambiar un dato del producto.** `product.extra` es un filtro que se dispara en `ProductData.php:247`. Hoy lo escucha el plugin de ejemplo `hello-aifec`:

```
| product.extra | filter | modules/catalog/src/ProductData.php:247 | plugins/hello-aifec/hooks.php:19 |  |
```

**Quiero tocar un precio.** `cart.adjustments`, `product.price`, `product.availability`, `tax.rate` y `shipping.options` tienen `pricing` en la última columna: son los **ganchos sellados**. Sin la capacidad declarada y concedida no te enganchas; ver [Ganchos sellados y capacidades](../06-plugins/03-ganchos.md#ganchos-sellados-y-capacidades).

**Quiero pintar en una página.** Busca los huecos (`slot`) de la plantilla que necesitas: `product.summary.after`, `cart.totals.after`, `header.actions`, `body.end`. Los argumentos que reciben y los que tiene que conservar un tema están en [Huecos y datos](../05-temas/05-huecos-y-datos.md).

## Ganchos de uso habitual

| Para… | Gancho | Clase |
|---|---|---|
| Reaccionar a un pago | `order.paid` | acción |
| Reaccionar a un cambio de estado | `order.status` | acción |
| Añadir un dato a un producto | `product.extra` | filtro |
| Añadir un menú a la tienda | `menu.items` | filtro |
| Añadir una sección a la portada | `home.sections` | filtro |
| Cambiar el `<head>` de una página | `page.meta` | filtro |
| Cambiar un recurso del panel | `admin.definitions` | filtro |
| Reaccionar a un guardado del panel | `admin.resource.saved` | acción |
| Cambiar una línea de ajuste del carrito | `cart.adjustments` | filtro sellado |
| Enseñar algo bajo el resumen de la ficha | `product.summary.after` | hueco |
| Enseñar algo al final de toda página | `body.end` | hueco |

## Si algo no sale

- **Mi gancho no está en la tabla.** La tabla refleja lo que había cuando se generó: se rehace con `php aifec docs:reference`. Si lo montas con una variable, va a la fila `dynamic`.
- **Me engancho y no se ejecuta.** Mira si el gancho está sellado, y si el módulo que lo dispara está activo (la tabla lista el código aunque el módulo esté apagado).
- **No sé qué argumentos recibe.** Abre el fichero de «Se dispara en»: los argumentos son los que siguen al nombre en `fire(…)`, `apply(…)` o `hook(…)`.

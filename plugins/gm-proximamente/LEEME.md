# GM coming soon (`gm-proximamente`)

Plugin del sistema AIFEC que lleva a la tienda lo que hacía `wc-proximamente` en WordPress: productos «próximamente». Viene **desactivado**.

## Qué hace

- **Estado de producto**: en la pestaña «Coming soon» de la ficha del producto en el panel, un interruptor y una fecha de salida opcional. El producto se ve pero **no se puede comprar**: el formulario de la tienda contesta con un mensaje claro y, si algo lo añade por otro camino (API, asistente), la línea se quita del carrito.
- **Insignia** «Próximamente» junto al precio de la ficha y su etiqueta como disponibilidad (filtro sellado `product.availability`, por eso declara la capacidad `pricing`: cambia lo que la ficha dice de la disponibilidad, a la vista y con su etiqueta).
- **«Avísame»**: formulario con correo y casilla de consentimiento (con campo trampa para robots y límite por hora). Una petición por correo y producto.
- **Avisos**: cuando el producto deja de ser «próximamente» —a mano, o solo el día de la fecha de salida (tarea cada 15 minutos)— se encola **un** correo por petición hecha en los últimos 90 días, y esa petición queda marcada: nunca se reenvía. Las de más de 90 días no se tocan y una tarea diaria las borra.
- **Panel**: Marketing → Avisos de próximamente (listado de solo lectura).

## Qué no hace

Con la capacidad `pricing` concedida el producto **no se puede comprar de verdad** (`purchasable` = false: ni botón en la ficha ni en la tarjeta, y el carrito y la caja lo rechazan en el servidor con la etiqueta), **no enseña precio** en ninguna parte (`show_price` = false: ni la página, ni los datos estructurados, ni los feeds) y a las máquinas les dice `PreOrder` (`schema`). En la **tarjeta** de los listados sale la insignia «Próximamente» en lugar de «Agotado» (filtro `product.badges`, que no es sellado).

## Cómo se configura

Ajustes → Próximamente: texto de la insignia, texto del formulario, peticiones por hora y dirección. Ajustes **por sitio**. Hace falta conceder la capacidad `pricing` en el panel de plugins para que cambie la etiqueta.

## Contrato AIFEC

Sin servicios externos. Dato personal declarado: correo. Dos tablas propias (`gm_soon_products`, `gm_soon_requests`) y la capacidad `pricing`. `php aifec contract:check gm-proximamente`.

Pruebas: `php tests/run.php plugins/gm-proximamente`.

# GM legal texts per product (`gm-legal-producto`)

Plugin del sistema AIFEC que lleva a la tienda lo que hacía `ecom_legal_producto` en WordPress: textos legales por producto y la renuncia al desistimiento de los productos digitales. Viene **desactivado**.

## Qué hace

- **Bloques legales reutilizables** (garantía, desistimiento de contenido digital, compatibilidad, soporte): se escriben una vez en Contenido → Bloques de texto legal y se **asignan a categorías (con sus subcategorías) o a productos**. Texto sin formato, una línea = un párrafo, siempre escapado.
- **Dónde salen**: en la ficha del producto (bajo el resumen o al final, a elegir), en la caja y **dentro del correo de confirmación del pedido** (bloques del filtro `order.mail.blocks`, en HTML y en texto), solo con los bloques marcados «también en el correo» y con lo que el cliente contestó a la renuncia. El correo propio (`legal_notice`) queda de recambio: solo sale si el del pedido no llevó los textos.
- **Renuncia al desistimiento**: si el carrito lleva productos digitales, la caja enseña una casilla. **No impide comprar**: se guarda lo que contestó el cliente, marcada o no, con `customers->consent(…, 'withdrawal_waiver', …)` y la versión de los textos, y se ve en la ficha del pedido del panel.
- **La descarga no sale hasta la renuncia**: con la capacidad `orders` concedida (filtro sellado `order.download.allowed`), un contenido digital cuyo cliente **no** marcó la casilla queda retenido: la página del pedido enseña el motivo en lugar del enlace, la dirección directa contesta 403 y el correo lleva a la página del pedido. Se entrega como siempre con la casilla marcada, con la renuncia apagada en los ajustes y en los pedidos de los que no se preguntó nada (anteriores al plugin).

## Qué no hace

El original **obligaba** a marcar las casillas para poder pagar; aquí la casilla solo pregunta y apunta. Tampoco retiene la descarga si no se marca: el módulo de pedidos concede las descargas al pagar y no tiene un gancho para frenarlas (se pide en el informe).

## Cómo se configura

Ajustes → Textos legales por producto: dónde salen en la ficha, si se enseñan en la caja, el texto de la casilla y la versión de los textos (cámbiala cada vez que los reescribas). Ajustes **por sitio**.

## Contrato AIFEC

Sin servicios externos. Dato personal declarado: correo. Tres tablas propias (`gm_legal_blocks`, `gm_legal_assignments`, `gm_legal_orders`) y la capacidad `orders` (la puerta de las descargas). `php aifec contract:check gm-legal-producto`.

Pruebas: `php tests/run.php plugins/gm-legal-producto`.

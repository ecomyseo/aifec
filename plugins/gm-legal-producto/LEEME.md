# GM legal texts per product (`gm-legal-producto`)

Plugin del sistema AIFEC que lleva a la tienda lo que hacía `ecom_legal_producto` en WordPress: textos legales por producto y la renuncia al desistimiento de los productos digitales. Viene **desactivado**.

## Qué hace

- **Bloques legales reutilizables** (garantía, desistimiento de contenido digital, compatibilidad, soporte): se escriben una vez en Contenido → Bloques de texto legal y se **asignan a categorías (con sus subcategorías) o a productos**. Texto sin formato, una línea = un párrafo, siempre escapado.
- **Dónde salen**: en la ficha del producto (bajo el resumen o al final, a elegir), en la caja y **dentro del correo de confirmación del pedido** (bloques del filtro `order.mail.blocks`, en HTML y en texto), solo con los bloques marcados «también en el correo» y con lo que el cliente contestó a la renuncia. El correo propio (`legal_notice`) queda de recambio: solo sale si el del pedido no llevó los textos.
- **Petición de empezar ya (desistimiento)**: si el carrito lleva descargas, servicios o las dos cosas, la caja enseña UNA casilla, **encima del botón de pagar** (hueco `checkout.review.before_pay`), con el texto de lo que lleva: descarga (pierdes el desistimiento al empezar), servicio (si desistes después de empezar, pagas la parte proporcional) o mezcla. Sin descargas ni servicios no sale. **No impide comprar**: el pedido guarda lo que contestó el cliente, marcada o no, con el **texto exacto que vio**, el tipo, la versión de los textos y la fecha y hora, **sin su IP** (tabla `gm_legal_orders`; ya no se copia a la tabla de consentimientos, que guarda la IP). Se ve en la ficha del pedido del panel.
- **Sin marcar, la descarga espera los 14 días**: con la capacidad `orders` concedida (filtro sellado `order.download.allowed`), una descarga cuyo cliente **no** marcó la casilla queda retenida hasta que pasan los días del plazo (ajuste «Días que se retiene una descarga», 14 de serie) y entonces se entrega sola. Mientras tanto la página del pedido enseña el motivo con la fecha de liberación, la dirección directa contesta 403 y el correo lleva a la página del pedido. Los servicios no se retienen: el cliente conserva el derecho y paga a prorrata si desiste tras empezar. Con la casilla marcada, con la petición apagada en los ajustes y en los pedidos de los que no se preguntó nada (anteriores al plugin), se entrega como siempre.
- **El correo de confirmación lo confirma por escrito**: con la fecha, el texto exacto que marcó (o no marcó) y lo que implica.

## Qué no hace

El original **obligaba** a marcar las casillas para poder pagar; aquí la casilla solo pregunta y apunta.

## Cómo se configura

Ajustes → Textos legales por producto: dónde salen en la ficha, si se enseñan en la caja, el texto de la casilla y la versión de los textos (cámbiala cada vez que los reescribas). Ajustes **por sitio**.

## Contrato AIFEC

Sin servicios externos. Dato personal declarado: correo. Tres tablas propias (`gm_legal_blocks`, `gm_legal_assignments`, `gm_legal_orders`) y la capacidad `orders` (la puerta de las descargas). `php aifec contract:check gm-legal-producto`.

Pruebas: `php tests/run.php plugins/gm-legal-producto`.

# Pedidos a mano

El cliente que llama por teléfono, el que escribe por WhatsApp o el que paga en el mostrador: su pedido lo haces tú desde el panel, en «Ventas» → «Pedidos» → «Pedido nuevo». Precios, impuestos, envíos, cupones y existencias son los de la tienda, y el pedido que sale es igual que uno hecho en la web: descuenta existencias, se factura cuando se cobra, manda sus correos y avisa a tu programa de gestión.

## Los pasos

Todo está en una sola pantalla, con el resumen a la derecha (debajo en el móvil). Cada paso se guarda al momento y puedes ir y volver.

1. **Cliente.** Búscalo por nombre, correo o teléfono (salen como mucho diez) o pulsa «Cliente nuevo» y escribe nombre, correo y teléfono. El correo hace falta: es donde llega la confirmación. Si ese correo ya es de un cliente, no se crea otro: la pantalla te ofrece «Usar este cliente».
2. **Productos.** Búscalos por nombre, referencia o código de barras. Al elegir uno se añade una unidad; las unidades se cambian en la tabla, con el tope de las existencias. Una variante agotada sale como «Sin existencias» y no se puede elegir.
3. **Entrega.** Elige una de las direcciones del cliente o escribe una nueva: se guarda en su cuenta, con la misma comprobación de país y código postal que en la caja. Si la tienda tiene una forma de envío de recogida, aquí sale «El cliente lo recoge en la tienda»; la dirección sigue haciendo falta para la factura.
4. **Envío.** Las tarifas que la tienda daría a esos productos y a esa dirección. Sale elegida la más barata, como en la caja.
5. **Cupón.** Opcional, y con las mismas reglas que en la caja.
6. **Pago.** Ver más abajo.
7. **Confirmar.** Una nota interna (solo la ve el equipo, en el historial del pedido) y la casilla «Mandar el correo de confirmación al cliente», marcada de serie. «Crear el pedido» lleva a su ficha.

El resumen lo calcula siempre la tienda, igual que en la caja: líneas, base imponible, cada impuesto, envío, descuentos y total.

## Los tres caminos de pago

| Camino | Qué pasa |
|---|---|
| Una forma de pago que da instrucciones (transferencia, contra reembolso…) | El pedido nace «En espera», como en la tienda, y el cliente recibe las instrucciones para pagar. Cuando llegue el dinero, lo das por cobrado en la ficha. |
| «Ya está pagado» | Para lo que ya cobraste: efectivo, tarjeta en el mostrador, Bizum… Escribe con qué se pagó. El pedido nace pagado, con su apunte de cobro y una nota con lo que escribiste. |
| «Mandar enlace de pago» | Todavía no está disponible: la página del pedido no puede cobrar un pedido que sigue esperando el pago. Sale apagado y lo dice. |

Desde el panel no se cobra ninguna tarjeta: los datos de una tarjeta nunca pasan por la tienda.

## El precio de esta venta

En cada línea, «Precio de esta venta» pone un precio por unidad distinto del de catálogo (con impuestos). Pide un motivo corto. En el pedido, la línea conserva su precio de catálogo y aparece al lado otra línea, «Precio de esta venta: …», con la diferencia; el motivo queda en el historial del pedido y en el registro de actividad. Los cupones y el descuento del grupo del cliente se calculan sobre el precio de catálogo.

## Quién puede

| Permiso | Qué deja hacer | De serie |
|---|---|---|
| `orders.create` | Abrir «Pedido nuevo», crear pedidos y «Repetir» | El administrador y quien ya gestiona pedidos (el gestor de la tienda) |
| `orders.price_override` | Poner el precio de esta venta | Solo el administrador |

El precio de esta venta hay que darlo **por su nombre**: un papel con «orders.*» no lo tiene. Sin el permiso, el campo no sale y, si alguien lo manda a mano, recibe un «Prohibido».

## Repetir un pedido

En la ficha de un pedido, «Repetir» abre «Pedido nuevo» con el mismo cliente, las mismas líneas y la misma dirección. Lo que ya no existe o está agotado se deja fuera y se avisa arriba; si quedan menos unidades de las que llevaba, se pone lo que hay. Revisa el pago y confirma.

## Por si acaso

- **Un doble clic no crea dos pedidos.** Si el formulario se envía otra vez, te lleva al pedido que ya se creó.
- **Si algo cambió entre el total que viste y la confirmación** (quedan menos existencias, un precio subió, un cupón caducó), el pedido no se crea: vuelves a la pantalla con lo que cambió marcado. Revisa y confirma otra vez.
- **En el listado de pedidos** los hechos a mano llevan la marca «Panel», y el filtro «Canal» los separa. Su ficha dice quién los creó.
- Lo que no se hace aquí: cambiar las líneas de un pedido ya creado ni preparar presupuestos.

Más sobre la ficha y los estados en [Pedidos](05-pedidos.md); sobre los cupones, en [Cupones](09-cupones.md); y sobre las formas de pago, en [Formas de pago](10-formas-de-pago.md).

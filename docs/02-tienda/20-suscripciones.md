# Servicios y suscripciones mensuales

Un servicio con un pago puntual se vende como un producto de tipo «Servicio». Las cuotas mensuales se gestionan en **Ventas → Suscripciones**. Activa el módulo «Suscripciones» si todavía no aparece.

## Preparar los planes

1. En **Suscripciones → Planes mensuales**, crea un plan con nombre, servicio incluido, precio mensual y clase de impuesto. El precio se escribe como los demás precios de la tienda.
2. En **Suscripciones → Ajustes**, activa las suscripciones y elige la pasarela.
3. Enlaza la página de servicios mensuales, `/suscripciones/planes`, desde un menú o desde tu página comercial.

El cliente entra en su cuenta, selecciona una dirección de facturación y revisa el importe mensual final, incluidos los impuestos aplicables. Debe marcar expresamente la autorización del pago recurrente. Sin esta aceptación no se crea la suscripción. Las suscripciones aparecen en **Mi cuenta → Mis suscripciones**, desde donde el cliente puede cancelar los próximos pagos.

Modificar un plan afecta a nuevas altas. Los mandatos existentes conservan el importe y los impuestos aceptados en su alta; para cambiar esas condiciones hay que cancelar y solicitar una nueva autorización. Desactivar un plan impide nuevas altas, pero no cancela a los clientes actuales. Un plan utilizado no se puede borrar.

## Demostración sin dinero

El proveedor **Simulado** funciona únicamente en sitios de demostración o con la depuración activada. En demos públicas es siempre el proveedor utilizado, aunque alguien seleccione PayPal en la configuración. La primera cuota crea un pedido de prueba. Desde la ficha del panel puedes simular la siguiente mensualidad o un fallo; los pedidos siguen siendo de prueba y nunca se mueve dinero.

La tarea periódica registra las cuotas simuladas vencidas en las últimas 24 horas. No recupera automáticamente cuotas históricas. Puedes enseñar una renovación inmediatamente mediante el botón de simulación del panel; conserva su vencimiento ficticio y registra el pedido con la hora actual.

## PayPal Subscriptions

La conexión utiliza la API oficial de suscripciones, distinta de los pagos puntuales de PayPal. Configura en **Suscripciones → Ajustes** el identificador de cliente, secreto e identificador del webhook de cada entorno. Los secretos se guardan cifrados y no se muestran después. Empieza con **Sandbox**.

La dirección de aviso figura en el listado del panel. Registra por separado la de sandbox y la de producción. Suscríbela a estos eventos:

- `PAYMENT.SALE.COMPLETED`, `PAYMENT.SALE.REFUNDED` y `PAYMENT.SALE.REVERSED`.
- `BILLING.SUBSCRIPTION.ACTIVATED`, `BILLING.SUBSCRIPTION.UPDATED`, `BILLING.SUBSCRIPTION.SUSPENDED`, `BILLING.SUBSCRIPTION.CANCELLED`, `BILLING.SUBSCRIPTION.EXPIRED` y `BILLING.SUBSCRIPTION.PAYMENT.FAILED`.

El cliente aprueba el mandato en PayPal. Volver de PayPal o ver la suscripción activa no significa que haya un pago confirmado. Solo el aviso de un cobro completado crea su pedido. AIFEC comprueba la firma, la cuenta y el entorno, el identificador del mandato, el importe y la moneda, y evita duplicar una cuota aunque se repita el aviso. Una devolución notificada se registra en el pedido sin pedir a PayPal otra devolución.

PayPal programa las mensualidades y sus reintentos cuando un cobro falla. No se activa el cobro acumulado de saldos pendientes: cada aviso de cuota debe coincidir con la mensualidad autorizada. AIFEC refleja el pago pendiente o la suspensión y permite cancelar, suspender y reanudar desde el panel. El cliente puede cancelar desde su cuenta. Las suscripciones canceladas o finalizadas no se reactivan. Un alta que todavía no tiene mandato aprobado tampoco se puede marcar como activa manualmente.

Los avisos pueden llegar fuera de orden. Una devolución recibida antes de su cobro se conserva en una bandeja duradera y se aplica al registrar la cuota; nunca se acepta y descarta en silencio. Un aviso de impago anterior a un pago posterior no vuelve a marcar la suscripción como pendiente de pago.

## Comprobaciones antes de cobrar

Completa una inscripción con una cuenta compradora sandbox, espera el aviso de pago y comprueba el pedido asociado; prueba también la cancelación y los avisos repetidos. Las pruebas automáticas usan un servidor HTTP simulado: no acreditan un cobro real en el sandbox de tu cuenta. Sin tus claves y la configuración de webhooks, esa comprobación queda pendiente. El módulo no activa la facturación de AIFEC.

Documentación del contrato consultada el 14 de noviembre de 2026: [API de suscripciones](https://developer.paypal.com/api/subscriptions/v1/), [eventos de suscripciones](https://developer.paypal.com/subscriptions/webhooks/) y [reintentos de cobro](https://developer.paypal.com/subscriptions/payment-failure-retry/).

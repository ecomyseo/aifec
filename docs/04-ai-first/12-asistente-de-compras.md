# El asistente de compras

El asistente usa el catálogo, el carrito y las tarifas de AIFEC. En modo de reglas puede localizar productos, mostrar sus precios y consultar las opciones de entrega. Cuando se conecta un proveedor de IA, la presentación del chat lo indica. El comercio sigue calculando los importes.

## Búsqueda por presupuesto

Con «busco un atril de unos 40 €», el buscador de la tienda muestra artículos desde 30 € hasta 46 €, incluidos ambos extremos. Para un presupuesto aproximado, indica la moneda. Así el asistente distingue el importe de una talla o una medida.

## Ofertas y cupones

Una pregunta como «¿Qué ofertas tenéis?» muestra artículos que tienen un precio anterior superior al vigente. En **Ajustes del asistente**, escribe en **Códigos de descuento que se pueden anunciar** los códigos generales que quieras publicar, separados por comas. Si el campo queda vacío, los códigos generales siguen siendo privados. Un cliente identificado puede consultar los cupones vigentes que se le hayan asignado. La disponibilidad final de un descuento depende de las condiciones de su carrito.

## Destinos y privacidad

El cliente puede indicar un país o una zona de entrega al preguntar. Cuando hace falta un código postal, el chat lo pide para calcular la tarifa de ese destino. La consulta de portes se mantiene separada de la dirección del pedido. En las consultas de salud que reconoce el asistente, se recomienda consultar a un profesional y se guarda un marcador en el historial.

## Límites de conversación

Los valores iniciales son 20 mensajes por minuto y 300 al día por IP. Una ráfaga excesiva activa una pausa inicial de tres minutos. El navegador muestra el tiempo restante, conserva el borrador y vuelve a habilitar el campo al terminar. En redes compartidas, varias personas pueden consumir el mismo límite. La conservación del historial se configura en días y empieza con 30.

## Seguimiento

El panel de preguntas sin respuesta incluye los últimos siete días. Cuenta cada conversación una vez y señala las que terminaron con una búsqueda sin productos. Si el visitante encontró lo que buscaba en un turno posterior, esa conversación deja de figurar como perdida en el periodo. Una ventana sin conversaciones medidas se presenta sin porcentaje.

Para comprobar los cambios con los cuatro catálogos de demostración, ejecuta la siguiente orden desde la raíz de AIFEC.

```shell
php tests/run.php SectorEvaluationTest
php aifec assistant:evaluate --site=main
```

La prueba ejecuta entre 178 y 182 consultas por catálogo. Incluye cambios de idioma y de tema, erratas y precios contrastados en céntimos. La orden de consola crea una base SQLite temporal con el catálogo y las tarifas del sitio indicado, comprueba los resultados y elimina la copia al terminar. La evaluación utiliza reglas y excluye los pedidos y las conversaciones de clientes.

## Preparar el catálogo y las respuestas

El asistente usa los apartados publicados de la ficha para responder sobre ingredientes, materiales, cuidados y modo de empleo. Las preguntas frecuentes de la ficha también aparecen al abrir el chat desde ese producto. Para dirigir una respuesta editorial a una categoría, indica la ruta de su página al editar la guía. Las comparativas admiten de dos a diez productos y varios párrafos en Markdown. Guarda una guía por idioma y actívala cuando esté lista.

Configura el sector y sus equivalencias en el buscador. Una búsqueda con compra posterior puede generar una propuesta de sinónimo para revisión cuando existe consentimiento estadístico. El comerciante decide si la incorpora. En la ficha del producto puedes indicar las unidades de un paquete, su equivalente individual y los días habituales de reposición. El comparador usa estos valores y los precios vigentes para calcular el precio unitario y el ahorro.

## Atender a un cliente

Cuando el cliente pide una persona, el chat muestra un resumen y pide permiso para compartirlo. El equipo puede responder desde el panel de conversaciones con el permiso de atención. El comprador consulta la respuesta en esa misma conversación. El enlace de WhatsApp requiere un proveedor configurado y un número público internacional. El aviso de reposición utiliza el formulario del complemento Próximamente, con consentimiento para un único correo cuando vuelva a haber existencias.

El buscador de regalos pregunta por la categoría y el presupuesto. El cliente puede quitar los criterios visibles. Puede guardar un presupuesto en su propia cuenta mediante la opción expresa del chat. La venta cruzada y la recompra se activan por separado en los ajustes. Para proponer un complemento se usa una relación de venta cruzada que el comerciante haya creado en el catálogo.

## Compra, medición y privacidad

El chat calcula los portes para el destino indicado y permite elegir transportista. Si se usa una dirección guardada, el cliente puede aceptarla o cambiarla. Antes de pagar se presenta el resumen con impuestos y gastos de envío. El titular confirma el pago en el flujo habitual de la tienda.

En el móvil, el chat ocupa la pantalla y la página de fondo queda quieta. En escritorio se puede ampliar, acoplar a un lado o abrir en otra ventana. «Empezar de nuevo» termina el historial del chat. El panel de conversaciones muestra las valoraciones, el motivo elegido y el coste estimado si el proveedor comunica los datos necesarios. Con consentimiento estadístico, la capa de datos registra el uso del chat y atribuye las compras asistidas.

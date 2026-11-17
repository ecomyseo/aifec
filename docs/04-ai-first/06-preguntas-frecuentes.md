# Preguntas frecuentes

Diez preguntas de un comerciante sobre tiendas «AI-first». Las respuestas dicen lo que AIFEC hace de verdad. De lo que hagan terceros (Google, OpenAI, Anthropic, cualquier asistente) no se promete nada.

## 1. ¿Esto sustituye al SEO?

No. El SEO de siempre sigue ahí y lo gestionas igual: títulos y descripciones, direcciones amigables, redirecciones, mapa del sitio y `robots.txt` (ver [SEO](../03-contenido-y-diseno/06-seo.md)). Lo AI-first se añade encima: una versión en texto de cada página, un `/llms.txt`, feeds de producto y una API, además de los datos estructurados que ya leen los buscadores.
## 2. ¿ChatGPT va a vender mis productos?

No hay manera de saberlo, y nadie puede prometértelo. Lo que sí hace tu tienda es dejarse leer bien: ofrece sus productos, precios y existencias en formatos que un asistente entiende ([qué ofrece tu tienda a las máquinas](02-lo-que-publica-tu-tienda.md)), y tiene una puerta para que un asistente conectado pueda preparar una compra ([API y MCP](05-api-y-mcp-para-no-tecnicos.md)). Si un asistente decide recomendarte o no, lo decide él. En «Robots y asistentes» puedes ver si te visitan y si llegan compras desde ellos.

## 3. ¿Qué pasa si cambio un precio?

Lo cambias una vez, en la ficha del producto. La página, los datos estructurados, la versión en texto y la API lo muestran en cuanto guardas. Los feeds son ficheros que se rehacen en segundo plano, unos minutos después del cambio. No hay que actualizar nada en otro sitio. Ver [una sola verdad](01-una-sola-verdad.md).

## 4. ¿Puede la IA cambiar mis textos o mis precios sin que yo lo sepa?

No. La IA solo propone: cada texto que escribe queda como una propuesta pendiente, y no llega a la tienda hasta que alguien pulsa «Aprobar». Los precios, los impuestos, los envíos y los totales no los toca nunca: salen de los servicios de la tienda. Ver [IA en el panel](../03-contenido-y-diseno/07-ia-en-el-panel.md).

## 5. ¿Y si no quiero usar IA?

La IA viene apagada, y la tienda funciona igual: el buscador propio, el panel y el asistente de compra (en su modo sencillo, con reglas fijas) no la necesitan. Las páginas de tu tienda nunca dependen de la IA para cargarse. La IA se usa solo para proponer textos en el panel y, si la enciendes, para que el asistente conteste con más soltura.

## 6. ¿Cuánto me va a costar la IA?

Lo decides tú. Tienes un tope diario en céntimos (200 por defecto) y otro de llamadas (500 por defecto); al llegar a uno, la IA se para hasta mañana. La pantalla «Uso de la IA» enseña el gasto de hoy y de los últimos 30 días. El gasto es una estimación con los precios que escribas en los ajustes; con los precios a cero no se cuenta en céntimos. Los modelos los cobra tu proveedor. Ver [IA en el panel](../03-contenido-y-diseno/07-ia-en-el-panel.md).

## 7. ¿Puedo impedir que los robots de IA lean mi tienda?

Sí. En «Ajustes» → «SEO», pestaña «Robots», puedes bloquear por separado a los buscadores, a los asistentes de IA y a los robots de entrenamiento. Es una petición que los robots serios respetan, no una cerradura. Y bloquear a los asistentes significa que no podrán leer tu tienda para contestar a una persona. Ver [robots de IA](03-robots-de-ia.md).

## 8. ¿Se envían datos de mis clientes a una IA?

Depende de lo que enciendas.

- Con la IA apagada, no se envía nada.
- Al proponer un texto, se envía la ficha del producto, no datos de clientes.
- Con la IA encendida, lo que un cliente escribe al asistente de compra se envía al proveedor que elijas, para que pueda contestar. Esas conversaciones pueden llevar datos personales: se borran pasados los días que indiques en «Ajustes del asistente» (30 por defecto), y conviene nombrarlas en tu política de privacidad.
- Las visitas de robots se cuentan sin guardar direcciones IP.

Los servicios externos a los que llama un módulo los declara él y los ves en «Módulos y plugins», columna «Declara».

## 9. ¿Puede un asistente hacer un pedido en mi tienda sin que yo me entere?

Un pedido hecho por una API o por un asistente conectado necesita la revisión previa del pedido con su total exacto y la confirmación de la persona. Esa confirmación la comprueba la tienda: manda al correo del comprador un código con el total, y el pedido solo se hace si el programa lo devuelve. Así puede comprar cualquier asistente, pero ninguno sin la persona. Si prefieres dar paso solo a programas de tu confianza, crea una clave con el permiso `orders:create` (con ella no hace falta el código) y elige «Solo con clave» en «Pedidos hechos por agentes de IA» (API → Pedidos); con «No», nadie hace pedidos por esta vía. Además, el pedido no puede pasar del importe máximo, si has fijado uno. El pedido, cuando se hace, aparece en «Pedidos» como cualquier otro, con la nota de cómo se confirmó, y el cobro lo hace la persona. Ver [API y MCP](05-api-y-mcp-para-no-tecnicos.md).

## 10. ¿Cómo sé si mi tienda está bien preparada?

Mira la [auditoría AIFEC](04-la-auditoria-aifec.md): da una nota de 0 a 100 a la tienda y a cada producto, y dice qué falta. No mide si venderás más: mide si la información está completa. Los contadores de «Robots y asistentes» empiezan a cero y suben con las visitas reales.

## Si algo no sale

- **No encuentro un ajuste que se nombra.** Comprueba que su módulo esté activo en «Diseño» → «Módulos y plugins».
- **Una respuesta no coincide con lo que veo.** Mira el ajuste que cita: puede haberse cambiado.

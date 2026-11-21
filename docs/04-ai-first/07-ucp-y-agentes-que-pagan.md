# UCP, ACP y agentes que compran

El módulo **Comercio con agentes** conecta la caja de AIFEC con UCP y ACP. Utiliza los mismos productos, variantes, impuestos, descuentos, existencias y transportistas que la tienda. Cada protocolo se activa por separado en *Ajustes → UCP y agentes*.

## UCP

El perfil público está en `/.well-known/ucp`. La versión predeterminada es **2026-08-25**; también se sirve el perfil **2026-04-08** en `/.well-known/ucp/2026-04-08`. Las respuestas llevan los manejadores de pago disponibles y las claves públicas para comprobar firmas HTTP.

La API REST vive en `/ucp/v1`: catálogo (`POST /catalog/search`, `/catalog/lookup`, `/catalog/product`), carritos (`POST /carts`, `GET` y `PUT /carts/{id}`, `POST /carts/{id}/cancel`) y sesiones de compra (`POST /checkout-sessions`, `GET` y `PUT /checkout-sessions/{id}`, `POST /checkout-sessions/{id}/complete` y `/cancel`). Crear una compra con `cart_id` reutiliza el contenido del carrito, sin sustituirlo por líneas superpuestas de la petición.

Los identificadores de artículo son `<producto>` o `<producto>-<variante>`, como en el catálogo. Las cantidades son unidades enteras. Los importes y las opciones de entrega los calcula la tienda; no se aceptan precios enviados por el agente.

## ACP

El documento `/.well-known/acp.json` anuncia **Agentic Commerce Protocol 2026-04-17** por REST. Las sesiones se crean en `POST /checkout_sessions`; se consultan con `GET /checkout_sessions/{id}` y se actualizan con `POST` sobre la misma dirección. `POST …/complete` y `POST …/cancel` completan o cancelan la sesión. También existen las rutas equivalentes bajo `/acp/v1`.

Cada petición necesita una clave `Authorization: Bearer` de la API de la tienda con el permiso `orders:create`. Las operaciones que cambian una sesión necesitan `Idempotency-Key`. La cabecera `API-Version: 2026-04-17` fija el contrato. El documento de descubrimiento detalla el transporte disponible. El catálogo ACP se sirve en /acp/feed.jsonl. El comprador paga en la caja de la tienda.

Los artículos repetidos del contrato ACP se agrupan en su cantidad correspondiente. La adaptación conserva el estado completo de la caja y las actualizaciones parciales de ACP. Los pedidos se identifican con el canal **Plataforma de IA (ACP)**.

## Pago y continuación en la web

La sesión mantiene los artículos y proporciona continue_url para que el comprador confirme en la tienda. La caja ofrece tarjeta, PayPal y los demás métodos que estén habilitados. El estado de la sesión recoge el pedido confirmado.

Las compras abiertas caducan a las 24 horas. Una sesión cancelada o caducada ya no se puede recuperar con su enlace. Las sesiones creadas con una clave solo se consultan o cambian con esa misma clave. Los reintentos idempotentes conservan el resultado y los cambios concurrentes de la misma compra se serializan.

## Agentes de navegador

Web Bot Auth permite verificar firmas de agentes de los orígenes de confianza configurados. Una cabecera `User-Agent` por sí sola no autentica a nadie. La firma válida permite atribuir la visita; no sustituye el consentimiento del comprador ni las comprobaciones del pago.

En el módulo **ecom_aifec para PrestaShop**, la pantalla independiente *AIFEC: agentes de navegador* muestra agentes conocidos y cabeceras observadas, con actividad y permisos por tienda. Los navegadores automáticos desconocidos no crean carritos por defecto; el comerciante puede permitir entradas concretas. Los enlaces deliberados de una persona y las API autenticadas conservan sus recorridos. No existe una lista finita de todos los navegadores de agentes: el panel combina catálogo y observación real, y describe las cabeceras como indicios.

## Verificación de esta integración

Las pruebas distinguen HTTP real y bases de datos locales de la simulación del proveedor de pagos. Crear un pedido local con un PSP simulado demuestra el recorrido y la persistencia; no es un cargo bancario ni una certificación de Google, OpenAI o un adquirente. Los informes de la integración conservan los esquemas oficiales, las peticiones, las respuestas y los fallos reproducidos y corregidos.

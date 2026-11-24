# AIFEC 1.3.1

## Compra con enlaces

- La guía de `/buy-with-links` habla en tono informativo: basta con abrir las direcciones, no hace falta conector y la persona revisa el total y confirma. Fuera las frases que decían qué ocultar a la persona. Una prueba falla si reaparecen.
- Los robots de vista previa de enlaces (WhatsApp, Facebook, Slack, Telegram, Discord…) y las precargas del navegador ya no crean carritos por `new`, `add` ni `start`: contestan 403.

## Búsqueda de la API

- `/api/v1/search` dice en `meta` cuántos devuelve (`returned`), el límite (`limit`, `limit_max`) y si hay más (`has_more`, con una nota), para que `total` y `data` no parezcan descuadrados.
- Sinónimos de serie en castellano: soporte = asistencia técnica; aviso = notificación = alerta.

## Feeds

- Orden de consola `php aifec feeds:excluded`: lista por identificador los productos que no entran en los feeds ACP y Google, con su motivo. El diagnóstico enseña hasta 40 por identificador. Todo sale de la misma regla (`FeedEligibility`).

## Desistimiento en la caja (plugin GM legal texts per product)

- El texto de la casilla depende de lo que lleva el carrito: descarga, servicio (pago a prorrata si se desiste tras empezar) o las dos cosas. Sin descargas ni servicios no sale.
- La casilla va encima del botón de pagar (hueco nuevo `checkout.review.before_pay`).
- Sin marcar, la descarga queda retenida los 14 días del plazo y después se entrega sola (ajuste «Días que se retiene una descarga»).
- El pedido guarda el texto exacto que vio el cliente, el tipo y la fecha, sin su IP, y el correo de confirmación lo confirma con la fecha.

---

# AIFEC 1.3.0

## Licencia y origen

- **AIFEC pasa de Apache 2.0 a GNU AGPL v3 con términos adicionales de origen** (`LICENSE.md`): hay que conservar el aviso visible «Creado con AIFEC», la autoría de Ecom Experts y la marca del generador; los nombres AIFEC y Ecom Experts no se licencian para versiones modificadas; hay una licencia comercial aparte. Las versiones hasta la 1.2.6 siguen siendo Apache 2.0 (`LICENSES/Apache-2.0.txt`). Ficheros nuevos: `NOTICE` y `CONTRIBUTING.md`.
- La tienda lleva «Creado con AIFEC» al final de cada página, el panel dice versión, autor y licencia, y `<meta name="generator">` y `/.well-known/aifec.json` declaran el origen.
- Dominio principal del proyecto: `aifec.dev`.

## Privacidad en la compra con enlaces

- Los datos de quien compra (nombre, dirección, teléfono y correo) **ya no se leen de una dirección web**: quedan en historiales y registros. De serie se descartan y se manda a la persona al formulario de la tienda, que ahora envía por POST. Ajuste «Permitir los datos del comprador escritos en la dirección» (apagado de serie) para recuperar el comportamiento anterior.
- La guía de `/buy-with-links` sale en el idioma de la tienda (antes siempre en inglés).

## Feeds y datos estructurados

- Una sola regla decide qué productos entran en los feeds (ACP, Google, OpenAI) con su motivo (sin imagen, precio oculto, gratuito, no comprable); se cuenta en `llms.txt`, `/.well-known/aifec.json` y «Sistema → Diagnóstico». Ajuste para incluir productos gratuitos (apagado de serie).
- La dirección de la tienda se escribe en campos (calle, código postal, localidad, provincia, país) y de ahí salen Organization (JSON-LD), `llms.txt` e `index.md`. Con solo el texto libre de antes, todo sigue igual.

## Robots y textos

- Cabecera `Link` con la versión en texto (`.md`) de cada página; ajustes por familia de robot para recibir el texto en la misma dirección (buscadores apagado de serie; los de vista previa de enlaces siempre reciben HTML); `sitemap-md.xml`; el panel de robots enseña el porcentaje servido como `.md`.

## Rendimiento y WebMCP

- Minificado de JavaScript y CSS con dos interruptores en Ajustes → General → Rendimiento (encendidos de serie).
- WebMCP: `submit_review` y `ask_shop_assistant`; los filtros del listado quedan excluidos a propósito.

## Probado

- Pruebas en verde de `modules/api`, `modules/semantic`, `modules/agentic`, `modules/mcp`, `modules/webmcp`, `modules/assistant`, `modules/reviews`, `tests/Kernel`, `tests/Front` y `tests/Admin`, con mutaciones que reponen cada fallo.

---

# AIFEC 1.2.6

## Descubrimiento de UCP y ACP

- El perfil UCP se sirve igual en `/.well-known/ucp`, `/.well-known/ucp.json`, con barra final, con la versión y en `/ucp/v1`, también con HEAD y OPTIONS (JSON, orígenes abiertos). Las direcciones `.well-known` se escriben en minúsculas.
- Con UCP, ACP o MCP apagados, la respuesta es un 404 en JSON que dice por qué y no se guarda en caché; «Sistema → Diagnóstico» avisa cuando el módulo está activo y UCP o ACP siguen apagados. La página 404 de HTML tampoco se guarda.
- La ficha de UCP y la de ACP salen sin `Expires` ni `Pragma` junto a `max-age`. `acp.json` declara su autenticación (bearer, cabecera `Authorization`, ámbitos `acp` y `orders:create`).
- `buy-with-links`: sin órdenes dirigidas al modelo y con `cart_summary` (líneas y total) en los pasos de entrega y de pago; la búsqueda devuelve `buy_url` por producto.
- Guía de nginx con `ucp.json` y con `if_not_empty` en `HTTP_AUTHORIZATION`. Catálogo para agentes (`ard.json` y `ai-catalog.json`) válido.
- El script de cierre del ZIP incluye `protocol/`, `CHANGELOG.md` y `README.es.md` y se niega a sacar un paquete sin `protocol/Acp.php`.

## WebMCP

- Buscadores, fichas, tarjetas de producto, carrito, entrada de cliente y formulario de contacto de los ocho temas de serie llevan `toolname`; los nombres no se repiten en una página. El panel de WebMCP enseña qué plantillas marcan cada formulario.

## Ficheros descargables con dirección pública

- Cada fichero de un producto digital puede tener una dirección pública `public/descargas/<código de 48 cifras>/<nombre>`, para avisar de actualizaciones a todos los clientes. Interruptor «Dirección pública» con la ruta y la dirección completa con botón «Copiar», casilla en la subida y «Sustituye a» para subir una versión nueva sin perder la dirección. Sin listados. Columna `product_files.public_token` (migración 0004).

## Inteligencia artificial

- Nuevos proveedores en los ajustes: Anthropic Claude (proveedor propio con herramientas), ChatGPT, Grok, Mistral, DeepSeek, Groq, OpenRouter y Perplexity, además de Gemini y los compatibles con OpenAI.
- El asistente de la tienda avisa de que es una inteligencia artificial.

## Medición

- Contenedor de Google Tag Manager listo para importar (`modules/datalayer/gtm/aifec-gtm-ga4.json`): etiqueta de Google, GA4 y los 15 eventos de la capa de datos, con consentimiento de estadísticas.

## Probado

- Pruebas en verde de `modules/agentic`, `modules/api`, `modules/mcp`, `modules/assistant`, `modules/webmcp`, `modules/catalog`, `modules/orders`, `modules/ai`, `modules/datalayer`, `tests/Ai` y `tests/Admin`, con mutaciones que reponen cada fallo.

---

# AIFEC 1.2.5

## Seguridad del panel

- **Dirección propia del panel.** Nuevo ajuste «Dirección del panel» (General → Seguridad). Con él, el panel vive en esa ruta, `/admin` responde «no encontrado», todos los enlaces y formularios salen con la ruta nueva y la cookie de sesión se limita a ella. Un valor mal escrito o reservado se ignora y el panel sigue en `/admin`.
- **Passkeys (WebAuthn).** Módulo nuevo `security`: alta, entrada sin contraseña y borrado de passkeys de las personas del panel. Solo se guarda la clave pública; se comprueban desafío, origen, sitio, verificación del usuario, firma y contador. Sin dependencias: CBOR y WebAuthn escritos a mano, con `openssl`.
- **Auditoría de seguridad.** Pantalla, `php aifec security:audit` y tarea diaria: doce comprobaciones con nota de 0 a 100 y aviso en el Inicio si algo está en rojo.
- **Aviso de versión nueva.** Una vez al día se lee la última release de GitHub y el Inicio avisa si es mayor que la instalada (`php aifec security:update-check`). No instala nada.

## Catálogo ARD válido

- `/.well-known/ai-catalog.json` (y su nombre actual `/.well-known/ard.json`) lleva en cada entrada identificador, nombre, tipo IANA válido y consultas representativas, y la portada enlaza con `rel="ard"`.

## Probado

- `tests/run.php modules/security`: 29 correctas (182 comprobaciones), con claves P-256 reales para las passkeys, en PHP 7.4 y 8.3.

## Qué no cambia

- Sin ruta propia el panel sigue en `/admin`. La actualización automática desde GitHub es otra entrega: hoy solo se avisa.

---

# AIFEC 1.2.4

## Catálogo para Google, Microsoft y Perplexity

- Nuevo catálogo en el formato de Google Merchant Center (texto separado por tabuladores) en `/acp/feed-google.tsv`. Una línea por variante, `item_group_id` para agrupar las variantes de un producto y las 16 columnas que piden Google Merchant Center, Microsoft Merchant Center y Perplexity. Los tabuladores y saltos de línea de los textos se limpian para que no descuadren las columnas.
- Prueba nueva en `modules/agentic/tests/HostedCommerceTest.php`: columnas exactas en todas las líneas, disponibilidad en el formato de Google, agrupación de variantes, imagen propia de la variante y aviso `identifier_exists=no` cuando faltan GTIN y MPN.

## Guía de pasos fuera de la tienda

- Pestaña nueva «Pasos fuera de la tienda» en los ajustes de UCP y agentes, y documento `docs/04-ai-first/10-pasos-fuera-de-la-tienda.md`. Explican, paso a paso, qué hacer en Google Merchant Center, Google Pay y UCP, ChatGPT (OpenAI), Claude y otros asistentes por conector, Microsoft Copilot, Perplexity y Redsys, con las direcciones de la propia tienda ya escritas.
- Dice con claridad qué depende de cada plataforma: la aprobación y el momento en que empiezan a leer los ficheros no los decide la tienda.
- Traducido al castellano en `modules/agentic/lang/es.php`.

## Probado

- `tests/run.php modules/agentic`: 33 correctas, 0 fallan (563 comprobaciones).
- `tests/Privacy`: 8 correctas. `tests/Kernel`: 131 correctas. `tests/Admin`: 385 correctas.
- En una instalación real, con una clave de prueba con el ámbito `orders:create` (borrada después): sesiones UCP y ACP creadas, consultadas, actualizadas, completadas con una credencial ficticia (la tienda responde `requires_escalation` y envía a su caja) y canceladas, sin pedidos ni cargos.

## Qué no cambia

- `is_eligible_checkout` sigue en `false` y `payment_handlers` vacío: el comprador termina en la caja de la tienda. El pago dentro del asistente necesita que la plataforma y un proveedor de pago compatible lo habiliten.

---

# AIFEC 1.2.3

## UCP

- Perfiles de descubrimiento con catálogo, carrito y sesiones de compra por REST y MCP. Cada instalación ofrece sus propias direcciones y utiliza su propio catálogo.
- Las búsquedas conservan los términos relevantes de la consulta. Si el catálogo solo tiene sillas de parapente, una consulta sobre sillas de camping devuelve una lista vacía.
- Se conserva la búsqueda de una sola palabra y el filtro de precio. La búsqueda sin índice de texto aplica el mismo criterio.

## Checkout ACP

- Catálogo JSONL en UTF-8 y sesiones de compra con creación, consulta, actualización, continuación y cancelación.
- La documentación explica por qué `is_eligible_checkout` y `enable_checkout` quedan en `false` en la demo de AIFEC. El pago integrado requiere habilitación por parte de OpenAI.
- Las sesiones ACP siguen enviando al comprador a la caja de AIFEC para confirmar y pagar.

## Instalación

- El ZIP contiene los contratos de comercio, las migraciones y la guía para Apache y Nginx. UCP y ACP se habilitan en los ajustes de cada tienda.

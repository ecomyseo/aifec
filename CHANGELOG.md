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

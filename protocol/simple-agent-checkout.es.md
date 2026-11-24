# Compra sencilla para agentes (Simple Agent Checkout)

[English](simple-agent-checkout.md) · **Español**

*Versión 0.1, noviembre de 2026. La implementan [AIFEC](../README.es.md) y el módulo `ecom_aifec` para PrestaShop.*

Este documento describe cómo un asistente de IA puede comprar en una tienda en línea **para una persona** usando solo lo que ya tiene cualquier asistente: abrir direcciones web, o llamar a herramientas por MCP. Es pequeño a propósito. No sustituye a UCP (Google) ni a ACP (OpenAI): cubre las tiendas y los asistentes a los que esos protocolos todavía no llegan.

## Principios

1. **La tienda es la única fuente de verdad.** Los precios, los impuestos, el envío, las existencias y los totales salen siempre de las respuestas de la tienda. El asistente nunca los calcula.
2. **Confirma una persona.** Un pedido solo se hace cuando la persona ha visto el total exacto y lo ha confirmado: con un código de 6 cifras que la tienda le manda por correo, o porque la tienda confía en quien llama con una clave de la API que ella misma ha dado.
3. **Paga la persona, en la página de la tienda.** El asistente nunca ve ni envía datos de pago.
4. **Cada respuesta dice qué viene después.** Las respuestas traen las siguientes direcciones (o herramientas) listas para usar: el asistente nunca tiene que montar ni adivinar una dirección.
5. **Primero, HTTP sencillo.** Todo funciona con peticiones GET y respuestas legibles; MCP y la API REST son los mismos recorridos para los clientes que pueden hacer más.

## 1. Descubrimiento

Una tienda que sigue este documento publica:

| Qué | Dónde |
|---|---|
| Una guía para modelos de lenguaje | `/llms.txt` (text/plain), enlazada desde todas las páginas (`<link rel="alternate" type="text/plain">` y un enlace visible en el pie) |
| Una versión en texto del catálogo | `/index.md`, y versiones `.md` de categorías y fichas |
| El servidor MCP | `<link rel="mcp" href="…/mcp">` en todas las páginas |
| La ficha del servidor MCP | `/.well-known/mcp/server-card.json` (también `/.well-known/mcp-server-card`, `/.well-known/mcp` y `/mcp/server-card`), con el formato del registro oficial de MCP |
| Un catálogo de puertas para IA | `/.well-known/ai-catalog.json` |
| La descripción de la API REST | `/openapi.json`, `/.well-known/openapi.json` y `/api/openapi.json` (OpenAPI 3.1) |

Cada ficha de producto (HTML y `.md`) dice, con palabras claras, la dirección que un asistente abre para comprar ese producto.

## 2. Compra con enlaces (solo GET)

Para los asistentes que solo saben abrir direcciones.

```
GET /buy-with-links                         cómo funciona, como datos
GET /buy-with-links/new/{producto}          una unidad de un producto en un carrito nuevo
GET /buy-with-links/new/{producto}/{opcion} lo mismo, para una opción (talla, color…)
GET /buy-with-links/{paso}/{carrito}[/{a}[/{b}]]  cada paso siguiente, con el carrito en la dirección
```

Los pasos son `start`, `add`, `cart`, `customer`, `shipping-options`, `shipping`, `payment-options`, `payment`, `status`, `review`, `code` y `place`. Cada respuesta es JSON (o el mismo JSON dentro de una página HTML mínima para los lectores que piden HTML) con:

- `data`: lo que contesta la tienda: líneas, precios en céntimos y con formato, totales con y sin envío;
- `say`: un texto corto que el asistente puede pasar a la persona tal cual;
- `next`: las direcciones de los siguientes pasos posibles.

Hay dos finales buenos:

- **Entregar el carrito (el penúltimo paso).** La respuesta trae una dirección que abre **este carrito** en la caja de la tienda (`/carrito/retomar/{ficha}` en AIFEC). El asistente se la da a la persona, que escribe sus datos y paga. Ningún dato personal pasa por el asistente.
- **Pedido (el último paso).** El asistente envía los datos de la persona, elige el envío y el pago, y pide la revisión. La tienda manda al comprador un código de 6 cifras con el total. Cuando la persona devuelve el código, el asistente abre el paso `place` con él y la tienda contesta con el número de pedido y dónde pagar.

## 3. MCP

`POST /mcp`, HTTP en streaming, versiones del protocolo 2025-06-18, 2025-03-26 y 2024-11-05. No hace falta clave.

Las herramientas siguen los pasos: `search_products`, `get_product`, `list_categories`, `check_availability`, `shipping_quote`, `store_info`, `cart_view`, `cart_add`, `cart_update`, `checkout_link`, `checkout_status`, `checkout_set_customer`, `checkout_shipping_options`, `checkout_set_shipping`, `checkout_payment_options`, `checkout_set_payment`, `checkout_review` y `checkout_place`. Cada argumento está descrito y las herramientas llevan sus anotaciones (`readOnlyHint`, `idempotentHint`…).

- **Sesiones.** El carrito es de la sesión MCP (`Mcp-Session-Id`). Los clientes que no guardan esa cabecera (el conector de ChatGPT es uno) reciben un `session_id` de la primera herramienta que escribe y pueden mandarlo después como argumento.
- **Entregar el carrito.** Sin cabecera de sesión, `cart_add` y `cart_update` ya devuelven `checkout_url`, la dirección que abre ese carrito en la caja. `checkout_link` devuelve la misma.
- **Pedido.** `checkout_review` devuelve el pedido exacto, su total y un `confirm_token`, y la tienda manda un código al comprador. `checkout_place` necesita `confirm_token`, `buyer_confirmed: true` y `buyer_code` (o una clave de la API con el permiso `orders:create`). Cualquier cambio después de la revisión invalida la ficha.

## 4. API REST

El mismo recorrido en JSON por HTTP, descrito en OpenAPI: búsqueda y productos (`/api/v1/search`, `/api/v1/products`…), un carrito cuya ficha viaja en `X-Cart-Token`, y los pasos de la caja (`customer`, `shipping`, `payment`, `review`, `place`). Las respuestas son `{"data": …}`, las listas añaden `"meta"` y los errores son `{"error": {"status", "message"}}`.

## 5. Lo que garantiza la tienda

- El mismo precio, existencias y total en la página web, la página `.md`, la API, MCP y la compra con enlaces.
- Ningún pedido sin la confirmación de la persona (código del comprador o una clave que dio la tienda), y límites por importe y por hora.
- El pago, solo en la página de la tienda y con sus propias pasarelas.
- Entregar un carrito no necesita ningún dato personal.

## 6. Relación con UCP y ACP

UCP y ACP permiten que una plataforma de IA cobre dentro de su propia pantalla, después de que la tienda se dé de alta en esa plataforma. La compra sencilla para agentes no necesita darse de alta en nada y funciona hoy con cualquier asistente, a cambio de un último paso en la página de la tienda. Una tienda puede ofrecer las dos cosas: AIFEC publica además un perfil UCP en `/.well-known/ucp` cuando el comerciante lo enciende.

## Implementación de referencia

- AIFEC: https://github.com/ecomyseo/aifec. Módulos `api` (REST y compra con enlaces), `mcp`, `semantic` (`llms.txt` y páginas `.md`) y `agentic` (UCP).
- Demo en directo: https://aifec.dev/

Con la [licencia de AIFEC](../LICENSE.md) (AGPL v3 con términos adicionales de origen; hasta la 1.2.6, Apache 2.0).

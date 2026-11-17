# MCP

El servidor MCP (Model Context Protocol) deja que un asistente de IA (Claude, ChatGPT y otros) use la tienda con las mismas herramientas que el asistente de la propia tienda: buscar productos, mirar disponibilidad y envíos, preparar un carrito y llevar la compra hasta el final. Esta página explica cómo se conecta un cliente, qué herramientas ofrece y qué permisos tiene. Las pruebas de abajo se hicieron con `curl` contra un sitio local.

## Cómo se conecta un cliente

El servidor habla **JSON-RPC 2.0 sobre HTTP**: un mensaje por petición `POST` a `/mcp`, una respuesta JSON, sin flujos abiertos (no usa SSE). Los clientes lo encuentran en la dirección de `/.well-known/mcp.json`, que dice el protocolo, la dirección y las herramientas:

```
curl -s "$TIENDA/.well-known/mcp.json"
```

```json
{"name":"AIFEC","protocol":"mcp","protocolVersion":"2025-06-18","transport":"json-rpc-over-http","endpoint":"http://127.0.0.1:8766/mcp","authentication":{"required":false,"scheme":"bearer","note":"Optional: an API key raises the usage limit. Placing an order needs the 6-digit code the shop emails to the buyer at the review (buyer_code), or a key with the scope orders:create."},"tools":[…]}
```

Un `GET /mcp` responde 405 con un JSON que explica esto mismo. Las versiones del protocolo que habla son `2025-06-18`, `2025-03-26` y `2024-11-05`; si el cliente pide una que no conoce, el servidor contesta con la más nueva.

### El saludo

```
curl -s -i -X POST "$TIENDA/mcp" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"prueba","version":"1"}}}'
```

La respuesta lleva la cabecera **`Mcp-Session-Id`** y un resultado como este:

```json
{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18","capabilities":{"tools":{"listChanged":false},"resources":{"subscribe":false,"listChanged":false}},"serverInfo":{"name":"aifec","title":"AIFEC","version":"1.0.0"},"instructions":"This is the online shop AIFEC. Use search_products and get_product to find things, then cart_add to build a cart (it belongs to this session). …"}}
```

El cliente guarda el identificador de sesión y lo manda en las siguientes peticiones (`Mcp-Session-Id: …`): **cada conexión tiene su propio carrito**. Los métodos que atiende son `initialize`, `ping`, `tools/list`, `tools/call`, `resources/list`, `resources/templates/list` y `resources/read`; una notificación (`notifications/initialized`) no se contesta. Un método que no existe da el error JSON-RPC `-32601` y unos parámetros inválidos, `-32602`:

```
curl -s -X POST "$TIENDA/mcp" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":9,"method":"nada"}'
{"jsonrpc":"2.0","id":9,"error":{"code":-32601,"message":"Method not found: nada"}}
```

## Las herramientas

`tools/list` devuelve las herramientas con su nombre, su descripción y el esquema de sus argumentos:

| Herramienta | Qué hace |
|---|---|
| `search_products` | Busca en el catálogo con palabras, categoría, precio máximo o solo en stock. |
| `get_product` | Todo lo que la tienda dice de un producto. |
| `list_categories` | Las categorías. |
| `check_availability` | Si un producto o variante se puede comprar en una cantidad. |
| `shipping_quote` | Opciones, precios y plazos de envío a un destino. |
| `store_info` | Información de la tienda y sus políticas: contacto, horarios, devoluciones, formas de pago. |
| `cart_view`, `cart_add`, `cart_update` | Ver, añadir y cambiar el carrito de la conexión. |
| `checkout_link` | La dirección para terminar de pagar en la web. |
| `checkout_status`, `checkout_set_customer`, `checkout_shipping_options`, `checkout_set_shipping`, `checkout_payment_options`, `checkout_set_payment` | Los pasos de la compra. |
| `checkout_review` | El pedido exacto que se haría, con su total y el `confirm_token`. |
| `checkout_place` | Hace el pedido. Exige el `confirm_token`, `buyer_confirmed: true` y el `buyer_code` que la tienda mandó por correo al comprador (o una clave con `orders:create`). |
| `order_status` | El estado de un pedido, con su número y su correo. Está **apagada de fábrica**. |

Son las mismas herramientas que usan el asistente de la tienda y la API: no hay un segundo juego que mantener. Un módulo añade herramientas con el filtro `assistant.tools`. El comerciante elige cuáles se ofrecen en «Ajustes» › «Todos los ajustes» › «MCP», pestaña de herramientas; por defecto salen todas menos `order_status`.

Una llamada se hace con `tools/call`:

```
curl -s -X POST "$TIENDA/mcp" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"search_products","arguments":{"query":"silla","limit":1}}}'
```

El resultado trae el texto de la respuesta en `content[0].text` (un JSON que hay que leer otra vez) y, si la herramienta lo ofrece, el mismo dato estructurado en `structuredContent`. **Un fallo de una herramienta no es un error de protocolo**: sale como un resultado normal con `"isError": true`:

```json
{"jsonrpc":"2.0","id":10,"result":{"content":[{"type":"text","text":"{\"error\":\"No encuentro ese producto\"}"}],"structuredContent":{"error":"No encuentro ese producto"},"isError":true}}
```

Desde la consola se puede hacer la misma llamada sin servidor, como lo haría un cliente:

```
php aifec mcp:call search_products '{"query":"silla","limit":1}' [id-de-sesión]
```

Imprime primero la sesión y después el resultado.

## Los recursos

Además de herramientas, el servidor ofrece recursos de solo lectura (`resources/list`, `resources/read`):

| Recurso | Qué es |
|---|---|
| `aifec://llms.txt` | Un resumen de la tienda para modelos de lenguaje: categorías, más vendidos, políticas y dónde están los feeds. |
| `aifec://store` | Datos de la tienda: nombre, contacto, horarios, devoluciones y formas de pago. |

## Permisos y límites

- **No hace falta clave** para leer ni para preparar un carrito.
- Una **clave de la API** (`Authorization: Bearer aifec_api_…`) sube el límite de uso. Solo valen las claves que empiezan por `aifec_api_`: una clave del ERP no entra por esta puerta.
- **Hacer un pedido** necesita que una persona haya visto el total y lo confirme. Al llamar a `checkout_review`, la tienda manda por correo al comprador un código de seis cifras con el total y se lo dice al asistente («he enviado un código de verificación; pídeselo y espera»). Si la tienda tiene encendido el envío por WhatsApp, el código llega también al teléfono del comprador. El asistente pasa ese código en `buyer_code`, con `buyer_confirmed: true` y el `confirm_token`. Con una clave que tenga el ámbito `orders:create` no hace falta el código. El ajuste «Pedidos hechos por agentes de IA» (API → Pedidos) permite dejarlo solo con clave o apagarlo. Nadie fija precios por esta vía y **la persona paga**: transferencia y contra reembolso dan instrucciones; el pago en línea da la dirección donde el cliente paga.
- Los **límites de uso** y los topes de pedido (importe máximo, pedidos por hora) son los de la API. El pedido por MCP además sigue el interruptor del asistente «Dejar que el asistente tome el pedido en el chat».
- Se enciende y se apaga en «Todos los ajustes» › «MCP» (`mcp.enabled`): apagado, todas sus direcciones responden 404.

## Si algo no sale

- **`404` en `/mcp`.** El MCP está apagado, o el módulo `mcp` lo está.
- **El cliente dice que no encuentra herramientas.** Mira la lista de herramientas ofrecidas en los ajustes: si las desmarcaste todas, no hay nada que listar.
- **El carrito sale vacío en la siguiente llamada.** El cliente no manda la cabecera `Mcp-Session-Id` que dio `initialize`: sin ella el servidor no sabe a qué carrito te refieres.
- **`checkout_place` falla.** Falta el `confirm_token`, `buyer_confirmed` o el `buyer_code` (o está mal escrito o caducado: vale 15 minutos); o el ajuste «Pedidos hechos por agentes de IA» (API → Pedidos) pide una clave con `orders:create`; o el importe pasa el máximo.

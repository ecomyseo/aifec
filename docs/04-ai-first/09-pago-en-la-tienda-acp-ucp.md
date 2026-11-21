# ACP y UCP con confirmación en la tienda

Activa UCP y ACP desde **Ajustes → UCP y agentes**. Los perfiles y las rutas habilitadas aparecen en `/llms.txt` y `/.well-known/aifec.json`. En AIFEC, el perfil de la raíz corresponde a la tienda de `https://aifec.gmartos.es` y utiliza su mismo catálogo, inventario y caja.

## UCP 2026-08-25

El [perfil UCP](https://aifec.gmartos.es/.well-known/ucp) anuncia REST en `https://aifec.gmartos.es/ucp/v1` y MCP en `https://aifec.gmartos.es/ucp/mcp`. REST usa `POST /catalog/search`, `POST /catalog/lookup` y `POST /catalog/product` bajo la URL base. MCP ofrece las mismas operaciones como `search_catalog`, `lookup_catalog` y `get_product`, además de las herramientas de carrito y checkout. Usa `POST /ucp/v1/carts` para guardar la selección y `POST /ucp/v1/checkout-sessions` con `cart_id` para convertirla en una sesión de compra.

```bash
curl -fsS https://aifec.gmartos.es/.well-known/ucp
curl -fsS https://aifec.gmartos.es/ucp/v1/catalog/search \
  -H 'Content-Type: application/json' -d '{"query":"silla"}'
curl -fsS https://aifec.gmartos.es/ucp/v1/catalog/search \
  -H 'Content-Type: application/json' -d '{"query":"silla sencilla para camping"}'
curl -fsS https://aifec.gmartos.es/ucp/v1/catalog/search \
  -H 'Content-Type: application/json' -d '{"query":"¿Tenéis alguna silla sencilla para camping?"}'
```

La búsqueda UCP conserva el uso solicitado. Si el catálogo tiene sillas de parapente pero carece de sillas de camping, la última consulta devuelve `products: []`.

## ACP 2026-04-17

El [perfil ACP](https://aifec.gmartos.es/.well-known/acp.json) anuncia el [catálogo JSONL](https://aifec.gmartos.es/acp/feed.jsonl) y `https://aifec.gmartos.es/checkout_sessions`. AIFEC conserva el [catálogo OpenAI](https://aifec.gmartos.es/feeds/openai.json). El enlace de checkout ACP lleva al comprador a la caja de AIFEC.

`is_eligible_checkout` y `enable_checkout` indican si la oferta admite el pago integrado en ChatGPT. En esta tienda de demostración, ambos campos se publican en `false`, incluso para las ofertas con existencias, porque la tienda no tiene habilitado Instant Checkout. Las ofertas disponibles pueden comprarse en la caja de AIFEC a través de `continue_url`. OpenAI ofrece el pago integrado a socios seleccionados en beta y recomienda usar la caja del comercio para el resto de integraciones. [Documentación de checkout de OpenAI](https://developers.openai.com/plugins/build/monetization)

Envía `Authorization: Bearer` con una clave de AIFEC que tenga el permiso `orders:create`. Envía también `API-Version: 2026-04-17` e `Idempotency-Key` en las peticiones que escriben.

Sustituye `$ITEM_ID` por el `item_id` de una oferta disponible en el catálogo JSONL.

```bash
curl -fsS https://aifec.gmartos.es/checkout_sessions \
  -H "Authorization: Bearer $AIFEC_API_KEY" -H 'API-Version: 2026-04-17' \
  -H 'Content-Type: application/json' -H "Idempotency-Key: $(uuidgen)" \
  -d "{\"line_items\":[{\"id\":\"$ITEM_ID\",\"quantity\":1}],\"currency\":\"eur\",\"capabilities\":{}}"
```

Si la sesión necesita datos de entrega, los identifica en sus mensajes. El enlace `continue_url` conserva el carrito para que el comprador confirme el pedido y pague con los medios activos de la tienda. Las herramientas preparan la compra y dejan los datos de tarjeta en la caja o en la pasarela.

    {"status":"requires_escalation","continue_url":"https://tienda.example/ucp/continue/…"}

Consulta GET /checkout_sessions/{id} en ACP o GET /ucp/v1/checkout-sessions/{id} en UCP con la misma clave. El protocolo muestra el pedido como completado después de que la tienda registra el pago.

Referencias de desarrolladores. [UCP Checkout](https://ucp.dev/specification/shopping/checkout/), [ACP](https://www.agenticcommerce.dev/) y [catálogo de OpenAI](https://developers.openai.com/commerce/specs/file-upload/products).

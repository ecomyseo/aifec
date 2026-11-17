# Simple Agent Checkout

**English** · [Español](simple-agent-checkout.es.md)

*Version 0.1 — November 2026. Implemented by [AIFEC](../README.md) and by the `ecom_aifec` module for PrestaShop.*

Simple Agent Checkout describes how an AI assistant can buy in an online store **for a person**, using only what every assistant already has: opening web addresses, or calling tools over MCP. It is deliberately small. It does not replace UCP (Google) or ACP (OpenAI); it covers the stores and the assistants those protocols do not reach yet.

## Principles

1. **The store is the only source of truth.** Prices, taxes, shipping, stock and totals always come from the store's answers. An assistant never calculates them.
2. **A person confirms.** An order is placed only after the person has seen the exact total and confirmed it — with a 6-digit code the store emails them, or because the store trusts the caller with an API key it issued.
3. **The person pays, on the store's page.** The assistant never sees or sends payment details.
4. **Every answer says what comes next.** Answers include the next addresses (or tools) ready to use, so an assistant never has to build or guess a URL.
5. **Plain HTTP first.** Everything works with GET requests and readable answers; MCP and the REST API are the same flows for clients that can do more.

## 1. Discovery

A store that follows this document publishes:

| What | Where |
|---|---|
| A guide for language models | `/llms.txt` (text/plain), linked from every page (`<link rel="alternate" type="text/plain">` and a visible footer link) |
| A text version of the catalogue | `/index.md`, and `.md` versions of category and product pages |
| The MCP server | `<link rel="mcp" href="…/mcp">` in every page |
| The MCP server card | `/.well-known/mcp/server-card.json` (also `/.well-known/mcp-server-card`, `/.well-known/mcp` and `/mcp/server-card`), in the format of the official MCP Registry |
| A catalogue of AI entry points | `/.well-known/ai-catalog.json` |
| The REST API description | `/openapi.json`, `/.well-known/openapi.json` and `/api/openapi.json` (OpenAPI 3.1) |

Each product page (HTML and `.md`) states, in plain words, the address an assistant opens to buy that product.

## 2. Buying with links (GET only)

For assistants that can only open addresses.

```
GET /buy-with-links                         how it works, as data
GET /buy-with-links/new/{product}           one unit of a product in a new cart
GET /buy-with-links/new/{product}/{variant} the same, for one option (size, colour…)
GET /buy-with-links/{step}/{cart}[/{a}[/{b}]]  every following step, with the cart in the path
```

The steps are `start`, `add`, `cart`, `customer`, `shipping-options`, `shipping`, `payment-options`, `payment`, `status`, `review`, `code` and `place`. Every answer is JSON (or the same JSON inside a minimal HTML page for readers that ask for HTML) with:

- `data` — what the store answers: lines, prices in cents and formatted, totals with and without shipping;
- `say` — a short text the assistant can pass on to the person as it is;
- `next` — the addresses of the next possible steps.

There are two good endings:

- **Hand-over (the penultimate step).** The answer includes an address that opens **this cart** at the store's checkout (`/carrito/retomar/{token}` in AIFEC). The assistant gives it to the person, who types their details and pays. No personal data passes through the assistant.
- **Order (the last step).** The assistant sends the person's details, chooses shipping and payment, and asks for the review. The store emails the buyer a 6-digit code with the total. When the person gives the code back, the assistant opens the `place` step with it and the store answers with the order number and where to pay.

## 3. MCP

`POST /mcp`, Streamable HTTP, protocol versions 2025-06-18, 2025-03-26 and 2024-11-05. No key is needed.

The tools mirror the steps: `search_products`, `get_product`, `list_categories`, `check_availability`, `shipping_quote`, `store_info`, `cart_view`, `cart_add`, `cart_update`, `checkout_link`, `checkout_status`, `checkout_set_customer`, `checkout_shipping_options`, `checkout_set_shipping`, `checkout_payment_options`, `checkout_set_payment`, `checkout_review` and `checkout_place`. Every argument is described, and tools carry annotations (`readOnlyHint`, `idempotentHint`…).

- **Sessions.** The cart belongs to the MCP session (`Mcp-Session-Id`). Clients that do not keep that header — ChatGPT's connector is one — get a `session_id` from the first tool that writes and can send it as an argument afterwards.
- **Hand-over.** Without a session header, `cart_add` and `cart_update` already return `checkout_url`, the address that opens that cart at the checkout. `checkout_link` returns the same address.
- **Order.** `checkout_review` returns the exact order, its total and a `confirm_token`, and the store emails the buyer a code. `checkout_place` needs `confirm_token`, `buyer_confirmed: true` and `buyer_code` (or an API key with the scope `orders:create`). Any change after the review invalidates the token.

## 4. REST API

The same flow as JSON over HTTP, described in OpenAPI: search and products (`/api/v1/search`, `/api/v1/products`…), a cart whose token travels in `X-Cart-Token`, and the checkout steps (`customer`, `shipping`, `payment`, `review`, `place`). Answers are `{"data": …}`, lists add `"meta"`, errors are `{"error": {"status", "message"}}`.

## 5. What the store guarantees

- The same price, stock and total in the web page, the `.md` page, the API, MCP and buying with links.
- No order without the person's confirmation (buyer code or a key the store issued), and limits per order amount and per hour.
- Payment only on the store's page, with the store's own payment providers.
- Nothing personal is needed to hand over a cart.

## 6. Relation to UCP and ACP

UCP and ACP let an AI platform pay inside its own interface, after the store is onboarded with that platform. Simple Agent Checkout needs no onboarding and works with any assistant today, at the cost of a final step on the store's page. A store can offer both: AIFEC also publishes a UCP profile at `/.well-known/ucp` when the merchant turns it on.

## Reference implementation

- AIFEC: https://github.com/ecomyseo/aifec — modules `api` (REST and buying with links), `mcp`, `semantic` (`llms.txt`, `.md` pages) and `agentic` (UCP).
- Live demo: https://aifec.gmartos.es/

Licensed under the [Apache License 2.0](../LICENSE.md).

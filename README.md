# AIFEC — AI-First E-Commerce

**English** · [Español](README.es.md)

[![License: AGPL v3](https://img.shields.io/badge/license-AGPL%20v3%20%2B%20terms-blue.svg)](LICENSE.md)
[![PHP 7.4–8.4](https://img.shields.io/badge/PHP-7.4%E2%80%938.4-777bb4.svg)](docs/08-operacion/01-requisitos.md)
[![MCP Registry](https://img.shields.io/badge/MCP%20Registry-es.gmartos.aifec%2Fshop-black.svg)](https://registry.modelcontextprotocol.io/v0.1/servers?search=es.gmartos.aifec)
[![Live demo](https://img.shields.io/badge/demo-aifec.dev-1c3a2f.svg)](https://aifec.dev/)

**An open, practical way for AI assistants to buy from independent online stores — today, with any LLM, and with a person always in control.**

AIFEC is a complete online store (catalogue, cart, checkout, orders, content) that installs on any PHP hosting, like WordPress. It is built so that an AI assistant can find a product, fill a cart and take the buyer to the checkout without guessing — while prices, stock and payment always stay with the store.

> **Status: beta.** The public demo runs the latest code. There are no production stores using it yet.

## Why

AI assistants already recommend products, but most of them cannot finish a purchase in an ordinary store. The big platform protocols (Google's UCP, OpenAI's ACP) need onboarding with each platform and today only work in a few countries. AIFEC takes a simpler path that works now:

- **Buying with links** — any assistant that can open a URL (plain GET) can buy, step by step. No plugin, connector or browser automation.
- **An MCP server** — for assistants that support connectors (Claude, ChatGPT and others), listed in the official MCP Registry.
- **Human in the loop** — the assistant prepares the cart; the person confirms with a code the store emails them and pays on the store's own page. The assistant never sees payment details.

It is meant to **complement** UCP and ACP, not to compete with them: AIFEC also ships a UCP profile (off by default) for when those platforms open to more stores.

## Live demo

The demo store has fictional products and brands, and only test payments.

| What | Address |
|---|---|
| Store | https://aifec.dev/ |
| Buying with links (start here as an assistant) | https://aifec.dev/buy-with-links |
| MCP server (Streamable HTTP, no key) | https://aifec.dev/mcp |
| MCP server card | https://aifec.dev/.well-known/mcp/server-card.json |
| Guide for language models | https://aifec.dev/llms.txt |
| REST API and OpenAPI | https://aifec.dev/api · https://aifec.dev/openapi.json |

Try it: ask your assistant *“Find me a tall, narrow bathroom cabinet under 50 € at aifec.dev and leave it ready to buy.”*

## How an assistant buys

1. **Find** — the store publishes `llms.txt`, an MCP server card, a product feed and plain HTML pages. Every product page includes the address an assistant opens to buy it.
2. **Fill the cart** — with links (`/buy-with-links/new/{product}`) or with MCP (`cart_add`). Every answer includes the next addresses or tools, so the assistant never has to invent a URL.
3. **Hand over or confirm** — the assistant either gives the person a link that opens the checkout with that cart, or prepares the order: the store emails the buyer a 6-digit code, and the order is placed only when the person gives that code back.
4. **Pay** — always on the store's page (card, PayPal, transfer…). Prices, taxes and totals always come from the store.

The full flow is described in **[Simple Agent Checkout](protocol/simple-agent-checkout.md)**.

### What works today

Measured in November 2026 with test customers on the demo store:

| Assistant | Result |
|---|---|
| Claude (web) | Opens the links, fills the cart and gives the checkout link or places the order with the buyer's code. |
| ChatGPT | With the AIFEC MCP connector: finds the product, fills the cart and gives the checkout link. Without it, it rarely opens a live store. |
| Grok | Followed the links to the checkout in our tests. |
| Gemini | Did not reach the checkout in our tests: it answers from Google's index instead of opening the store. |

## Quick start

Requirements: PHP 7.4 to 8.4 with the usual extensions (`pdo`, `json`, `mbstring`, `curl`, `openssl`, `dom`, `gd`), and SQLite or MySQL/MariaDB. Nothing else: no Composer, no Node, no extra services.

**On a hosting** (about 5 minutes):

1. Download the ZIP from the [releases](https://github.com/ecomyseo/aifec/releases) and upload the `aifec` folder.
2. Point the web root of your domain to `aifec/public`.
3. Open the domain in a browser and follow the three screens: language, database (SQLite needs no setup) and your store.

**On your computer** (one minute):

```
git clone https://github.com/ecomyseo/aifec.git && cd aifec
php aifec site:create main --url=http://127.0.0.1:8000 --admin-email=you@example.test --admin-name="Your name"
php -S 127.0.0.1:8000 -t public public/index.php
```

Then open http://127.0.0.1:8000 (store) and http://127.0.0.1:8000/admin (panel). With SQLite, `site:create` took one second on a laptop; the MCP server, `llms.txt`, the API and buying with links work from the first minute.

## How it is built

```
core/        the kernel: request, routes, data, views, admin panel, console
modules/     what ships by default (catalogue, orders, customers, shipping, taxes, content, api, mcp, agentic…)
plugins/     add-ons, under the AIFEC plugin contract
themes/      themes; all inherit from the base theme
public/      the only folder the web server sees
tests/       kernel tests (each module keeps its own)
docs/        documentation
aifec        the console:  php aifec help
```

Modules, plugins and themes are declared with small files (`module.json`, `hooks.php`, `routes.php`, `admin.php`, `settings.php`, `theme.json`); the admin panel is built from those declarations.

## Factories for themes and plugins

Two separate public repositories build and check themes and plugins for AIFEC, with their own gates (structure, contract, headers, no credentials or personal data, tests against a real AIFEC installation) and a skill for AI assistants:

- [aifec-fabrica-temas](https://github.com/ecomyseo/aifec-fabrica-temas): theme factory.
- [aifec-fabrica-plugins](https://github.com/ecomyseo/aifec-fabrica-plugins): plugin factory.

Both are licensed like AIFEC (GNU AGPL v3 with the origin terms) and are kept in step with this repository.

## Tests

```
php tests/run.php              all of them
php tests/run.php modules/mcp  only the ones under that path
```

They need no network and no database server: every test builds its own site in a temporary folder.

## Languages

The code and its source strings are in English, with a complete Spanish translation. The store, the panel and the assistant work in both. The documentation in [`docs/`](docs/) is in Spanish for now.

## License

Free software under the [GNU AGPL v3 with additional terms](LICENSE.md): you can use, study, change and share it, and you must keep the visible "Created with AIFEC" notice, the author notices and the generator marker (see the additional terms in `LICENSE.md` and the `NOTICE` file). A modified version offered over a network must publish its source. The names AIFEC and Ecom Experts are not licensed for modified versions. A commercial license without these terms is available from Ecom Experts. Releases up to 1.2.6 were distributed under Apache 2.0 ([LICENSES/Apache-2.0.txt](LICENSES/Apache-2.0.txt)). Copyright 2026 Ecom Experts.

## Contact

Ecom Experts — ecomyseo@gmail.com · https://aifec.dev/

If you work on agentic commerce, MCP or a store platform and want to talk about this, write to us: we are happy to give a 20-minute demo.

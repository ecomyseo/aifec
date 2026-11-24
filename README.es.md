# AIFEC — AI-First E-Commerce

[English](README.md) · **Español**

[![Licencia: AGPL v3](https://img.shields.io/badge/licencia-AGPL%20v3%20%2B%20t%C3%A9rminos-blue.svg)](LICENSE.md)
[![PHP 7.4–8.4](https://img.shields.io/badge/PHP-7.4%E2%80%938.4-777bb4.svg)](docs/08-operacion/01-requisitos.md)
[![Registro MCP](https://img.shields.io/badge/Registro%20MCP-es.gmartos.aifec%2Fshop-black.svg)](https://registry.modelcontextprotocol.io/v0.1/servers?search=es.gmartos.aifec)
[![Demo en directo](https://img.shields.io/badge/demo-aifec.gmartos.es-1c3a2f.svg)](https://aifec.gmartos.es/)

**Una forma abierta y práctica de que los asistentes de IA compren en tiendas independientes: hoy, con cualquier modelo, y siempre con una persona al mando.**

AIFEC es una tienda en línea completa (catálogo, carrito, caja, pedidos y contenidos) que se instala en cualquier alojamiento con PHP, como un WordPress. Está hecha para que un asistente de IA encuentre un producto, llene el carrito y lleve al comprador a la caja sin adivinar nada, mientras el precio, las existencias y el pago siguen siendo de la tienda.

> **Estado: beta.** La demo pública lleva el código más reciente. Todavía no hay tiendas en producción que lo usen.

## Por qué

Los asistentes de IA ya recomiendan productos, pero casi ninguno puede terminar una compra en una tienda corriente. Los protocolos de las grandes plataformas (UCP de Google, ACP de OpenAI) exigen darse de alta en cada una y hoy solo funcionan en pocos países. AIFEC va por un camino más sencillo que funciona ya:

- **Compra con enlaces**: cualquier asistente que sepa abrir una dirección (una petición GET) puede comprar, paso a paso. Sin complementos, conectores ni automatizar un navegador.
- **Un servidor MCP**: para los asistentes que admiten conectores (Claude, ChatGPT y otros), dado de alta en el registro oficial de MCP.
- **Una persona al mando**: el asistente prepara el carrito; la persona lo confirma con un código que la tienda le manda por correo y paga en la página de la tienda. El asistente nunca ve los datos de pago.

Quiere **sumar** a UCP y ACP, no competir con ellos: AIFEC trae también un perfil UCP (apagado de serie) para cuando esas plataformas se abran a más tiendas.

## Demo en directo

La tienda de demostración tiene productos y marcas ficticios, y solo pagos de prueba.

| Qué | Dirección |
|---|---|
| Tienda | https://aifec.gmartos.es/ |
| Compra con enlaces (por aquí empieza un asistente) | https://aifec.gmartos.es/buy-with-links |
| Servidor MCP (HTTP en streaming, sin clave) | https://aifec.gmartos.es/mcp |
| Ficha del servidor MCP | https://aifec.gmartos.es/.well-known/mcp/server-card.json |
| Guía para modelos de lenguaje | https://aifec.gmartos.es/llms.txt |
| API REST y OpenAPI | https://aifec.gmartos.es/api · https://aifec.gmartos.es/openapi.json |

Pruébalo: pídele a tu asistente *«Búscame en aifec.gmartos.es un mueble de baño estrecho y alto de menos de 50 € y déjamelo listo para comprar»*.

## Cómo compra un asistente

1. **Encuentra**: la tienda publica `llms.txt`, la ficha de su servidor MCP, un catálogo para máquinas y páginas HTML sencillas. Cada ficha de producto lleva la dirección que un asistente abre para comprarlo.
2. **Llena el carrito**: con enlaces (`/buy-with-links/new/{producto}`) o con MCP (`cart_add`). Cada respuesta trae las siguientes direcciones o herramientas, así que el asistente nunca tiene que inventarse una dirección.
3. **Entrega o confirma**: o le da a la persona un enlace que abre la caja con ese carrito, o prepara el pedido: la tienda manda al comprador un código de 6 cifras por correo, y el pedido solo se hace cuando la persona devuelve ese código.
4. **Paga**: siempre en la página de la tienda (tarjeta, PayPal, transferencia…). Los precios, los impuestos y los totales salen siempre de la tienda.

El recorrido completo está en **[Compra sencilla para agentes](protocol/simple-agent-checkout.es.md)**.

### Qué funciona hoy

Medido en noviembre de 2026 con clientes de prueba en la tienda de demostración:

| Asistente | Resultado |
|---|---|
| Claude (web) | Abre los enlaces, llena el carrito y da el enlace de la caja, o hace el pedido con el código del comprador. |
| ChatGPT | Con el conector MCP de AIFEC: encuentra el producto, llena el carrito y da el enlace de la caja. Sin él, casi nunca abre una tienda en directo. |
| Grok | Siguió los enlaces hasta la caja en nuestras pruebas. |
| Gemini | No llegó a la caja en nuestras pruebas: contesta con lo que tiene Google indexado en vez de abrir la tienda. |

## Empezar

Requisitos: PHP 7.4 a 8.4 con las extensiones corrientes (`pdo`, `json`, `mbstring`, `curl`, `openssl`, `dom`, `gd`), y SQLite o MySQL/MariaDB. Nada más: ni Composer, ni Node, ni servicios aparte.

**En un alojamiento** (unos 5 minutos):

1. Descarga el ZIP de las [versiones publicadas](https://github.com/ecomyseo/aifec/releases) y sube la carpeta `aifec`.
2. Apunta la raíz web del dominio a `aifec/public`.
3. Abre el dominio en el navegador y sigue las tres pantallas: idioma, base de datos (SQLite no necesita nada) y tu tienda.

**En tu ordenador** (un minuto):

```
git clone https://github.com/ecomyseo/aifec.git && cd aifec
php aifec site:create main --url=http://127.0.0.1:8000 --admin-email=tu@ejemplo.test --admin-name="Tu nombre"
php -S 127.0.0.1:8000 -t public public/index.php
```

Después abre http://127.0.0.1:8000 (la tienda) y http://127.0.0.1:8000/admin (el panel). Con SQLite, `site:create` tardó un segundo en un portátil; el servidor MCP, `llms.txt`, la API y la compra con enlaces funcionan desde el primer minuto.

La guía completa de instalación está en [docs/08-operacion/02-instalar.md](docs/08-operacion/02-instalar.md).

## Cómo está hecho

```
core/        el núcleo: petición, rutas, datos, vistas, panel, consola
modules/     lo que trae de serie (catálogo, pedidos, clientes, envíos, impuestos, contenidos, api, mcp, agentic…)
plugins/     lo que se añade, bajo el Contrato AIFEC
themes/      los temas; todos heredan del tema base
public/      lo único que ve el servidor web
tests/       las pruebas del núcleo (las de cada módulo, en su carpeta)
docs/        la documentación
aifec        la consola:  php aifec help
```

Un módulo, un plugin y un tema se declaran con ficheros pequeños (`module.json`, `hooks.php`, `routes.php`, `admin.php`, `settings.php`, `theme.json`); el panel se construye a partir de esas declaraciones.

## Pruebas

```
php tests/run.php              todas
php tests/run.php modules/mcp  solo lo que contenga esa ruta
```

No necesitan red ni base de datos aparte: cada prueba monta su sitio en una carpeta temporal.

## Idiomas

El código y sus textos fuente están en inglés, con la traducción castellana completa. La tienda, el panel y el asistente funcionan en los dos. La documentación de [`docs/`](docs/) está en castellano.

## Licencia

Software libre bajo la [GNU AGPL v3 con términos adicionales](LICENSE.md): puedes usarlo, estudiarlo, cambiarlo y compartirlo, y debes conservar el aviso visible «Creado con AIFEC», los avisos de autoría y la marca del generador (términos adicionales de `LICENSE.md` y fichero `NOTICE`). Una versión modificada que se ofrezca por red debe publicar su código. Los nombres AIFEC y Ecom Experts no se licencian para versiones modificadas. Hay una licencia comercial sin estos términos disponible en Ecom Experts. Las versiones hasta la 1.2.6 se distribuyeron bajo Apache 2.0 ([LICENSES/Apache-2.0.txt](LICENSES/Apache-2.0.txt)). Copyright 2026 Ecom Experts.

## Contacto

Ecom Experts — ecomyseo@gmail.com · https://aifec.gmartos.es/

Si trabajas en comercio con agentes, en MCP o en una plataforma de tiendas y quieres hablar de esto, escríbenos: te enseñamos una demo de 20 minutos.

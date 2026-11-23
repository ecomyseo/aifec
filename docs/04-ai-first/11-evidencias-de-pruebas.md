# Evidencias de pruebas del comercio con agentes (23 de noviembre de 2026)

Este documento recoge lo que el equipo ejecutó con peticiones POST, claves de prueba y navegador, que un auditor que solo lee páginas públicas no puede repetir. Está publicado para que cualquiera lo consulte. Todo lo que se dice aquí se puede contrastar con lo que **sí** es público por GET (perfiles, feeds y server cards); los POST y los flujos de carrito se anotan como ejecutados por el equipo.

Ningún pedido real se ha confirmado en producción. Las pruebas con clave se hicieron con una clave de pruebas creada y borrada el mismo día. Los datos de comprador de las pruebas son del propio equipo.

## 1. Qué es público y se comprueba con GET

| Sitio | UCP | ACP | Feed ACP | Feed Google Merchant | MCP |
|---|---|---|---|---|---|
| https://aifec.gmartos.es (plataforma) | `/.well-known/ucp` | `/.well-known/acp.json` | `/acp/feed.jsonl` | `/acp/feed-google.tsv` | `/.well-known/mcp/server-card.json` |
| https://detodounpoco.gmartos.es (plataforma) | igual | igual | igual | igual | igual |
| https://themes.gmartos.es/deporte (módulo PrestaShop, demo) | igual | igual | igual | igual | igual |
| https://www.drogueriaelbarco.com (módulo PrestaShop, producción) | igual | igual | igual | igual | igual |

La demo de PrestaShop tenía solo MCP y se actualizó el 23/11/2026 a la misma versión que producción: hoy publica UCP, ACP y los dos feeds. Si una auditoría la vio con un 404, es una copia anterior: vuelve a pedirla.

`payment_handlers` va vacío y `is_eligible_checkout` en `false` **por política**: el comprador termina en la caja de la tienda (`continue_url`), no dentro del asistente.

## 2. Plataforma AIFEC (aifec.gmartos.es), con clave de pruebas (permiso `orders:create`)

**ACP** (cabeceras `API-Version: 2026-04-17` e `Idempotency-Key`):
- Crear sesión → 201, `requires_escalation`, `continue_url` a la caja de la tienda, total 794,90 € (790 € más 4,90 € de envío).
- Consultar → 200. Cambiar la cantidad → 200.
- Completar con un token de pago ficticio → 200 y sigue en `requires_escalation`; no se crea pedido ni cargo.
- Cancelar → 200, estado `canceled`; repetir la misma cancelación devuelve la respuesta original.
- Sin clave y sin versión: 400 `unsupported_version`. Con versión y sin clave: 401.

**UCP**:
- Crear sesión → 201 con `continue_url`; consultar → 200; actualizar a 2 unidades (PUT) → 200, total 1.584,90 €.
- Completar con una credencial ficticia → 200, mensaje «Esta tienda cobra en su propia caja». Sin pedido ni cargo.
- Cancelar → 200, `canceled`.
- Sin clave: 401 con `continue_url` al enlace de compra. Con una clave inventada: 401 (no degrada a anónimo).

## 3. Módulo PrestaShop (demo de Deporte), con clave de pruebas

- ACP y UCP: crear → 201 con `continue_url`, consultar → 200, cambiar, completar con credencial ficticia (escalada a la caja) y cancelar → 200. Como la tienda exige el DNI y los protocolos no tienen campo para él, la respuesta lo dice con claridad («esta dirección necesita un número de identificación fiscal; termina la dirección en la tienda») en vez de fallar.
- Un perfil de agente inalcanzable da 424 `profile_unreachable`; con un perfil alcanzable funciona.

## 4. MCP

- `initialize` y `tools/list` → 200 con 18 herramientas en la plataforma, en la demo y en Barco.
- Flujo en la demo hasta el último paso previo a confirmar: buscar, carrito (un producto con variantes pide `variant_id`: 422 claro), comprador, envío, pago, `checkout_status` → `ready: true` y `checkout_review` → código de 6 dígitos enviado por correo (el log del servidor de correo lo confirma).
- `checkout_review` con `email_confirmed: false` → 409 y no envía nada.
- Con una clave de pedidos, `checkout_place` confirma sin el código: pedido de prueba en la demo con pago por cheque (sin cobro).
- En la plataforma: flujo anónimo completo hasta `ready: true` (pago contra reembolso) y enlace de compra.
- En Barco: un producto, una unidad, hasta `ready: true` y el código enviado; sin confirmar.

## 5. Enlaces de compra (verificados con Chromium sin ventana, sin cambiar su User-Agent)

- Plataforma: el enlace `/carrito/retomar/<token>` abre una pantalla de confirmación y «Continuar a la caja» lleva a `/finalizar-compra` con el producto y el total (794,90 €); sin errores de JavaScript ni respuestas 4xx/5xx.
- Demo y Barco: el enlace abre la revisión con producto, envío y total; «Seguir a la caja» lleva a la caja con el carrito cargado. En Barco: 21,22 € (16,02 € más 5,20 € de envío).
- Un navegador sin ventana ya no recibe un 403 en el enlace de compra del módulo.

## 6. Búsqueda (20 consultas reales con productos químicos en Barco)

- Primer resultado correcto en 17 de 20. Las tres restantes son productos que la tienda no vende (lejía, sal fina, vinagre); `vinagre` devuelve 0.
- Consultas sin sentido (`zzzqqq`) → 0 resultados en la plataforma, en la demo y en Barco.

## 7. Feeds y rendimiento

- Feed ACP de Barco: 1.552 líneas JSON válidas; 12,5 s la primera vez y 0,59 s con caché.
- Feed Google Merchant: Barco 1.553 líneas, la plataforma 227 y la demo con todas las líneas en 16 columnas; la primera lectura de Barco tarda 14,4 s y las siguientes 0,5 s.
- Carga moderada (30–75 peticiones por segundo): mediana de 0,13 a 0,23 s. Después de la primera ráfaga, la IP del probador recibe 429 en todos los endpoints: el límite de peticiones funciona.

## 8. Lo que no se ha demostrado

- Pago real dentro del agente: depende de un proveedor de pago compatible (Google Pay con Redsys está programado y apagado) y de que la plataforma lo habilite.
- Distribución: depende de que Google, OpenAI, Microsoft y Perplexity acepten el feed y el perfil. La guía [«Pasos fuera de la tienda»](10-pasos-fuera-de-la-tienda.md) explica cómo pedirlo.

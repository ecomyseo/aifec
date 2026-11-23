# Contenedor de Google Tag Manager para AIFEC (GA4 y comercio electrónico)

`aifec-gtm-ga4.json` es un contenedor listo para importar en Google Tag Manager. Recoge los eventos que ya empuja la capa de datos de AIFEC (módulo «Medición») y los manda a Google Analytics 4.

## Qué lleva

| Pieza | Detalle |
|---|---|
| Variable | «AIFEC - ID de medición de GA4» (constante): pon tu `G-XXXXXXXXXX`. |
| Etiqueta Google | «Google tag - GA4 (AIFEC)»: se lanza al inicializar en todas las páginas (`page_view` incluido). |
| Eventos de comercio | `view_item_list`, `select_item`, `view_item`, `add_to_cart`, `remove_from_cart`, `view_cart`, `begin_checkout`, `add_shipping_info`, `add_payment_info`, `purchase`. Leen `ecommerce` de la capa de datos tal cual (artículos, valor, moneda, número de pedido). |
| Otros eventos | `search` (`search_term`), `login` y `sign_up` (`method`), `assistant_open` y `assistant_order` (`transaction_id`, `value`, `currency`). |
| Consentimiento | Todas las etiquetas exigen `analytics_storage`. La tienda ya manda el Consent Mode v2 (`default` y `update`) con su aviso de cookies, y el propio Tag Manager solo se carga con el consentimiento de su categoría. |

Los reembolsos (`refund`) los manda el servidor de AIFEC por el protocolo de medición; no van en el navegador.

## Cómo usarlo

1. En Tag Manager, crea un contenedor nuevo de tipo **Web**.
2. **Administrador → Importar contenedor** → elige `aifec-gtm-ga4.json` → espacio de trabajo existente (o nuevo) → **Combinar → Sobrescribir las etiquetas, activadores y variables en conflicto** → Confirmar.
3. Abre la variable **AIFEC - ID de medición de GA4** y cambia `G-XXXXXXXXXX` por tu ID de medición de GA4 (Analytics → Administrador → Flujos de datos).
4. En AIFEC: **Medición → Tag Manager** → ID del contenedor (`GTM-XXXXXXX`) y la categoría de consentimiento (estadísticas). **Deja vacío el ID de medición de la pestaña «Google Analytics»**: Tag Manager o las etiquetas directas, no los dos, o cada visita se cuenta dos veces.
5. En Tag Manager, **Vista previa** con la dirección de la tienda: acepta las cookies de estadísticas y comprueba que salen `page_view` y, al navegar, `view_item_list`, `view_item`, `add_to_cart`… En Analytics, **Informes → Tiempo real**.
6. **Enviar → Publicar**.

Para volver a generarlo con tu ID ya puesto: `python generar_gtm.py G-XXXXXXXXXX` (en `modulos IA\utiles\aifec`).

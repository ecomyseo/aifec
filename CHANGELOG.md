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

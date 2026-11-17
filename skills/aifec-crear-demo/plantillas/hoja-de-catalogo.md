# Hoja de catálogo (se rellena en la fase 1 y manda sobre la 2)

Se rellena en tus notas, **fuera** del paquete. Cuando esté llena, el catálogo se escribe sin pensar: solo se pasa a JSON.

## 1. Cabecera del sector

| | |
|---|---|
| Sector y tienda ficticia | Papelería · «Cuaderno y Tinta» |
| Qué vende / a quién | Escritura, cuadernos, archivo y escritorio, para estudiantes y oficinas pequeñas |
| Rango de precios (céntimos, IVA dentro) | de 120 a 3490 |
| Atributos del sector | material (select), formato (select), gramaje (number, g/m²), uso (multiselect), reciclado (bool) |
| Atributos de variante | color, rayado (`"variant": true`) |
| Tema | `base` |

## 2. Categorías y reparto (24 a 60 productos)

| Categoría (código) | Hijas | Productos | Con variantes | Ofertas |
|---|---|---|---|---|
| `escritura` | `boligrafos`, `lapices-y-rotuladores`, `tintas` | 10 | 2 | 2 |
| `cuadernos-y-papel` | `libretas`, `blocs-y-notas`, `sobres` | 8 | 2 | 2 |
| `archivo-y-organizacion` | `carpetas`, `archivadores` | 4 | 1 | 0 |
| `escritorio` | — | 4 | 0 | 1 |

Regla: ninguna categoría con menos de 2 productos, ninguna con más de la mitad del catálogo.

## 3. Hoja de productos (una fila por producto, antes de escribir el JSON)

| SKU | Nombre (único) | Categorías | Marca | Precio | Oferta | Stock | Variantes | Forma / color del dibujo |
|---|---|---|---|---|---|---|---|---|
| DEMO-PAP-001 | Bolígrafo de gel Brisa | boligrafos | trazo-norte | 190 | — | variantes | color ×3 | boligrafo / azul |
| DEMO-PAP-006 | Portaminas Hilo de 0,5 mm | lapices-y-rotuladores | trazo-norte | 650 | — | 0 (agotado) | — | lapiz / gris |
| DEMO-PAP-010 | Tinta negra de dibujo (30 ml) | tintas | papel-del-valle | 650 | — | 3 (pocas) | — | frasco / negro |

## 4. Un producto completo (formato que pasa `demo:check`)

```json
{
    "sku": "DEMO-PAP-011",
    "name": "Libreta cosida Alba A5",
    "summary": "<p>Libreta A5 cosida con tapa blanda de cartulina y papel reciclado de 90 g.</p>",
    "description": "<p>Alba es una libreta cosida de tapa blanda con 96 hojas de papel reciclado de 90 g/m², un gramaje que soporta tinta de pluma y rotulador fino sin traspasar. El lomo cosido a hilo permite abrirla del todo sobre la mesa y la cartulina de la tapa tiene un tacto agradable que no se estropea en el bolso. Se vende lisa, rayada o cuadriculada. Producto de ejemplo.</p>",
    "price": 790,
    "manage_stock": true,
    "mpn": "CT-011",
    "weight": 220,
    "delivery_min_days": 1,
    "delivery_max_days": 3,
    "featured": true,
    "categories": ["libretas", "cuadernos-y-papel"],
    "brand": "papel-del-valle",
    "attributes": {"material": "papel", "formato": "a5", "gramaje": 90, "reciclado": true, "uso": ["estudio", "escritura-diaria"]},
    "facts": [
        {"label": "Hojas", "value": "96"},
        {"label": "Papel", "value": "Reciclado, 90 g/m²"},
        {"label": "Formato", "value": "A5 (14,8 × 21 cm)"}
    ],
    "faqs": [
        {"question": "¿La tinta traspasa?", "answer": "Con plumas finas y bolígrafos no; con rotuladores muy húmedos puede asomar un poco."},
        {"question": "¿Tiene margen?", "answer": "La versión rayada lleva margen; la lisa y la cuadriculada, no."}
    ],
    "tags": ["libretas"],
    "meta_title": "Libreta cosida Alba A5 | Cuaderno y Tinta",
    "meta_description": "Libreta A5 cosida con tapa blanda y papel reciclado de 90 g.",
    "images": [
        {"file": "libreta-cosida-alba-a5-principal.svg", "alt": "Libreta cosida Alba A5, vista principal"},
        {"file": "libreta-cosida-alba-a5-detalle.svg", "alt": "Libreta cosida Alba A5, detalle"}
    ],
    "variants": [
        {"sku": "DEMO-PAP-011-LIS", "gtin": "2990000001016", "stock": 70, "values": {"rayado": "liso"}},
        {"sku": "DEMO-PAP-011-RAY", "gtin": "2990000001023", "stock": 70, "values": {"rayado": "rayas"}}
    ]
}
```

Lo que NO lleva este producto, a propósito: `stock` ni `gtin` a nivel de producto (van en las variantes). Un producto sin
variantes lleva `"stock": 12` y `"gtin": "299…"`. Una oferta es `"sale_price": 990` (menor que `price`); no hace falta más.
El campo `related` que aparece en `demos/moda` **no se carga** (el cargador lo ignora): no cuentes con él.

## 5. Código de barras inventado con cifra de control correcta (Python)

```python
def gtin(n):
    """EAN-13 de demostración: prefijo 299 + número de orden de 9 cifras + cifra de control."""
    base = "299" + "%09d" % n
    suma = sum(int(c) * (1 if i % 2 == 0 else 3) for i, c in enumerate(base))
    return base + str((10 - suma % 10) % 10)
```

Cada llamada con un `n` distinto da un código distinto; reserva un contador para todo el paquete (variantes incluidas).

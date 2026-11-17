# GM licences (`gm-licencias`)

Plugin del sistema AIFEC que lleva a la tienda lo que hacía `ecom_licencias_wc` en WordPress, y lo amplía: licencias de los módulos que se venden. Viene **desactivado**.

## Qué hace

- **Al pagarse un pedido** con productos marcados como «con licencia» se genera **una licencia por unidad** (clave aleatoria de 25 letras y cifras sin las que se confunden, guardada tal cual para que el cliente la vea). Emitir dos veces el mismo pedido no duplica nada. Devolver el pedido revoca sus licencias (todas, o las unidades devueltas si es parcial).
- **Dos tipos de licencia** por producto, en la pestaña «Licences» de su ficha: *clave con dominios* (dominios permitidos y meses de actualizaciones) y *clave firmada* para un NIF, que se comprueba sin conexión y que es **el mismo formato `ECTPV-…` que ya leen los programas desplegados** (la compatibilidad con lo que hacía el plugin de WordPress: una clave por línea con las unidades dentro, NIF del campo «NIF» de la facturación del pedido, validado). Sin NIF válido o sin clave de firma queda **pendiente** y se reemite desde el panel.
- **API para los módulos instalados**: `POST /api/gm/licencia/verificar` (`key` + `domain`) activa el dominio si queda sitio, y `POST /api/gm/licencia/desactivar` lo libera. Respuesta siempre con las mismas claves (`valid`, `reason`, `updates_until`, `updates_active`, `domains_used`, `domains_max`, `activated`); una clave inventada, una revocada y una pendiente dan la misma respuesta. Límite de uso por dirección y por clave (429). El registro de comprobaciones no guarda dirección, dominio ni correo y una tarea diaria borra lo de más de 90 días.
- **El cliente** las ve en «Mis licencias» (`/mi-cuenta/licencias`), en el resumen de su cuenta, en la página del pedido y **dentro del correo de confirmación del pedido** (bloque del filtro `order.mail.blocks`, con las licencias emitidas al componerlo). El correo propio (`license_issued`) ya no sale aparte: queda de recambio para cuando las claves no caben en el bloque (más de once licencias).
- **Panel**: Ventas → Licencias: listado con búsqueda por clave, correo y producto; ficha con dominios activados (quitar uno), revocar, reactivar, ampliar actualizaciones y reemitir la clave firmada; alta manual.

## Cómo se configura

1. Ajustes → Licencias: los límites de la API y, solo para las claves firmadas, la clave privada de firma (PEM, curva `prime256v1`; se guarda cifrada y no vuelve a enseñarse).
2. En cada producto, pestaña «Licences»: tipo, dominios y meses.

Los ajustes son **por sitio**. Para que las claves firmadas salgan, el pedido necesita el NIF en el campo «NIF» de la facturación (ajuste `orders.require_vat_id` en Pedidos).

## Contrato AIFEC

Sin servicios externos. Datos personales declarados: correo, nombre y NIF. Cuatro tablas propias (`gm_license_products`, `gm_licenses`, `gm_license_domains`, `gm_license_checks`) que se borran al desinstalar sin conservar datos. Ninguna capacidad. `php aifec contract:check gm-licencias`.

Pruebas: `php tests/run.php plugins/gm-licencias`.

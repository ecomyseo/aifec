# Glosario

Los términos que aparecen en la guía del desarrollador, por orden alfabético. Cada uno dice qué es y dónde se explica.

**Acción.** Una clase de gancho: «ha pasado esto». El oyente no devuelve nada (`order.paid`). Se escucha con `on()`. Ver [Ganchos](../06-plugins/03-ganchos.md).

**Ajuste.** Un valor por sitio que se cambia sin tocar código, con su clave en minúsculas y puntos (`store.name`). Lo declara el `settings.php` de cada módulo o plugin. Ver [Ajustes](04-ajustes.md).

**API pública.** Las rutas `/api/v1`: leer la tienda, preparar un carrito y, con una clave y confirmación humana, hacer un pedido. Ver [API](07-api.md).

**Bloque de precio.** El array que describe un precio para enseñarlo: `amount`, `compare`, `discount_percent`, `with_tax`… Los importes son céntimos. Ver [Huecos y datos](../05-temas/05-huecos-y-datos.md).

**Caché de página.** La copia de una página completa que se sirve sin arrancar la aplicación. La activa el controlador con `cacheable($segundos, $etiquetas)` y se renueva con `invalidate()`. Ver [La tienda](../06-plugins/06-tienda.md).

**Capacidad.** Un permiso especial que un plugin declara y el administrador concede. Hoy hay una, `pricing`, para tocar precios, impuestos o plazos. Ver [Ganchos](../06-plugins/03-ganchos.md).

**Céntimo.** La unidad de todo importe: un entero (`4999` es 49,99 €). Nunca un decimal.

**Contrato AIFEC.** Las reglas que un plugin cumple para activarse (M1, P1 y C1 a C10), que comprueba un validador. Ver [El Contrato AIFEC](../06-plugins/08-el-contrato-aifec.md).

**Cron.** La orden `php aifec cron`, que el servidor lanza cada minuto: ejecuta las tareas programadas y después la cola de trabajos.

**Entidad.** Algo con dirección amigable propia: un producto, una categoría, una página. Se registra con `$app->entity()` y `$app->slugs()->set()`.

**Esencial.** Un módulo que no se puede apagar (`"essential": true`). Un plugin no lo es.

**Filtro.** Una clase de gancho: recibe un valor, lo cambia y lo devuelve (`product.extra`). Se escucha con `filter()`.

**Gancho.** Un punto del sistema donde otro código puede engancharse. Hay tres clases: acción, filtro y hueco. Ver [Referencia de ganchos](03-ganchos.md).

**Gancho sellado.** Un filtro que protege el dinero (`product.price`, `tax.rate`, `cart.adjustments`…): solo entra un plugin con la capacidad `pricing` concedida.

**Hueco.** Un gancho de plantilla: un punto de una página donde los módulos y plugins ponen su HTML (`product.summary.after`). Un tema debe conservar los que trae cada plantilla. Ver [Huecos y datos](../05-temas/05-huecos-y-datos.md).

**Intermediario.** Algo que se comprueba antes del controlador de una ruta: `admin`, `customer`, `csrf`, `api`. Ver [Rutas](05-rutas.md).

**Línea de ajuste.** Una línea con nombre, origen e importe en céntimos que un plugin con `pricing` añade al desglose del carrito. Es la única forma de tocar un precio. Ver [Ganchos](../06-plugins/03-ganchos.md).

**MCP.** Model Context Protocol: el servidor que deja a un asistente de IA usar la tienda. Ver [MCP](08-mcp.md).

**Migración.** Un fichero de `migrations/` que crea o cambia una tabla. Se aplica una sola vez y no se edita después. Ver [Datos y migraciones](../06-plugins/05-datos-y-migraciones.md).

**Módulo.** Una parte del sistema incluida en `modules/`: catálogo, pedidos, correo, SEO… Tiene la misma forma que un plugin.

**Oyente.** La función que se engancha a un gancho.

**Panel.** La administración, bajo `/admin`. Los módulos le aportan pantallas con `admin.php`. Ver [El panel](../06-plugins/04-panel.md).

**Papel.** Una lista de permisos que se asigna a una persona del equipo: `admin`, `manager`, `editor`, `support`, `demo`. Ver [Permisos](06-permisos.md).

**Personalizador.** La pantalla donde el comerciante cambia las opciones de un tema («Diseño» › «Temas» › «Personalizar»). Ver [Opciones y personalizador](../05-temas/04-opciones-y-personalizador.md).

**Pieza.** Una plantilla pequeña de un tema que se reutiliza (`partials/header`, `partials/price`). Ver [Plantillas y piezas](../05-temas/03-plantillas-y-piezas.md).

**Plugin.** Una ampliación de terceros en `plugins/<id>/`, con su `plugin.json`, que pasa el contrato antes de activarse.

**ProductCard, ProductData.** Los arrays que describen un producto para una tarjeta de listado y para su ficha. Ver [Huecos y datos](../05-temas/05-huecos-y-datos.md).

**Recurso.** Un listado y un formulario del panel declarados con un array en `admin.php`, sin controlador. Ver [El panel](../06-plugins/04-panel.md).

**Sitio.** Una tienda dentro de una instalación, con su carpeta de datos, su base de datos y sus ajustes. Una instalación puede tener varios. Ver [Arquitectura](01-arquitectura.md).

**Slot.** Otro nombre de «hueco», como se llama en el código (`slot()`, `render()`).

**Slug.** La parte de una dirección que viene del título (`como-cuidar-tu-silla`). Se saca con `Aifec\Util\Str::slug()`.

**Tarea programada.** Una función que el cron ejecuta a una hora o con una frecuencia: `daily@07:30`, `hourly` o cada N segundos. Se declara en `jobs.php`.

**Tema.** El aspecto de la tienda: envoltorio, piezas, hoja y guion. Un tema hijo hereda de su padre. Ver [Cómo funciona un tema](../05-temas/01-como-funciona-un-tema.md).

**Trabajo.** Una unidad de trabajo en la cola, que se ejecuta fuera de la petición (`push()`). Si falla, se reintenta. Ver [Tareas, correo e IA](../06-plugins/07-tareas-correo-e-ia.md).

**Validador.** La herramienta que comprueba un tema (`theme:check`) o un plugin (`contract:check`) y saca un informe con nota.

**Versión en texto.** La versión en Markdown de cada página pública (`<dirección>.md`), pensada para asistentes de IA, que sale de los mismos datos que la página.

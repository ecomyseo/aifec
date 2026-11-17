# Probar y aprender en el buscador

En **Marketing → Buscador → Probar y aprender**, el superadministrador puede autorizar una ronda interna para detectar problemas y proponer mejoras del buscador. Se utiliza la IA que ya está configurada en esa tienda. No necesitas copiar claves ni configurar otra cuenta de proveedor.

Esta función conserva conocimiento comprobado en AIFEC: interpretaciones de frases residuales y reglas que ordenan productos ya encontrados. No entrena los pesos del modelo, no inventa productos y no cambia los filtros de precio, dimensiones, exclusiones, existencias o visibilidad. Una puntuación de IA no da por aprobada la tienda.

## Quién puede usarla

El módulo **Aprendizaje del buscador** es opcional. Tras activarlo, la nueva pestaña aparece dentro del Buscador existente. Los perfiles con permiso de lectura pueden ver la explicación, el historial y los informes de su tienda.

Solo una cuenta activa con papel **admin** y permiso de gestión del aprendizaje puede iniciar, reanudar, cancelar, validar, aplicar o revertir una ronda. Tener un permiso comodín en otro papel no convierte esa cuenta en superadministrador. La cuenta pública de administración de una demo puede leer la explicación, pero nunca iniciar aprendizaje de pago; las tiendas de demostración no pueden autorizar estas ejecuciones.

Abrir la pantalla, instalar el módulo o hacer una petición GET no crea una ronda ni hace llamadas de IA. El cron solo continúa trabajos previamente autorizados por un superadministrador y comprueba de nuevo sus permisos en cada tanda.

## Preparar una ronda

1. Comprueba el nombre y la URL de la **tienda actual** que muestra la pantalla. Para usar otra tienda, cambia de sitio en Sitios y abre su propio panel. La función no permite seleccionar arbitrariamente un inquilino ajeno.
2. Selecciona un idioma configurado en esa tienda. La memoria y las evidencias se conservan por tienda, URL normalizada e idioma; no se mezclan entre clientes.
3. Elige el origen: **perfiles ficticios con el catálogo real**, o **consultas registradas evaluadas como casos simulados**. Puedes pedir de 1 a 20 perfiles o casos. Para el histórico, indica un periodo de hasta 365 días que no termine en el futuro; se leen como máximo 100 filas para seleccionar los casos seguros.
4. Elige el nivel de IA: económico, estándar o avanzado. Cada nivel utiliza el proveedor, modelo y clave cifrada ya configurados en IA → Ajustes. Las consultas con datos personales, teléfonos, correos, direcciones o secretos se excluyen u ocultan antes de enviarse al modelo.
5. Escribe un presupuesto positivo explícito, en la moneda visible de la tienda, y marca la autorización de esa única ronda. El límite máximo por ronda es 100.000 céntimos; puedes elegir un importe menor. También siguen vigentes los límites generales de IA de la tienda.
6. Pulsa **Iniciar la ronda autorizada**. Se crea un trabajo pequeño y reanudable; no se inicia un aprendizaje autónomo permanente.

Las fechas del histórico no se utilizan para contactar con visitantes ni para volver a ejecutar acciones sobre clientes antiguos. Las búsquedas de la ronda no se mezclan con las estadísticas de visitas reales.

## Presupuesto y llamadas inciertas

Para un proveedor de pago deben estar configurados los precios de entrada y salida del nivel elegido. Antes de cada tanda, AIFEC reserva de forma transaccional el coste máximo de su petición completa y su salida permitida. No mantiene bloqueada la base de datos mientras espera al proveedor.

La pantalla distingue presupuesto autorizado, coste conciliado con el uso registrado, importe reservado, llamadas y fichas de entrada y salida. El coste calculado usa las tarifas configuradas: no es una factura del proveedor.

El historial, la ficha y los dos PDF conservan la moneda y el símbolo guardados al iniciar cada ronda. Cambiar después la moneda o su símbolo en los ajustes no convierte los importes históricos ni sustituye su unidad; ese cambio pausa la ejecución para revisión. Si un registro antiguo carece de moneda, sus importes se muestran como «Sin registrar».

Si se desconoce el resultado de una llamada, no se presume un coste total de cero ni se repite automáticamente. La reserva queda retenida y la ronda se pausa para revisión. Cambiar de proveedor, modelo, clave o tarifas durante una ejecución también impide continuar con condiciones distintas de las autorizadas.

## Revisar y aplicar

La ficha conserva los identificadores, posiciones, totales, filtros y evidencias del catálogo de cada búsqueda antes y después. Los perfiles y evaluaciones aparecen marcados como simulados. Los casos sin una verdad contrastable se muestran como no verificables.

La API propone, pero AIFEC ejecuta el buscador real. Además de los casos propuestos, conserva controles de regresión elegidos a partir del catálogo. La validación determinista exige una mejora medible sin regresiones y comprueba que sigan existiendo los productos y sus hechos publicados.

Las propuestas quedan pendientes. Para aplicarlas, el superadministrador debe revisar las evidencias, superar la validación y confirmar expresamente la aplicación. El servidor vuelve a comprobar contexto, versión y datos del catálogo. Un formulario abierto antes de un cambio no permite aplicar una revisión obsoleta.

**Cancelar** impide nuevas llamadas y aplicaciones posteriores. Si ya hay una llamada en curso, su uso conocido o su reserva sin resolver permanecen visibles. La cancelación también exige conservar el contexto original de la ronda; cambiar la URL no permite continuarla ni trasladar sus propuestas a otro contexto.

## Historial, reversión e informes

Cada aplicación conserva la memoria anterior y posterior y su número de versión. Para **Revertir esta aplicación de aprendizaje**, se exige la versión actual esperada y una confirmación explícita. Deben revertirse primero las aplicaciones posteriores; una reversión antigua no borra aprendizaje más reciente.

Cada ronda ofrece dos PDF privados:

- **Experiencia simulada:** casos y resultados del buscador que realmente quedaron registrados.
- **Propuestas y verificación:** evidencias, comprobaciones y aplicaciones o reversiones registradas. Una propuesta pendiente no se presenta como una mejora aplicada.

Los informes se generan con el PDF del núcleo, sin servicios externos. Una ausencia de datos se muestra como «Sin registrar», nunca como un éxito, una nota aprobada o un coste cero inventado. La descarga comprueba permisos y contexto. Los cambios de URL o idioma bloquean reanudación, validación, aplicación, reversión y descarga bajo un contexto distinto.

La preparación o instalación de esta función no autoriza llamadas con las API de clientes. Cada ejecución de pago necesita su propia solicitud del superadministrador y su presupuesto.

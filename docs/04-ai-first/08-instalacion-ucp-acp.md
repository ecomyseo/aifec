# Instalación de UCP y ACP

Esta guía describe la implementación. No acredita el despliegue público ni una certificación de Google u OpenAI. Los protocolos futuros investigados se mantienen fuera de esta implementación.

## AIFEC

Activa cada protocolo en **Ajustes → UCP y agentes** y entrega a cada integración su propia clave con los permisos necesarios. Los perfiles son rutas dinámicas: `/.well-known/ucp` (también como `/.well-known/ucp.json`, con barra final y con la versión: `/.well-known/ucp/2026-04-08`) y `/.well-known/acp.json` (también `/.well-known/acp`). Las direcciones `.well-known` se escriben en minúsculas: con mayúsculas dan 404. Con el protocolo apagado, responden 404 en JSON diciendo que está apagado, y «Sistema → Diagnóstico» lo avisa. No se sustituyen por el feed de productos de OpenAI.

Apache necesita el `public/.htaccess` de esta entrega. Para Nginx se incluye `docs/04-ai-first/ucp-acp-nginx.conf`: las excepciones de `.well-known` deben preceder a las reglas que bloquean rutas ocultas. Se conserva la configuración PHP existente. En instalaciones con varias tiendas bajo un dominio, cada prefijo conserva sus endpoints; el perfil de la raíz identifica un único comercio principal. No se debe servir allí el catálogo de otro comercio.

El código público de AIFEC puede atender varios sitios. Antes de actualizarlo se necesita identificar la revisión activa, guardar código, configuración y bases de datos y preparar la vuelta atrás. La copia local no actualiza automáticamente las demos.

## ecom_aifec para PrestaShop

La instalación y la migración crean los archivos reales `.well-known/ucp` y `.well-known/acp.json` en la raíz conocida de la web y las reglas recuperables de Apache. Los perfiles contienen endpoints y claves públicas; nunca claves privadas ni credenciales de pago. La aplicación guarda copias antes de sustituir archivos propios o cambiar reglas.

En **AIFEC → Comercio UCP / ACP → Instalación** se muestran los archivos escritos y las incidencias. El botón **Instalar o actualizar los archivos de descubrimiento** vuelve a publicarlos después de guardar la configuración. Si no se conoce la raíz del dominio o no hay permisos de escritura, descarga `ucp`, `acp.json` y las reglas del servidor desde esa pantalla. Copia los dos documentos en la carpeta `.well-known` de la raíz pública, conservando exactamente sus nombres. El archivo `ucp` no tiene extensión.

En Nginx, el administrador del alojamiento debe instalar las reglas descargadas dentro del bloque del servidor, antes del bloqueo de archivos ocultos, y recargar la configuración. El módulo no afirma haber modificado Nginx ni disponer de sus permisos. El botón **Comprobar los perfiles públicos** comprueba su respuesta HTTP, tipo JSON y correspondencia con la tienda; no certifica el checkout ni el cobro.

El módulo conserva perfiles ajenos y avisa si existe `ecom_ucpgoogle` activo. Un superadministrador debe seleccionar una tienda y confirmar el cambio de motor desde esa pantalla. Esa acción desactiva el motor anterior en la tienda seleccionada y conserva copias. Nunca deben atender ambos módulos las mismas rutas estándar. Varios comercios bajo un mismo origen necesitan elegir quién posee el perfil de la raíz; los demás conservan sus rutas de tienda.

Desactivar el protocolo, el módulo o desinstalarlo retira únicamente los perfiles que siguen coincidiendo con sus hashes de publicación y sus bloques propios de Apache. No elimina documentos modificados por terceros. Si no puede retirar un archivo propio, muestra el error para resolver sus permisos.

## Pago en el comercio

El comprador abre el enlace de continuación, revisa el pedido y paga en la caja habitual con tarjeta, PayPal u otro medio activo. Los protocolos no cobran por su cuenta. El catálogo ACP usa /acp/feed.jsonl y el resultado aparece en la sesión cuando la tienda registra el pago. Consulta la guía 09-pago-en-la-tienda-acp-ucp.md para ver ejemplos.

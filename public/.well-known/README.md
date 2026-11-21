# Descubrimiento de comercio con agentes

AIFEC sirve `/.well-known/ucp` y `/.well-known/acp.json` mediante su controlador de entrada. El contenido depende del dominio y de la tienda seleccionada, por lo que no se comparte un único JSON estático entre todas las tiendas alojadas en la aplicación.

En Apache, `public/.htaccess` envía estas direcciones al controlador aunque exista un archivo antiguo. En Nginx deben instalarse las excepciones de `docs/04-ai-first/ucp-acp-nginx.conf` antes de las reglas que bloquean archivos ocultos. No se deben modificar las excepciones de ACME o Let's Encrypt.

Cada tienda debe tener activado el módulo `agentic` y sus interruptores UCP/ACP. Desactivado, responde 404. Publicar un feed de productos no activa el checkout ACP ni sustituye al perfil UCP.

En una tienda con prefijo, por ejemplo `/demos/deporte`, las mismas rutas existen bajo ese prefijo. El perfil del origen corresponde a la tienda principal del dominio: varias tiendas independientes con un mismo dominio deben elegir el perfil principal o disponer de dominios propios. La configuración debe reflejar esa elección y no mezclar comerciantes.

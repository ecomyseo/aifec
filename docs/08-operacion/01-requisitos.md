# Requisitos del servidor

Esta página dice qué necesita un servidor para ejecutar AIFEC. El instalador comprueba casi todo esto antes de dejarte continuar, así que puedes leerla antes de contratar el alojamiento o dejar que el asistente te lo diga.

## Lo que comprueba el instalador

La primera pantalla del asistente, «Idioma y comprobaciones», enseña una tabla de requisitos. Los obligatorios bloquean el botón «Continuar»; los recomendados solo avisan.

| Comprobación | Tipo | Para qué sirve |
|---|---|---|
| PHP 7.4 o posterior | Obligatorio | Se prueba con 7.4, 8.1, 8.3 y 8.4. |
| Controlador de base de datos (SQLite o MySQL/MariaDB) | Obligatorio | `pdo_sqlite` o `pdo_mysql`. Basta con uno. |
| Extensión mbstring | Obligatorio | Texto con tildes, eñes y emojis. |
| Extensión json | Obligatorio | Ajustes, API y datos guardados. |
| Extensión ctype | Obligatorio | Validación de formularios e importaciones. |
| Extensión dom | Obligatorio | Limpieza segura del HTML y los SVG. |
| Extensión openssl o sodium | Obligatorio | Cifran los secretos: claves de pasarelas de pago, contraseña SMTP. |
| La carpeta de datos admite escritura | Obligatorio | Aquí vive la base SQLite, la caché, el registro y las copias. |
| La carpeta pública de medios admite escritura | Obligatorio | `public/media`: las imágenes que subes. |
| Biblioteca de imágenes (gd o imagick) | Recomendado | Sin ella no se crean las versiones pequeñas y WebP de las imágenes. |
| Extensión intl | Recomendado | Formato de números y fechas. Si falta, el sistema usa una alternativa. |
| Extensión curl | Recomendado | Hace falta para cualquier llamada a un servicio de fuera (IA, PayPal, importadores). Sin ella esas llamadas fallan con «cURL is not available». |
| Extensión fileinfo | Recomendado | Reconoce el tipo real de los ficheros que se suben. |
| Extensión zip | Recomendado | Instalar plugins desde un ZIP. |

El instalador prueba la escritura creando y borrando un fichero vacío. Es la única medida fiable: los permisos que enseña Windows o una carpeta montada engañan.

El instalador también muestra las extensiones recomendadas `zlib`, `exif` y `ftp`, con la función que deja de estar disponible si faltan. La extensión `dom` es obligatoria: si falta, no permite continuar con la instalación.

## Base de datos

- **SQLite**: no hay nada que configurar. La base es un solo fichero dentro de la carpeta de datos. Es lo más sencillo para una tienda pequeña y para las demostraciones.
- **MySQL o MariaDB**: la base de datos tiene que existir ya, y el usuario tiene que poder crear tablas en ella. Las tablas llevan un prefijo (`af_` por defecto) para que varias tiendas compartan una base.

El sistema no comprueba la versión del servidor de base de datos. Se ha usado con MariaDB 11.4.

## Espacio

- El código ocupa unos 20 MB (sin contar `public/assets`).
- Los recursos publicados (`public/assets`) son menos de 1 MB.
- Un sitio nuevo con SQLite ocupa unos 2 MB de datos. Crecen con el catálogo, las imágenes y las copias.
- «Diagnóstico» avisa del espacio libre en disco.

## Límites de PHP

«Diagnóstico» → «Límites de memoria y de subida» marca un problema si:

- `memory_limit` es menor de 128 MB,
- `upload_max_filesize` es menor de 8 MB,
- `post_max_size` es menor que `upload_max_filesize`.

También avisa si OPcache está apagada: el sitio va más lento de lo que podría. El tamaño máximo de un fichero subido lo fija el ajuste «Fichero más grande (MB)» de «Ajustes» → «Medios» (8 MB de serie, hasta 64), y en la práctica no puede superar lo que permita PHP.

## Lo que no hace falta

- Composer: el proyecto no tiene `vendor/`.
- Node ni ningún paso de compilación.
- Redis, colas externas ni un servicio aparte para las tareas.
- Una consola en el alojamiento: la consola es cómoda, pero las tareas pueden llamarse por una dirección web (véase [Tareas programadas](04-tareas-programadas.md)).

## Si algo no sale

- **«Corrige lo marcado con ✗ y recarga esta página.»** Hay un requisito obligatorio sin cumplir. Mira la columna «Detalle»: dice «No instalado» o «Sin permiso de escritura».
- **«Sin permiso de escritura» en la carpeta de datos o en `public/media`.** El usuario con el que corre PHP no puede escribir ahí. Da permisos a esa carpeta, no a todo el proyecto.
- **«La extensión pdo_mysql no está instalada en este servidor.»** En la pantalla «Base de datos» la opción MySQL queda desactivada. Usa SQLite o activa la extensión desde el panel del alojamiento.
- **El asistente funciona pero la tienda muestra errores al subir imágenes.** Mira la versión de PHP y la extensión `gd` en [Diagnóstico y problemas](11-diagnostico-y-problemas.md).

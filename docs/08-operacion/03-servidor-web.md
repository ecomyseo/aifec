# Servidor web

AIFEC solo necesita que el servidor web entregue la carpeta `public/` y mande a `index.php` todo lo que no sea un fichero que exista. Esta página da la configuración para Apache y para nginx, y explica el caso de un alojamiento con panel donde nginx va delante de Apache.

## La regla: la raíz web es `public/`

Dentro de `public/` solo hay `index.php`, el `.htaccess`, los recursos publicados (`assets/`) y los medios públicos (`media/<sitio>/`). El código, los ajustes y los datos quedan fuera de la raíz web, y por eso no se pueden pedir por dirección. Con la raíz bien puesta, estas direcciones no devuelven ningún fichero: llegan a AIFEC y contestan 404.

Se comprobó llamando a la aplicación con estas peticiones (mismo resultado que verías por HTTP):

```
/core/bootstrap.php            -> 404
/modules/tools/module.json     -> 404
/main/config.php               -> 404
/favicon.ico                   -> 200
/robots.txt                    -> 200
```

Si el código está en otra carpeta que `public/` (instalación con el código fuera de la raíz web), crea junto a `public/index.php` un fichero `aifec-root.php` que devuelva la ruta del código:

```php
<?php
return '/var/aifec';
```

## Apache

Hace falta `mod_rewrite` y que el directorio admita `.htaccess` (`AllowOverride All`, o al menos `FileInfo Indexes Options AuthConfig`). El `.htaccess` viene en `public/` y hace esto:

```apache
Options -Indexes
AddDefaultCharset UTF-8

<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
</IfModule>
```

- Todo lo que no es un fichero existente va a `index.php`.
- La cabecera `Authorization` llega a PHP: la necesita la API con clave.
- Los recursos (`.css`, `.js`, imágenes, fuentes) salen con caché de un año. Es seguro: la dirección de cada recurso publicado lleva su versión.
- `aifec-root.php` y `.htaccess` están denegados.

## nginx

Bloque completo de ejemplo. **No probado en esta guía** (no hay nginx en la máquina de pruebas): revísalo con `nginx -t` antes de recargar.

```nginx
server {
    listen 80;
    server_name tienda.ejemplo.test;
    root /var/www/aifec/public;
    index index.php;
    client_max_body_size 64m;

    # Todo lo que no existe como fichero lo atiende index.php.
    location / {
        try_files $uri /index.php$is_args$args;
    }

    # Ocultos (.git, .htaccess): nada. Se deja pasar .well-known para validar certificados.
    location ~ /\.(?!well-known) {
        deny all;
    }

    # Solo index.php ejecuta código. Un .php subido a media/ no corre.
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ \.php$ {
        return 404;
    }
}
```

`client_max_body_size` debe ser al menos tan grande como el mayor fichero que quieras subir. Añade el bloque `listen 443 ssl` y los certificados como en cualquier sitio nginx.

## Alojamiento con panel y nginx delante de Apache

Paneles como Hestia ponen nginx delante de Apache. nginx entrega él mismo, sin preguntar a Apache, cualquier fichero que exista con extensión de estático (`css`, `js`, `json`, `xml`, `html`, `pdf`, imágenes, fuentes, `zip`, `gz`, `txt`…). El `.htaccess` no se entera.

Consecuencia: si la raíz web del dominio es la carpeta del proyecto y no `public/`, esos ficheros se descargan desde Internet: los `module.json`, las copias `.zip`, los registros `.gz`. Hay dos arreglos, y conviene hacer el primero:

1. **Poner la raíz web en `public/`.** En Hestia: `v-change-web-domain-docroot USUARIO DOMINIO DOMINIO public yes`. El quinto argumento (`yes`) deja `open_basedir` abarcando el proyecto entero; sin él PHP no puede leer el código fuera de `public/` y todas las peticiones dan 500 con «require(): open_basedir restriction in effect». Esto está comprobado en un servidor real, no en la máquina de esta guía.
2. **Cerrar el resto con reglas `location`** en un fichero que el panel incluya (en Hestia, `nginx.conf_aifec` y `nginx.ssl.conf_aifec` dentro de `/home/USUARIO/conf/web/DOMINIO/`), y recargar nginx:

```nginx
location ~ ^/(core|modules|plugins|themes|sites|demos|tests|docs)/ {
    return 404;
}
```

También esta regla queda **sin probar** aquí.

## Descargas públicas de los ficheros de producto (`/descargas/`)

Un fichero descargable de un producto puede tener una **dirección pública** con un código al azar de 48 cifras: `https://tu-tienda/descargas/<código>/<nombre>`. Sirve para avisar de una actualización a todos los clientes. AIFEC copia el fichero en `public/descargas/<código>/`; sin código nadie la adivina y sin listados nadie la encuentra. En nginx:

```nginx
# Se actualiza en cada versión: sin caché larga, sin índice de carpeta y sin pasar por la caché de páginas ni por PHP.
location ^~ /descargas/ {
    autoindex off;
    expires off;
    add_header Cache-Control "no-cache, must-revalidate" always;
    try_files $uri =404;
}
```

- **Sin caché larga.** Si el servidor pone `expires max` a los `.zip` (Hestia lo hace), esta regla tiene que ir **antes** y con `^~`: así gana a la de las extensiones y el cliente siempre recibe la última versión.
- **Sin listados.** `autoindex off` (es el valor de serie) y, en cada carpeta, un `index.php` que redirige a la de arriba: `/descargas/` y `/descargas/<código>/` no enseñan nada. En Apache, `public/descargas/.htaccess` lleva `Options -Indexes`.
- **Fuera de la caché de páginas.** `^~ /descargas/` no llega a `index.php`, así que AIFEC no guarda ni sirve ninguna de estas direcciones desde su caché.

## HTTPS y proxies

AIFEC da por segura una petición si PHP trae `HTTPS` activado, si llega por el puerto 443 o si el proxy manda `X-Forwarded-Proto: https`. Con HTTPS, las cookies llevan `Secure` y las respuestas llevan `Strict-Transport-Security`.

La dirección IP de la visita sale solo de `REMOTE_ADDR`: AIFEC no lee `X-Forwarded-For`. Detrás de un proxy, `REMOTE_ADDR` es la IP del proxy a menos que el servidor la restituya (`mod_remoteip` en Apache, `set_real_ip_from` en nginx). Los límites de intentos y de uso de la API cuentan por esa dirección.

## Si algo no sale

- **Solo funciona la portada; el resto da 404 del servidor.** La reescritura no está activa: falta `mod_rewrite`, `AllowOverride` o el `try_files` de nginx.
- **Pantalla en blanco o 500 en todas las páginas.** Mira el registro (`<datos>/<sitio>/logs/`) y [Diagnóstico y problemas](11-diagnostico-y-problemas.md). En un alojamiento con panel, mira también `open_basedir`.
- **Se pueden descargar ficheros del proyecto.** La raíz web no es `public/`. Pasa a la opción 1 de arriba.
- **La API no reconoce una clave que es buena.** Puede que la cabecera `Authorization` no llegue a PHP: en Apache hace falta la regla del `.htaccess`; en nginx pasa sola por FastCGI. Si la fijas a mano con `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`, añade **`if_not_empty`** al final (`… $http_authorization if_not_empty;`): sin él, nginx manda la cabecera vacía en todas las peticiones y AIFEC apaga la caché de páginas.

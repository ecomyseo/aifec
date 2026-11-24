# Instalar AIFEC

AIFEC se instala de dos formas: con el asistente web, que son tres pantallas, o por consola con una sola orden. Las dos crean el mismo sitio: la base de datos, el ajuste básico de la tienda y la primera persona del panel.

## Instalar desde el ZIP descargable

El paquete descargable contiene el sistema y su instalador web. No contiene una tienda ya instalada: no trae cuentas del operador, claves de API, bases de datos de demostraciones ni datos de clientes. Cada instalación crea su propia cuenta de administración y sus propios secretos.

1. Descarga el ZIP y su suma SHA-256 desde la página de descarga de AIFEC. La suma permite comprobar que el archivo llegó completo.
2. Descomprime la carpeta `aifec` y súbela al alojamiento, o utiliza el administrador de archivos del alojamiento para extraerla.
3. En el panel del alojamiento, apunta la raíz web del dominio a `aifec/public`. El resto del sistema debe quedar fuera de la raíz pública. Si el alojamiento no permite cambiarla, sigue la alternativa de [Servidor web](03-servidor-web.md) para separar los archivos públicos del código privado.
4. Abre el dominio en el navegador y completa «Idioma y comprobaciones», «Base de datos» y «Tu tienda», como se explica más abajo.

No necesitas Composer, Node ni una consola para completar el asistente web. Con MySQL o MariaDB debes crear antes una base vacía desde el panel del alojamiento; con SQLite no hace falta ese paso. El correo y los pagos externos se configuran después y no se activan automáticamente.

AIFEC se publica con la licencia GNU AGPL v3 con términos adicionales de origen (el archivo `LICENSE.md` del paquete; hasta la 1.2.6 fue Apache 2.0). El código está en https://github.com/ecomyseo/aifec.

## Antes de empezar

1. Copia el proyecto al servidor.
2. Apunta la raíz web del dominio a la carpeta `public/`, no a la carpeta del proyecto. Es lo único que debe verse desde Internet. Cómo hacerlo en Apache, nginx y en un alojamiento con panel está en [Servidor web](03-servidor-web.md).
3. Decide dónde van los datos. Por defecto, en la carpeta `sites/` del proyecto. Lo recomendable es sacarlos fuera de la carpeta pública y, si puedes, fuera del proyecto: crea un fichero `data-path.php` junto a la carpeta `core/` que devuelva la ruta.

```php
<?php
return '/var/aifec-datos';
```

La carpeta tiene que existir o poder crearse, y PHP tiene que poder escribir en ella. Si por descuido la carpeta de datos queda dentro de `public/`, el instalador escribe en ella un `.htaccess` que lo niega todo y un `index.html` vacío, pero eso solo protege en Apache. «Diagnóstico» lo avisa.

## Con el asistente web

Abre la dirección del sitio en el navegador. Mientras no exista ningún sitio, esa dirección enseña el asistente.

1. **«Idioma y comprobaciones».** Elige «Español» o «English» y revisa la tabla «Requisitos» (explicada en [Requisitos](01-requisitos.md)). Pulsa «Continuar».
2. **«Base de datos».** Elige «SQLite (no hay que configurar nada)» o «MySQL o MariaDB». Con MySQL rellena «Servidor», «Puerto», «Nombre de la base de datos», «Usuario», «Contraseña» y «Prefijo de las tablas». El asistente comprueba la conexión al pulsar «Continuar». Con «Atrás» vuelves a la pantalla anterior. La contraseña de la base de datos no vuelve nunca al navegador.
3. **«Tu tienda».** Escribe «Nombre de la tienda» y «Correo de contacto». En «Tu acceso» escribe «Tu nombre», «Tu correo» y una contraseña de al menos 10 caracteres («Repítela» para confirmarla). Pulsa «Instalar».
4. **«Listo».** Enseña «AIFEC está instalado» y dos botones: «Abrir el panel» y «Abrir la tienda».

Lo que viaja de una pantalla a otra va firmado y caduca a las dos horas. El asistente borra su clave temporal (`install.key`) al terminar.

## Por consola

```
php aifec site:create main --url=http://localhost:8800 --admin-email=ana@cliente.test --admin-name="Ana Prueba" --admin-password=clave-de-mentira-1 --name="Tienda de prueba"
```

Salida real:

```
Site created: main
URL: http://localhost:8800
Panel: http://localhost:8800/admin
Email: ana@cliente.test
```

Una contraseña escrita en la orden queda en el historial de la consola. Si omites `--admin-password`, según el código la orden genera una de 16 caracteres y la enseña una sola vez, en una línea «Password: … (shown only once: save it now)»; guárdala en ese momento. Esa variante no se ha ejecutado para esta guía.

Opciones más usadas:

| Opción | Para qué |
|---|---|
| `--url=` | Dirección pública del sitio. Obligatoria. |
| `--admin-email=` | Correo de la primera persona del panel. Obligatorio. |
| `--db=mysql` con `--db-host`, `--db-port`, `--db-name`, `--db-user`, `--db-pass`, `--db-prefix` | Usar MySQL o MariaDB en vez de SQLite. |
| `--lang=es` y `--timezone=Europe/Madrid` | Idioma y zona horaria (son los valores de serie). |
| `--data=/ruta` | Carpeta de datos, si no usas `data-path.php`. |

La lista completa sale con `php aifec help`. No hay una orden `install`: la instalación por consola es `site:create`.

## Después de instalar

1. Entra en `/admin` con el correo y la contraseña elegidos.
2. Pon la tienda en HTTPS y comprueba que `url` del sitio empieza por `https://` (`php aifec site:config main url=https://…`).
3. Programa el cron: [Tareas programadas](04-tareas-programadas.md).
4. Abre «Sistema» → «Diagnóstico» y mira qué queda en naranja o en rojo. Con una tienda nueva saldrán tres cosas: un problema en rojo («Las tareas programadas se están ejecutando»: nunca se han ejecutado) y dos avisos en naranja (el correo retenido y que solo hay un administrador sin segundo paso).
5. El correo de una tienda nueva sale **retenido**: no se envía nada hasta que lo sueltes. Se explica en [Correo](05-correo.md).

## Si algo no sale

- **El asistente dice «Los datos del formulario han caducado o se han modificado. Empieza de nuevo.»** Pasaron más de dos horas o el formulario cambió. Empieza otra vez desde la primera pantalla.
- **«Esa base de datos ya tiene tablas de AIFEC con ese prefijo».** Usa otro prefijo o una base vacía. El asistente no pisa una instalación existente.
- **«La contraseña de la base de datos ya no está guardada. Vuelve atrás y escríbela otra vez.»** Vuelve a la pantalla 2 y escribe la contraseña de nuevo.
- **La dirección enseña un 404 «No site is configured for this address.»** Ya hay sitios, pero ninguno corresponde a ese dominio o esa ruta. Mira `php aifec sites`.

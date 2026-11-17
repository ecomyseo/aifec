# Seguridad

Una parte de la seguridad de AIFEC la hace el sistema solo, y otra depende de cómo lo instales y lo mantengas. Esta página separa las dos y termina con una lista de diez comprobaciones para pasar antes de abrir la tienda.

## Lo que hace el sistema solo

**Acceso**

- Las contraseñas se guardan con Argon2id, o con bcrypt de coste 12 si el servidor no tiene Argon2. Las de otras tiendas (WordPress, PrestaShop) se verifican con su esquema y se renuevan al primer acceso.
- Las sesiones viven en la base de datos, y de la cookie solo se guarda su resumen. Cada navegador mantiene la sesión dos semanas; en «Mi cuenta» → «Sesiones» se cierran.
- Las cookies son `HttpOnly`, `SameSite=Lax` y, con HTTPS, `Secure`. Llevan el sitio en el nombre: dos sitios del mismo dominio no comparten sesión.
- «Mi cuenta» → «Verificación en dos pasos» añade un código de teléfono a la contraseña.
- El panel limita los intentos de entrada: 5 fallos por cuenta o 20 por dirección IP en 15 minutos, y contesta «Demasiados intentos. Espera quince minutos y vuelve a probar.» con un `429`.

**Formularios y peticiones**

- Todo formulario con sesión lleva una ficha CSRF ligada a la sesión y al formulario. Vale dos horas: después sale «El formulario ha caducado. Recarga la página y vuelve a intentarlo.»
- La API limita las peticiones por minuto y dirección («Ajustes» → «General» → «Seguridad» → «Peticiones a la API por minuto», 120 de serie; cero es sin límite). El asistente, los formularios de contacto, las valoraciones y el consentimiento tienen límites por hora.
- Lo que viene de un dato se escapa al pintarlo; el HTML que escriben las personas pasa por un limpiador; las consultas llevan sus valores como parámetros.

**Ficheros y datos**

- Lo que se sube se reconoce por su contenido, no por su nombre. Los SVG pasan por un limpiador.
- La carpeta de medios lleva un `.htaccess` que impide ejecutar guiones. La de ficheros privados rechaza extensiones peligrosas (`php`, `phar`, `html`, `exe`, `sh`, `js`…) y niega el acceso por dirección. Copias y facturas viven en `<datos>/<sitio>/private/`, fuera de la raíz web.
- Los secretos (claves de pasarelas, contraseña SMTP) se guardan cifrados con libsodium o AES-256-GCM. El registro tapa los valores cuyo nombre huele a secreto.
- El «Registro de actividad» dice quién cambió qué.
- Las páginas HTML llevan `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin` y `Permissions-Policy: camera=(), microphone=(), geolocation=()`; con HTTPS, también `Strict-Transport-Security: max-age=31536000`.

**Política de seguridad de contenido**

Las páginas HTML llevan además la cabecera `Content-Security-Policy`, que le dice al navegador qué puede cargar y ejecutar la página. De serie es **estricta**:

- Los guiones solo se cargan de la propia tienda. Un guion escrito dentro de la página solo se ejecuta si su huella (sha256) va en la cabecera, y eso solo lo hacen el sistema y sus módulos. Un `<script>` que alguien consiga colar en un comentario, un nombre o una dirección **no se ejecuta**.
- No se admiten objetos incrustados (`object-src 'none'`), ni un `<base>` ajeno (`base-uri 'self'`), ni que otra web meta la tienda en un marco (`frame-ancestors 'self'`).
- Imágenes y vídeos, de la tienda o de cualquier dirección `https:`; marcos, de la tienda, YouTube y Vimeo (los vídeos incrustados del contenido).

Se ajusta en «Ajustes» → «General» → «Seguridad»:

| Ajuste | Para qué |
|---|---|
| «Política de seguridad de contenido» | «Estricta» (de serie), «Relajada» (los guiones dejan de estar limitados; lo demás sigue) o «Apagada». |
| «Otras webs de las que pueden cargar las páginas» | Orígenes de más, uno por línea y con `https://` (un CDN tuyo, un servicio de mapas). Solo lo que hayas añadido tú: los módulos y los plugins declaran lo suyo. |

**Se relaja sola** mientras haya un gestor de etiquetas o un píxel configurado en «Medición» (Google Tag Manager, Google Analytics o Ads, Meta, TikTok, Microsoft): esos guiones escriben y cargan los suyos y con la política estricta dejarían de medir. Si mides solo desde el servidor («Mandar compras y reembolsos desde el servidor»), la política se queda estricta.

Para comprobarla: abre la tienda, pulsa F12 y mira la consola. Un recurso bloqueado sale como «Refused to load…» o «Refused to execute…» con la directiva que lo impide; añade su origen en el ajuste de arriba.

**El asistente y los datos de pago**

- La tienda nunca cobra por el chat. Si alguien escribe en él el número de una tarjeta, un IBAN o un código de seguridad, se quitan **antes de todo**: no llegan a la conversación guardada, ni al registro, ni al modelo de IA. Solo queda `***` y las cuatro últimas cifras, y el asistente contesta con un aviso fijo de la tienda.
- En la conversación guardada, el correo, el teléfono, el NIF, el nombre y la calle van enmascarados. Al modelo llegan solo en el mensaje en que el cliente los escribe, porque los necesita para hacer el pedido o buscarlo.
- Un fichero o un trozo de código pegado en el chat (un PDF, una imagen en base64) no se le pregunta al modelo ni se guarda entero.
- Las conversaciones se borran solas a los 30 días sin actividad («Ajustes» → «Asistente de compra» → «Días que se conservan las conversaciones»).
- Límites: 20 mensajes cada diez minutos por visitante, 60 por dirección de origen y 30 por conversación («Mensajes por conversación»); cada mensaje, 1.000 caracteres.

## Lo que te toca a ti

| Tema | Qué hacer |
|---|---|
| Raíz web | Que sea `public/`. Véase [Servidor web](03-servidor-web.md). |
| Permisos | PHP escribe en la carpeta de datos, en `public/media/` y en `public/assets/`, y en nada más. `config.php` guarda la clave secreta y los datos de la base de datos: que no lo lea ningún otro usuario del servidor. |
| HTTPS | Certificado válido, `url` del sitio con `https://` y redirección de `http`. |
| Copias | Fuera de la máquina y probadas: [Copias y restaurar](06-copias-y-restaurar.md). |
| Claves | La dirección web del cron, las claves de la API y las de las pasarelas son contraseñas. Si se filtran, se cambian. |
| IP real | Detrás de un proxy, restituye la IP del visitante, o los límites contarán la del proxy. |
| Al día | Mantén PHP y AIFEC actualizados y `debug` apagado en producción. |

## Lista de diez comprobaciones

1. La raíz web es `public/` y `/core/bootstrap.php` devuelve 404.
2. La dirección del sitio empieza por `https://` y `http` redirige.
3. «Diagnóstico» no tiene ningún problema en rojo.
4. Hay al menos dos administradoras con verificación en dos pasos («Diagnóstico» avisa: «Un solo administrador y sin segundo paso»).
5. `debug` está apagado: `php aifec site:config main debug=0`.
6. La carpeta de datos no cuelga de `public/` («No está dentro de la carpeta pública»).
7. `config.php` y la carpeta de datos no son legibles por otros usuarios.
8. Hay copia nocturna, se guarda fuera de la máquina y se ha restaurado una de prueba.
9. No queda ninguna cuenta de demostración en una tienda real.
10. El correo, las pasarelas y la indexación están como quieres: una tienda nueva sale con el correo retenido y el pago simulado.

## Si algo no sale

- **«Demasiados intentos. Espera quince minutos…»** Cinco fallos con esa cuenta o veinte desde esa dirección. Se desbloquea a los 15 minutos; cambiar la contraseña no lo adelanta, porque el límite se mira antes de comprobarla.
- **«El formulario ha caducado.»** Llevaba abierto más de dos horas. Recarga y repite.
- **Una persona perdió el teléfono del segundo paso.** Puede entrar con uno de sus códigos de recuperación. Otra persona con permiso para editar el equipo puede desactivar su segundo paso desde su ficha; la operación cierra sus sesiones, anula sus códigos y queda registrada. Si no queda acceso al panel, la consola ofrece `php aifec user:2fa-reset <correo> --site=<sitio>`; comprueba el correo y el sitio antes de ejecutarla. Después, la persona debe configurar de nuevo el segundo paso desde «Mi cuenta».
- **«La carpeta de datos está dentro de la carpeta pública y cualquiera podría descargar sus ficheros».** Muévela fuera y apunta `data-path.php` a ella.

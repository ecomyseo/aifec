# Cómo funciona un tema

Un tema decide cómo se ve la tienda: el envoltorio de todas las páginas, las piezas comunes (cabecera, pie, tarjeta de producto), los estilos y el guion. No decide qué datos salen ni cuánto cuestan: eso lo hacen los módulos. Esta página explica qué hay dentro de un tema, qué hereda de su padre y qué cargan sus recursos.

## Las carpetas

Cada tema vive en `themes/<slug>/`. El `<slug>` usa minúsculas, números y guiones.

```
themes/escaparate/
  theme.json            identidad, tema padre, opciones, menús, tamaños de imagen
  templates/
    layout.php          envoltorio de todas las páginas
    home.php, error.php
    partials/           piezas: header, footer, product-card, price, breadcrumbs…
    modules/<módulo>/front/<plantilla>.php    sustituye una plantilla de un módulo
  assets/css/theme.css, assets/js/theme.js, assets/img/…, assets/fonts/…
  lang/es.php           traducciones de las cadenas del tema
  functions.php         opcional: ganchos propios del tema
  preview.png           opcional: 1200 × 900, la portada en escritorio
```

Solo `theme.json` es obligatorio. Un tema hijo escribe únicamente lo que quiere distinto: todo lo demás lo hereda.

## theme.json

```json
{
    "name": "Escaparate",
    "version": "1.0.0",
    "description": "Example child theme…",
    "parent": "base"
}
```

| Clave | Qué hace |
|---|---|
| `name` | Obligatorio. Es lo único que el validador exige del manifiesto. |
| `version`, `description` | Se enseñan en «Diseño» › «Temas». |
| `parent` | El tema del que hereda. Si no lo declaras, hereda de `base` igualmente. La cadena admite hasta seis temas y no puede tener ciclos. |
| `css`, `js` | Con el valor `"replace"` cortan la herencia: solo se carga la hoja (o el guion) de este tema. |
| `menus` | Los sitios de la tienda donde el comerciante puede poner un menú (`main`, `footer`, `legal` en el tema base). |
| `image_sizes` | Tamaños `[ancho, alto]` en que la biblioteca de medios prepara cada imagen. `thumb`, `card` y `large` existen siempre. |
| `font_stacks` | Pilas de fuentes disponibles para las opciones de tipo `font`. |
| `settings` | Las opciones que el comerciante cambia en el personalizador. Ver [Opciones y personalizador](04-opciones-y-personalizador.md). |

Las claves de un hijo se funden con las de su padre: lo del hijo manda y lo que no declara, lo hereda. Por eso el `theme.json` de un hijo puede cambiar solo el valor de fábrica de un color sin repetir el resto del campo.

## Qué se hereda y cómo

| Qué | Cómo se resuelve |
|---|---|
| Plantillas y piezas | Se busca primero en el tema activo, luego en su padre, y así hasta `base`. La primera que existe gana. |
| Plantillas de módulos | Igual: `templates/modules/<módulo>/front/<plantilla>.php` del tema, o la del propio módulo si ningún tema la sustituye. |
| Recursos (`asset()`) | Busca el fichero en el tema activo y en sus padres. |
| `lang/es.php` | Se cargan todos, del `base` al activo. El hijo traduce encima. |
| `functions.php` | Se cargan todos, del `base` al activo. |
| Hoja y guion | Ver abajo. |

## theme_styles() y theme_scripts()

El envoltorio no escribe las direcciones de la hoja y del guion: llama a `theme_styles()` en el `<head>` y a `theme_scripts()`.

- `theme_styles()` pinta cada `assets/css/theme.css` de la cadena, **primero el del tema base y encima el del hijo**. Un hijo pequeño solo escribe lo que cambia.
- **La hoja va escrita dentro de la página**, en un `<style data-af-css="…">`, sin comentarios ni espacios de más: el navegador pinta sin esperar a otra petición (ninguna hoja bloquea el primer pintado). Se enlaza con un `<link rel="stylesheet">` de los de siempre solo si pasa de 64 KB o si usa `@import` o direcciones relativas (`url(../img/…)`): escribe las de tus imágenes completas desde la raíz, o usa `data:`. Quien instala puede volver a enlazarlas todas con `'inline_css' => false` en el `config.php` del sitio. Las hojas de los módulos que pintan algo en la tienda hacen lo mismo con `\Aifec\Front\ThemeVars::styleTag($app, 'm', 'mi-modulo', 'mi-hoja.css')` (vale igual para un plugin).
- `theme_scripts()` hace lo mismo con `assets/js/theme.js` y pone `defer` en cada `<script>`.
- Con `"css": "replace"` en el `theme.json`, se carga solo la hoja de ese tema y de los hijos que tenga por encima. Lo mismo con `"js": "replace"`.

El tema Atelier usa `"css": "replace"` porque rehace toda la hoja. Escaparate no lo usa: suma unas pocas reglas a la de `base`.

Los campos del tema que declaran `var` salen solos como variables CSS dentro de `page_head()`: no hace falta escribir `:root { … }` a mano.

## Dónde se sirven los recursos

En desarrollo, el propio sistema sirve `/assets/t/<tema>/<versión>/css/theme.css`. En producción, `php aifec assets:publish` copia los recursos a `public/assets/` y los sirve el servidor web sin pasar por PHP. Tras cambiar un recurso en producción, vuelve a lanzar esa orden.

## Si algo no sale

- **El tema no aparece en «Diseño» › «Temas».** Falta el fichero `themes/<slug>/theme.json` o el nombre de la carpeta tiene mayúsculas o espacios. Si el JSON está mal escrito, el tema sí aparece, pero con el error y sin poder activarse.
- **Mi hoja no se carga.** Debe llamarse `assets/css/theme.css`. Con otro nombre, `theme_styles()` no la ve. Una hoja extra se carga con `$this->push('styles', '<link …>')` desde una plantilla.
- **Cambio una plantilla o una hoja y la tienda sigue igual.** La tienda guarda las páginas en caché. Lanza `php aifec cache:clear`.

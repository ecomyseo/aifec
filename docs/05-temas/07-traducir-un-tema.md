# Traducir un tema

Los textos del tema se escriben en inglés en el código y se traducen en un catálogo por idioma. El validador exige el catálogo castellano completo: un texto sin traducir es un fallo que bloquea la activación. Esta página explica cómo se escriben los textos, cómo es el catálogo y qué orden dice lo que falta.

## Escribir un texto

En una plantilla, un texto que ve una persona pasa por `t()`:

```php
<?= e(t('Open the menu')) ?>
<?= e(t('Hello {name}', ['name' => $nombre])) ?>
<?= e(tn('{n} piece', '{n} pieces', $n)) ?>
```

- El texto fuente va **en inglés** y es la clave del catálogo.
- Las variables van entre llaves y se pasan en un array. Un texto con variable es una sola cadena: nunca montes una frase pegando trozos traducidos.
- `tn()` elige entre singular y plural según el número. En el catálogo, la clave junta las dos formas con `|`.
- `t()` no escapa: envuélvela con `e()`.

Lo mismo en `theme.json`: los `label`, el `description` y los nombres de los menús van en inglés y se traducen igual.

## El catálogo

`lang/es.php` devuelve un array de «texto inglés» => «traducción»:

```php
<?php

declare(strict_types=1);

return [
    'Hello {name}' => 'Hola, {name}',
    '{n} piece|{n} pieces' => '{n} pieza|{n} piezas',
    'Open the menu' => 'Abrir el menú',
];
```

La clave es el texto inglés **tal cual**: mayúsculas, signos y variables incluidos. La clave no se traduce. La traducción va en castellano con sus tildes y eñes. Si una clave no está en el catálogo, la tienda enseña el texto inglés tal cual, sin error.

Con esas tres entradas, `t('Hello {name}', ['name' => 'Ana'])` da «Hola, Ana», `tn('{n} piece', '{n} pieces', 3)` da «3 piezas» y un texto que no está en el catálogo sale como se escribió.

El tema carga su catálogo encima de los de sus padres y de los de los módulos: lo que traduzcas aquí manda.

## Qué falta

```
php aifec i18n:check themes/escaparate
```

La orden lee los ficheros, sin necesitar el sitio. Escribe una línea por cada texto sin traducir, ya con el formato del catálogo, y un resumen:

```
    'Last units' => '',   // themes/escaparate/templates/partials/product-card.php
    'Notice strip' => '',   // themes/escaparate/theme.json
I18N themes/escaparate [es]: 12 strings, 8 missing
```

Copia las líneas a `lang/es.php`, rellena lo de la derecha y vuelve a lanzarla hasta que diga `0 missing`. Sale con código 1 si falta algo, y con 0 si no.

Otras opciones:

| Orden | Para qué |
|---|---|
| `i18n:check themes/escaparate fr` | Comprueba otro idioma (`lang/fr.php`). Sin catálogo, falta todo. |
| `i18n:check themes/escaparate es --unused` | Además lista como `UNUSED:` las claves del catálogo que ya no usa ninguna plantilla. |
| `i18n:check core`, `modules/<slug>`, `plugins/<slug>` | Lo mismo para el núcleo, un módulo o un plugin. |

## Qué textos ve la orden

- Las llamadas `t('…')`, `tn('…', '…')` y `helpText('…')` escritas con el texto literal.
- Los `label`, `description` y demás textos de `theme.json`, incluidas las opciones de un `select` y los nombres de los menús.

Lo que la orden **no** ve:

- Una llamada con una variable: `t($texto)`. Si el texto sale de una lista, declara cada uno en el catálogo igualmente.
- Un texto escrito en un `.js`. Mándalo desde PHP, en un atributo `data-…` de la plantilla.

Si hay un texto que no debe traducirse (un nombre propio, un ejemplo de formato), lístalo en `lang/i18n-ignore.php`: `return ['texto', …];`.

## Un tema hijo repite lo suyo

La orden solo mira el catálogo del propio tema y el del núcleo, no el del padre. Si tu cabecera usa `t('Open the menu')` y esa cadena ya está traducida en `base`, tu `lang/es.php` tiene que traerla igualmente o el validador falla con «Falta la traducción en lang/es.php». Es lo que pasa en [Tu primer tema hijo](02-tu-primer-tema-hijo.md) con «Cart» y «Open the menu».

## Si algo no sale

- **La tienda enseña el texto en inglés.** La clave del catálogo no coincide letra por letra con el texto del código (una comilla, un espacio, un punto). Copia la línea que escribe `i18n:check`.
- **`i18n:check` dice que falta algo que sí he traducido.** Comprueba que el fichero devuelve un array (`return [ … ];`) y que no tiene errores de sintaxis: `php -l themes/<slug>/lang/es.php`.
- **Quiero otro idioma.** Añade `lang/<código>.php` y escribe el código en el ajuste «Idiomas» (pestaña «Idiomas y moneda» de «Ajustes» › «General»). Las direcciones de ese idioma llevan su prefijo (`/en/…`).

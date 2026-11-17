# La tienda

Un plugin puede añadir páginas a la tienda, dar su dirección a cada una, pintarlas con las piezas del tema, cargar sus propios estilos y dejar que la caché de página las sirva. Esta página lo hace con un segundo plugin pequeño, **Página de ejemplo de tienda** (`ejemplo-tienda`): una página pública por número y por dirección amigable. Pasa el contrato con 100/100 y sus cuatro pruebas en verde con PHP 7.4, 8.3 y 8.4.

Los ficheros reales llevan la cabecera de autor y licencia en un comentario inicial; aquí se omite. `plugin.json` es el de [Tu primer plugin](02-tu-primer-plugin.md) con su id, su espacio de nombres (`Plugins\EjemploTienda`) y una tabla (`ejemplo_tienda_pages`, con `id`, `title`, `body` y `created_at`, creada por su migración).

## Una ruta de la tienda

`routes.php` devuelve una función que recibe el enrutador:

```php
<?php

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Plugins\EjemploTienda\PageController;

return static function (Router $r, App $app): void {
    $r->get('/ejemplo-tienda/{id:\d+}', [PageController::class, 'show'])->name('ejemplo_tienda.page');
};
```

- Métodos: `get()`, `post()` y `any()`; `group(['prefix' => …, 'middleware' => […]], fn)` agrupa rutas con un prefijo y unos intermediarios.
- Patrones en la ruta: `{id}`, `{id:\d+}` (con expresión) o `{ruta:.+}`. Llegan al controlador en `$params`.
- Intermediarios disponibles: `customer` (exige cliente con sesión), `csrf` (en los envíos de la tienda, exige que vengan de la propia web) y `api`. En el panel, `admin`.
- Dale siempre un **nombre** (`->name('…')`) y enlaza con `route('nombre')`. Nunca escribas una dirección a mano: `url('/carrito')`, `route('ejemplo_tienda.page', ['id' => 3])`. Así funcionan los sitios en subcarpeta y en otros idiomas.
- No uses los nombres reservados del comercio (`cart`, `cart.add`, `checkout`, `order.thanks`, `account`, `search`…).

## El controlador

Recibe `(Request $request, array $params)` y devuelve una `Response`:

```php
<?php

declare(strict_types=1);

namespace Plugins\EjemploTienda;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

final class PageController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function show(Request $request, array $params): Response
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        $row = (new Pages($this->app))->find($id);
        if ($row === null) {
            throw HttpError::notFound();
        }
        $page = ['title' => (string) $row['title'], 'description' => '', 'type' => 'page', 'breadcrumbs' => [['name' => t('Home'), 'url' => url('/')], ['name' => (string) $row['title'], 'url' => '']]];

        return $this->app->render('ejemplo-tienda::front/page', ['page' => $page, 'row' => $row])
            ->cacheable(600, ['ejemplo_page:' . $id]);
    }
}
```

- `throw HttpError::notFound()` (también `forbidden()`, `badRequest()`, `tooMany()`) corta la petición con la página de error del tema.
- Para otra respuesta: `Response::html()`, `::json()`, `::redirect()`, `::file()` o `::text()`. `Response::json()` ya lleva las cabeceras anti-caché.
- El array `$page` son los datos de cabecera. Con `title`, `type` y `breadcrumbs`, el envoltorio completa canónica, robots y los datos estructurados. Los tipos `cart`, `checkout`, `account`, `order`, `search` y `error` nunca se indexan.

## La plantilla, dentro del tema

`views/front/page.php` empieza envolviéndose en el `layout` del tema:

```php
<?php

$this->layout('layout', ['page' => $page]);
// La hoja va escrita dentro de la página (ninguna petición bloquea el primer pintado); si pasa de 64 KB, se enlaza sola.
$this->push('styles', \Aifec\Front\ThemeVars::styleTag(app(), 'm', 'ejemplo-tienda', 'page.css'));
?>
<article class="af-container af-section ejemplo-tienda-page">
    <h1 class="af-title"><?= e($row['title']) ?></h1>
    <div class="af-prose"><?= nl2br(e($row['body'])) ?></div>
</article>
```

La plantilla pinta solo su contenido; la cabecera, el pie y la barra del móvil los pone el tema, sea el que sea. Usa las clases `af-*` del tema (`af-container`, `af-section`, `af-title`, `af-prose`…) y todo dato con `e()`. Un solo `<h1>` por página.

La plantilla puede ser sustituida por el tema activo: un tema que ponga `templates/modules/ejemplo-tienda/front/page.php` manda sobre la tuya. Por eso las clases y los datos son parte de lo que ofreces.

## Huecos del tema

Tu plugin no necesita pedir nada al tema para pintar en la ficha, el carrito o el pie: se engancha a sus huecos con `slot()`, como en [Tu primer plugin](02-tu-primer-plugin.md). Los huecos están en [Huecos y datos](../05-temas/05-huecos-y-datos.md).

## Recursos estáticos

Los ficheros de `assets/` se sirven como `/assets/m/<id>/<versión>/<fichero>`. La dirección se construye con `app()->url()->asset('m', 'ejemplo-tienda', 'page.css')`:

```css
.ejemplo-tienda-page .af-prose {
    max-width: 42rem;
    color: var(--ink);
}
```

Usa las variables del tema (`var(--ink)`) en vez de colores fijos. El contrato (regla C3) limita lo que cargas:

- **Nada de recursos de otro dominio**: ni un `<script>` o `<link>` con `https://…`, ni un `@import`, ni una imagen de fuera en una hoja.
- **Peso**: los recursos del plugin juntos, hasta 60 KB; por encima de 60 KB es un aviso («Los recursos pesan … KB; por encima de 60 KB es mucho») y por encima de 200 KB, un fallo.
- En producción, `php aifec assets:publish` copia los recursos a `public/assets/`.

## Dirección amigable

Una página con su propia dirección (`/ejemplo/como-cuidar-tu-silla`) se registra en la tabla de direcciones del núcleo. Dos piezas:

```php
// hooks.php: dice quién pinta ese tipo de entidad (recibe $params['id'])
$app->entity(Pages::TYPE, [PageController::class, 'show']);

// al guardar la página: registra su dirección en el idioma, y devuelve la que queda de verdad
$this->app->slugs()->set(self::TYPE, $id, $this->app->defaultLang(), 'ejemplo/' . $slug);
```

`Pages::create()` calcula `$slug` con `Aifec\Util\Str::slug($titulo, 80)`, que quita tildes y signos: «Cómo cuidar tu silla» da `como-cuidar-tu-silla`. Si otra entidad ya usa esa dirección, `set()` añade `-2`, `-3`… Para enlazar: `$app->url()->entity('ejemplo_page', $id)`.

## La caché de página

La tienda guarda las páginas completas y las sirve **sin arrancar la aplicación** a quien no tiene la sesión del panel abierta. Tu controlador la activa con:

```php
->cacheable(600, ['ejemplo_page:' . $id])
```

Los segundos de vida y las **etiquetas** de la página. Cuando cambia el dato, avisas a la caché con las mismas etiquetas:

```php
$this->app->invalidate(['ejemplo_page:' . $id]);
```

Reglas:

- Solo se guarda una respuesta `GET` que no ponga cookies.
- **Nada personal en el HTML**: la misma copia se sirve a todo el mundo. El contador del carrito lo rellena el JavaScript del tema leyendo una cookie.
- Una página de datos que cambian (una lista, un contador) lleva las etiquetas de lo que muestra: así se renueva sola.
- El contrato exige que una página que era cacheable lo siga siendo con tu plugin activo. Si tu hueco hace que la ficha deje de guardarse, falla («La página … deja de poder guardarse en caché con el plugin»).
- Con un recurso del panel, `'tags' => ['catalog']` renueva las fichas y listados al guardar.

## Probar una página

```php
function ejemploSite(): App
{
    $active = ['plugins', 'ejemplo-tienda'];
    $app = testApp(['modules' => $active, 'settings' => ['modules.active' => $active]]);
    $app->migrate(true);
    $app->boot();

    return $app;
}

test('la ruta fija pinta la página dentro del tema, con el título escapado y la hoja del plugin', function (): void {
    $app = ejemploSite();
    $id = (new Pages($app))->create('Garantía & envíos <rápidos>', "Primera línea\nSegunda línea");
    $r = (new TestClient($app))->get('/ejemplo-tienda/' . $id);
    same(200, $r->status);
    contains('<h1 class="af-title">Garantía &amp; envíos &lt;rápidos&gt;</h1>', $r->body);
    notContains('<rápidos>', $r->body);
    contains('Primera línea<br />', $r->body);
    contains('rel="canonical"', $r->body, 'page_head() la pone el tema');
    contains('/assets/m/ejemplo-tienda/', $r->body);
    contains('page.css', $r->body);
});

test('la respuesta se puede guardar en la caché de página con su etiqueta', function (): void {
    $app = ejemploSite();
    $id = (new Pages($app))->create('Una página', 'Texto');
    $r = (new TestClient($app))->get('/ejemplo-tienda/' . $id);
    same(600, $r->cacheTtl);
    same(['ejemplo_page:' . $id], $r->cacheTags);
});

test('la dirección amigable lleva a la misma página y una página que no existe da 404', function (): void {
    $app = ejemploSite();
    $id = (new Pages($app))->create('Cómo cuidar tu silla', 'Cuerpo');
    same('ejemplo/como-cuidar-tu-silla', $app->slugs()->path(Pages::TYPE, $id, 'es'));
    $web = new TestClient($app);
    $friendly = $web->get('/ejemplo/como-cuidar-tu-silla');
    same(200, $friendly->status);
    contains('Cómo cuidar tu silla', $friendly->body);
    same(404, $web->get('/ejemplo-tienda/9999')->status);
    same(404, $web->get('/ejemplo/no-existe')->status);
});
```

`$r->cacheTtl` y `$r->cacheTags` dicen lo que el controlador pidió a la caché, sin necesidad de servidor.

## Lo que carga tu página y la política de contenido

Las páginas de la tienda y del panel llevan una política de seguridad de contenido estricta (ver «Seguridad» en la guía de operación). Para un plugin significa tres cosas:

1. **Tus guiones van en ficheros** de `assets/`, cargados con `<script src>`. Nada de `onclick="…"` en las plantillas ni de enlaces `javascript:`: el navegador no los ejecuta. Los eventos se conectan desde tu fichero, por delegación.
2. **Si necesitas un guion escrito en la página**, declara su huella con el filtro `csp`. El texto tiene que ser, letra por letra, lo que va entre `<script>` y `</script>`:

```php
$app->hooks()->filter('csp', static function ($csp) {
    if ($csp instanceof \Aifec\Kernel\Csp) {
        $csp->inline(MiPlugin::inlineJs());
    }

    return $csp;
});
```

3. **Si cargas algo de otra web** (un mapa, un reproductor, una pasarela con su propio guion), decláralo en la directiva que toca:

```php
$app->hooks()->filter('csp', static function ($csp) {
    if ($csp instanceof \Aifec\Kernel\Csp) {
        $csp->add('script-src', 'https://js.pasarela.example')
            ->add('frame-src', 'https://js.pasarela.example')
            ->add('connect-src', 'https://api.pasarela.example');
    }

    return $csp;
});
```

`$csp->relax()` quita el límite a los guiones: es para quien carga un gestor de etiquetas, que escribe los suyos. No lo uses para salir del paso; declara tus orígenes.

La cabecera se guarda con la página en la caché: si lo que declaras depende de un ajuste, vacía la etiqueta `pages` al guardarlo.

## Si algo no sale

- **Mi guion no se ejecuta y no hay error en el servidor.** Mira la consola del navegador: «Refused to execute inline script…» es la política de contenido. Pásalo a un fichero o declara su huella.
- **La ruta da 404.** El plugin no está activo, o `routes.php` no devuelve la función. Tras activarlo lanza `php aifec cache:clear`.
- **La página sale sin el diseño del tema.** Falta `$this->layout('layout', ['page' => $page])` al principio de la plantilla.
- **Cambio el dato y la página sigue igual.** No avisas a la caché: llama a `$app->invalidate([...])` con las etiquetas de la página al guardar.
- **La hoja del plugin no carga.** El fichero debe estar en `assets/` y la dirección salir de `url()->asset('m', '<id>', '<fichero>')`. En producción, vuelve a lanzar `assets:publish`.

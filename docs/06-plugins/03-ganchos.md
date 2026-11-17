# Ganchos

Los ganchos son la forma de ampliar AIFEC sin tocar el núcleo. Un módulo o el núcleo anuncia un punto («se ha pagado un pedido», «voy a calcular los datos de un producto», «aquí va un hueco bajo el resumen»); tu plugin se engancha y hace lo suyo. Hay tres clases, y los que tocan dinero están sellados: solo entra un plugin con una capacidad concedida.

La lista completa de ganchos que existen, con dónde se disparan y quién los escucha, está en [Referencia de ganchos](../07-referencia/03-ganchos.md). Esta página enseña cómo se escucha cada clase.

## Las tres clases

| Clase | Escuchar | Disparar | Para qué |
|---|---|---|---|
| Acción | `$app->hooks()->on('nombre', $oyente)` | `fire('nombre', …)` | «Ha pasado esto». El oyente no devuelve nada. |
| Filtro | `$app->hooks()->filter('nombre', $oyente)` | `apply('nombre', $valor, …)` | «¿Quieres cambiar este valor?». El oyente recibe el valor, lo cambia y lo **devuelve**. |
| Hueco | `$app->hooks()->slot('nombre', $oyente)` | `render('nombre', …)` | Un trozo de HTML en un punto de la página. El oyente devuelve un `string` ya escapado. |

Todo se hace en el `hooks.php` del plugin, que recibe la aplicación:

```php
return static function (App $app): void {
    $app->hooks()->on('order.paid', static function ($order) use ($app): void { … });
};
```

El oyente es cualquier función que se pueda llamar: una función anónima, `[Clase::class, 'metodo']`…

## Una acción

Es lo que hace Avisos de pedido (ver [Tareas, correo e IA](07-tareas-correo-e-ia.md)): cuando se paga un pedido, encola un trabajo.

```php
$app->hooks()->on('order.paid', static function ($order) use ($app): void {
    if (!is_array($order) || !isset($order['id'])) {
        return;
    }
    $app->service('jobs')->push('avisos_pedido.notify', ['order_id' => (int) $order['id']], ['unique' => 'avisos_pedido:' . (int) $order['id']]);
});
```

Acciones de uso habitual y lo que reciben: `order.placed` (`array $pedido`), `order.paid` (`array $pedido`), `order.status` (`array $pedido, string $de, string $a`), `order.cancelled`, `order.refunded` (`array $pedido, array $devolución`), `cart.changed` (`array $carrito`), `settings.saved` (`string $módulo, array $claves, App $app`) y `admin.resource.saved` (`string $recurso, $id, array $valores, ?array $antes`).

## Un filtro

Un filtro recibe el valor y los argumentos de contexto, y devuelve el valor:

```php
$app->hooks()->filter('product.extra', static function ($extra, $product) use ($app): array {
    $extra = is_array($extra) ? $extra : [];
    $extra['made_in'] = ['label' => t('Made in'), 'value' => 'España', 'type' => 'text', 'public' => true];

    return $extra;
});
```

Es el gancho del plugin de ejemplo `hello-aifec`, que se entrega con el sistema. Siempre:

- Comprueba lo que recibes (`is_array`) y devuelve **el mismo tipo**.
- Devuelve el valor aunque no lo cambies. Un filtro que no devuelve nada deja el valor en `null`.
- Si lanza una excepción, se anota y se conserva el valor que había: el sitio sigue.

Filtros habituales: `product.extra` (datos tipados del producto; ver el contrato), `listing.query` (los parámetros de un listado), `menu.items` (`$items, $ubicación, $app`), `home.sections` (`$secciones, $app`), `page.meta` (`$página, $app`: título, descripción, datos estructurados), `mail.templates` (`$plantillas, $app`) y `request.start` (`null, $petición, $app`; puede devolver una `Response` y cortar la petición).

## Un hueco

Un hueco devuelve HTML. Consejos de producto rellena el de la ficha:

```php
$app->hooks()->slot('product.summary.after', static function ($product) use ($app): string {
    // …
    return $app->view()->render('consejos-producto::front/tip', ['title' => $tips->title(), 'text' => $text]);
});
```

Los huecos pertenecen a las plantillas de los temas y de los módulos: `product.summary.after`, `cart.totals.after`, `checkout.review.after`, `header.actions`, `body.end`… El tema decide dónde los pinta; tú rellenas lo que va dentro. Los que existen y qué argumentos reciben están en [Huecos y datos](../05-temas/05-huecos-y-datos.md). Un hueco devuelve un `string`; si no hay nada que enseñar, devuelve `''`.

## Prioridad

Cada método admite un tercer argumento, la prioridad (10 por defecto). **Los oyentes de número menor se ejecutan antes.**

```php
$app->hooks()->filter('product.extra', $miOyente, 5);   // antes que los de prioridad 10
```

## Si un oyente lanza

Cada oyente queda atribuido a su plugin. Si lanza una excepción, se anota en el registro y los demás siguen: un plugin que falla no tumba la tienda. En las pruebas, en cambio, el fallo se convierte en error de la prueba; ver [Pruebas](09-pruebas.md).

## Ganchos sellados y capacidades

Cinco filtros protegen la única verdad del dinero: el precio, el stock, los impuestos y los plazos.

| Gancho sellado | Qué controla |
|---|---|
| `product.price` | El precio de un producto. |
| `product.availability` | La disponibilidad. |
| `tax.rate` | El tipo de impuesto. |
| `shipping.options` | Las opciones de envío y sus precios. |
| `cart.adjustments` | Las líneas de ajuste del carrito. |

Todos exigen la **capacidad `pricing`**. Se concede en tres pasos:

1. **El plugin la declara** en `plugin.json`: `"capabilities": ["pricing"]`. Sin declararla, el validador falla con «Se engancha al gancho sellado «product.price» sin declarar la capacidad «pricing»».
2. **El administrador la concede**: `php aifec plugin:grant ecotasa pricing`, o el botón «Conceder «pricing»» en la fila del plugin. Para retirarla, `--off` o «Retirar «pricing»». Pedir una capacidad que el plugin no declara da `«ecotasa» no pide la capacidad «otra»`.
3. **El plugin se engancha.** Mientras no esté concedida, el filtro **no se engancha**: no hay error visible, el sistema lo anota como «Sealed hook» en el registro de errores de ganchos.

Y una regla de fondo: nunca se cambia un precio a escondidas. Un plugin con `pricing` añade una **línea de ajuste con nombre**, con su origen y su importe en céntimos, que el cliente ve en el desglose. Ecotasa es el ejemplo mínimo:

```php
$app->hooks()->filter('cart.adjustments', static function ($adjustments, $summary) use ($app): array {
    $adjustments = is_array($adjustments) ? $adjustments : [];
    $cents = $app->settings()->int('ecotasa.cents', 150);
    if ($cents > 0 && $app->settings()->bool('ecotasa.enabled', true) && is_array($summary) && !empty($summary['count'])) {
        $adjustments[] = ['label' => t('Eco fee'), 'amount' => $cents, 'source' => 'ecotasa'];
    }

    return $adjustments;
});
```

Una línea de ajuste lleva `label` (el nombre que ve el cliente), `amount` (céntimos, positivo o negativo), `source` (quién la pone) y, si quieres, `tax_rate` y `tax_name`; sin ellos usa el impuesto general. Un ajuste de importe 0 se descarta, y uno negativo que dejaría el carrito por debajo de cero no se aplica.

La prueba de Ecotasa comprueba las dos cosas, con y sin concesión:

```php
test('con la capacidad concedida, la tasa sale como una línea de ajuste con su nombre y su origen', function (): void {
    $totals = ecotasaCart(ecotasaSite(true))['totals'];
    same(1, count($totals['adjustments']));
    same('Ecotasa', $totals['adjustments'][0]['label']);
    same('ecotasa', $totals['adjustments'][0]['source']);
    same(150, $totals['adjustment_total']);
    same(1150, $totals['total']);
});

test('sin la capacidad concedida el filtro no se engancha y el carrito no cambia', function (): void {
    expectHookErrors();
    $app = ecotasaSite(false);
    $totals = ecotasaCart($app)['totals'];
    same([], $totals['adjustments']);
    same(1000, $totals['total']);
    $errors = $app->hooks()->errors();
    contains('Sealed hook', $errors[count($errors) - 1]['error']);
});
```

`ecotasaSite()` y `ecotasaCart()` son dos ayudas de la prueba: la primera monta un sitio con el carrito y el plugin activo y concede (o no) `pricing`, con `'plugins.granted' => ['ecotasa:pricing']`; la segunda añade un producto de 10 euros al carrito y devuelve su resumen.

## Comprobar que te has enganchado

Una prueba sencilla para cualquier gancho: pregunta a quién escucha.

```php
check(in_array('avisos-pedido', $app->hooks()->modulesOn('order.paid'), true), 'escucha order.paid');
```

## Si algo no sale

- **Mi filtro de precio no hace nada.** No tiene la capacidad concedida. Mira `plugin:grant` y el registro de errores de ganchos.
- **El validador dice «Escribe en la tabla … que no es suya».** No toques tablas del comercio (`products`, `orders`…) con SQL. Cambia lo que ves con un filtro y, si es dinero, con una línea de ajuste.
- **El hueco aparece vacío.** Tu tema no pinta ese hueco o el oyente devolvió `''`. `php aifec cache:clear` descarta una página guardada.

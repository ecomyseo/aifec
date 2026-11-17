# Rutas

Una ruta une una dirección con el código que la atiende. Esta página explica cómo leer la tabla de rutas fijas del sistema, que se genera del enrutador y no se copia aquí: [Rutas](_generado/rutas.md). También cómo se declara una y qué cosas no están en la tabla.

## Las columnas

| Columna | Qué dice |
|---|---|
| **Área** | `admin` (el panel, bajo `/admin`), `api` (bajo `/api`) o `front` (la tienda). |
| **Métodos** | `GET`, `POST`… A un `GET` le acompaña siempre `HEAD`. |
| **Ruta** | La dirección, relativa al sitio. Los trozos entre llaves son parámetros: `{id}` es cualquier valor y `{id:\d+}` solo números. En la tabla se ven con la barra invertida doblada (`\\d+`) porque es Markdown: la expresión real es `\d+`. |
| **Nombre** | El nombre con el que se enlaza: `route('cart')`. Algunas rutas, sobre todo de acciones del panel, no tienen. |
| **Intermediarios** | Lo que se comprueba antes de llegar al controlador. |

## Los intermediarios

| Intermediario | Qué comprueba |
|---|---|
| `admin` | Que hay una sesión del panel. En los envíos (`POST`), que el origen es el propio sitio y que la ficha del formulario es válida. |
| `customer` | Que hay un cliente con sesión. |
| `csrf` | En los envíos de la tienda, que vienen de la propia web. |
| `api` | El límite de uso y los errores en JSON. |
| `api.gate` | Todo lo que pasa entre una petición a `/api/v1` y su controlador: que la API esté encendida, el origen en las rutas del carrito, la clave, el límite de uso y el formato de los errores. |
| `api.on` | Solo que la API esté encendida (la página de ayuda, el documento OpenAPI). |

Los nombres de las rutas del comercio están reservados (`cart`, `cart.add`, `cart.update`, `checkout`, `order.thanks`, `account`, `account.login`, `search`…): un plugin no debe usarlos.

## Lo que la tabla no tiene

La tabla lista las **rutas fijas**. No están:

- Las **direcciones amigables** de productos, categorías, marcas, páginas y entradas del blog (`/producto/silla`, `/categoria/mesas`). Se resuelven después, en la tabla de direcciones del sistema, y cada una se pinta por el controlador de su tipo. Ver [Arquitectura](01-arquitectura.md).
- Las rutas de **otros idiomas**: el idioma principal no lleva prefijo y los demás empiezan por `/en/…`, `/fr/…`; son las mismas rutas.
- Las rutas de un módulo **apagado**. La tabla refleja lo que hay cargado.
- Los recursos estáticos (`/assets/…`) y los medios, que sirve el servidor web o el servidor de recursos del sistema.

Las direcciones de las pantallas de la tienda (`urls.cart` = `carrito`, `urls.checkout` = `finalizar-compra`, `urls.account` = `mi-cuenta`, `urls.search` = `buscar`) salen de ajustes y se pueden cambiar: por eso se enlaza siempre por el nombre de la ruta y nunca a mano.

## Declarar una ruta

En el `routes.php` de un módulo o plugin:

```php
return static function (Router $r, App $app): void {
    $r->get('/ejemplo-tienda/{id:\d+}', [PageController::class, 'show'])->name('ejemplo_tienda.page');
    $r->group(['prefix' => '/admin/consejos-producto', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('/resumen', [TipsController::class, 'summary']);
    });
};
```

Los dos ejemplos son los que se prueban en [La tienda](../06-plugins/06-tienda.md) y en [El panel](../06-plugins/04-panel.md). Cada ruta nueva sale en la tabla la próxima vez que se genera.

## Para qué sirve la tabla

- Saber **qué dirección está libre** antes de elegir la tuya.
- Ver **qué protege cada ruta**: una acción del panel sin `admin` en la última columna sería un agujero.
- Encontrar el **nombre** de una ruta para enlazarla.
- Comprobar que tu plugin **ha registrado** lo que creías: tras activarlo, y volver a generar la tabla.

## Si algo no sale

- **Mi ruta da 404.** El plugin no está activo, o `routes.php` no devuelve la función, o el arranque está en caché: `php aifec cache:clear`.
- **Mi ruta pisa a otra, o la pisan.** Una dirección fija (sin parámetros) gana siempre a una con parámetros; entre las que llevan parámetros, gana la que se registró primero. Mira la tabla antes de elegir.
- **Responde 405.** La dirección existe, pero no con ese método (un `GET` a una ruta que solo admite `POST`).
- **Un `POST` del panel responde que el formulario ha caducado.** Falta la ficha en el formulario (`Admin::tokenField(app())`) o la sesión ha caducado.

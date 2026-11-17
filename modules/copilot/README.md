# Copiloto del panel

El comerciante escribe lo que quiere («marca el pedido 12 como enviado con el seguimiento 4455», «sube un 5 % los precios de la categoría sillas», «¿dónde cambio el IVA?») y el panel lo **encuentra** o lo **prepara**, le enseña exactamente qué va a cambiar, lo **aplica solo cuando pulsa** y le deja **deshacerlo**.

Pantalla: `/admin/copilot` (entrada «Copiloto» arriba del menú). También el buscador de órdenes (Ctrl + K): un texto que parece una orden ofrece «Hacer: …».

## Cómo añadir una acción (otro módulo o plugin)

Con el filtro `copilot.actions`, desde el `hooks.php` del módulo. Si el módulo no está activo, su acción no existe.

```php
$app->hooks()->filter('copilot.actions', static function ($list, App $app): array {
    $list = is_array($list) ? $list : [];
    $list[] = [
        'id' => 'loyalty.points',                     // área.acción, en minúsculas; el primero que llega con un id lo conserva
        'label' => 'Give loyalty points',            // en inglés y corto: se traduce al enseñarlo
        'description' => 'Gives points to a customer.', // lo que lee un modelo para elegirla
        'permission' => 'loyalty.edit',              // o una función (App, array $args): string
        'params' => [
            ['name' => 'customer', 'type' => 'string', 'required' => true, 'description' => 'Customer email'],
            ['name' => 'points', 'type' => 'int', 'required' => true, 'description' => 'Points to give'],
        ],
        'preview' => [Loyalty::class, 'preview'],    // function (App, array $args): array
        'apply' => [Loyalty::class, 'apply'],        // function (App, array $args): array
        'undo' => [Loyalty::class, 'undo'],          // opcional: function (App, array $undo): array
        'examples' => ['Give 50 points to ana@cliente.test'],
        'understand' => [Loyalty::class, 'read'],    // opcional: entenderla sin IA
    ];

    return $list;
});
```

Tipos de dato: `int`, `money` (céntimos), `percent` (centésimas de punto: 500 = 5 %), `string`, `bool`, `enum` (con `enum` = lista de valores y `labels` opcional) y `date` (`Y-m-d`). Un grupo `one_of` (`[['price', 'delta']]`) pide al menos un dato de cada grupo.

- **`preview`** devuelve `['summary' => frase, 'changes' => [['label', 'from', 'to'], …], 'count' => n, 'warnings' => [], 'errors' => [], 'choices' => [['param', 'value', 'label']], 'links' => [['label', 'url']]]`. No cambia nada. Con `errors` no se deja aplicar; con `choices` (varias lecturas) la propuesta queda incompleta y la pantalla las ofrece. Los enlaces son rutas del propio panel (empiezan por `/`).
- **`apply`** devuelve `['ok' => bool, 'message' => texto, 'undo' => datos o null]`. Escribe con sentencias condicionadas al valor que vio la vista previa (`UPDATE … WHERE precio = ?`) y comprueba cuántas filas cambió.
- **`undo`** recibe lo que guardó `apply` y **solo deshace si lo cambiado sigue como lo dejó la propuesta**; si alguien lo tocó después, devuelve `['ok' => false, 'message' => …]` y no cambia nada.
- **`understand`** (`function (App, string $textoOriginal, string $textoNormalizado): ?array`) devuelve `['args' => datos ya tipados, 'score' => 0-100]` o `null`. El texto normalizado va en minúsculas y sin tildes (`Aifec\Modules\Copilot\Reader`). Por debajo de 60 puntos no cuenta; si dos acciones puntúan parecido, se ofrecen las dos.
- **`readonly` => true**: la acción solo informa y nunca se aplica (así funciona `orders.refund`: el copiloto no devuelve dinero, da el enlace al pedido).

Reglas del copiloto que no se saltan:

- Una propuesta toca como mucho `copilot.max_rows` registros (500).
- Aplicar vuelve a calcular la vista previa; si su huella no es la que vio la persona, **no aplica** y enseña la nueva.
- Cada acción exige además **su permiso**; un cambio en bloque no avisa a clientes; nada se borra.
- Cada aplicar y cada deshacer va a la auditoría (`Admin::audit`).

## Acciones de serie

| Módulo | Acción | Datos |
|---|---|---|
| orders | `orders.status` | `order`, `status`, `note`, `notify` |
| orders | `orders.ship` | `order`, `carrier`, `tracking`, `notify` |
| orders | `orders.note` | `order`, `note`, `public` |
| orders | `orders.refund` (solo enlace) | `order` |
| catalog | `catalog.price` | `product`, `price` \| `delta` \| `percent` |
| catalog | `catalog.bulk_price` | `category` \| `brand` \| `query`, `percent` |
| catalog | `catalog.stock` | `product` (producto o variante), `quantity` \| `delta` |
| catalog | `catalog.visibility` | `product` \| `category` \| `brand` \| `query`, `active` |
| catalog | `catalog.create` | `name`, `price` |
| coupons | `coupons.create` | `code`, `percent` \| `amount`, `ends`, `uses` |
| shipping | `shipping.rate` | `carrier`, `zone`, `rate`, `price` |
| shipping | `shipping.free_from` | `carrier`, `amount` |
| (núcleo) | `settings.set` | `setting`, `value` (lista cerrada) |

La lista cerrada de ajustes (`SettingActions::safe()`): nombre, correo y teléfono de la tienda, productos por página, aviso de stock bajo, enseñar productos agotados, precios con o sin impuestos, compra sin cuenta y registro de clientes. Cada uno exige el permiso de su propia pantalla de ajustes.

## Con IA

Si el servicio `ai` está encendido y `copilot.ai` vale sí, las acciones se le dan al modelo como herramientas (más tres búsquedas de solo lectura: productos, pedidos y categorías). **El modelo solo elige acción y datos**: no aplica nada, lo que busca llega cercado como dato (`Prompt::fence`), la instrucción del sistema es siempre la misma y no lleva nada de la tienda. Si falla, está apagada o no elige ninguna acción, se usan las reglas.

## Ajustes

`copilot.ai` (sí), `copilot.max_rows` (500) y `copilot.history_days` (90; una tarea diaria, `copilot.purge`, borra del historial lo anterior: solo filas del copiloto).

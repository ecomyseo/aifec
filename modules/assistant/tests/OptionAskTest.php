<?php
/**
 * AIFEC — pruebas de la guarda de opciones del asistente: si el cliente pide una talla, un color u otra opción y el producto
 * no la tiene, no se añade nada al carrito, lo diga el modelo o no.
 *
 * Nace de un fallo que vio el usuario en la demo de calzado (13/11/2026): «mi talla es 39, si hay talla añade al carrito»
 * sobre un zapato sin tallas → «¡Perfecto! Ya lo he añadido a tu carrito».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Assistant\OptionAsk;

require_once __DIR__ . '/FastKit.php';

/** Un carrito de mentira que apunta lo que se le añade (y con un producto con variantes y sin decir cuál, falla como el de verdad). */
final class OptionAskCart
{
    /** @var array<int,array<string,int>> */
    public $added = [];

    /** @var int[] productos que exigen variante */
    public $needVariant = [];

    /** @return array<string,mixed> */
    public function summary(): array
    {
        return ['count' => count($this->added), 'lines' => [], 'totals' => ['total' => 0, 'items_total' => 0], 'currency' => 'EUR', 'problems' => []];
    }

    /** @return array<string,mixed> */
    public function add(int $productId, int $variantId, int $qty): array
    {
        if ($variantId === 0 && in_array($productId, $this->needVariant, true)) {
            return ['ok' => false, 'error' => 'Choose an option', 'key' => ''];
        }
        $this->added[] = ['product_id' => $productId, 'variant_id' => $variantId, 'qty' => $qty];

        return ['ok' => true, 'error' => '', 'key' => 'p' . $productId . '-v' . $variantId];
    }
}

/** Lo pedido en un mensaje, como «tipo:valor». */
function optionAskOf(string $message): array
{
    return array_map(static function (array $ask): string {
        return $ask['kind'] . ':' . $ask['value'];
    }, OptionAsk::wanted($message));
}

test('lo que pide el mensaje: tallas con número y con letra, «si hay talla», colores; y nada cuando no pide ninguna opción', function (): void {
    same(['size:39'], optionAskOf('mi talla es 39, si hay talla añade al carrito'), 'la frase del fallo');
    same(['size:39'], optionAskOf('Talla 39'));
    same(['size:39'], optionAskOf('mi talla es la 39'));
    same(['size:42.5'], optionAskOf('lo quiero del número 42,5'));
    same(['size:10'], optionAskOf('size: 10 please'));
    same(['size:m'], optionAskOf('en la talla M'));
    same(['size:xl'], optionAskOf('Size XL'));
    same(['size:unica'], optionAskOf('¿es talla única?'));
    same(['size:'], optionAskOf('si hay talla añádelo'), 'pregunta si la hay, sin decir cuál');
    same(['size:'], optionAskOf('if you have my size, add it'));
    same(['size:'], optionAskOf('añádelo en mi talla'));
    same(['colour:azul'], optionAskOf('lo quiero en azul'));
    same(['colour:negro'], optionAskOf('de color negro'));
    same(['colour:black'], optionAskOf('in black please'));
    same(['size:39', 'colour:azul'], optionAskOf('talla 39 en azul'));
    same([], optionAskOf('añade la silla al carrito'));
    same([], optionAskOf('quiero 2 unidades'));
    same([], optionAskOf('¿llega en 24 horas?'));
    same([], optionAskOf('envíalo en una caja'));
    same([], optionAskOf(''));
});

test('comparar lo pedido con el valor de una opción: sin tildes ni mayúsculas, como palabra entera', function (): void {
    check(OptionAsk::same('39', '39'));
    check(OptionAsk::same('39', '39 EU'));
    check(OptionAsk::same('42.5', '42,5'));
    check(OptionAsk::same('azul', 'Azul marino'));
    check(OptionAsk::same('m', 'M'));
    check(!OptionAsk::same('39', '39.5'), '39 no es 39,5');
    check(!OptionAsk::same('39', '139'));
    check(!OptionAsk::same('m', 'Medium'));
    check(!OptionAsk::same('', 'M'));
    check(OptionAsk::inText('azul', 'Zapato de vestir azul'));
    check(!OptionAsk::inText('39', 'Zapato de vestir Executive marino'));
});

test('el fallo: «mi talla es 39, si hay talla añade al carrito» sobre un producto sin tallas NO añade nada y lo dice', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $shoe = aifecProduct($app, ['name' => 'Zapato de vestir Executive marino', 'price' => 8995, 'stock' => 1]);
    $tools = $app->service('tools');
    $fake = new OptionAskCart();

    $r = $tools->call('cart_add', ['product_id' => $shoe], ['cart' => $fake, 'message' => 'mi talla es 39, si hay talla añade al carrito']);
    check(isset($r['error']), 'es un error: no se ha añadido');
    same(true, $r['no_options']);
    same('talla 39', $r['asked']);
    contains('no tiene tallas', (string) $r['error'], 'el texto que ve el cliente si lo enseña el asistente por reglas');
    contains('Nothing was added', (string) $r['message'], 'y el modelo lo sabe');
    same([], $fake->added, 'el carrito no se ha tocado');

    // Sin pedir ninguna opción, o desde el botón de la tarjeta (sin mensaje), se añade como siempre.
    $ok = $tools->call('cart_add', ['product_id' => $shoe], ['cart' => $fake, 'message' => 'vale, añádelo así']);
    check(!isset($ok['error']), 'se añade');
    same(1, count($fake->added));
    $ok = $tools->call('cart_add', ['product_id' => $shoe], ['cart' => $fake]);
    check(!isset($ok['error']));
});

test('lo que se pide ya es el propio producto («en azul» y se llama «… azul»): se añade', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $blue = aifecProduct($app, ['name' => 'Zapato náutico azul', 'price' => 5000, 'stock' => 4]);
    $fake = new OptionAskCart();
    $r = $app->service('tools')->call('cart_add', ['product_id' => $blue], ['cart' => $fake, 'message' => 'añade el náutico en azul']);
    check(!isset($r['error']), 'se añade');
    $r = $app->service('tools')->call('cart_add', ['product_id' => $blue], ['cart' => $fake, 'message' => 'añade el náutico en rojo']);
    check(isset($r['error']) && !empty($r['no_options']), 'en rojo no existe');
    same(1, count($fake->added));
});

test('con variantes: una talla que no existe no añade y devuelve las que hay; la que existe se añade aunque el modelo mande otra', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $tools = $app->service('tools');
    $variants = [];
    foreach ($app->db()->select('SELECT id, sku FROM {variants} WHERE product_id = ?', [$ids['variantes']]) as $row) {
        $variants[(string) $row['sku']] = (int) $row['id'];
    }
    $fake = new OptionAskCart();
    $fake->needVariant = [$ids['variantes']];

    $none = $tools->call('cart_add', ['product_id' => $ids['variantes'], 'variant_id' => $variants['NUBE-S']], ['cart' => $fake, 'message' => 'lo quiero en la talla M']);
    check(isset($none['error']), 'la M no existe: no se añade la S');
    same('talla M', $none['unavailable']);
    same(['Talla S', 'Talla L'], $none['choices'], 'van las que sí hay');
    same([], $fake->added);

    // Pide la L y el modelo manda la S: la guarda quita la variante y el asistente tiene que resolverla (o preguntar).
    $wrong = $tools->call('cart_add', ['product_id' => $ids['variantes'], 'variant_id' => $variants['NUBE-S']], ['cart' => $fake, 'message' => 'talla L, por favor']);
    check(!isset($wrong['ok']), 'no se añade la S');
    foreach ($fake->added as $line) {
        check((int) $line['variant_id'] !== $variants['NUBE-S'], 'la S no entra');
    }

    $right = $tools->call('cart_add', ['product_id' => $ids['variantes'], 'variant_id' => $variants['NUBE-L']], ['cart' => $fake, 'message' => 'talla L, por favor']);
    check(!isset($right['error']) && !isset($right['need']), 'la L, que es la pedida, se añade');
});

test('en la conversación: el modelo intenta añadir, la tienda no añade, no hay «carrito cambiado» y el carrito sigue vacío', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $shoe = aifecProduct($app, ['name' => 'Zapato de vestir Executive marino', 'price' => 8995, 'stock' => 1]);
    $ai->script(assistantTool('cart_add', ['product_id' => $shoe]), assistantText('Este zapato no tiene tallas para elegir. ¿Lo quieres tal cual?'));

    $r = assistantSay($web, 'mi talla es 39, si hay talla añade al carrito');

    same([], $r['actions'], 'sin «carrito cambiado»');
    same([], assistantCartLines($app, $web), 'el carrito sigue vacío');
    contains('no tiene tallas', (string) $r['text']);
    $result = assistantLastMessage($ai->asked[1])->toolResult['result'];
    contains('no_options', $result, 'el modelo recibe el motivo');
    contains('Nothing was added', $result);
});

// ------------------------------------------------------------------ por reglas (sin IA, o con la IA caída)

/** Una tienda con la zapatilla de colores (negro y rojo se pueden comprar; azul, agotado) y la IA apagada. */
function optionAskRules(): array
{
    [$app, , $ai] = fastShop();
    fastShoe($app);
    $ai->on = false;

    return [$app, new TestClient($app)];
}

test('por reglas: con un solo producto a la vista, «la quiero en rojo, añádela al carrito» añade la roja', function (): void {
    [$app, $web] = optionAskRules();

    $found = assistantSay($web, 'busco la zapatilla plana');
    same(['Zapatilla Plana'], array_column($found['cards'], 'name'), 'un solo producto a la vista');

    $r = assistantSay($web, 'la quiero en rojo, añádela al carrito');

    contains('Añadido a tu carrito: Zapatilla Plana', $r['text']);
    $lines = array_values(assistantCartLines($app, $web));
    same(1, count($lines));
    contains('Rojo', (string) json_encode($lines[0], JSON_UNESCAPED_UNICODE), 'la variante roja');
});

test('por reglas: un color que no hay no añade nada, dice los que sí y los ofrece como botones; el que se elige se añade', function (): void {
    [$app, $web] = optionAskRules();
    assistantSay($web, 'busco la zapatilla plana');

    $r = assistantSay($web, 'si la hay en verde añade al carrito');

    contains('no está disponible', $r['text']);
    contains('Disponibles: Negro, Rojo.', $r['text']);
    notContains('Añadido', $r['text']);
    same([], assistantCartLines($app, $web), 'el carrito sigue vacío');
    same(true, in_array('Negro', $r['suggestions'], true) && in_array('Rojo', $r['suggestions'], true), 'los colores que hay, como botones');

    $r = assistantSay($web, 'Negro');
    contains('Añadido a tu carrito', $r['text']);
    same(1, count(assistantCartLines($app, $web)));
});

test('por reglas: «¿y en azul?» o «¿la tenéis en rojo?» contestan si la hay sin añadir nada, y el botón que se ofrece la añade', function (): void {
    [$app, $web] = optionAskRules();
    assistantSay($web, 'busco la zapatilla plana');

    $sold = assistantSay($web, '¿y en azul?');
    contains('De «Azul» no quedan existencias. Disponibles: ', $sold['text']);
    contains('Negro', $sold['text']);
    contains('Rojo', $sold['text']);
    same([], assistantCartLines($app, $web));

    $none = assistantSay($web, '¿y en amarillo?');
    contains('no está disponible', $none['text']);

    $yes = assistantSay($web, '¿la tenéis en rojo?');
    contains('Sí, «Rojo» está disponible.', $yes['text']);
    same(true, in_array('Añade «Rojo» al carrito', $yes['suggestions'], true));
    same([], assistantCartLines($app, $web), 'preguntar no añade nada');

    $r = assistantSay($web, 'Añade «Rojo» al carrito');
    contains('Añadido a tu carrito', $r['text']);
    same(1, count(assistantCartLines($app, $web)));
});

test('por reglas: quien busca otra cosa («busco otra en azul») no pregunta por el producto que tiene delante', function (): void {
    [, $web] = optionAskRules();
    assistantSay($web, 'busco la zapatilla plana');

    $r = assistantSay($web, 'busco otra silla en azul');

    notContains('no quedan existencias', $r['text']);
    notContains('Disponibles:', $r['text']);
});

test('con IA: al pedir una opción que no hay, la ventana ofrece con botones las que sí, y el modelo no recibe la lista interna', function (): void {
    [$app, , $ai, $web] = fastShop();
    $shoe = fastShoe($app);
    $ai->script(assistantTool('cart_add', ['product_id' => $shoe]), assistantText('En verde no la tenemos. ¿Negra o roja?'));

    $r = assistantSay($web, 'añade la zapatilla plana en verde');

    same(true, in_array('Negro', $r['suggestions'], true) && in_array('Rojo', $r['suggestions'], true));
    same([], assistantCartLines($app, $web));
    $result = assistantLastMessage($ai->asked[1])->toolResult['result'];
    contains('unavailable', $result);
    notContains('"pick"', $result, 'lo de los botones no viaja al modelo');
});

test('la tarjeta del chat trae dos fotos y lo que se puede elegir: los colores que hay, sin el agotado', function (): void {
    [$app, , $ai, $web] = fastShop();
    $shoe = fastShoe($app);
    $two = aifecProduct($app, ['name' => 'Bota Doble Vista', 'price' => 7900, 'stock' => 5, 'images' => 2]);
    searchService($app)->reindexNow();
    $ai->on = false;

    $r = assistantSay($web, 'busco la zapatilla plana');
    $card = $r['cards'][0];
    same($shoe, $card['id']);
    same(1, count($card['options']));
    same('Color', $card['options'][0]['name']);
    $values = $card['options'][0]['values'];
    sort($values);
    same(['Negro', 'Rojo'], $values, 'las opciones que se pueden comprar; el azul está agotado y no sale');
    check(array_key_exists('image2', $card), 'la tarjeta lleva la clave de la segunda foto');

    $r = assistantSay($web, 'busco la bota doble vista');
    $card = $r['cards'][0];
    same($two, $card['id']);
    check($card['image'] !== '' && $card['image2'] !== '' && $card['image'] !== $card['image2'], 'dos fotos distintas');
    contains('https://tienda.test/', $card['image2'], 'con su dirección completa');
    same([], $card['options'], 'sin opciones, lista vacía');
});

test('la tarjeta del chat pinta las fotos arriba y debajo el nombre, el precio y las opciones; muestra la disponibilidad del catálogo', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__) . '/assets/assistant.js');
    $photos = strpos($js, "'af-assistant-card-photos'");
    $name = strpos($js, "'af-assistant-card-name'");
    $price = strpos($js, "'af-assistant-card-price'");
    $opts = strpos($js, "'af-assistant-card-opts'");
    $actions = strpos($js, "'af-assistant-card-actions'");
    check($photos !== false && $name !== false && $price !== false && $opts !== false && $actions !== false, 'las cinco piezas');
    check($photos < $name && $name < $price && $price < $opts && $opts < $actions, 'en ese orden: fotos, nombre, precio, opciones y botón');
    contains('[safeUrl(data.image), safeUrl(data.image2)]', $js, 'las dos fotos pasan por el filtro de direcciones');
    contains("if (data.availability)", $js, 'se muestra la disponibilidad cuando la informa el catálogo');
    $css = (string) file_get_contents(dirname(__DIR__) . '/assets/assistant.css');
    contains('.af-assistant-card-photos{display:grid;grid-auto-flow:column', $css, 'las fotos, una al lado de la otra');
    check(preg_match('/\.af-assistant-card-now\{[^}]*font-size:1\.3rem[^}]*font-weight:800/', $css) === 1, 'el precio, lo más destacado del texto');
    notContains('grid-template-columns:72px', $css, 'ya no es una miniatura a la izquierda');
    check(preg_match('/\.af-assistant-cards\{([^}]*)\}/', $css, $list) === 1, 'la regla de la lista de fichas');
    notContains('overflow-x', $list[1], 'varias fichas van una debajo de otra: sin carrusel (lo pidió el usuario)');
    notContains('scroll-snap', $css, 'sin carrusel');
});

test('la tarjeta de producto del chat lleva un solo botón: «añadir» si se puede y, si no, «elegir opciones» o «ver»', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__) . '/assets/assistant.js');
    check(preg_match("/if \\(data\\.can_add\\) \\{(.*?)\\} else if \\(link\\) \\{/s", $js, $m) === 1, 'el bloque de botones');
    check(strpos($m[1], "text('view')") === false, 'con «añadir» ya no sale también «ver»');
    contains("var title = el('a', 'af-assistant-card-name', data.name);", $js, 'el nombre lleva a la ficha');
    same(1, substr_count($js, "text('view')"), '«ver» solo cuando no se puede añadir');
});

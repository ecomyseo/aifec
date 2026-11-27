<?php
/**
 * AIFEC — pruebas de las reglas que entienden una frase sin IA: precio (tres formas de decir cada cosa), atributos del catálogo
 * (plural, género, sin tildes, mayúsculas), medidas, categoría y marca, existencias, oferta, orden, negación, sinónimos del
 * ajuste, frases combinadas, erratas, y que un valor que no existe en el catálogo nunca se convierte en filtro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Search\Understand;

require_once __DIR__ . '/helpers.php';

/**
 * La ficha de precio que se entiende de una frase: [mínimo, máximo] en céntimos.
 *
 * @return array{0:?int,1:?int}|null
 */
function askPrice(\Aifec\Kernel\App $app, string $q): ?array
{
    foreach (searchService($app)->asker()->plan($q, 'es', false)['filters'] as $filter) {
        if ($filter['key'] === 'price') {
            return [$filter['min'], $filter['max']];
        }
    }

    return null;
}

test('un importe se lee como se escribe en castellano: coma decimal, punto de millares', function (): void {
    same(20000, Understand::cents('200'));
    same(4999, Understand::cents('49,99'));
    same(4950, Understand::cents('49,5'));
    same(150000, Understand::cents('1.500'));
    same(150050, Understand::cents('1.500,50'));
    same(4999, Understand::cents('49.99'), 'el punto con dos decimales es un decimal');
    same(null, Understand::cents('abc'));
    same(null, Understand::cents(''));
});

test('precio máximo: «menos de», «hasta» y «por debajo de» dicen lo mismo, con o sin el euro', function (): void {
    $app = searchApp();
    askShop($app);
    foreach (['silla menos de 200 €', 'silla hasta 200 euros', 'silla por debajo de 200', 'silla como máximo 200€'] as $q) {
        same([null, 20000], askPrice($app, $q), $q);
    }
    $u = askUnderstood($app, 'silla menos de 200 €');
    same('Precio: menos de 200 €', preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $u['price'])), 'la ficha para enseñar');
});

test('precio mínimo: «más de», «desde» y «a partir de»', function (): void {
    $app = searchApp();
    askShop($app);
    foreach (['silla más de 300', 'silla desde 300 €', 'silla a partir de 300 euros', 'silla mas de 300'] as $q) {
        same([30000, null], askPrice($app, $q), $q);
    }
});

test('un rango: «entre 50 y 100», con decimales y en cualquier orden', function (): void {
    $app = searchApp();
    askShop($app);
    same([5000, 10000], askPrice($app, 'silla entre 50 y 100 €'));
    same([5050, 10000], askPrice($app, 'silla entre 50,50 y 100 euros'));
    same([5000, 10000], askPrice($app, 'silla entre 100 y 50'), 'el menor es el mínimo');
    same([5000, 10000], askPrice($app, 'silla más de 50 menos de 100'), 'dos límites sueltos son un solo rango');
});

test('el presupuesto aproximado exige moneda y respeta ambos extremos en céntimos', function (): void {
    $app = searchApp();
    askShop($app);
    same([3000, 4600], askPrice($app, 'silla de unos 40 €'));
    same([3001, 4601], askPrice($app, 'silla en torno a 40,01 euros'));
    same(null, askPrice($app, 'talla de unos 40'));
});

test('un número sin «menos de» ni «más de» no es un precio', function (): void {
    $app = searchApp();
    askShop($app);
    same(null, askPrice($app, 'silla 200'));
    same(null, askPrice($app, 'modelo 200 euros'));
});

test('barato: tres formas, y es un orden, no un filtro', function (): void {
    $app = searchApp();
    askShop($app);
    foreach (['silla barata', 'la silla más barata', 'sillas baratas', 'lo más barato en sillas'] as $q) {
        $u = askUnderstood($app, $q);
        check(isset($u['sort']), $q . ': entiende el orden');
        same('Ordenado por: Precio: de menor a mayor', $u['sort'], $q);
        check(!isset($u['price']), $q . ': no es un precio');
    }
});

test('oferta: «rebajado», «en oferta» y «ofertas»', function (): void {
    $app = searchApp();
    askShop($app);
    foreach (['sillón rebajado', 'sillón en oferta', 'ofertas de sillón'] as $q) {
        $u = askUnderstood($app, $q);
        check(isset($u['sale']), $q);
        same('En oferta', $u['sale'], $q);
        notContains('oferta', $u['_text'], $q . ': las palabras de la ficha no quedan en el texto');
    }
    same('sillón', askUnderstood($app, 'sillón rebajado')['_text'], 'lo que queda es el texto');
});

test('existencias: «en stock», «disponible ya» y «con existencias»', function (): void {
    $app = searchApp();
    askShop($app);
    foreach (['silla en stock', 'silla disponible ya', 'silla con existencias'] as $q) {
        $u = askUnderstood($app, $q);
        check(isset($u['stock']), $q);
        same('En stock', $u['stock'], $q);
        same('silla', $u['_text'], $q);
    }
});

test('orden: novedades y más vendidos', function (): void {
    $app = searchApp();
    askShop($app);
    same('Ordenado por: Novedades', askUnderstood($app, 'novedades en sillas')['sort']);
    same('Ordenado por: Novedades', askUnderstood($app, 'lo nuevo en sillas')['sort']);
    same('Ordenado por: Más vendidos', askUnderstood($app, 'sillas más vendidas')['sort']);
    same('Ordenado por: Más vendidos', askUnderstood($app, 'las sillas más vendidos')['sort']);
});

test('«mejor valorados» solo se entiende si la tienda tiene valoraciones', function (): void {
    $app = searchApp();
    askShop($app);
    same('Ordenado por: Mejor valorados', askUnderstood($app, 'sillas mejor valoradas')['sort'], 'con valoraciones (la silla premium tiene)');
    $sin = searchApp();
    aifecProduct($sin, ['name' => 'Silla sencilla']);
    searchService($sin)->reindexNow();
    $u = askUnderstood($sin, 'sillas mejor valoradas');
    check(!isset($u['sort']), 'sin valoraciones no es un orden');
    contains('valoradas', $u['_text'], 'y las palabras se quedan para el buscador');
});

test('un valor del catálogo se entiende en singular, plural, género, mayúsculas y sin tildes', function (): void {
    $app = searchApp();
    askShop($app);
    foreach (['silla negra', 'sillas negras', 'SILLA NEGRO', 'silla Negra', 'negros'] as $q) {
        $u = askUnderstood($app, $q);
        check(isset($u['attr:color']), $q . ': entiende el color');
        same('Color: Negro', $u['attr:color'], $q);
    }
    same('silla', askUnderstood($app, 'silla negra')['_text']);
    same('Color: Café', askUnderstood($app, 'lámpara CAFE')['attr:color'], 'sin tilde se entiende un valor con tilde');
    same('Color: Gris perla', askUnderstood($app, 'sillón gris perla')['attr:color'], 'un valor de dos palabras');
    same('Material: Cuero', askUnderstood($app, 'sillón de cuero')['attr:material']);
});

test('un valor que no existe en el catálogo no se convierte en filtro', function (): void {
    $app = searchApp();
    askShop($app);
    $u = askUnderstood($app, 'silla fucsia');
    check(!isset($u['attr:color']), 'fucsia no es un color de esta tienda');
    same(['_text'], array_keys($u), 'ninguna ficha');
    same('silla fucsia', $u['_text']);
    $material = askUnderstood($app, 'silla de titanio');
    same(['_text'], array_keys($material));
});

test('medidas: un número con su unidad contra un atributo numérico del catálogo', function (): void {
    $app = searchApp();
    $ids = askShop($app);
    $plan = searchService($app)->asker()->plan('mesa de 180 cm', 'es', false);
    $keys = array_map(static function (array $f): string {
        return $f['key'];
    }, $plan['filters']);
    check(in_array('number:largo', $keys, true), implode(',', $keys));
    same([['code' => 'largo', 'op' => '=', 'value' => 180.0]], $plan['restrict']['numbers']);
    same('Largo: 180 cm', $plan['filters'][0]['label']);
    $max = searchService($app)->asker()->plan('mesa hasta 100 cm', 'es', false);
    same('<=', $max['restrict']['numbers'][0]['op']);
    same('Largo: hasta 100 cm', $max['filters'][0]['label']);
    $min = searchService($app)->asker()->plan('mesa más de 100 centímetros', 'es', false);
    same('>=', $min['restrict']['numbers'][0]['op']);
    $kilos = searchService($app)->asker()->plan('silla hasta 10 kilos', 'es', false);
    same('peso', $kilos['restrict']['numbers'][0]['code'], '«kilos» es la unidad del peso');
    same(0, count(searchService($app)->asker()->plan('mesa de 180 pulgadas', 'es', false)['restrict']['numbers']), 'una unidad que ningún atributo usa no entiende nada');
    unset($ids);
});

test('categoría y marca por su nombre, solo si la frase dice algo más', function (): void {
    $app = searchApp();
    $ids = askShop($app);
    $u = askUnderstood($app, 'sillas de oficina nordika negras');
    check(isset($u['category'], $u['brand'], $u['attr:color']), 'categoría, marca y color: ' . implode(',', array_keys($u)));
    same('Categoría: Sillas de oficina', $u['category']);
    same('Marca: Nordika', $u['brand']);
    $plan = searchService($app)->asker()->plan('sillas de oficina negras', 'es', false);
    same($ids['cat']['oficina'], $plan['catalog']['category_id']);
    same(null, $plan['catalog']['brand_id']);
    // Solo el nombre: es una búsqueda de texto, no un filtro.
    foreach (['silla de oficina', 'sillas', 'nordika', 'mesas'] as $q) {
        $alone = askUnderstood($app, $q);
        same(['_text'], array_keys($alone), $q . ': sin fichas');
        same($q, $alone['_text']);
    }
    $two = askUnderstood($app, 'nordika sillas');
    check(isset($two['category'], $two['brand']), 'dos nombres juntos sí son filtros');
});

test('«sin cuero»: lo que lleve esa palabra no sale', function (): void {
    $app = searchApp();
    askShop($app);
    $plan = searchService($app)->asker()->plan('sillón sin cuero', 'es', false);
    same(['cuero'], $plan['restrict']['without']);
    same('Sin: cuero', $plan['filters'][0]['label']);
    same('sillón', $plan['text']);
    same([], searchService($app)->asker()->plan('sillón sin ', 'es', false)['restrict']['without'], '«sin» sin nada detrás no excluye');
});

test('los sinónimos del ajuste «search.synonyms» valen para las reglas', function (): void {
    $app = searchApp(['search.synonyms' => "barato = chollo, ganga\n# un comentario\nnegro = oscuro"]);
    askShop($app);
    same('Ordenado por: Precio: de menor a mayor', askUnderstood($app, 'silla chollo')['sort']);
    same('Ordenado por: Precio: de menor a mayor', askUnderstood($app, 'silla ganga')['sort']);
    same('Color: Negro', askUnderstood($app, 'silla oscuro')['attr:color'], 'un alias de un valor');
    check(!isset(askUnderstood($app, 'silla oscura')['attr:color']), 'un alias solo vale con la forma exacta que se escribió');
});

test('un sinónimo de varias palabras cambia las palabras y recuerda de dónde salió', function (): void {
    $app = searchApp(['search.synonyms' => 'en oferta = de saldo, a precio de saldo']);
    askShop($app);
    $plan = searchService($app)->asker()->plan('sillón a precio de saldo', 'es', false);
    check(isset($plan['catalog']) && $plan['catalog']['on_sale'], 'se entiende como «en oferta»');
    same('sillón', $plan['text']);
    same('sillón', $plan['filters'][0]['remove_query'], 'quitar la ficha quita las palabras que la produjeron');
});

test('frases combinadas: sillas negras de oficina baratas en stock', function (): void {
    $app = searchApp();
    $ids = askShop($app);
    $u = askUnderstood($app, 'sillas negras de oficina baratas en stock');
    check(isset($u['attr:color'], $u['sort'], $u['stock']), implode(',', array_keys($u)));
    same('Color: Negro', $u['attr:color']);
    $plan = searchService($app)->asker()->plan('silla de oficina negra por menos de 200 €', 'es', false);
    same(['price', 'attr:color', 'category'], array_values(array_filter(array_map(static function (array $f): string {
        return $f['key'];
    }, $plan['filters']), static function (string $k): bool {
        return in_array($k, ['price', 'attr:color', 'category'], true);
    })), 'precio, color y categoría de una sola frase');
    same(20000, $plan['catalog']['price_max']);
    same(['color' => ['negro']], $plan['catalog']['filters']);
    same($ids['cat']['oficina'], $plan['catalog']['category_id'], 'la categoría más larga gana a «Sillas»');
});

test('erratas: lo que no se entiende queda para el buscador, que lo corrige', function (): void {
    $app = searchApp();
    askShop($app);
    $u = askUnderstood($app, 'sila negra');
    same('Color: Negro', $u['attr:color']);
    same('sila', $u['_text'], '«sila» llega tal cual al buscador, que sabe corregirla');
    $result = searchService($app)->ask('sila negra', ['ai' => false]);
    same('silla', $result['understood']['corrected']);
    check($result['total'] >= 3, 'y encuentra sillas negras: ' . $result['total']);
});

test('mayúsculas y sin tildes dan lo mismo', function (): void {
    $app = searchApp();
    askShop($app);
    $a = askUnderstood($app, 'SILLAS NEGRAS POR MENOS DE 200 €');
    $b = askUnderstood($app, 'sillas negras por menos de 200 €');
    same(array_keys($b), array_keys($a));
    $plain = askUnderstood($app, 'sillon por menos de 150 euros');
    same('sillon', $plain['_text']);
});

test('cada ficha sabe qué palabras la produjeron: quitarla deja la frase sin ellas', function (): void {
    $app = searchApp();
    askShop($app);
    $plan = searchService($app)->asker()->plan('silla de oficina negra por menos de 200 €', 'es', false);
    $remove = [];
    foreach ($plan['filters'] as $filter) {
        $remove[$filter['key']] = $filter['remove_query'];
    }
    same('silla de oficina negra', $remove['price']);
    same('silla de oficina por menos de 200 €', $remove['attr:color']);
    foreach ($remove as $key => $query) {
        $again = searchService($app)->asker()->plan($query, 'es', false);
        $keys = array_map(static function (array $f): string {
            return $f['key'];
        }, $again['filters']);
        check(!in_array($key, $keys, true), $key . ': al quitarla ya no sale: ' . implode(',', $keys));
    }
});

test('una frase vacía, o solo de palabras vacías, no revienta ni inventa filtros', function (): void {
    $app = searchApp();
    askShop($app);
    foreach (['', '   ', 'de la', '¿?', '€'] as $q) {
        $plan = searchService($app)->asker()->plan($q, 'es', false);
        same([], $plan['filters'], '«' . $q . '»');
    }
    same('de la', searchService($app)->asker()->plan('de la', 'es', false)['text'], 'lo que son solo palabras vacías se lo deja al buscador');
});

test('texto raro: «&», comillas, tildes, eñes y emojis', function (): void {
    $app = searchApp();
    askShop($app);
    foreach (['silla & mesa', 'silla "negra"', 'niño ñu 🙂 negra', "o'neill negra", 'a\' OR 1=1 --'] as $q) {
        $plan = searchService($app)->asker()->plan($q, 'es', false);
        check(is_array($plan['filters']), $q);
    }
    same('Color: Negro', askUnderstood($app, 'silla "negra"')['attr:color']);
});

test('el vocabulario se construye una vez y se invalida cuando cambia el catálogo', function (): void {
    $app = searchApp();
    askShop($app);
    $search = searchService($app);
    $search->asker()->plan('silla negra', 'es', false);
    $before = $app->db()->queries;
    $search->asker()->plan('sillón rojo', 'es', false);
    $other = $app->db()->queries - $before;
    check($other <= 1, 'la segunda frase no rehace el vocabulario (sale de la caché): ' . $other . ' consultas');
    $none = askUnderstood($app, 'silla violeta');
    check(!isset($none['attr:color']), 'violeta todavía no existe');
    aifecAttribute($app, 'color', 'Color', ['violeta' => 'Violeta']);
    $app->invalidate(['catalog']);
    $after = askUnderstood($app, 'silla violeta');
    same('Color: Violeta', $after['attr:color'], 'al cambiar el catálogo, el vocabulario se rehace');
});

test('las palabras que se reconocen salen del catálogo de idioma: sin traducción, la lista en inglés', function (): void {
    $app = searchApp(['search.synonyms' => '']);
    askShop($app);
    $plan = searchService($app)->asker()->plan('chair under 200 euros in stock', 'fr', false);
    same(20000, $plan['catalog']['price_max'], 'un idioma sin traducción entiende las palabras inglesas');
    check($plan['catalog']['in_stock']);
});

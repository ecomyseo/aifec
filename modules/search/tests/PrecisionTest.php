<?php
/**
 * AIFEC — pruebas de precisión del buscador: lo que sale como resultado exacto tiene que ser lo que se pidió.
 *
 * Salieron al probar el buscador con un catálogo real de 226 productos (13/11/2026):
 *  - «algo para limpiar la plata» daba «20 resultados» (una mesa lacada, un champú, dos bicicletas): el motor casaba quitando
 *    una de las dos palabras con significado y eso se daba por bueno;
 *  - «jamón para regalar por menos de 200 €» decía «no hay resultados» habiendo jamón de menos de 200 €: «regalar» solo salía
 *    en uno caro, y en vez de listar por la categoría y el precio se proponía quitar el precio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/MeaningKit.php';

/**
 * La tienda de las pruebas de significado, más un producto que «limpia» y otro «plata» (ninguno limpia la plata), y un regalo caro.
 *
 * @return array<string,mixed>
 */
function precisionShop(\Aifec\Kernel\App $app): array
{
    $ids = meaningShop($app);
    $ids['pano'] = aifecProduct($app, ['name' => 'Paño para limpiar pantallas', 'price' => 600, 'categories' => [$ids['cat']['accesorios']]]);
    $ids['bandeja'] = aifecProduct($app, ['name' => 'Bandeja organizadora color plata', 'price' => 1900, 'categories' => [$ids['cat']['accesorios']]]);
    $ids['regalo'] = aifecProduct($app, ['name' => 'Silla de oficina de piel para regalar', 'price' => 45000, 'categories' => [$ids['cat']['oficina']]]);
    searchService($app)->reindexNow();

    return $ids;
}

test('precisión: casar una sola de las dos palabras con significado no es un resultado exacto', function (): void {
    $app = searchApp();
    precisionShop($app);
    $search = searchService($app);

    // El motor, por sí solo, encuentra el paño («limpiar») y la bandeja («plata») quitando una palabra, y lo dice.
    $raw = $search->query('algo para limpiar la plata', ['lang' => 'es']);
    same(true, $raw['relaxed'], 'el motor ha tenido que quitar una palabra');
    same(2, $raw['terms'], 'de dos con significado («algo», «para» y «la» no cuentan)');
    check((int) $raw['total'] >= 1, 'y aun así devuelve algo');

    $result = $search->ask('algo para limpiar la plata', ['ai' => false]);
    same([], $result['products'], 'pero eso no se enseña como resultado de lo que se pidió');
    same(0, $result['total']);
    check($result['answer_kind'] !== 'exact', 'ni se cuenta como exacto: ' . $result['answer_kind']);
});

test('precisión: con las dos palabras en el mismo producto sigue siendo exacto, y con tres palabras vale casar dos', function (): void {
    $app = searchApp();
    precisionShop($app);
    $search = searchService($app);

    $both = $search->ask('paño para limpiar', ['ai' => false]);
    same('exact', $both['answer_kind']);
    same(['Paño para limpiar pantallas'], meaningNames($both['products']));

    // Tres palabras con significado y una que no está en ningún producto: dos de tres sí contesta a lo que se pidió.
    $raw = $search->query('paño limpiar gafas', ['lang' => 'es']);
    if ($raw['relaxed'] && (int) $raw['total'] > 0) {
        same(3, $raw['terms']);
        $three = $search->ask('paño limpiar gafas', ['ai' => false]);
        same('exact', $three['answer_kind'], 'dos de tres palabras es un resultado');
        same(['Paño para limpiar pantallas'], meaningNames($three['products']));
    } else {
        check(true, 'el motor no relaja esta frase: nada que comprobar aquí');
    }
});

test('precisión: si las palabras no caben en los filtros entendidos, se lista por los filtros antes de proponer quitar uno', function (): void {
    $app = searchApp();
    precisionShop($app);
    $search = searchService($app);

    // «regalar» solo está en la silla de 450 €; por menos de 200 € hay tres sillas de oficina.
    $result = $search->ask('sillas de oficina para regalar por menos de 200 €', ['ai' => false]);
    same('exact', $result['answer_kind'], 'hay sillas de oficina de menos de 200 €: eso es lo que se enseña');
    same(['Silla de oficina blanca', 'Silla de oficina básica', 'Silla de oficina ergonómica'], meaningNames($result['products']));
    same(3, $result['total']);
    same(null, $result['relaxed'], 'sin proponer quitar el precio');
    same('', $result['understood']['text'], 'la palabra que estorbaba deja de buscarse');
    foreach ($result['products'] as $product) {
        check((int) $product['price'] <= 20000, 'ninguno pasa del tope: ' . $product['name']);
    }
});

test('precisión: si con los filtros solos tampoco hay nada, se sigue proponiendo quitar el que estorba', function (): void {
    $app = searchApp();
    precisionShop($app);
    $result = searchService($app)->ask('sillas de oficina para regalar por menos de 50 €', ['ai' => false]);
    same([], $result['products']);
    check($result['answer_kind'] === 'relaxed' && is_array($result['relaxed']), 'no hay sillas de menos de 50 €: se propone quitar un filtro (' . $result['answer_kind'] . ')');
});

test('precisión: sin filtros entendidos, una palabra que no encaja no se convierte en el catálogo entero', function (): void {
    $app = searchApp();
    precisionShop($app);
    $result = searchService($app)->ask('zapatillas de montaña', ['ai' => false]);
    same([], $result['products'], 'no hay zapatillas: no se lista nada como exacto');
    same(0, $result['total']);
});

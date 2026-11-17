<?php
/**
 * AIFEC — pruebas de «rellénalo de una vez»: el motor entiende un texto escrito de cualquier manera, marca lo dudoso, saca
 * la provincia del código postal, no inventa lo que no está y no guarda nada.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Orders\Checkout\TextReader;

require_once __DIR__ . '/FrontKit.php';

/**
 * Lo que entiende el motor de una frase, con una tienda que tiene un producto físico en el carrito.
 *
 * @return array<string,mixed>
 */
function ckUndRead(string $text): array
{
    $app = frontShop();
    $web = new TestClient($app);
    frontAdd($web, frontProduct($app), 1);

    return aifecCartRun($web, static function ($cart, App $app) use ($text): array {
        return $app->service('checkout')->understand($text);
    });
}

test('entiende una frase en orden normal: nombre, calle con piso, código postal con ciudad, teléfono y correo', function (): void {
    $r = ckUndRead('Ana Ruiz, calle Mayor 1 3ºB, 28013 Madrid, 999000001, ana@correo.test');
    same([
        'email' => 'ana@correo.test', 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'phone' => '999000001', 'line1' => 'Calle Mayor 1', 'line2' => '3ºB',
        'postcode' => '28013', 'city' => 'Madrid', 'region' => '28',
    ], $r['fields']);
    same([], $r['doubts']);
    same([], $r['missing']);
});

test('entiende la misma frase con el orden cambiado', function (): void {
    $r = ckUndRead('28013 Madrid, calle Mayor 1, ana@correo.test, 999000001, Ana Ruiz');
    same('Ana', $r['fields']['first_name']);
    same('Ruiz', $r['fields']['last_name']);
    same('Calle Mayor 1', $r['fields']['line1']);
    same('28013', $r['fields']['postcode']);
    same('Madrid', $r['fields']['city']);
    same('ana@correo.test', $r['fields']['email']);
    same([], $r['missing']);
});

test('entiende una frase escrita sin comas', function (): void {
    $r = ckUndRead('Ana Ruiz calle Mayor 1 3ºB 28013 Madrid 999000001 ana@correo.test');
    same('Ana', $r['fields']['first_name']);
    same('Ruiz', $r['fields']['last_name']);
    same('Calle Mayor 1', $r['fields']['line1']);
    same('3ºB', $r['fields']['line2']);
    same('28013', $r['fields']['postcode']);
    same('Madrid', $r['fields']['city']);
    same('999000001', $r['fields']['phone']);
    same('ana@correo.test', $r['fields']['email']);
    same([], $r['missing']);
});

test('entiende un NIF y un nombre dicho con «me llamo»', function (): void {
    $r = ckUndRead('Me llamo Luis Pérez Gil, 12345678Z, Avenida de la Paz 12, 46800 Xàtiva, 999000002, luis@correo.test');
    same('12345678Z', $r['fields']['vat_id']);
    same('Luis', $r['fields']['first_name']);
    same('Pérez Gil', $r['fields']['last_name']);
    same('Avenida de la Paz 12', $r['fields']['line1']);
    same('Xàtiva', $r['fields']['city']);
    same('46', $r['fields']['region']);
    same([], $r['doubts'], 'un nombre dicho expresamente no es dudoso');
});

test('separa el piso, la puerta o la escalera del número de la calle', function (): void {
    foreach ([
        'Ana Ruiz, calle Mayor 1 3ºB, 28013 Madrid, ana@correo.test' => ['Calle Mayor 1', '3ºB'],
        'Ana Ruiz, Avenida de la Paz 12 piso 2, 28013 Madrid, ana@correo.test' => ['Avenida de la Paz 12', 'piso 2'],
        'Ana Ruiz, c/ Sol 4 bajo A, 28013 Madrid, ana@correo.test' => ['C/ Sol 4', 'bajo A'],
        'Ana Ruiz, calle Mayor 1, 28013 Madrid, ana@correo.test' => ['Calle Mayor 1', null],
    ] as $text => $expected) {
        $r = ckUndRead($text);
        same($expected[0], $r['fields']['line1'], $text);
        if ($expected[1] === null) {
            check(!isset($r['fields']['line2']), 'sin piso no hay segunda línea: ' . $text);
        } else {
            same($expected[1], $r['fields']['line2'], $text);
        }
    }
});

test('entiende un teléfono con espacios y lo deja en nueve cifras', function (): void {
    $r = ckUndRead('Pedro López, calle Sol 4, 35001 Las Palmas, 999 00 00 03, pedro@correo.test');
    same('999000003', $r['fields']['phone']);
    same('35', $r['fields']['region']);
    same('Las Palmas', $r['fields']['city']);
    $prefixed = ckUndRead('Pedro López, calle Sol 4, 35001 Las Palmas, +34 999 000 003, pedro@correo.test');
    same('999000003', $prefixed['fields']['phone'], 'también con el prefijo del país');
});

test('entiende un correo en mayúsculas y lo guarda en minúsculas', function (): void {
    $r = ckUndRead('Ana Ruiz, calle Mayor 1, 28013 Madrid, ANA.RUIZ@Correo.TEST');
    same('ana.ruiz@correo.test', $r['fields']['email']);
});

test('una frase que no trae nada no rellena nada y dice todo lo que falta', function (): void {
    foreach (['hola, quiero una mesa de roble', '', '   ', '😀😀😀'] as $text) {
        $r = ckUndRead($text);
        same([], $r['fields'], 'nada que rellenar: «' . $text . '»');
        same([], $r['doubts']);
        same(['email', 'first_name', 'line1', 'postcode', 'city'], $r['missing']);
    }
});

test('el código postal da siempre la provincia, y la ciudad solo sale si el texto la trae', function (): void {
    $r = ckUndRead('28013');
    same(['postcode' => '28013', 'region' => '28'], $r['fields'], 'la ciudad no se inventa');
    same(['email', 'first_name', 'line1', 'city'], $r['missing']);

    $canarias = ckUndRead('Ana Ruiz, calle Mayor 1, 35001, ana@correo.test');
    same('35', $canarias['fields']['region']);
    check(!isset($canarias['fields']['city']), 'sin ciudad en el texto no hay ciudad');
    same(['city'], $canarias['missing']);
});

test('lo dudoso se marca: un NIF o un código postal que no valen, y los datos que vienen repetidos', function (): void {
    $r = ckUndRead('Ana Ruiz, calle Mayor 1, 99999 Madrid, 999000001, 12345678A');
    same('invalid', $r['doubts']['vat_id']);
    same('invalid', $r['doubts']['postcode']);
    check(!isset($r['fields']['vat_id']) && !isset($r['fields']['postcode']), 'lo que no vale no se rellena');
    same(['email', 'postcode', 'city'], $r['missing']);

    $two = ckUndRead('Ana Ruiz, calle Mayor 1, 28013 Madrid, ana@correo.test, otra@correo.test, 999000001, 999000002');
    same('ambiguous', $two['doubts']['email']);
    same('ambiguous', $two['doubts']['phone']);
    same('ana@correo.test', $two['fields']['email'], 'se toma el primero');

    $badMail = ckUndRead('Ana Ruiz, calle Mayor 1, 28013 Madrid, ana@correo');
    same('invalid', $badMail['doubts']['email']);
});

test('un nombre que se ha deducido sin apellidos se marca como dudoso; uno dicho con «me llamo» no', function (): void {
    $guessed = ckUndRead('Ana, calle Mayor 1, 28013 Madrid, ana@correo.test');
    same('guessed', $guessed['doubts']['first_name']);
    $stated = ckUndRead('Me llamo Ana, calle Mayor 1, 28013 Madrid, ana@correo.test');
    check(!isset($stated['doubts']['first_name']), 'un nombre dicho no es dudoso');
});

test('entender no guarda nada: ni el carrito ni el borrador cambian', function (): void {
    $app = frontShop();
    $web = new TestClient($app);
    frontAdd($web, frontProduct($app), 1);
    $before = $app->db()->row('SELECT checkout, email, destination FROM {carts} WHERE id = 1');
    aifecCartRun($web, static function ($cart, App $app): void {
        $app->service('checkout')->understand('Ana Ruiz, calle Mayor 1, 35001 Las Palmas, 999000001, ana@correo.test');
    });
    same($before, $app->db()->row('SELECT checkout, email, destination FROM {carts} WHERE id = 1'), 'el carrito queda igual');
    $st = aifecCartRun($web, static function ($cart, App $app): array {
        return $app->service('checkout')->state();
    });
    same('', $st['contact']['email']);
    same('', $st['address']['first_name']);
    same(['contact', 'address', 'payment'], $st['missing']);
});

test('lo que falta tiene en cuenta lo que el borrador ya sabe', function (): void {
    $app = frontShop();
    $web = new TestClient($app);
    frontAdd($web, frontProduct($app), 1);
    $r = aifecCartRun($web, static function ($cart, App $app): array {
        $app->service('checkout')->fill(['email' => 'ana@cliente.test', 'first_name' => 'Ana', 'last_name' => 'Ruiz']);

        return $app->service('checkout')->understand('calle Mayor 1, 28013 Madrid');
    });
    same([], $r['missing'], 'el correo y el nombre ya estaban en el borrador');
    same('Calle Mayor 1', $r['fields']['line1']);
});

test('un texto con signos, comillas, tildes, emojis o larguísimo no rompe nada', function (): void {
    $odd = ckUndRead('José "Pepe" Núñez & Co. 😀, calle <Mayor> 1, 28013 Madrid, jose@correo.test');
    check(is_array($odd['fields']) && is_array($odd['doubts']) && is_array($odd['missing']), 'devuelve su forma de siempre');
    same('jose@correo.test', $odd['fields']['email']);

    $long = ckUndRead(str_repeat('calle Mayor 1 ', 600) . ' ana@correo.test');
    check(is_array($long['fields']), 'un texto de más de 2000 caracteres se recorta y se lee');
    $bad = ckUndRead("Ana\xC3\x28 Ruiz, calle Mayor 1, 28013 Madrid, ana@correo.test");
    check(is_array($bad['fields']), 'un texto con bytes que no son UTF-8 tampoco rompe');
});

test('TextReader en una compra solo digital no pide calle ni código postal', function (): void {
    $r = TextReader::read('Ana Ruiz, ana@correo.test', [], false);
    same(['email' => 'ana@correo.test', 'first_name' => 'Ana', 'last_name' => 'Ruiz'], $r['fields']);
    same([], $r['missing']);
    $physical = TextReader::read('Ana Ruiz, ana@correo.test', [], true);
    same(['line1', 'postcode', 'city'], $physical['missing']);
});

// ---------------------------------------------------------------------- segunda ronda de clientes (P088): el piso, la corrección y la ciudad

test('«3.º A» entre comas se guarda en la segunda línea y la ciudad que va con «(España)» y una frase detrás no se pierde', function (): void {
    $r = TextReader::read('Marta Ruiz Peña, marta.ruiz@ejemplo.test, teléfono 999000001, Calle de la Muestra 5, 3.º A, 46001 Valencia (España). Quiero el envío urgente y pago contra reembolso.', [], true);
    same('Calle de la Muestra 5', $r['fields']['line1']);
    same('3º A', $r['fields']['line2'], 'el piso y la puerta no se pierden');
    same('46001', $r['fields']['postcode']);
    same('Valencia', $r['fields']['city']);
    same('Marta', $r['fields']['first_name']);
    same('Ruiz Peña', $r['fields']['last_name']);
    same([], $r['missing'], 'con la ciudad dentro de la dirección no falta nada');
});

test('«2.º B» con «me llamo…» y el país entre paréntesis: la ciudad sale sin el punto final', function (): void {
    $r = TextReader::read('Me llamo Francisco Gil Mora, mi email es paco.gil@ejemplo.test, mi teléfono 999000002 y la dirección es Avenida del Ejemplo 12, 2.º B, 28013 Madrid (España).', [], true);
    same('Madrid', $r['fields']['city'], 'no se vuelve a preguntar la ciudad');
    same('2º B', $r['fields']['line2']);
    same('Francisco', $r['fields']['first_name'], 'quien se presenta conserva su nombre');
    same([], $r['missing']);
});

test('una corrección «uy la dirección está mal, es…» cambia la dirección y no inventa un nombre', function (): void {
    $r = TextReader::read('uy la direccion esta mal, es Plaza Inventada 7, 1.º, 29001 Málaga', ['first_name' => 'Lucia', 'last_name' => 'Navarro Sanz', 'email' => 'lucia.navarro@ejemplo.test'], true);
    check(!isset($r['fields']['first_name']) && !isset($r['fields']['last_name']), 'el texto de antes de «es» no es un nombre: ' . json_encode($r['fields']));
    same('Plaza Inventada 7', $r['fields']['line1']);
    same('1º', $r['fields']['line2']);
    same('29001', $r['fields']['postcode']);
    same('Málaga', $r['fields']['city']);
    same([], $r['missing'], 'el nombre y el correo de antes siguen valiendo');
});

test('otras formas de corregir («no, es calle…», «cámbiala por…») tampoco se leen como un nombre', function (): void {
    $a = TextReader::read('no, es calle Mayor 3, 2º C, 28013 Madrid', [], true);
    check(!isset($a['fields']['first_name']), 'sin nombre: ' . json_encode($a['fields']));
    same('Calle Mayor 3', $a['fields']['line1']);
    same('2º C', $a['fields']['line2']);
    $b = TextReader::read('cámbiala por Calle Luna 4, 3.º izq., 28013 Madrid', [], true);
    check(!isset($b['fields']['first_name']), 'sin nombre: ' . json_encode($b['fields']));
    same('Calle Luna 4', $b['fields']['line1']);
    same('3º izq', $b['fields']['line2'], 'el piso con su puerta');
});

test('«bajo» detrás de la calle es el piso, no el nombre ni se pierde', function (): void {
    $r = TextReader::read('Íñigo Prueba Test, inigo.prueba@ejemplo.test, 999000004, Calle del Test 1, bajo, 48001 Bilbao, España. Contra reembolso.', [], true);
    same('Calle del Test 1', $r['fields']['line1']);
    same('bajo', $r['fields']['line2']);
    same('Íñigo', $r['fields']['first_name']);
    same('Bilbao', $r['fields']['city']);
});

test('«2n 1a» en inglés y con «VAT ID»: el piso se conserva y la ciudad no es «Phone»', function (): void {
    $r = TextReader::read("Hanna Berg, hanna.berg@ejemplo.test, phone +34 999000005, Carrer de l'Exemple 9, 2n 1a, 08001 Barcelona (Spain). Company: Berg Studio SL, VAT ID ESB00000000.", [], true);
    same("Carrer de l'Exemple 9", $r['fields']['line1'], 'la dirección no arrastra el NIF');
    same('2n 1a', $r['fields']['line2']);
    same('Barcelona', $r['fields']['city']);
    same('999000005', $r['fields']['phone']);
});

test('el piso sin comas y con escalera («calle Mayor 1 3.º B»; «esc. B 4.º izq.») también se separa de la calle', function (): void {
    $r = TextReader::read('Ana Ruiz, calle Mayor 1 3.º B, 28013 Madrid, ana@correo.test', [], true);
    same('Calle Mayor 1', $r['fields']['line1']);
    same('3º B', $r['fields']['line2']);
    same('Ana', $r['fields']['first_name']);
    $s = TextReader::read('Ana Ruiz, calle Luna 4, esc. B 4.º izq., 28013 Madrid, ana@correo.test', [], true);
    same('Calle Luna 4', $s['fields']['line1']);
    check(strpos($s['fields']['line2'], 'esc. B') === 0 && strpos($s['fields']['line2'], '4º') !== false, 'la escalera y el piso: ' . $s['fields']['line2']);
});

// ---------------------------------------------------------------------- tercera ronda de clientes (P093): en inglés, con etiquetas y con la empresa

test('Hanna, la frase entera: nombre Hanna Berg (no «Yes»), calle sin «Address», piso 2n 1a, Barcelona, empresa y NIF como se dio', function (): void {
    $r = TextReader::read("Yes, let's go to checkout. My details: Hanna Berg, hanna.berg@ejemplo.test, phone +34 999000005, address Carrer de l'Exemple 9, 2n 1a, 08001 Barcelona (Spain). Invoice to company Berg Studio SL, VAT ID ESB00000000. I want cash on delivery.", [], true);
    same([
        'email' => 'hanna.berg@ejemplo.test', 'first_name' => 'Hanna', 'last_name' => 'Berg', 'phone' => '999000005', 'line1' => "Carrer de l'Exemple 9",
        'line2' => '2n 1a', 'postcode' => '08001', 'city' => 'Barcelona', 'region' => '08', 'company' => 'Berg Studio SL', 'vat_id' => 'ESB00000000',
    ], $r['fields']);
    same([], $r['doubts']);
    same([], $r['missing']);
});

test('etiquetas en inglés con dos puntos y en otro orden, separadas por puntos: cada dato a su campo', function (): void {
    $r = TextReader::read("Name: Hanna Berg. Address: Carrer de l'Exemple 9, 2n 1a. Postcode 08001. City: Barcelona. Phone 999000005. Email hanna.berg@ejemplo.test. Company: Berg Studio SL. VAT: ESB00000000", [], true);
    same('Hanna', $r['fields']['first_name']);
    same('Berg', $r['fields']['last_name']);
    same("Carrer de l'Exemple 9", $r['fields']['line1']);
    same('2n 1a', $r['fields']['line2'], 'el piso detrás de la calle y antes de un punto');
    same('08001', $r['fields']['postcode']);
    same('Barcelona', $r['fields']['city'], 'la ciudad no es «. Email»');
    same('999000005', $r['fields']['phone']);
    same('hanna.berg@ejemplo.test', $r['fields']['email']);
    same('Berg Studio SL', $r['fields']['company']);
    same('ESB00000000', $r['fields']['vat_id']);
});

test('etiquetas en castellano, con y sin dos puntos: «Nombre», «dirección», «CP», «ciudad:», «empresa», «NIF»', function (): void {
    $r = TextReader::read('Nombre Ana Ruiz; dirección: Calle Mayor 1, 3º B; CP 28013; ciudad: Madrid; teléfono 999000001; correo ana@correo.test; empresa Ruiz Muebles SL; NIF B12345674', [], true);
    same([
        'email' => 'ana@correo.test', 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'phone' => '999000001', 'line1' => 'Calle Mayor 1', 'line2' => '3º B',
        'postcode' => '28013', 'city' => 'Madrid', 'region' => '28', 'company' => 'Ruiz Muebles SL', 'vat_id' => 'B12345674',
    ], $r['fields']);
});

test('«Factura a nombre de X» es la empresa, la frase de arranque «Sí, vamos.» no es un nombre y el NIF pierde solo guiones y puntos', function (): void {
    $r = TextReader::read('Sí, vamos. Factura a nombre de Muebles Ruiz SL, NIF B-1234567.4. Ana Ruiz, calle Mayor 1, 28013 Madrid, 999000001, ana@correo.test', [], true);
    same('Muebles Ruiz SL', $r['fields']['company']);
    same('B12345674', $r['fields']['vat_id']);
    same('Ana', $r['fields']['first_name']);
    same('Ruiz', $r['fields']['last_name']);
});

test('una palabra de asentimiento o una etiqueta nunca es un nombre: «Ok. Yes, …» deja el nombre que viene después', function (): void {
    $r = TextReader::read('Ok. Yes, 13001 Ciudad Real, calle Mayor 2, Ana Ruiz, ana@correo.test', [], true);
    same('Ana', $r['fields']['first_name'], 'ni «Ok» ni «Yes»');
    same('Ciudad Real', $r['fields']['city'], 'y «Ciudad Real» no se toma por la etiqueta «ciudad»');
    foreach (['Yes', 'Ok', 'Vale', 'Hola', 'Thanks', 'Address', 'Dirección', 'Name', 'Sí'] as $word) {
        same(false, TextReader::personName($word), $word);
    }
    same(true, TextReader::personName('Hanna Berg'));
    same(true, TextReader::personName('María de la Cruz Díaz'));
});

test('el NIF con etiqueta se guarda como se dio (ESB00000000 no pasa a B00000000) y uno español que no cuadra se marca, no se guarda', function (): void {
    same('ESB00000000', TextReader::read('Hanna Berg, hanna@ejemplo.test, VAT ID ESB00000000', [], false)['fields']['vat_id']);
    same('ESB00000000', TextReader::taxId(' es-b 000.000.00 '));
    $bad = TextReader::read('Ana Ruiz, ana@correo.test, NIF 12345678A', [], false);
    check(!isset($bad['fields']['vat_id']), 'un NIF que no cuadra no se guarda');
    same('invalid', $bad['doubts']['vat_id']);
});

test('«entregar a lucia navarro sanz porfa» es el nombre (sin «porfa») y la conversación («I want cash on delivery») no es ningún dato', function (): void {
    $r = TextReader::read('lucia.navarro@ejemplo.test 999000003 y entregar a lucia navarro sanz porfa', [], true);
    same('Lucia', $r['fields']['first_name']);
    same('Navarro Sanz', $r['fields']['last_name']);
    $talk = TextReader::read('Hanna Berg, hanna@ejemplo.test, Carrer Gran 3, 08001 Barcelona. I want cash on delivery.', [], true);
    same('Barcelona', $talk['fields']['city']);
    check(!isset($talk['fields']['company']), 'nada de empresa inventada');
});

test('un nombre con apellidos y «de la» sigue siendo un nombre, y un «bajo» o un «1º» sueltos sin calle delante no se toman por piso', function (): void {
    $r = TextReader::read('María de la Cruz Díaz, calle Mayor 1, 28013 Madrid, maria@correo.test', [], true);
    same('María', $r['fields']['first_name']);
    same('De la Cruz Díaz', $r['fields']['last_name']);
    check(!isset($r['fields']['line2']), 'sin piso no hay segunda línea');
    $odd = TextReader::read('1º, 28013 Madrid', [], true);
    check(!isset($odd['fields']['line2']), 'un «1º» sin calle delante no es un piso: ' . json_encode($odd['fields']));
});

test('los datos con «+» en vez de espacios y la provincia entre paréntesis se leen enteros', function (): void {
    // 16/11/2026: Claude guardó los datos por enlace y la tienda contestó «no he encontrado ningún dato personal ahí».
    $plus = TextReader::read('Daniel+Soler,+Calle+de+Ejemplo+5,+50001+Zaragoza,+999+000+105,+cliente@correo.test', [], true);
    same('Daniel', $plus['fields']['first_name'] ?? '', json_encode($plus['fields']));
    same('Calle de Ejemplo 5', $plus['fields']['line1'] ?? '');
    same('Zaragoza', $plus['fields']['city'] ?? '');
    same('cliente@correo.test', $plus['fields']['email'] ?? '');
    same([], $plus['missing']);
    $province = TextReader::read('Daniel Soler, Calle de Ejemplo 5, 50001 Zaragoza (Zaragoza), 999 000 105, cliente@correo.test', [], true);
    same('Zaragoza', $province['fields']['city'] ?? '', json_encode($province['fields']));
    same([], $province['missing']);
    $phone = TextReader::read('Ana Ruiz, Calle de Ejemplo 1, 28013 Madrid, +34 999 000 106, ana@cliente.test', [], true);
    check(strpos($phone['fields']['phone'] ?? '', '999000106') !== false, 'un «+» con espacios alrededor no se toca: ' . json_encode($phone['fields']));
    same('Madrid', $phone['fields']['city'] ?? '');
});

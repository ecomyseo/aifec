<?php
/**
 * AIFEC — pruebas del normalizador y del troceador de texto del buscador.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Search\Tokenizer;

test('normalize quita tildes, diéresis y eñes', function (): void {
    $t = new Tokenizer('es');
    same('modulo envios nandu', $t->normalize('Módulo Envíos ÑANDÚ'));
    same('pinguino', $t->normalize('Pingüino'));
});

test('normalize quita signos y colapsa espacios', function (): void {
    $t = new Tokenizer('es');
    same('hola mundo test', $t->normalize("  ¡Hola,   Mundo!\n\t(Test)  "));
    same('tom jerry ok', $t->normalize('Tom & Jerry "ok"'));
    same('se 01', $t->normalize('SE-01'));
});

test('tokens: palabras vacías fuera y plurales a su raíz', function (): void {
    $t = new Tokenizer('es');
    same(['pedido', 'cliente'], $t->tokens('Quiero los pedidos de mis clientes'));
    same(['pagar', 'bizum'], $t->tokens('pagar con bizum'));
});

test('tokens: plurales castellanos (-s, -es y -ces)', function (): void {
    $t = new Tokenizer('es');
    same(['luz'], $t->tokens('luces'));
    same(['motor', 'colchon'], $t->tokens('motores colchones'));
    same(['nombre', 'verde', 'cable'], $t->tokens('nombres verdes cables'));
    same(['ley', 'mes'], $t->tokens('leyes meses'));
});

test('tokens: el género se pliega y los diminutivos corrientes se reducen', function (): void {
    $t = new Tokenizer('es');
    same(['blanco', 'blanco', 'blanco'], $t->tokens('blanca blanco blancas'));
    same(['casa', 'casa'], $t->tokens('casa casas'));
    same(['gato', 'perro'], $t->tokens('gatito perrita'));
    same(['bonito'], $t->tokens('bonito'));
});

test('tokens: un número pegado a su unidad se separa y se conserva unido', function (): void {
    $t = new Tokenizer('es');
    same(['130', 'kg', '130kg'], $t->tokens('130kg'));
    same(['130', 'kg', '130kg'], $t->tokens('130KG'));
    same(['12', 'v', '12v'], $t->tokens('12V'));
    same(['2024'], $t->tokens('2024'));
});

test('tokens: las referencias con guiones o puntos se conservan enteras y troceadas', function (): void {
    $t = new Tokenizer('es');
    same(['se-01', 'se', '01'], $t->tokens('SE-01'));
    same(['v2.5', 'v2', '5'], $t->tokens('v2.5'));
    same(['ref-a-100', 'ref', '100'], $t->tokens('ref-a-100'), 'las letras sueltas se descartan');
});

test('texto vacío, solo signos o emojis no fallan', function (): void {
    $t = new Tokenizer('es');
    same([], $t->tokens(''));
    same([], $t->tokens('¡¿ ... !? --- ()'));
    same([], $t->tokens("😀 🎉 \u{1F680}"));
    same('', $t->normalize('😀'));
    same([], $t->raw(''));
    same([], $t->matching('', 'algo'));
    same([], $t->matching('algo', ''));
});

test('un texto de 5.000 palabras se trocea entero', function (): void {
    $t = new Tokenizer('es');
    $tokens = $t->tokens(str_repeat('pedido cliente ', 2500));
    same(5000, count($tokens));
    same('pedido', $tokens[0]);
    same('cliente', $tokens[4999]);
    same(5000, count($t->raw(str_repeat('los pedidos ', 2500))));
});

test('un texto con UTF-8 inválido no lanza nada', function (): void {
    $t = new Tokenizer('es');
    $texto = "caf\xE9 ok \xFF\xFE fin";
    check(is_string($t->normalize($texto)));
    check(is_array($t->tokens($texto)));
    check(is_array($t->matching('ok', $texto)));
});

test('raw no quita palabras vacías ni reduce a la raíz', function (): void {
    $t = new Tokenizer('es');
    same(['los', 'pedidos', 'de', 'nandu'], $t->raw('Los pedidos de Ñandú'));
});

test('matching devuelve las palabras del texto tal como están escritas', function (): void {
    $t = new Tokenizer('es');
    same(['bots', 'formularios'], $t->matching('bloquear los bots del formulario', 'Bloqueo de bots en formularios'));
    same(['Módulo', 'Envíos'], $t->matching('modulo de envios', 'Módulo de Envíos gratis'));
    same(['bots'], $t->matching('bots bots', 'bots y más bots'), 'sin repetir');
});

test('matching reconoce referencias y medidas', function (): void {
    $t = new Tokenizer('es');
    same(['SE-01'], $t->matching('se-01', 'Silla SE-01 negra'));
    same(['130kg'], $t->matching('130', 'Aguanta 130kg de peso'));
});

test('inglés: plurales, -ing y -ed', function (): void {
    $t = new Tokenizer('en');
    same(['run', 'sho', 'kid'], $t->tokens('Running shoes for kids'));
    same($t->tokens('make'), $t->tokens('making'));
    same($t->tokens('battery'), $t->tokens('batteries'));
    same($t->tokens('cookie'), $t->tokens('cookies'));
    same($t->tokens('box'), $t->tokens('boxes'));
    same($t->tokens('stop'), $t->tokens('stopped'));
});

test('otro idioma: sin palabras vacías ni raíces', function (): void {
    $t = new Tokenizer('fr');
    same(['les', 'chaussures'], $t->tokens('Les Chaussures'));
    same(['les', 'chaussures'], (new Tokenizer('fr-FR'))->tokens('LES chaussures'));
});

test('las dos vías de quitar tildes (intl y tabla propia) coinciden', function (): void {
    $muestra = 'ñandú über çà ø ß æ œ đ crème brûlée ǎ ő ł';
    $conTabla = Tokenizer::stripAccents($muestra, false);
    same('nandu uber ca o ss ae oe d creme brulee a o l', $conTabla);
    same($conTabla, Tokenizer::stripAccents($muestra, true));
    same('', Tokenizer::stripAccents(''));
});

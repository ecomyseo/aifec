<?php
/**
 * AIFEC — pruebas de lo que tiene que cumplir una traducción (marcadores, plurales, HTML) y del glosario.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Translations\Check;
use Aifec\Modules\Translations\Glossary;

require_once __DIR__ . '/TranslationsKit.php';

test('una traducción con los mismos marcadores vale, aunque cambien de sitio', function (): void {
    same(null, Check::problem('Hello {name}, you have {n} items', 'Tienes {n} artículos, {name}'));
    same(null, Check::problem('Add to cart', 'Añadir al carrito'));
});

test('un marcador perdido se rechaza con su motivo', function (): void {
    $problem = Check::problem('Hello {name}', 'Hola');
    same('lost', $problem['code']);
    same('{name}', $problem['vars']['marker']);
    $app = trxApp();
    contains('{name}', Check::message($problem));
    contains('Falta el marcador', Check::message($problem));
});

test('un marcador inventado se rechaza con su motivo', function (): void {
    $problem = Check::problem('Hello', 'Hola {nombre}');
    same('invented', $problem['code']);
    same('{nombre}', $problem['vars']['marker']);
    $app = trxApp();
    contains('no está en el texto original', Check::message($problem));
});

test('un marcador traducido (otro nombre entre llaves) también se rechaza', function (): void {
    same('lost', Check::problem('Hello {name}', 'Hola {nombre}')['code']);
});

test('un plural con otra cantidad de formas se rechaza', function (): void {
    $problem = Check::problem('{n} item|{n} items', '{n} artículos');
    same('forms', $problem['code']);
    same('2', $problem['vars']['n']);
    same('forms', Check::problem('{n} item|{n} items', '{n} a|{n} b|{n} c')['code']);
    $app = trxApp();
    contains('2 formas', Check::message($problem));
});

test('un plural con una forma vacía se rechaza y uno completo vale', function (): void {
    same('forms', Check::problem('{n} item|{n} items', '{n} artículo| ')['code']);
    same(null, Check::problem('{n} item|{n} items', '{n} artículo|{n} artículos'));
    same(null, Check::problem('One item|{n} items', 'Un artículo|{n} artículos'), 'el {n} puede ir solo en una forma, como en el original');
});

test('HTML donde no lo había se rechaza', function (): void {
    same('html', Check::problem('Add to cart', 'Añadir al <b>carrito</b>')['code']);
    same('html', Check::problem('Add to cart', '<script>alert(1)</script>')['code']);
    same(null, Check::problem('Price < 10 and > 5', 'Precio < 10 y > 5'), 'un signo suelto no es una etiqueta');
});

test('en un texto HTML valen sus etiquetas y se rechaza una etiqueta nueva', function (): void {
    same(null, Check::problem('<p>Solid <strong>oak</strong></p>', '<p>Roble <strong>macizo</strong></p>', 'html'));
    $problem = Check::problem('<p>Solid oak</p>', '<p>Roble</p><script>x</script>', 'html');
    same('tags', $problem['code']);
    contains('<script>', $problem['vars']['tags']);
});

test('una traducción vacía o solo con espacios se rechaza', function (): void {
    same('empty', Check::problem('Add to cart', '')['code']);
    same('empty', Check::problem('Add to cart', "  \n ")['code']);
});

test('los caracteres de control se rechazan y los saltos de línea valen', function (): void {
    same('control', Check::problem('Add', "Añadir\x07")['code']);
    same(null, Check::problem("Line one\nLine two", "Línea uno\nLínea dos"));
});

test('una traducción enorme se rechaza', function (): void {
    same('long', Check::problem('Add', str_repeat('a', Check::MAX_LENGTH + 1))['code']);
});

test('«&», comillas, tildes, eñes y emojis pasan tal cual', function (): void {
    same(null, Check::problem('Cart & "more"', 'Cesta & «más» ñandú 🛒'));
    same('Cesta & «más»', Check::clean("  Cesta & «más»\r\n"));
    same("a\nb", Check::clean("a\r\nb"));
});

test('markers() y tags() devuelven cada nombre una vez y en orden', function (): void {
    same(['name', 'n'], Check::markers('{name} {n} {name}'));
    same(['p', 'strong'], Check::tags('<p><strong>x</strong></p><P>y</P>'));
    same([], Check::markers('sin llaves { a } {}'));
});

test('cada problema se cuenta en castellano', function (): void {
    $app = trxApp();
    same('La traducción está vacía', Check::message(['code' => 'empty', 'vars' => []]));
    same('Lleva HTML y el texto original no tiene', Check::message(['code' => 'html', 'vars' => []]));
    same('El nombre Oslo tiene que quedarse como está', Check::message(['code' => 'keep', 'vars' => ['name' => 'Oslo']]));
    same('La traducción no vale', Check::message(['code' => 'otro', 'vars' => []]));
});

test('el glosario: términos con su traducción y nombres que no se traducen; comentarios y líneas vacías fuera', function (): void {
    $parsed = Glossary::parse("carrito = panier\n# un comentario\n\nOslo\n  pedido=commande  \nsolo = \nOslo");
    same(['carrito' => 'panier', 'pedido' => 'commande'], $parsed['map']);
    same(['Oslo'], $parsed['keep']);
});

test('el glosario se guarda por idioma, vacío se borra y viaja ya ordenado', function (): void {
    $app = trxApp();
    Glossary::set($app, 'fr', "carrito = panier\r\nOslo\nAIFEC");
    same("carrito = panier\nOslo\nAIFEC", Glossary::get($app, 'fr'));
    same('', Glossary::get($app, 'de'));
    same("carrito = panier\nNever translate these names: Oslo, AIFEC", Glossary::lines($app, 'fr'));
    Glossary::set($app, 'fr', str_repeat('x', Glossary::MAX + 50));
    same(Glossary::MAX, mb_strlen(Glossary::get($app, 'fr')));
    Glossary::set($app, 'fr', '   ');
    same('', Glossary::get($app, 'fr'));
    same('', Glossary::lines($app, 'fr'));
});

test('un nombre que no se traduce no puede perderse (sin mirar mayúsculas), y no cuenta si el original no lo lleva', function (): void {
    same('Oslo', Glossary::lostName(['Oslo'], 'Silla Oslo de roble', 'Chaise en chêne'));
    same('', Glossary::lostName(['Oslo'], 'Silla Oslo de roble', 'Chaise OSLO en chêne'));
    same('', Glossary::lostName(['Oslo'], 'Mesa de roble', 'Table en chêne'));
});

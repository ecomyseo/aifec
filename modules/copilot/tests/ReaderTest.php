<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Las piezas que leen texto libre (sin tienda): tildes, porcentajes, importes, fechas, números de pedido y los tipos de dato.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Copilot\Like;
use Aifec\Modules\Copilot\Params;
use Aifec\Modules\Copilot\Reader;

test('norm: minúsculas, sin tildes y sin signos, pero respeta el porcentaje, el importe y el número', function (): void {
    same('sube un 5 % los precios de la categoria sillas', Reader::norm('¡Sube un 5 % los precios, de la CATEGORÍA «Sillas»!'));
    same('pon 59,90 € ya', Reader::norm('Pon 59,90 € ya.'));
    same('anade 3 unidades a sku-001', Reader::norm('Añade 3 unidades a SKU-001'));
    same('pedido 2026-000012', Reader::norm('Pedido 2026-000012'));
    same('', Reader::norm('¿¡?!'));
});

test('fold no cambia la posición de ningún carácter: lo escrito se recupera con sus tildes y mayúsculas', function (): void {
    $raw = 'Crea un producto llamado Lámpara Ñandú a 25 €';
    $fold = Reader::fold($raw);
    same(mb_strlen($raw), mb_strlen($fold));
    same('Lámpara Ñandú', Reader::capture($raw, $fold, '/llamado\s+(.+?)\s+a\s+\d/i'));
    same(null, Reader::capture($raw, $fold, '/no existe\s+(\w+)/'));
});

test('takePercent: «5 %», «5%», «por ciento», decimales y el signo escrito', function (): void {
    same([500, 'sube un los precios'], Reader::takePercent('sube un 5 % los precios'));
    same([500, 'sube'], Reader::takePercent('sube 5%'));
    same([250, 'rebaja'], Reader::takePercent('rebaja 2,5 por ciento'));
    same([-250, 'baja el precio'], Reader::takePercent('baja -2,5% el precio'));
    same([1000, 'raise by'], Reader::takePercent('raise 10 percent by'));
    same([null, 'sin numero'], Reader::takePercent('sin numero'));
});

test('takeMoney: con euros, con el signo € delante o detrás; sin moneda no es un importe', function (): void {
    same([5990, 'pon ya'], Reader::takeMoney('pon 59,90 € ya'));
    same([1200, 'cuesta'], Reader::takeMoney('cuesta 12 euros'));
    same([1200, 'valor'], Reader::takeMoney('valor €12'));
    same([123456, ''], Reader::takeMoney('1.234,56 eur'));
    same([null, 'pon 12'], Reader::takeMoney('pon 12'));
});

test('takeAmountAfter: un importe detrás de «a», «to», «en» o «=», con moneda o sin ella; no confunde una cantidad con unidad', function (): void {
    same([5990, 'pon el precio'], Reader::takeAmountAfter('pon el precio a 59,90'));
    same([5990, 'set the price'], Reader::takeAmountAfter('set the price to 59.90 €'));
    same([1200, 'precio'], Reader::takeAmountAfter('precio = 12'));
    same([null, 'pesa a 12kg'], Reader::takeAmountAfter('pesa a 12kg'));
});

test('takeInt: un entero con su unidad o detrás de una preposición', function (): void {
    same([5, 'anade a la silla'], Reader::takeInt('anade 5 unidades a la silla'));
    same([20, 'stock'], Reader::takeInt('stock a 20'));
    same([-3, 'quita'], Reader::takeInt('quita -3 uds'));
});

test('date: ISO, día/mes/año, mes escrito en castellano y en inglés; una fecha imposible no es fecha', function (): void {
    same('2026-12-31', Reader::date('2026-12-31'));
    same('2026-12-31', Reader::date('hasta el 31/12/2026'));
    same('2026-12-31', Reader::date('31 de diciembre de 2026'));
    same('2026-12-31', Reader::date('until December 31, 2026'));
    same('2026-03-05', Reader::date('5-3-2026'));
    same(null, Reader::date('31/02/2026'));
    same(null, Reader::date('mañana'));
});

test('yesNo: un «no» o un «desactiva» gana al «activa» que lleva dentro; sin pista, null', function (): void {
    same(true, Reader::yesNo('activa el registro'));
    same(false, Reader::yesNo('desactiva el registro'));
    same(false, Reader::yesNo('muestra los precios sin impuestos'));
    same(true, Reader::yesNo('show the prices with tax'));
    same(null, Reader::yesNo('los precios de la tienda'));
});

test('orderRef: número simple, con año, con almohadilla o «n.º»; sin número, vacío', function (): void {
    same('12', Reader::orderRef('marca el pedido 12 como enviado'));
    same('2026-000012', Reader::orderRef('order #2026-000012 shipped'));
    same('7', Reader::orderRef(Reader::norm('pedido n.º 7')));
    same('9', Reader::orderRef('compra 9'));
    same('', Reader::orderRef('sube los precios'));
});

test('trimWords quita el relleno de los extremos y deja lo de dentro', function (): void {
    same('mesa de roble', Reader::trimWords('pon el precio de mesa de roble a', ['pon', 'el', 'precio', 'de'], ['a']));
    same('', Reader::trimWords('el la', ['el', 'la'], []));
});

test('Params: cada tipo se lee de un texto y vuelve al campo del formulario', function (): void {
    same(5990, Params::parse('money', '59,90 €'));
    same(5990, Params::parse('money', '59.90'));
    same(null, Params::parse('money', 'mucho'));
    same(500, Params::parse('percent', '5 %'));
    same(-250, Params::parse('percent', '-2,5'));
    same(true, Params::parse('bool', 'sí'));
    same(false, Params::parse('bool', 'No'));
    same(null, Params::parse('bool', 'quizá'));
    same(20, Params::parse('int', '20'));
    same(null, Params::parse('int', '2 0'));
    same('2026-12-31', Params::parse('date', '31/12/2026'));
    same('on_hold', Params::parse('enum', 'ON_HOLD', ['enum' => ['processing', 'on_hold']]));
    same(null, Params::parse('enum', 'cancelled', ['enum' => ['processing', 'on_hold']]));
    same('59,90', Params::toForm('money', 5990));
    same('5', Params::toForm('percent', 500));
    same('2,5', Params::toForm('percent', 250));
    same('1', Params::toForm('bool', true));
});

test('Params::read ignora lo vacío y lo desconocido y avisa de lo que no vale; Params::missing pide los obligatorios y un dato de cada grupo', function (): void {
    $defs = [
        Params::define(['name' => 'product', 'type' => 'string', 'required' => true]),
        Params::define(['name' => 'price', 'type' => 'money']),
        Params::define(['name' => 'percent', 'type' => 'percent']),
    ];
    list($args, $bad) = Params::read($defs, ['product' => ' Silla ', 'price' => '', 'percent' => 'x', 'otra' => '1']);
    same(['product' => 'Silla'], $args);
    same(['percent' => 'invalid'], $bad);
    same(['price', 'percent'], Params::missing($defs, ['product' => 'Silla'], [['price', 'percent']]));
    same([], Params::missing($defs, ['product' => 'Silla', 'price' => 100], [['price', 'percent']]));
    same(['product'], Params::missing($defs, ['price' => 100], [['price', 'percent']]));
    same(null, Params::define(['name' => 'Mal Nombre', 'type' => 'string']), 'un nombre con mayúsculas no vale');
    same(null, Params::define(['name' => 'x', 'type' => 'raro']), 'un tipo que no existe no vale');
    same(null, Params::define(['name' => 'x', 'type' => 'enum']), 'un enum sin opciones no vale');
});

test('Like escapa el porcentaje y el guion bajo para que valgan como letras', function (): void {
    same('50!% !_ !!', Like::escape('50% _ !'));
    same('%a!_b%', Like::contains('a_b'));
});

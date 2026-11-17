<?php
/**
 * AIFEC — catálogo castellano del módulo de impuestos.
 * La clave es la cadena inglesa tal como está en el código; no se toca. El valor es la traducción.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'A class groups the products that carry the same tax, for example the standard and the reduced rate.' => 'Una clase agrupa los productos que llevan el mismo impuesto, por ejemplo el tipo general y el reducido.',
    'A class with rates or products cannot be deleted.' => 'Una clase con tipos o con productos no se puede borrar.',
    'Active' => 'Activo',
    'Applies to the prices of products, shipping, fees and coupons.' => 'Vale para los precios de productos, envío, recargos y cupones.',
    'Canary Islands (IGIC), Ceuta and Melilla (IPSI) have their own rates, chosen by postcode in Tax rates: the sales there carry no VAT.' => 'Canarias (IGIC), Ceuta y Melilla (IPSI) tienen sus propios tipos, elegidos por código postal en Tipos de impuesto: allí las ventas no llevan IVA.',
    'Change the tax settings, classes and rates' => 'Cambiar los ajustes, las clases y los tipos de impuesto',
    'Changing a rate does not change the orders already placed: they keep the tax they were charged.' => 'Cambiar un tipo no cambia los pedidos ya hechos: conservan el impuesto que se les cobró.',
    'Class' => 'Clase',
    'Country' => 'País',
    'Customers with a tax ID that receive in another EU country pay no tax.' => 'Los clientes con NIF que reciben en otro país de la UE no pagan impuestos.',
    'Default class' => 'Clase por defecto',
    'If two rates are equally specific, the one with the higher priority is used.' => 'Si dos tipos son igual de concretos, se usa el de mayor prioridad.',
    'No tax for businesses of other EU countries' => 'Sin impuestos para empresas de otros países de la UE',
    'Optional. Empty applies to the whole country.' => 'Opcional. Vacío vale para todo el país.',
    'Order' => 'Orden',
    'Postcodes' => 'Códigos postales',
    'Prefixes or ranges separated by commas, for example 35,38 or 51000-51999. When filled, the region is not used.' => 'Prefijos o rangos separados por comas, por ejemplo 35,38 o 51000-51999. Si se rellena, la región no se usa.',
    'Prices already include tax' => 'Los precios ya llevan el impuesto',
    'Prices with tax included: the price you type is the final price and the tax is taken out of it; when it is off, the tax is added on top of the price you type.' => 'Precios con el impuesto incluido: el precio que escribes es el final y el impuesto se saca de él; si está apagado, el impuesto se suma al precio que escribes.',
    'Prices with tax, shop country and tax of the shipping' => 'Precios con impuesto, país de la tienda e impuesto del envío',
    'Priority' => 'Prioridad',
    'Products or shipping methods use this class. Move them to another class first.' => 'Hay productos o métodos de envío que usan esta clase. Pásalos antes a otra clase.',
    'Products without a class use this one. Only one class can be the default.' => 'Los productos sin clase usan esta. Solo una clase puede ser la de por defecto.',
    'Rate' => 'Tipo',
    'Rates' => 'Tipos',
    'Region' => 'Región',
    'See the tax classes and rates' => 'Ver las clases y los tipos de impuesto',
    'Shop country' => 'País de la tienda',
    'Shop postcode' => 'Código postal de la tienda',
    'Show prices with tax in the shop' => 'Enseñar los precios con impuestos en la tienda',
    'Tax' => 'Impuestos',
    'Tax class' => 'Clase de impuesto',
    'Tax classes' => 'Clases de impuesto',
    'Tax classes and rates by destination' => 'Clases y tipos de impuesto según el destino',
    'Tax of the shipping' => 'Impuesto del envío',
    'Tax rate' => 'Tipo de impuesto',
    'Tax rates' => 'Tipos de impuesto',
    'Taxes' => 'Impuestos',
    'The code cannot change once the class has rates or is used by a product.' => 'El código no se puede cambiar cuando la clase tiene tipos o la usa algún producto.',
    'The default class cannot be deleted.' => 'La clase por defecto no se puede borrar.',
    'The most specific rate wins: postcodes first, then region, then the country alone.' => 'Gana el tipo más concreto: primero los códigos postales, después la región y por último el país solo.',
    'The name that appears on the invoice, for example VAT 21 %.' => 'El nombre que sale en la factura, por ejemplo IVA 21 %.',
    'The shipping takes the tax class you choose here, or the highest rate of the products in the cart.' => 'El envío lleva la clase de impuesto que elijas aquí, o el tipo más alto de los productos del carrito.',
    'There must always be a default class. Make another class the default instead.' => 'Siempre tiene que haber una clase por defecto. Marca otra como la de por defecto.',
    'This class has tax rates. Delete them first.' => 'Esta clase tiene tipos de impuesto. Bórralos antes.',
    'This class is in use: its code cannot be changed.' => 'Esta clase está en uso: su código no se puede cambiar.',
    'Use prefixes or ranges separated by commas, for example 35,38 or 51000-51999.' => 'Usa prefijos o rangos separados por comas, por ejemplo 35,38 o 51000-51999.',
    'When two rates apply equally, the higher priority wins.' => 'Cuando dos tipos valen por igual, gana la mayor prioridad.',

    // Nombres sembrados por la migración y opciones que se montan en el código.
    'Highest rate in the cart' => 'El tipo más alto del carrito',
    'Reduced rate' => 'Tipo reducido',
    'Standard rate' => 'Tipo general',
    'Super-reduced rate' => 'Tipo superreducido',
    'Zero rate' => 'Tipo cero',
    // Menú: «Impuestos» con sus pestañas.
    'Classes' => 'Clases',
    'Percentages' => 'Tipos',
];

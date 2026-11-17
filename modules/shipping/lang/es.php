<?php
/**
 * AIFEC — catálogo castellano del módulo de envíos.
 * La clave es la cadena inglesa tal como está en el código; no se toca. El valor es la traducción.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    // Nombres sembrados en la base (se guardan en inglés y se traducen al enseñarlos).
    'Peninsula' => 'Península',
    'Balearic Islands' => 'Baleares',
    'Canary Islands' => 'Canarias',
    'Ceuta and Melilla' => 'Ceuta y Melilla',

    'Shipping zones, carriers and rates' => 'Zonas de envío, transportistas y tarifas',
    'See the shipping zones, carriers and rates' => 'Ver las zonas de envío, los transportistas y las tarifas',
    'Change the shipping settings, zones, carriers and rates' => 'Cambiar los ajustes de envío, las zonas, los transportistas y las tarifas',

    'Shipping' => 'Envíos',
    'Shipping zones' => 'Zonas de envío',
    'Shipping zone' => 'Zona de envío',
    'Carriers' => 'Transportistas',
    'Carrier' => 'Transportista',
    'Test a shipment' => 'Probar un envío',

    // Zonas
    'A zone is a set of places that share the same shipping prices, for example the peninsula or the islands.' => 'Una zona es un conjunto de lugares que comparten los mismos precios de envío, por ejemplo la península o las islas.',
    'The most specific place wins: a postcode before a region, a region before a country, a country before the rest of the world.' => 'Gana el lugar más concreto: un código postal antes que una región, una región antes que un país y un país antes que el resto del mundo.',
    'A zone with carrier rates cannot be deleted.' => 'Una zona con tarifas de transportistas no se puede borrar.',
    'This zone has carrier rates. Delete them first.' => 'Esta zona tiene tarifas de transportistas. Bórralas primero.',
    'Where' => 'Dónde',
    'Where it applies' => 'Dónde se aplica',
    'Add a place' => 'Añadir un lugar',
    'The most specific place wins: postcodes, then region, then country, then the rest of the world.' => 'Gana el lugar más concreto: primero los códigos postales, luego la región, luego el país y por último el resto del mundo.',
    'When two zones fit a destination equally, the one with the lower order wins.' => 'Si dos zonas encajan igual con un destino, gana la de menor orden.',
    'Rest of the world' => 'Resto del mundo',
    'Country' => 'País',
    'Region' => 'Región',
    'Postcodes' => 'Códigos postales',
    'For example 35,38 or 51000-51999' => 'Por ejemplo 35,38 o 51000-51999',
    'Use prefixes or ranges separated by commas, for example 35,38 or 51000-51999.' => 'Usa prefijos o rangos separados por comas, por ejemplo 35,38 o 51000-51999.',
    'Active' => 'Activa',
    'Inactive' => 'Inactiva',
    'Order' => 'Orden',
    'Status' => 'Estado',

    // Transportistas
    'A carrier is a way to send the order. With a rate table, you set a price for each zone and each weight or order amount range.' => 'Un transportista es una forma de enviar el pedido. Con una tabla de tarifas, pones un precio para cada zona y cada tramo de peso o de importe.',
    'With pickup in store there are no rates: you set the price and the address where the customer collects the order.' => 'Con la recogida en tienda no hay tarifas: pones el precio y la dirección donde el cliente recoge el pedido.',
    'Use Test a shipment to check that a destination gets the prices you expect.' => 'Usa «Probar un envío» para comprobar que un destino recibe los precios que esperas.',
    'Calculation' => 'Cálculo',
    'A rate table, or pickup in store. Plugins can add more.' => 'Una tabla de tarifas o la recogida en tienda. Los plugins pueden añadir más.',
    'Rate table' => 'Tabla de tarifas',
    'Pickup in store' => 'Recogida en tienda',
    'A short name without spaces, for example gls-24.' => 'Un nombre corto sin espacios, por ejemplo gls-24.',
    'Delivery time' => 'Plazo de entrega',
    'The text the customer sees, for example 24-48 h.' => 'El texto que ve el cliente, por ejemplo 24-48 h.',
    'Minimum days' => 'Días mínimos',
    'Maximum days' => 'Días máximos',
    'The maximum cannot be lower than the minimum.' => 'El máximo no puede ser menor que el mínimo.',
    'Maximum weight' => 'Peso máximo',
    'The heaviest package this carrier takes. 0 means no limit.' => 'El paquete más pesado que lleva este transportista. 0 significa sin límite.',
    'Free from' => 'Gratis a partir de',
    'Not stated' => 'Sin indicar',
    'Orders whose products reach this amount ship free. Leave it empty for never.' => 'Los pedidos cuyos productos llegan a este importe se envían gratis. Déjalo vacío para que nunca.',
    'Tax class' => 'Clase de impuesto',
    'As set in the tax settings' => 'Según los ajustes de impuestos',
    'Tracking link' => 'Enlace de seguimiento',
    'Use {code} where the tracking code goes, for example https://carrier.example/track/{code}.' => 'Usa {code} donde va el código de seguimiento, por ejemplo https://transportista.example/seguimiento/{code}.',
    'Pickup price' => 'Precio de la recogida',
    'Leave it at 0 for free pickup.' => 'Déjalo en 0 si la recogida es gratis.',
    'Pickup address' => 'Dirección de recogida',
    'Where and when the customer collects the order.' => 'Dónde y cuándo recoge el cliente el pedido.',
    'Rates' => 'Tarifas',
    'Add a rate' => 'Añadir una tarifa',
    'For each zone, the price of every weight or order amount range. A package that fits no range cannot be sent with this carrier.' => 'Para cada zona, el precio de cada tramo de peso o de importe. Un paquete que no cabe en ningún tramo no se puede enviar con este transportista.',
    'Zone' => 'Zona',
    'Based on' => 'Según',
    'Weight (g)' => 'Peso (g)',
    'Order amount' => 'Importe del pedido',
    'From' => 'Desde',
    'Up to (0 = no limit)' => 'Hasta (0 = sin tope)',
    'Price' => 'Precio',
    'The limits of a weight range are whole grams, for example 1000.' => 'Los límites de un tramo de peso son gramos enteros, por ejemplo 1000.',
    'The limits of an order amount range are amounts, for example 50 or 12,50.' => 'Los límites de un tramo de importe son importes, por ejemplo 50 o 12,50.',
    '"Up to" cannot be lower than "From".' => '«Hasta» no puede ser menor que «Desde».',
    'Orders were shipped with this carrier. Deactivate it instead of deleting it.' => 'Hay pedidos enviados con este transportista. Desactívalo en lugar de borrarlo.',

    // Ajustes
    'Default country and shipping estimate in the cart' => 'País de partida y cálculo del envío en el carrito',
    'Default country' => 'País de partida',
    'Let customers estimate the shipping in the cart' => 'Dejar que los clientes calculen el envío en el carrito',
    'They choose a country and a postcode and see the carriers and their prices.' => 'Eligen un país y un código postal y ven los transportistas y sus precios.',
    'Default country: the one used when a destination does not say its country.' => 'País de partida: el que se usa cuando un destino no dice su país.',
    'Estimate in the cart: the customer can see the shipping cost in the cart by choosing a country and a postcode, before reaching the checkout.' => 'Cálculo en el carrito: el cliente puede ver el coste del envío en el carrito eligiendo un país y un código postal, antes de llegar a la caja.',
    'Zones, carriers and rates are set in their own screens; use Test a shipment to check them.' => 'Las zonas, los transportistas y las tarifas se configuran en sus propias pantallas; usa «Probar un envío» para comprobarlas.',

    // Probar un envío
    'Postcode' => 'Código postal',
    'Weight' => 'Peso',
    'Amount of the products' => 'Importe de los productos',
    'Test' => 'Probar',
    'Result' => 'Resultado',
    'Province' => 'Provincia',
    'Free' => 'Gratis',
    'No zone covers this destination.' => 'Ninguna zona cubre este destino.',
    'No carrier can send this package to this destination.' => 'Ningún transportista puede enviar este paquete a este destino.',
    'Enter the weight in whole grams, for example 1500.' => 'Escribe el peso en gramos enteros, por ejemplo 1500.',

    // Una sola entrada «Envío» con pestañas, y el precio de una tarifa cambiado en su fila.
    'Zones and rates' => 'Zonas y tarifas',
    'Add a carrier' => 'Añadir un transportista',
    'Add a zone' => 'Añadir una zona',
    'Add the first carrier to offer shipping in the shop.' => 'Añade el primer transportista para ofrecer envío en la tienda.',
    'No carriers yet' => 'Todavía no hay transportistas',
    'No zones yet' => 'Todavía no hay zonas',
    'No rates yet' => 'Todavía no hay tarifas',
    'Open a carrier with a rate table and add its rates.' => 'Abre un transportista con tabla de tarifas y añade sus tarifas.',
    'Change the price of a rate in its own row: you see what a 1 kg order to Madrid would pay before you save.' => 'Cambia el precio de una tarifa en su propia fila: ves lo que pagaría un pedido de 1 kg a Madrid antes de guardar.',
    'Enter an amount, for example 4,95' => 'Escribe un importe, por ejemplo 4,95',
    'Price of this rate' => 'Precio de esta tarifa',
    'Rate price' => 'Precio de una tarifa',
    'See the effect' => 'Ver el efecto',
    'From {min} to {max}' => 'De {min} a {max}',
    'From {min} up' => 'Desde {min}',
    'A 1 kg order to Madrid pays' => 'Un pedido de 1 kg a Madrid paga',
    'With this change, a 1 kg order to Madrid would pay' => 'Con este cambio, un pedido de 1 kg a Madrid pagaría',
    'With this change, a 1 kg order to Madrid would pay: {prices}' => 'Con este cambio, un pedido de 1 kg a Madrid pagaría: {prices}',
    'No carrier can send a 1 kg order to Madrid.' => 'Ningún transportista puede enviar un pedido de 1 kg a Madrid.',
];

<?php
/**
 * AIFEC — catálogo castellano del módulo de cupones.
 * La clave es la cadena inglesa tal como está en el código; no se toca. El valor es la traducción.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'Discount codes' => 'Códigos de descuento',

    // Motivos por los que un cupón no vale (los ve el cliente)
    'Enter a coupon code' => 'Escribe un código de cupón',
    'This coupon code is not valid' => 'Este código de cupón no es válido',
    'This coupon is not valid yet. It starts on {date}.' => 'Este cupón todavía no es válido. Empieza el {date}.',
    'This coupon has expired' => 'Este cupón ha caducado',
    'This coupon has reached its usage limit' => 'Este cupón se ha agotado',
    'This coupon is for another customer' => 'Este cupón es de otro cliente',
    'You have already used this coupon the allowed number of times' => 'Ya has usado este cupón todas las veces permitidas',
    'This coupon is only valid for your first order' => 'Este cupón solo vale para tu primer pedido',
    'Add {amount} more to your order to use this coupon' => 'Añade {amount} más a tu pedido para usar este cupón',
    'This coupon does not apply to any item in your cart' => 'Este cupón no se puede aplicar a ningún artículo de tu carrito',
    'This coupon cannot be combined with other coupons' => 'Este cupón no se puede sumar a otros cupones',
    'Coupon {code}' => 'Cupón {code}',

    // Panel: permisos, menú y recurso
    'See the coupons' => 'Ver los cupones',
    'Create and change coupons' => 'Crear y cambiar cupones',
    'Coupons' => 'Cupones',
    'Coupon' => 'Cupón',
    'A coupon is typed by the customer in the cart. The code is saved in capital letters.' => 'El cliente escribe el cupón en el carrito. El código se guarda en mayúsculas.',
    'A used coupon cannot be deleted: turn it off instead.' => 'Un cupón que ya se ha usado no se puede borrar: se desactiva.',
    'If a coupon is limited to products and to categories, an item is included when it matches either of them.' => 'Si un cupón se limita a productos y a categorías, un artículo entra si coincide con una de las dos cosas.',

    // Listado
    'Code' => 'Código',
    'Discount' => 'Descuento',
    'Uses' => 'Usos',
    'Validity' => 'Vigencia',
    'Active' => 'Activo',
    'Type' => 'Tipo',
    'Status' => 'Estado',
    'Percentage' => 'Porcentaje',
    'Fixed amount' => 'Importe fijo',
    'Free shipping' => 'Envío gratis',
    'Valid' => 'Vigente',
    'Expired' => 'Caducado',
    'Used up' => 'Agotado',
    'From {date}' => 'Desde el {date}',
    'Until {date}' => 'Hasta el {date}',

    // Formulario
    'The customer types it in the cart. It is saved in capital letters.' => 'El cliente lo escribe en el carrito. Se guarda en mayúsculas.',
    'Generate a code' => 'Generar un código',
    'Between 0.01 and 100.' => 'Entre 0,01 y 100.',
    'Amount' => 'Importe',
    'Written like the product prices. Greater than zero.' => 'Se escribe como los precios de los productos. Mayor que cero.',
    'Internal note' => 'Nota interna',
    'Starts' => 'Empieza',
    'Ends' => 'Termina',
    'Maximum uses' => 'Usos máximos',
    'Uses per customer' => 'Usos por cliente',
    '0 = no limit.' => '0 = sin límite.',
    'Minimum order amount' => 'Importe mínimo del pedido',
    'Leave it empty for no minimum.' => 'Déjalo vacío si no hay mínimo.',
    'Only for the first order' => 'Solo para el primer pedido',
    'Not valid on items on sale' => 'No vale con artículos en oferta',
    'Can be added to other coupons' => 'Se puede sumar a otros cupones',
    'Limited to one customer' => 'Limitado a un cliente',
    'Limited to products' => 'Limitado a productos',
    'Product numbers separated by commas. Empty = all products.' => 'Números de producto separados por comas. Vacío = todos los productos.',
    'Limited to categories' => 'Limitado a categorías',
    'Nothing ticked = all categories.' => 'Ninguna marcada = todas las categorías.',
    'Limits' => 'Límites',

    // Validación al guardar
    'Use only letters, numbers, hyphens and underscores' => 'Usa solo letras, números, guiones y guiones bajos',
    'Another coupon already has this code' => 'Otro cupón ya tiene este código',
    'Enter a percentage between 0.01 and 100' => 'Escribe un porcentaje entre 0,01 y 100',
    'Enter an amount greater than zero' => 'Escribe un importe mayor que cero',
    'The end must be after the start' => 'El fin tiene que ser posterior al inicio',
    'This customer does not exist' => 'Este cliente no existe',
    'This coupon has been used and cannot be deleted. Turn it off instead.' => 'Este cupón ya se ha usado y no se puede borrar. Desactívalo.',

    // Columna derecha
    'Nobody has used this coupon yet.' => 'Nadie ha usado todavía este cupón.',
    'Order' => 'Pedido',
    'Email' => 'Correo electrónico',
    'Date' => 'Fecha',
];

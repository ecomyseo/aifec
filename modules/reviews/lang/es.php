<?php
/**
 * AIFEC — catálogo castellano del módulo de valoraciones.
 * La clave es la cadena inglesa tal como está en el código; no se toca. El valor es la traducción.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    // ------------------------------------------------------------------ módulo, permisos, menú y ajustes
    'Product reviews with moderation and verified purchases' => 'Valoraciones de producto con moderación y compras verificadas',
    'See the product reviews' => 'Ver las valoraciones de los productos',
    'Publish, reject and answer product reviews, and change their settings' => 'Publicar, rechazar y responder valoraciones, y cambiar sus ajustes',
    'Reviews' => 'Valoraciones',
    'Review settings' => 'Ajustes de valoraciones',
    'Moderation of reviews and the email that asks buyers for one' => 'Moderación de valoraciones y el correo que se las pide a los compradores',
    'Reviews: a new review waits for you to publish it, unless it comes from a verified purchase and you turn the first option on.' => 'Valoraciones: una valoración nueva espera a que la publiques, salvo que sea de una compra verificada y actives la primera opción.',
    'Verified purchase: there is a paid order from that email address with that product.' => 'Compra verificada: hay un pedido pagado de ese correo con ese producto.',
    'The request email is sent once, a few days after the order is completed, with one link per product. Each link can be used once. Orders completed before you installed this module never get one.' => 'El correo de petición se manda una sola vez, unos días después de completarse el pedido, con un enlace por producto. Cada enlace se puede usar una vez. Los pedidos completados antes de instalar este módulo no reciben ninguno.',
    'Publish verified purchases without moderation' => 'Publicar sin moderación las compras verificadas',
    'Request by email' => 'Petición por correo',
    'Ask buyers for a review by email' => 'Pedir una valoración a los compradores por correo',
    'Days after the order is completed' => 'Días después de completarse el pedido',

    // ------------------------------------------------------------------ correo
    'How was your order? Tell us what you think - {shop}' => '¿Qué tal tu pedido? Cuéntanos qué te ha parecido - {shop}',
    'Sent to the customer a few days after the order is completed, with a link to review each product' => 'Se manda al cliente unos días después de completarse el pedido, con un enlace para valorar cada producto',
    'Hello {name}' => 'Hola {name}',
    'Thank you for your order {number}. Your opinion helps other customers decide.' => 'Gracias por tu pedido {number}. Tu opinión ayuda a otros clientes a decidir.',
    'Each link can be used once.' => 'Cada enlace se puede usar una vez.',

    // ------------------------------------------------------------------ validación (los ve el cliente)
    'This review link is not valid or has already been used.' => 'Este enlace para valorar no es válido o ya se ha usado.',
    'This product is not available.' => 'Este producto no está disponible.',
    'Enter your name.' => 'Escribe tu nombre.',
    'The name is too long.' => 'El nombre es demasiado largo.',
    'Enter a valid email address.' => 'Escribe un correo electrónico válido.',
    'Choose a rating from 1 to 5 stars.' => 'Elige una nota de 1 a 5 estrellas.',
    'The title is too long.' => 'El título es demasiado largo.',
    'Write at least {n} characters.' => 'Escribe al menos {n} caracteres.',
    'The review is too long: {n} characters at most.' => 'La valoración es demasiado larga: {n} caracteres como máximo.',
    'You have already reviewed this product.' => 'Ya has valorado este producto.',
    'Check the highlighted fields.' => 'Revisa los campos marcados.',
    'Thank you! Your review is now published.' => '¡Gracias! Tu valoración ya está publicada.',
    'Thank you! Your review will appear once we have checked it.' => '¡Gracias! Tu valoración aparecerá en cuanto la hayamos revisado.',
    'Too many reviews in a short time. Please try again later.' => 'Demasiadas valoraciones en poco tiempo. Inténtalo de nuevo más tarde.',
    'Review {product}' => 'Valorar {product}',
    'Review link not valid' => 'Enlace para valorar no válido',

    // ------------------------------------------------------------------ la tienda
    'Customer reviews' => 'Valoraciones de clientes',
    '{rating} out of 5' => '{rating} de 5',
    '{n} review|{n} reviews' => '{n} valoración|{n} valoraciones',
    '{n} star|{n} stars' => '{n} estrella|{n} estrellas',
    'Reviews by rating' => 'Valoraciones por nota',
    'Verified purchase' => 'Compra verificada',
    'Reply from the shop' => 'Respuesta de la tienda',
    'View the breakdown' => 'Ver el desglose',
    'Read more' => 'Leer más',
    'Read less' => 'Leer menos',
    'Write a review' => 'Escribe una valoración',
    'Your name' => 'Tu nombre',
    'Your email' => 'Tu correo electrónico',
    'It is not shown. We use it to check that you bought the product.' => 'No se muestra. Lo usamos para comprobar que has comprado el producto.',
    'Your rating' => 'Tu nota',
    'Title (optional)' => 'Título (opcional)',
    'Your review' => 'Tu valoración',
    'Send my review' => 'Enviar mi valoración',
    'Back to the shop' => 'Volver a la tienda',
    'Back to the product' => 'Volver al producto',

    // ------------------------------------------------------------------ el panel
    'Quick views' => 'Vistas rápidas',
    'Pending' => 'Pendiente',
    'Published' => 'Publicada',
    'Rejected' => 'Rechazada',
    'All' => 'Todas',
    'A new review waits here until you publish it, unless it is a verified purchase and you turned on automatic publishing in the settings.' => 'Una valoración nueva espera aquí hasta que la publiques, salvo que sea de una compra verificada y hayas activado la publicación automática en los ajustes.',
    'Publishing or rejecting a review updates the rating of the product straight away.' => 'Al publicar o rechazar una valoración, la nota del producto se actualiza al momento.',
    'Review of {product}' => 'Valoración de {product}',
    'The review is published.' => 'La valoración está publicada.',
    'The review was already published.' => 'La valoración ya estaba publicada.',
    'The review is rejected and no longer shown.' => 'La valoración está rechazada y ya no se muestra.',
    'The review was already rejected.' => 'La valoración ya estaba rechazada.',
    'The reply is saved.' => 'La respuesta está guardada.',
    'The reply is too long: {n} characters at most.' => 'La respuesta es demasiado larga: {n} caracteres como máximo.',
    'Date' => 'Fecha',
    'Product' => 'Producto',
    'Rating' => 'Nota',
    'Customer' => 'Cliente',
    'Status' => 'Estado',
    'From' => 'Desde',
    'To' => 'Hasta',
    'No reviews yet' => 'Todavía no hay valoraciones',
    'Reviews show up here when a customer writes one on a product page.' => 'Las valoraciones salen aquí cuando un cliente escribe una en la página de un producto.',
    'Details' => 'Detalles',
    'Save the reply' => 'Guardar la respuesta',
    'Plain text, 1000 characters at most. Leave it empty to remove the reply.' => 'Texto plano, 1000 caracteres como máximo. Déjala vacía para quitar la respuesta.',
    'Publish' => 'Publicar',
    'Reject' => 'Rechazar',
    'Reject this review? It will not be shown in the shop.' => '¿Rechazar esta valoración? No se mostrará en la tienda.',
];

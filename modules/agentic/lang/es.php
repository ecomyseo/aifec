<?php
/**
 * AIFEC — catálogo castellano del módulo de comercio con agentes (UCP y agentes firmados).
 * La clave es la cadena inglesa tal como está en el código; no se toca. El valor es la traducción.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'Agentic commerce' => 'Comercio con agentes',
    'UCP checkout for AI platforms (Google AI Mode, Gemini) and verification of signed AI agents (ChatGPT agent and Wallet)' => 'Compra por UCP desde plataformas de IA (Modo IA de Google, Gemini) y verificación de los agentes de IA que firman (agente y monedero de ChatGPT)',

    // Ajustes
    'UCP and agents' => 'UCP y agentes',
    'Purchases from AI platforms through UCP and verification of signed AI agents' => 'Compras desde plataformas de IA por UCP y verificación de los agentes de IA que firman',
    'UCP is the protocol Google uses to buy in a shop from AI Mode and Gemini: the platform builds the cart, the delivery and the payment with the checkout of the shop, and the person pays with Google Pay.' => 'UCP es el protocolo con el que Google compra en una tienda desde el Modo IA y Gemini: la plataforma monta el carrito, la entrega y el pago con la caja de la tienda, y la persona paga con Google Pay.',
    'The signed agents (the agent and the wallet of ChatGPT) buy on the web like a person: here the shop recognises them, so their sales are attributed to them.' => 'Los agentes que firman (el agente y el monedero de ChatGPT) compran en la web como una persona: aquí la tienda los reconoce y sus ventas quedan atribuidas a ellos.',
    'Today both only buy in the United States; in Europe they are announced but have no date.' => 'Hoy los dos solo compran en Estados Unidos; en Europa están anunciados, pero sin fecha.',
    'UCP' => 'UCP',
    'Profile address' => 'Dirección del perfil',
    'Off: the profile and the purchase API answer 404.' => 'Apagado: el perfil y la API de compra responden 404.',
    'On: the platforms can complete the purchase with the payment methods announced.' => 'Encendido: las plataformas pueden terminar la compra con las formas de pago anunciadas.',
    'On, without a payment method for platforms: purchases are finished on the checkout page of the shop.' => 'Encendido, sin forma de pago para plataformas: las compras se terminan en la caja de la tienda.',
    'Choose the terms and the privacy pages: UCP asks for both.' => 'Elige la página de condiciones y la de privacidad: UCP pide las dos.',
    'Turn UCP on' => 'Encender UCP',
    'Off, the profile and the purchase API answer 404.' => 'Apagado, el perfil y la API de compra responden 404.',
    'Ask for an API key to complete a purchase' => 'Pedir una clave de la API para terminar una compra',
    'Give the platform a key of the API with the scope «orders:create». Off, anyone with a valid payment token could complete one.' => 'Dale a la plataforma una clave de la API con el permiso «orders:create». Apagado, cualquiera con un token de pago válido podría terminar una.',
    'Privacy policy page' => 'Página de política de privacidad',
    'It goes with the terms page of the orders in every purchase through UCP.' => 'Va con la página de condiciones de los pedidos en cada compra por UCP.',
    'Signed agents' => 'Agentes que firman',
    'Last verified agent' => 'Último agente verificado',
    '{name}, {date}' => '{name}, {date}',
    'None yet.' => 'Ninguno todavía.',
    'Verify the signature of AI agents' => 'Verificar la firma de los agentes de IA',
    'Web Bot Auth: the shop checks the signature with the public key of the agent and attributes the visit to it. It never blocks anybody.' => 'Web Bot Auth: la tienda comprueba la firma con la clave pública del agente y le atribuye la visita. No bloquea a nadie.',
    'Trusted agents' => 'Agentes de confianza',
    'One address per line. Only the keys of these agents are downloaded.' => 'Una dirección por línea. Solo se descargan las claves de estos agentes.',

    // La sesión de compra
    'Subtotal (including taxes)' => 'Subtotal (impuestos incluidos)',
    'This shop takes the payment on its own checkout page: open the continue address to pay.' => 'Esta tienda cobra en su propia caja: abre la dirección para continuar y paga allí.',
    'The email of the buyer is required' => 'Hace falta el correo del comprador',
    'Choose a delivery option' => 'Elige una forma de entrega',
    'The delivery address is required' => 'Hace falta la dirección de entrega',
    'A checkout needs at least one line item.' => 'Una compra necesita al menos un artículo.',
    'None of the items can be bought.' => 'No se puede comprar ninguno de los artículos.',
    'This checkout is already finished.' => 'Esta compra ya está terminada.',
    'This checkout is already an order: it cannot be canceled here.' => 'Esta compra ya es un pedido: no se puede cancelar aquí.',
    'This checkout was canceled.' => 'Esta compra se canceló.',
    'Completing a purchase needs the API key that the shop gave to this platform (Authorization: Bearer).' => 'Para terminar una compra hace falta la clave de la API que la tienda le dio a esta plataforma (Authorization: Bearer).',
    'This payment cannot be taken here. Finish the purchase on the checkout page of the shop.' => 'Este pago no se puede cobrar aquí. Termina la compra en la caja de la tienda.',
    'This payment cannot be taken for this purchase. Finish it on the checkout page of the shop.' => 'Este pago no se puede cobrar en esta compra. Termínala en la caja de la tienda.',
    'This order is above the highest amount the shop takes from an AI platform. Finish it on the checkout page of the shop.' => 'Este pedido supera el importe máximo que la tienda acepta desde una plataforma de IA. Termínalo en la caja de la tienda.',
    'Too many orders in an hour. Try again later.' => 'Demasiados pedidos en una hora. Inténtalo más tarde.',
    'The bank asks to confirm the card. Finish the purchase on the checkout page of the shop.' => 'El banco pide confirmar la tarjeta. Termina la compra en la caja de la tienda.',
    'The order has changed. Review it again.' => 'El pedido ha cambiado. Revísalo otra vez.',
    'The payment did not go through. Nothing was charged.' => 'El pago no ha salido. No se ha cobrado nada.',
    'The line items are not valid.' => 'Los artículos no son válidos.',
    'Unknown item: {id}' => 'Artículo desconocido: {id}',
    'Enter a valid quantity' => 'Escribe una cantidad válida',
    'This shipping method is not available for this order' => 'Este envío no está disponible para este pedido',
    'The request is too large.' => 'La petición es demasiado grande.',
    'The body must be a JSON object.' => 'El cuerpo tiene que ser un objeto JSON.',
    'The Idempotency-Key is too long.' => 'La Idempotency-Key es demasiado larga.',
    'This Idempotency-Key was already used with another request.' => 'Esta Idempotency-Key ya se usó con otra petición.',
    'This request is still being processed.' => 'Esta petición todavía se está procesando.',
];

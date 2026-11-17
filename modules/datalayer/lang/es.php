<?php
/**
 * AIFEC — catálogo castellano del módulo de la capa de datos.
 * La clave es la cadena inglesa tal como está en el código; no se toca. El valor es la traducción.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'Measurement for the shop: GA4 ecommerce events, Tag Manager, Google Ads, Meta, TikTok and Microsoft Ads, loaded only after consent and without breaking the page cache' => 'Medición de la tienda: eventos de comercio de GA4, Tag Manager, Google Ads, Meta, TikTok y Microsoft Ads, cargados solo tras el consentimiento y sin romper la caché de página',

    // Las cookies de los destinos
    '1 day' => '1 día',
    '2 years' => '2 años',
    '3 months' => '3 meses',
    '13 months' => '13 meses',
    'Remembers that you signed in or created an account, to measure it' => 'Recuerda que has iniciado sesión o creado una cuenta, para medirlo',
    'Tells visitors apart to measure how the shop is used' => 'Distingue a los visitantes para medir cómo se usa la tienda',
    'Measures how well adverts lead to a purchase' => 'Mide si los anuncios acaban en una compra',
    'Measures adverts and shows them to people who may like them' => 'Mide los anuncios y se los enseña a quien pueda interesarle',

    // El panel
    'Measurement' => 'Medición',
    'Server events' => 'Eventos del servidor',
    'See the events the server sent to the measurement services' => 'Ver los eventos que el servidor mandó a los servicios de medición',
    'Change the measurement settings' => 'Cambiar los ajustes de medición',
    'Here you see what the server sent to Google Analytics and Meta, and what each one answered.' => 'Aquí ves lo que el servidor mandó a Google Analytics y a Meta, y lo que contestó cada uno.',
    'Only purchases and refunds that happen after you turn the switch on are sent. Old orders are never sent.' => 'Solo se mandan las compras y los reembolsos que ocurren después de encender el interruptor. Los pedidos antiguos no se mandan nunca.',
    'An event is only sent if the visitor accepted the cookies of that service when they placed the order. The others show «skipped».' => 'Un evento solo se manda si el visitante aceptó las cookies de ese servicio al hacer el pedido. Los demás salen como «omitido».',
    'The same event identifier is used in the browser and here, so nothing is counted twice.' => 'Se usa el mismo identificador de evento en el navegador y aquí, así que nada se cuenta dos veces.',
    'Server events are off.' => 'Los eventos del servidor están apagados.',
    'Turn them on in the settings' => 'Enciéndelos en los ajustes',
    'Last events' => 'Últimos eventos',
    'No server events yet' => 'Todavía no hay eventos del servidor',
    'Date' => 'Fecha',
    'Order' => 'Pedido',
    'Event' => 'Evento',
    'Service' => 'Servicio',
    'Status' => 'Estado',
    'Answer' => 'Respuesta',
    'Detail' => 'Detalle',
    'Sent' => 'Enviado',
    'Queued' => 'En cola',
    'Skipped' => 'Omitido',
    'Failed' => 'Fallido',
    'No consent' => 'Sin consentimiento',
    'The switch is off' => 'El interruptor está apagado',
    'Older than 24 hours' => 'Con más de 24 horas',
    'Not configured' => 'Sin configurar',
    'No answer in 24 hours' => 'Sin respuesta en 24 horas',

    // Los ajustes
    'Analytics and advertising tags, loaded only after the visitor agrees' => 'Etiquetas de analítica y publicidad, cargadas solo cuando el visitante está de acuerdo',
    'Every tag is loaded only after the visitor accepts its category in the cookie notice. Until then, nothing from a third party is downloaded.' => 'Cada etiqueta se carga solo después de que el visitante acepte su categoría en el aviso de cookies. Hasta entonces no se descarga nada de terceros.',
    'Use Tag Manager or the direct tags, not both for the same service, or visits are counted twice.' => 'Usa Tag Manager o las etiquetas directas, no las dos para el mismo servicio, o las visitas se cuentan dos veces.',
    'Prices in the events go without tax, as Google Analytics asks, unless you turn on the option. The tax goes in its own field.' => 'Los precios de los eventos van sin impuestos, como pide Google Analytics, salvo que enciendas la opción. El impuesto va en su propio campo.',
    'Server events (purchases and refunds) are off. When you turn them on, only what happens from then on is sent, and only for visitors who accepted the cookies of that service. Old orders are never sent.' => 'Los eventos del servidor (compras y reembolsos) están apagados. Al encenderlos solo se manda lo que ocurra desde ese momento, y solo de visitantes que aceptaron las cookies de ese servicio. Los pedidos antiguos no se mandan nunca.',
    'The identifiers are checked: one that does not look like a real one is ignored.' => 'Los identificadores se comprueban: uno que no tiene pinta de ser de verdad se ignora.',
    'General' => 'General',
    'Measure the shop' => 'Medir la tienda',
    'Prices in the events include tax' => 'Los precios de los eventos incluyen impuestos',
    'Do not measure people who are signed in to the panel' => 'No medir a quien tiene la sesión del panel abierta',
    'Send purchases and refunds from the server' => 'Mandar compras y reembolsos desde el servidor',
    'Off by default. Only what happens after you turn it on is sent.' => 'Apagado de serie. Solo se manda lo que ocurra después de encenderlo.',
    'Container ID' => 'Identificador del contenedor',
    'Needs consent for' => 'Pide consentimiento de',
    'Measurement ID' => 'Identificador de medición',
    'API secret (server events)' => 'Clave secreta de la API (eventos del servidor)',
    'Conversion ID' => 'Identificador de conversión',
    'Purchase conversion label' => 'Etiqueta de la conversión de compra',
    'Enhanced conversions (email and phone, hashed)' => 'Conversiones mejoradas (correo y teléfono, resumidos)',
    'Only sent if the visitor accepted marketing cookies.' => 'Solo se manda si el visitante aceptó las cookies de marketing.',
    'Pixel ID' => 'Identificador del píxel',
    'Access token (server events)' => 'Token de acceso (eventos del servidor)',
    'API version' => 'Versión de la API',
    'Meta changes it from time to time.' => 'Meta la cambia de vez en cuando.',
    'Test event code' => 'Código de evento de prueba',
    'UET tag ID' => 'Identificador de la etiqueta UET',
    'Your own code' => 'Tu propio código',
    'Code for visitors who accepted preferences' => 'Código para quien aceptó las preferencias',
    'Code for visitors who accepted statistics' => 'Código para quien aceptó las estadísticas',
    'Code for visitors who accepted marketing' => 'Código para quien aceptó el marketing',
    'Statistics' => 'Estadísticas',
    'Marketing' => 'Marketing',
    'Preferences' => 'Preferencias',
    'Necessary (no consent needed)' => 'Necesaria (no pide consentimiento)',
    'In demo shops you can review these settings, but external tags and custom scripts are never loaded and server events are never sent.' => 'En las tiendas de demostración puedes revisar estos ajustes, pero no se cargan etiquetas externas ni código personalizado y no se envían eventos del servidor.',
];

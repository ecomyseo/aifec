<?php
/**
 * AIFEC — catálogo castellano del módulo de pago por Redsys.
 * La clave es la cadena inglesa tal como está en el código; no se toca. El valor es la traducción.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'Redsys' => 'Redsys',
    'Card and Bizum payments through Redsys' => 'Cobros con tarjeta y con Bizum por Redsys',

    // Ajustes
    'Redsys gives you the merchant code, the terminal number and a secret key for each environment when it activates your shop.' => 'Redsys te da el código de comercio, el número de terminal y una clave secreta de cada entorno cuando activa tu tienda.',
    'Give the bank the notification address shown below: the order is marked as paid only when the bank calls it with a valid signature.' => 'Dale al banco la dirección de aviso que aparece abajo: el pedido se marca como pagado solo cuando el banco la llama con una firma válida.',
    'Start in the test environment and switch to live only after you have checked a whole test payment.' => 'Empieza en el entorno de pruebas y pasa a producción solo después de comprobar un pago de prueba completo.',
    'General' => 'General',
    'Keys' => 'Claves',
    'Environment' => 'Entorno',
    'Test: no real money moves' => 'Pruebas: no se mueve dinero de verdad',
    'Live: real payments' => 'Producción: pagos reales',
    'A payment made in the test environment is marked as a test order.' => 'Un pago hecho en el entorno de pruebas deja el pedido marcado como pedido de prueba.',
    'Merchant code' => 'Código de comercio',
    'Digits only.' => 'Solo cifras.',
    'Digits only. To test, sign up for your own Redsys test terminal and use its merchant code.' => 'Solo cifras. Para probar, date de alta en tu propio TPV de pruebas de Redsys y usa su código de comercio.',
    'Terminal' => 'Terminal',
    'Offer Bizum' => 'Ofrecer Bizum',
    'Your Redsys contract has to include Bizum.' => 'Tu contrato con Redsys tiene que incluir Bizum.',
    'Card payment title' => 'Título del pago con tarjeta',
    'Bizum title' => 'Título de Bizum',
    'Empty: the standard title.' => 'Vacío: el título de serie.',
    'Secret key (test)' => 'Clave secreta (pruebas)',
    'Secret key (live)' => 'Clave secreta (producción)',
    '32 characters. Saved encrypted; leave it empty to keep the current one.' => '32 caracteres. Se guarda cifrada; déjala vacía para conservar la actual.',
    '32 characters, from your own Redsys test terminal: AIFEC does not include one. Saved encrypted; leave it empty to keep the current one.' => '32 caracteres, la de tu propio TPV de pruebas de Redsys: AIFEC no trae ninguno. Se guarda cifrada; déjala vacía para conservar la actual.',
    'Notification address' => 'Dirección de aviso',
    'Your own test terminal' => 'Tu propio TPV de pruebas',
    'AIFEC does not include any Redsys test merchant or key. Whoever tests signs up for a test terminal of their own and types its merchant code, terminal and key here.' => 'AIFEC no trae ningún comercio ni clave de pruebas de Redsys. Quien hace las pruebas se da de alta en un TPV de pruebas propio y pone aquí su código de comercio, su terminal y su clave.',
    'Redsys: test cards and test environments' => 'Redsys: tarjetas y entornos de prueba',
    'Redsys: sign up for a test terminal' => 'Redsys: darse de alta en un TPV de pruebas',
    'Copy' => 'Copiar',
    'Give this address to the bank as the notification (merchant) URL.' => 'Dale esta dirección al banco como URL de notificación del comercio.',

    // Lo que ve el cliente
    'Bizum' => 'Bizum',
    'Card payment' => 'Pago con tarjeta',
    'Pay with Bizum on the secure page of the bank.' => 'Paga con Bizum en la página segura del banco.',
    'Pay with your card on the secure page of the bank.' => 'Paga con tu tarjeta en la página segura del banco.',
    'Order {number}' => 'Pedido {number}',
    'The payment was not completed. You can try again.' => 'El pago no se ha completado. Puedes intentarlo de nuevo.',

    // Lo que ve quien lleva la tienda, en el historial del pedido
    'The bank declined the payment (code {code}): {text}' => 'El banco ha rechazado el pago (código {code}): {text}',
    'The bank reported a payment in another currency ({currency}). Check it before shipping.' => 'El banco ha comunicado un pago en otra moneda ({currency}). Compruébalo antes de enviar.',

    // Códigos de respuesta de Redsys
    'Transaction approved' => 'Operación autorizada',
    'Card expired' => 'Tarjeta caducada',
    'Card temporarily suspended or under suspicion of fraud' => 'Tarjeta suspendida temporalmente o bajo sospecha de fraude',
    'Transaction not allowed for this card' => 'Operación no permitida para esta tarjeta',
    'PIN attempts exceeded' => 'Se ha superado el número de intentos del PIN',
    'Insufficient funds' => 'Saldo insuficiente',
    'Card not registered' => 'Tarjeta no registrada',
    'Card not effective' => 'Tarjeta todavía no activa',
    'Wrong security code (CVV)' => 'Código de seguridad (CVV) incorrecto',
    'Suspected fraud' => 'Sospecha de fraude',
    'Card not valid for this service' => 'Tarjeta no válida para este servicio',
    'Cardholder authentication failed' => 'Ha fallado la autenticación del titular',
    'Declined by the issuer without a reason' => 'Denegada por el emisor sin indicar el motivo',
    'Wrong expiry date' => 'Fecha de caducidad incorrecta',
    'Card suspended or under suspicion of fraud, retain card' => 'Tarjeta suspendida o bajo sospecha de fraude, retener la tarjeta',
    'Transaction approved for refunds and confirmations' => 'Operación autorizada para devoluciones y confirmaciones',
    'Merchant not registered' => 'Comercio no registrado',
    'System error' => 'Error del sistema',
    'Duplicated order number' => 'Número de pedido repetido',
    'Wrong session' => 'Sesión incorrecta',
    'Refund not allowed' => 'Devolución no permitida',
    'Payment cancelled by the customer' => 'Operación cancelada por el cliente',
    'Transaction waiting for the card data' => 'Operación a la espera de los datos de la tarjeta',
    'Transaction redirected to the issuer to authenticate' => 'Operación enviada al emisor para autenticar',
    'Unknown response' => 'Respuesta desconocida',
    // Google Pay con el token de una plataforma de IA (UCP)
    'Google Pay' => 'Google Pay',
    'What it is for' => 'Para qué sirve',
    'With this on, an AI platform that buys through UCP (Google AI Mode and Gemini) can pay your orders with Google Pay: it sends the card token encrypted for your bank and the shop charges it through Redsys at once. It does not appear in your normal checkout.' => 'Con esto encendido, una plataforma de IA que compra por UCP (el Modo IA de Google y Gemini) puede pagar tus pedidos con Google Pay: manda el token de la tarjeta cifrado para tu banco y la tienda lo cobra por Redsys en el momento. No sale en tu caja normal.',
    'Ask your bank to activate Google Pay on your Redsys terminal.' => 'Pide a tu banco que active Google Pay en tu TPV de Redsys.',
    'For real payments, register in the Google Pay & Wallet Console and copy your Google merchant ID below.' => 'Para cobrar de verdad, date de alta en la consola de Google Pay y Wallet y copia abajo tu identificador de comercio de Google.',
    'Turn on UCP in Settings → UCP and agents. Google only buys this way in some countries: today, the United States.' => 'Enciende UCP en Ajustes → UCP y agentes. Google solo compra así en algunos países: hoy, en Estados Unidos.',
    'Ready: Google Pay is offered through UCP in the {environment} environment.' => 'Listo: Google Pay se ofrece por UCP en el entorno {environment}.',
    'Not offered yet: turn it on and complete the Redsys details of the chosen environment.' => 'Todavía no se ofrece: enciéndelo y completa los datos de Redsys del entorno elegido.',
    'Accept Google Pay from AI platforms' => 'Aceptar Google Pay desde plataformas de IA',
    'The test environment of Redsys uses the test environment of Google Pay: nothing is charged.' => 'El entorno de pruebas de Redsys usa el entorno de pruebas de Google Pay: no se cobra nada.',
    'Google merchant ID' => 'Identificador de comercio de Google',
    'From the Google Pay & Wallet Console. Needed for real payments; not for tests.' => 'De la consola de Google Pay y Wallet. Hace falta para cobrar de verdad; para las pruebas, no.',
    'Card networks' => 'Redes de tarjeta',
    'Separated by commas: VISA, MASTERCARD, AMEX, DISCOVER, JCB. Only those your Redsys contract accepts.' => 'Separadas por comas: VISA, MASTERCARD, AMEX, DISCOVER, JCB. Solo las que acepte tu contrato con Redsys.',
    'Also accept cards saved in the Google account' => 'Aceptar también las tarjetas guardadas en la cuenta de Google',
    'They may need 3D Secure, which cannot be done through the platform: those orders are finished in your checkout. Cards on the phone are always accepted.' => 'Pueden necesitar 3D Secure, que no se puede hacer a través de la plataforma: esos pedidos se terminan en tu caja. Las tarjetas del móvil se aceptan siempre.',
    'Paid with Google Pay from the assistant, through the bank of the shop.' => 'Pagado con Google Pay desde el asistente, a través del banco de la tienda.',
    'There is no Google Pay payment to charge.' => 'No hay ningún pago de Google Pay que cobrar.',
    'Google Pay through Redsys only charges in euros.' => 'Google Pay por Redsys solo cobra en euros.',
    'The Google Pay payment could not be prepared.' => 'No se ha podido preparar el pago con Google Pay.',
    'The bank could not be reached. Nothing was charged.' => 'No se ha podido contactar con el banco. No se ha cobrado nada.',
    'The bank sent an answer that cannot be read. Nothing was charged.' => 'El banco ha mandado una respuesta que no se puede leer. No se ha cobrado nada.',
    'The bank refused the Google Pay request ({code}). Nothing was charged.' => 'El banco ha rechazado la petición de Google Pay ({code}). No se ha cobrado nada.',
    'The answer of the bank could not be verified. Nothing was marked as paid.' => 'No se ha podido verificar la respuesta del banco. No se ha dado nada por pagado.',
    'The answer of the bank does not match this order. Nothing was marked as paid.' => 'La respuesta del banco no corresponde a este pedido. No se ha dado nada por pagado.',
];

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/*
 * Catálogo castellano de WebMCP. La clave es la cadena inglesa tal como está en el código; no se toca.
 */
return [
    '«WebMCP» lets the AI assistant in a visitor\'s own browser use your shop for them; it is not a chat of the shop. The chat of the shop is «Assistant».' => '«WebMCP» deja que el asistente de IA del navegador de un visitante use tu tienda por él; no es un chat de la tienda. El chat de la tienda es «Asistente».',
    // ------------------------------------------------------------------ módulo, permisos y menú
    'Tools and annotated forms for AI agents that browse the shop in a browser' => 'Herramientas y formularios marcados para los agentes de IA que navegan por la tienda en un navegador',
    'See the tools and forms offered to AI agents in the browser' => 'Ver las herramientas y los formularios que se ofrecen a los agentes de IA en el navegador',
    'Change the WebMCP settings' => 'Cambiar los ajustes de WebMCP',
    'WebMCP' => 'WebMCP',
    'WebMCP settings' => 'Ajustes de WebMCP',

    // ------------------------------------------------------------------ ajustes
    'Lets an AI agent that browses the shop use its tools' => 'Deja que un agente de IA que navega por la tienda use sus herramientas',
    'WebMCP is the way a browser tells an AI agent what a page can do. The shop publishes its search, cart and checkout tools, and marks its forms so the agent knows what each one is for.' => 'WebMCP es la forma en que un navegador le cuenta a un agente de IA qué puede hacer una página. La tienda publica sus herramientas de búsqueda, carrito y caja, y marca sus formularios para que el agente sepa para qué sirve cada uno.',
    'The agent works on the visitor\'s own cart. It takes the visitor up to the Place order button: the order is always confirmed by the person on the page.' => 'El agente trabaja sobre el carrito del propio visitante. Lo lleva hasta el botón de hacer el pedido: el pedido lo confirma siempre la persona en la página.',
    'Turning it off removes the script, the form marks and the tool list from every page.' => 'Al apagarlo desaparecen de todas las páginas el guion, las marcas de los formularios y la lista de herramientas.',
    'General' => 'General',
    'Offer the shop tools to AI agents in the browser' => 'Ofrecer las herramientas de la tienda a los agentes de IA en el navegador',
    'Marks the forms and loads a small script that only runs when the browser offers WebMCP.' => 'Marca los formularios y carga un guion pequeño que solo actúa si el navegador ofrece WebMCP.',

    // ------------------------------------------------------------------ formularios marcados (los lee el agente)
    'Search the shop catalogue. Give the words to look for and the results page opens.' => 'Buscar en el catálogo de la tienda. Indica las palabras que buscar y se abre la página de resultados.',
    'What to look for, in the customer\'s own words' => 'Qué buscar, con las palabras del propio cliente',
    'Add this product to the cart. The price is always the shop\'s own.' => 'Añadir este producto al carrito. El precio es siempre el de la tienda.',
    'The product number, already filled in' => 'El número del producto, ya rellenado',
    'The variant number, 0 when the product has no variants' => 'El número de la variante, 0 si el producto no tiene variantes',
    'Units to add' => 'Unidades que añadir',
    'The value chosen for this option, for example the size or the colour' => 'El valor elegido para esta opción, por ejemplo la talla o el color',
    'Change the quantity of cart line {n}: {name}.' => 'Cambiar la cantidad de la línea {n} del carrito: {name}.',
    'The cart line, already filled in' => 'La línea del carrito, ya rellenada',
    'The new quantity' => 'La cantidad nueva',
    'Apply a discount coupon to the cart.' => 'Aplicar un cupón de descuento al carrito.',
    'The coupon code' => 'El código del cupón',
    'Estimate the delivery options and their price for a country and a postcode.' => 'Calcular las opciones de entrega y su precio para un país y un código postal.',
    'The region, leave it empty' => 'La provincia, déjala vacía',
    'Two-letter country code, for example ES' => 'Código del país de dos letras, por ejemplo ES',
    'The postcode of the destination' => 'El código postal del destino',
    'Sign in to the customer account with the email and the password.' => 'Entrar en la cuenta de cliente con el correo y la contraseña.',
    'The email of the account' => 'El correo de la cuenta',
    'The password of the account' => 'La contraseña de la cuenta',
    'Where to go after signing in, already filled in' => 'Adónde ir después de entrar, ya rellenado',
    'Subscribe an email address to the shop newsletter.' => 'Suscribir un correo al boletín de la tienda.',
    'The email to subscribe' => 'El correo que suscribir',
    'Send a message to the shop through the contact form.' => 'Enviar un mensaje a la tienda con el formulario de contacto.',
    'The name of the person writing' => 'El nombre de quien escribe',
    'Add «{name}» to the cart. The price is always the shop\'s own.' => 'Añadir «{name}» al carrito. El precio es siempre el de la tienda.',
    'Send 1 only if the person has said they accept the privacy policy' => 'Manda 1 solo si la persona ha dicho que acepta la política de privacidad',
    'Anti-spam trap: always leave it empty' => 'Trampa contra el correo basura: déjalo siempre vacío',
    'Filled in by the page: leave it empty' => 'Lo rellena la propia página: déjalo vacío',
    'The email where the shop will answer' => 'El correo donde contestará la tienda',
    'What the person wants to ask or say' => 'Lo que la persona quiere preguntar o decir',

    // ------------------------------------------------------------------ llms.txt
    'WebMCP tools for agents in the browser' => 'Herramientas WebMCP para agentes en el navegador',

    // ------------------------------------------------------------------ las direcciones
    'The order is confirmed by the person on the page, not through this tool.' => 'El pedido lo confirma la persona en la página, no con esta herramienta.',
    'Unknown tool: {name}' => 'Herramienta desconocida: {name}',
    'The input is not valid' => 'La entrada no es válida',
    'Too many requests. Wait a moment and try again.' => 'Demasiadas peticiones. Espera un momento e inténtalo de nuevo.',

    // ------------------------------------------------------------------ panel
    'The tools are what an AI agent can do in the shop from the browser: search, look at a product, fill the cart and prepare the checkout. They are the same ones the assistant, the API and the MCP server use.' => 'Las herramientas son lo que un agente de IA puede hacer en la tienda desde el navegador: buscar, ver un producto, llenar el carrito y preparar la caja. Son las mismas que usan el asistente, la API y el servidor MCP.',
    'The order itself is never placed by a tool: the agent takes the visitor to the Place order button and the visitor confirms.' => 'El pedido en sí no lo hace nunca una herramienta: el agente lleva al visitante hasta el botón de hacer el pedido y es el visitante quien confirma.',
    'The forms are marked in the page so the agent knows what each one is for. A form without a template is defined but not shown in the shop yet.' => 'Los formularios van marcados en la página para que el agente sepa para qué sirve cada uno. Un formulario sin plantilla está definido pero todavía no sale en la tienda.',
    'WebMCP is turned off: the pages carry no script, no form marks and no tool list.' => 'WebMCP está apagado: las páginas no llevan guion, ni marcas en los formularios, ni lista de herramientas.',
    'WebMCP is on.' => 'WebMCP está encendido.',
    'Tool list' => 'Lista de herramientas',
    'Published tools' => 'Herramientas publicadas',
    'Tool' => 'Herramienta',
    'What it does' => 'Qué hace',
    'Kind' => 'Tipo',
    'Reads' => 'Lee',
    'Changes something' => 'Cambia algo',
    'Marked forms' => 'Formularios marcados',
    'Form' => 'Formulario',
    'Fields' => 'Campos',
    'State' => 'Estado',
    'In {n} templates' => 'En {n} plantillas',
    'Not in any template yet' => 'Todavía en ninguna plantilla',
];

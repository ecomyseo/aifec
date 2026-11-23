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
 * Catálogo castellano del módulo MCP. La clave es la cadena inglesa tal como está en el código; no se toca.
 */
return [
    // ------------------------------------------------------------------ módulo, permisos y menú
    'Model Context Protocol server for assistants' => 'Servidor del Protocolo de Contexto de Modelos (MCP) para asistentes',
    'Change the MCP server settings' => 'Cambiar los ajustes del servidor MCP',

    // ------------------------------------------------------------------ ajustes
    'MCP' => 'MCP',
    'The Model Context Protocol server: lets an AI assistant use the shop with the same tools as the shop assistant' => 'El servidor del Protocolo de Contexto de Modelos: deja a un asistente de IA usar la tienda con las mismas herramientas que el asistente de la tienda',
    'An AI assistant (Claude, ChatGPT and others) connects to the address of this server and can search the catalogue, build a cart and take the purchase to the end with the same tools as the assistant of the shop.' => 'Un asistente de IA (Claude, ChatGPT y otros) se conecta a la dirección de este servidor y puede buscar en el catálogo, montar un carrito y llevar la compra hasta el final con las mismas herramientas que el asistente de la tienda.',
    'Each connection has its own cart. To place an order the person confirms the total with the code the shop emails to them, or the assistant uses a key of the API with the scope «orders:create»: it is the setting «Orders placed by AI agents» of the API.' => 'Cada conexión tiene su propio carrito. Para hacer un pedido la persona confirma el total con el código que la tienda le envía por correo, o el asistente usa una clave de la API con el ámbito «orders:create»: es el ajuste «Pedidos hechos por agentes de IA» de la API.',
    'The limits (highest order, orders per hour) are the ones of the API settings.' => 'Los límites (pedido más alto, pedidos por hora) son los de los ajustes de la API.',
    'Turn the MCP server on' => 'Encender el servidor MCP',
    'Proof for the official MCP Registry' => 'Prueba para el registro oficial de MCP',
    'The line «v=MCPv1; k=ed25519; p=…» with the public key. It is published at /.well-known/mcp-registry-auth so the registry can check that the domain is yours when you publish the server.' => 'La línea «v=MCPv1; k=ed25519; p=…» con la clave pública. Se publica en /.well-known/mcp-registry-auth para que el registro compruebe que el dominio es tuyo al publicar el servidor.',
    'Off, its addresses answer 404.' => 'Apagado, sus direcciones contestan 404.',
    'Tools' => 'Herramientas',
    'Tools the assistants can use' => 'Herramientas que pueden usar los asistentes',
    'Only the ticked tools are offered. By default all but the order status.' => 'Solo se ofrecen las marcadas. Por defecto, todas menos el estado de un pedido.',
    'Search products' => 'Buscar productos',
    'Product details' => 'Ficha de un producto',
    'List the categories' => 'Listar las categorías',
    'Check availability' => 'Comprobar la disponibilidad',
    'Shipping quote' => 'Coste de envío',
    'Shop information and policies' => 'Información y políticas de la tienda',
    'See the cart' => 'Ver el carrito',
    'Add to the cart' => 'Añadir al carrito',
    'Change the cart' => 'Cambiar el carrito',
    'Address to pay on the web' => 'Dirección para pagar en la web',
    'Order status (needs the order number and its email)' => 'Estado de un pedido (pide el número y su correo)',
    'Checkout: where the purchase is' => 'Caja: en qué punto está la compra',
    'Checkout: save contact and address' => 'Caja: guardar contacto y dirección',
    'Checkout: delivery options' => 'Caja: opciones de envío',
    'Checkout: choose the delivery' => 'Caja: elegir el envío',
    'Checkout: payment methods' => 'Caja: formas de pago',
    'Checkout: choose the payment' => 'Caja: elegir el pago',
    'Checkout: review the order' => 'Caja: revisar el pedido',
    'Checkout: place the order (needs the code emailed to the buyer, or a key with the scope orders:create)' => 'Caja: hacer el pedido (pide el código enviado por correo al comprador, o una clave con el ámbito orders:create)',
    'MCP server of {shop}' => 'Servidor MCP de {shop}',
    'Address: {url} · Model Context Protocol over HTTP (JSON-RPC by POST) · no key needed.' => 'Dirección: {url} · Model Context Protocol por HTTP (JSON-RPC por POST) · sin clave.',
    'How to connect it' => 'Cómo se conecta',
    'Claude: in the settings, «Connectors», add a custom connector with this address.' => 'Claude: en los ajustes, «Conectores», añade un conector personalizado con esta dirección.',
    'ChatGPT: in the settings of apps and connectors (developer mode), create a connector with this address.' => 'ChatGPT: en los ajustes de aplicaciones y conectores (modo desarrollador), crea un conector con esta dirección.',
    'Other MCP clients (Gemini CLI, Cursor, VS Code…): add an HTTP server with this address.' => 'Otros clientes MCP (Gemini CLI, Cursor, VS Code…): añade un servidor HTTP con esta dirección.',
    'With it, the assistant searches the catalogue, fills a cart and prepares the order; the person confirms it with a code the shop emails them and pays on the page of the shop.' => 'Con él, el asistente busca en el catálogo, llena un carrito y prepara el pedido; la persona lo confirma con un código que le manda la tienda por correo y paga en la página de la tienda.',
    'Server card: {card}' => 'Ficha del servidor: {card}',
    'Without connectors, an assistant can also buy here by opening addresses: {url}' => 'Sin conectores, un asistente también puede comprar aquí abriendo direcciones: {url}',
    'Search products in {store}' => 'Buscar productos en {store}',
    'Add a product of {store} to a cart' => 'Añadir un producto de {store} a un carrito',
    'Get a checkout link for a {store} cart' => 'Obtener el enlace de compra de un carrito de {store}',
    'What does {store} sell?' => '¿Qué vende {store}?',
    'Read the policies and how to buy at {store}' => 'Leer las políticas y cómo comprar en {store}',
    'Search the {store} catalogue through an API' => 'Buscar en el catálogo de {store} mediante una API',
    'Create a cart and check out at {store} through an API' => 'Crear un carrito y comprar en {store} mediante una API',
    'Buy from {store} with the Universal Commerce Protocol' => 'Comprar en {store} con el Protocolo de Comercio Universal',
    'Buy from {store} with the Agentic Commerce Protocol' => 'Comprar en {store} con el Protocolo de Comercio Agéntico',
    'Model Context Protocol server of the shop: search the catalogue, build a cart and get a checkout link' => 'Servidor del Protocolo de Contexto de Modelos de la tienda: buscar en el catálogo, montar un carrito y obtener el enlace de compra',
    'Summary of the shop for language models: categories, best sellers, policies and the doors for agents' => 'Resumen de la tienda para modelos de lenguaje: categorías, más vendidos, políticas y puertas para agentes',
    'Description of the shop API: catalogue search, carts and checkout' => 'Descripción de la API de la tienda: búsqueda en el catálogo, carritos y compra',
    'Universal Commerce Protocol profile of the shop' => 'Perfil del Protocolo de Comercio Universal de la tienda',
    'Agentic Commerce Protocol profile of the shop' => 'Perfil del Protocolo de Comercio Agéntico de la tienda',
];

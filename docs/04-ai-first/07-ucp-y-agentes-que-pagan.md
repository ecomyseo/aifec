# UCP y agentes que pagan

Algunas plataformas de IA ya no se limitan a recomendar: compran. Google lo hace desde el Modo IA y Gemini con **UCP** (Universal Commerce Protocol), y ChatGPT lo hace con su **agente** y su **monedero** (ChatGPT Wallet). El módulo **Comercio con agentes** prepara tu tienda para las dos cosas.

## En qué países funciona hoy

A noviembre de 2026, las dos solo compran **en Estados Unidos**:

- **UCP de Google**: Estados Unidos; Canadá y Australia anunciados, después el Reino Unido. Para el resto de Europa no hay fecha.
- **Monedero de ChatGPT**: Estados Unidos, con el plan Pro y tarjetas de allí. Fuera del Espacio Económico Europeo, Suiza y el Reino Unido.

Por eso el módulo viene **apagado**: lo enciendes cuando Google o tu banco te den acceso, y ya está todo hecho.

## UCP: la compra desde Google

Con UCP la plataforma no abre tu web: habla con tu tienda por una API. Monta el carrito, pide los gastos de envío a una dirección, enseña el total a la persona y, cuando ésta paga con **Google Pay**, le pide a tu tienda que haga el pedido.

Todo pasa por la caja de tu tienda: los mismos precios, impuestos, envíos, existencias y avisos que en la web. El pedido aparece en tu panel con el canal **Plataforma de IA (UCP)** y la plataforma que lo trajo.

Lo que hace falta:

1. Encender **UCP** en *Ajustes → UCP y agentes*. La dirección del perfil (`/.well-known/ucp`) es la que se da de alta en Google Merchant Center.
2. Elegir la **página de privacidad** (y tener la de condiciones en los ajustes de pedidos): UCP pide las dos.
3. Crear en la **API** una clave con el permiso `orders:create` y dársela a la plataforma. Sin esa clave, nadie puede cerrar una compra.
4. Para cobrar dentro de la plataforma, encender **Google Pay** en *Ajustes → Redsys*, pedir al banco que active Google Pay en tu TPV y, para cobrar de verdad, darte de alta en la consola de Google Pay y Wallet.

Sin Google Pay, UCP funciona igual: la plataforma prepara la compra y manda a la persona a tu caja con el carrito ya lleno para pagar allí.

### Qué ocurre si el pago no sale

- **Tarjeta rechazada**: no se cobra nada; la compra sigue abierta y la persona puede probar con otra.
- **El banco pide confirmar la tarjeta** (3D Secure): eso no se puede hacer dentro de la plataforma, así que la persona termina en tu caja.
- **Pedido por encima del máximo**: el máximo de pedidos por programas de los ajustes de la API vale también aquí; por encima, a tu caja.

El token de Google Pay solo vive en la memoria mientras dura el cobro: no se guarda en la base de datos, ni en el registro, ni en la respuesta.

## El agente y el monedero de ChatGPT

El agente de ChatGPT compra **en tu web**, como una persona, con un navegador en la nube; el monedero solo le da una tarjeta para pagar en tu caja. No hay nada que conectar.

Lo que sí hace tu tienda es **reconocerlo**: el agente firma cada petición (Web Bot Auth) y la tienda comprueba la firma con la clave pública que publica ChatGPT. Si la firma es buena:

- la visita y la venta quedan **atribuidas a ChatGPT**;
- los límites por dirección se aplican a la persona que lo usa, no a todos los que compran desde el mismo navegador en la nube.

No bloquea a nadie y no da permisos especiales: una firma que no se puede comprobar es una visita normal. En *Ajustes → UCP y agentes → Agentes que firman* ves cuándo vino el último agente verificado y eliges de qué agentes te fías (de serie, solo `https://chatgpt.com`).

## Para quien programa

- Perfil: `GET /.well-known/ucp` (versión 2026-04-08, servicio `dev.ucp.shopping` por REST, capacidades de compra y entrega).
- API: `POST /ucp/v1/checkout-sessions`, `GET` y `PUT /ucp/v1/checkout-sessions/{id}`, `POST …/{id}/complete` y `POST …/{id}/cancel`. Admite `Idempotency-Key`.
- El identificador de cada artículo es el del feed de Merchant Center: `<producto>` o `<producto>-<variante>`.
- Google Pay se anuncia con el manejador `com.google.pay` y se cobra por la API REST de Redsys.

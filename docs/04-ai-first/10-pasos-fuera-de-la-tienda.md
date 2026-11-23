# Pasos fuera de la tienda: Google, ChatGPT, Claude, Microsoft, Perplexity y Redsys

Tener los ficheros listos no basta: cada plataforma decide si los lee y casi todas piden un alta previa. Estas guías dicen qué hacer en su lado, por orden. Los nombres de los menús cambian de vez en cuando; si alguno no coincide, busca la misma idea.

Cambia `TU-TIENDA` por la dirección de tu tienda. Las direcciones de abajo las genera AIFEC solas; en **Ajustes → Agentes** puedes comprobar que están activas. En el módulo `ecom_aifec` de PrestaShop el conector MCP es `https://TU-TIENDA/mcp-ia` (en la plataforma AIFEC es `https://TU-TIENDA/mcp`), y las mismas guías están en la pestaña «Pasos fuera de la tienda» de su configuración.

**Dos cosas distintas, que no hay que mezclar.** *Llegar a la caja de la tienda*: el asistente busca, arma el carrito y entrega un enlace (`continue_url`); la persona paga en la caja de tu tienda, con tu pasarela. Esto funciona hoy en todas las tiendas con AIFEC y no necesita ningún alta. *Pagar dentro de ChatGPT o Gemini*: depende de programas de esas empresas (acceso limitado, lista de espera) y de que tu pasarela los admita; mientras no estés dentro, `is_eligible_checkout` es `false` y `payment_handlers` va vacío, a propósito. Que un asistente no cobre dentro de su chat no es un fallo de la tienda.

**Antes de pedir el alta**, descarga tu fichero de productos y comprueba que tiene tantas filas como productos vendes, que ninguna fila se queda sin precio ni imagen y que, si faltan identificadores (GTIN o MPN), aparece `identifier_exists=no`. Si Google rechaza productos, abre Diagnósticos en Merchant Center, corrige el dato en la tienda y espera a la siguiente lectura diaria.

Las condiciones de los programas de terceros (acceso anticipado, países, listas de espera, correos de soporte) cambian con frecuencia y esta guía las dio por buenas el 23/11/2026: compruébalas en la página oficial de cada programa antes de empezar.

## Google Merchant Center (fichas gratuitas y compras en Gemini)

Merchant Center es donde Google lee tu catálogo. Es gratis y es la base de todo lo demás de Google.

1. Entra en merchants.google.com con la cuenta de Google de la empresa y crea la cuenta (país, nombre del negocio, web).
2. Verifica y reclama la web: Merchant Center te da un fichero HTML o una etiqueta; súbelo a la tienda y pulsa verificar.
3. Añade los envíos, las devoluciones y los impuestos en los ajustes de Merchant Center. Google rechaza los productos si faltan.
4. Ve a Productos, luego Fuentes de datos, y añade una fuente de productos con lectura programada. Elige tu país y tu idioma.
5. Pega esta dirección como fichero a leer, pon la lectura en diaria y guarda.  
   `https://TU-TIENDA/acp/feed-google.tsv`
6. Espera a la primera lectura y abre los diagnósticos: corrige lo que Google indique (suele ser falta de identificadores o de imágenes).

## Google: pagar dentro de Gemini y el Modo IA (UCP y Google Pay)

Hoy es un programa de acceso anticipado, limitado a Estados Unidos. Hacer los pasos pronto no cuesta nada y te deja preparado.

1. Termina antes los pasos de Merchant Center de arriba.
2. Comprueba que tu perfil UCP es público y válido. Es la dirección que leerá Google.  
   `https://TU-TIENDA/.well-known/ucp`
3. Crea el perfil de empresa en la consola de Google Pay y Wallet (pay.google.com/business/console) y registra la web. Google te da un ID de comerciante que empieza por BCR2DN.
4. Pide a tu banco o a Redsys que activen Google Pay en tu TPV virtual y pregunta qué valor usar como gateway merchant ID. El ID de Google Pay no es el código de comercio de Redsys: hacen falta los dos.
5. En Merchant Center abre Ajustes, luego Integración UCP, y envía el formulario de interés. Google te avisa si te selecciona.
6. Hasta que Google te seleccione, los compradores terminan en tu caja. No se rompe nada.

## ChatGPT (OpenAI): feed de productos y Agentic Commerce Protocol

OpenAI lee los feeds de los comerciantes aprobados. El pago se hace en la web del comercio.

1. Abre chatgpt.com/merchants y rellena el formulario. Si ya lo enviaste estás en lista de espera; no hay que hacer nada más hasta que contesten.
2. Cuando te acepten te piden un feed de muestra para validarlo. Es el feed de la tienda.  
   `https://TU-TIENDA/acp/feed.jsonl`
3. Tras la validación toman una copia completa cada día. Mantén la tienda accesible en esa dirección.
4. La tienda ya tiene listo el perfil ACP y la API de compra. Da a OpenAI esta dirección si te la piden.  
   `https://TU-TIENDA/.well-known/acp.json`
5. Pagar dentro de la conversación necesita un proveedor de pago que acepte el token de pago compartido (hoy Stripe). Solo con Redsys, el comprador termina en tu caja.

## Claude, ChatGPT y otros asistentes: conectar la tienda como conector

Cualquier persona puede conectar la tienda a su asistente. No hace falta la aprobación de nadie y el comprador confirma con un código que le llega por correo.

1. Copia la dirección del conector de la tienda (MCP).  
   `https://TU-TIENDA/mcp`
2. En Claude (claude.ai): abre Ajustes, luego Conectores, luego Añadir conector personalizado, pega la dirección y guarda.
3. En ChatGPT: abre Ajustes, luego Conectores (puede hacer falta el modo desarrollador), añade un conector personalizado y pega la misma dirección.
4. Los demás asistentes que admiten conectores MCP funcionan igual: solo necesitan la dirección.
5. Pruébalo pidiendo al asistente que busque un producto y prepare un pedido. La tienda envía un código de seis cifras al comprador; sin él no se compra nada.
6. Para los asistentes que solo leen páginas, la tienda publica también un resumen para máquinas en esta dirección.  
   `https://TU-TIENDA/llms.txt`

## Microsoft Copilot y Bing

Copilot lee los productos a través de Microsoft Merchant Center.

1. Crea una cuenta de Microsoft Advertising y abre desde ella Microsoft Merchant Center.
2. Añade tu tienda y verifica la web.
3. Crea un catálogo de productos: puedes importarlo desde Google Merchant Center o usar el mismo fichero con lectura programada.  
   `https://TU-TIENDA/acp/feed-google.tsv`
4. Solicita el programa Copilot Merchant Program desde la misma cuenta si aparece la opción.

## Perplexity

Perplexity tiene un programa de comerciantes. No ha publicado una especificación única de feed; acepta un feed en el formato de Google Merchant.

1. Busca el alta del programa de comerciantes de Perplexity en su web y envía la solicitud.
2. Cuando te pidan un feed, da el fichero de Google Merchant.  
   `https://TU-TIENDA/acp/feed-google.tsv`

## Redsys: qué pedir a tu banco

Redsys todavía no ha anunciado pagos hechos por agentes de IA. Preguntar ahora muestra que hay demanda y te consigue una respuesta.

1. Pregunta a tu banco: active Google Pay en mi TPV virtual Redsys. Qué modo de conexión necesito y qué valor es el gateway merchant ID.
2. Escribe a soportevirtual@redsys.es (con copia a info@redsys.es) preguntando si Redsys aceptará los tokens de Google Pay que lleguen desde agentes de IA y un token de pago compartido como el de OpenAI.
3. Pregunta también por los productos de pago para agentes de Visa y Mastercard y cómo funcionaría la autenticación reforzada.

## Qué es solo tuyo y qué depende de ellos

Todo lo de arriba que empieza por «crea», «verifica» o «solicita» lo tienes que hacer tú en la web de cada plataforma. AIFEC deja listos los ficheros y las direcciones; la aprobación y el momento en que empiezan a leerlos los decide cada plataforma.

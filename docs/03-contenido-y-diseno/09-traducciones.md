# Traducciones

Si tu tienda vende en más de un idioma, en «Traducciones» ves **qué falta en cada uno** y lo traduces a mano, con un fichero para un traductor o con la IA. Lo que traduce la IA es siempre una propuesta: no sale en la tienda hasta que una persona lo aprueba.

Se llega desde «Ajustes» → «Todos los ajustes» → tarjeta «Traducciones» → «Abrir las traducciones de cada idioma». Los idiomas de la tienda se eligen en «Ajustes» → «General» → «Idiomas y moneda»; con un solo idioma, la pantalla lo dice y no hay nada que traducir.

## Qué está traducido

Cada idioma tiene su pestaña. La tabla «Qué está traducido» lleva una fila por clase de texto, con cuántos hay («Total»), cuántos están «Traducidos», cuántos están «Propuestos por la IA» esperando y cuántos «Faltan»:

| Fila | Qué es | Dónde se traduce |
|---|---|---|
| «Interfaz de la tienda» | Botones, etiquetas y mensajes que ven los clientes | Aquí |
| «Interfaz del panel» | Menús, botones y mensajes del panel, para el equipo | Aquí |
| «Productos» | Nombre, descripciones, datos clave, preguntas y textos para buscadores | Aquí, con la IA; a mano, en la ficha del producto |
| «Páginas y entradas» | Título, extracto y textos para buscadores | Aquí, con la IA; el cuerpo, en el editor de la página |
| «Menús» | Las etiquetas escritas a mano en los menús | Aquí |
| «Correos» | Asunto, saludo y despedida | En «Plantillas de correo» ([Correo](../02-tienda/11-correo.md)), también con IA |
| «Categorías» y «Atributos y valores» | Sus nombres y descripciones | En su ficha (aquí solo se cuentan) |

## A mano

«Ver y editar» abre los textos de esa fila, de 50 en 50: el «Original (inglés)», la «Traducción» y, si la hay, la «Propuesta de la IA» con sus botones «Aprobar» y «Descartar». Arriba puedes buscar («Buscar en inglés o en la traducción») y quedarte con «Solo lo que falta». «Guardar esta página» guarda lo que has escrito.

También puedes **cambiar una frase del idioma principal** («Cambiar un texto del idioma principal»): si no te gusta cómo dice algo la tienda, lo escribes a tu manera. Dejar un campo vacío vuelve al texto de serie.

Dos cosas que la pantalla comprueba por ti:

- Los marcadores entre llaves (`{name}`, `{n}`) tienen que seguir ahí: una traducción que pierde o se inventa uno no se guarda, y te dice cuál («Falta el marcador {name}»).
- Los plurales («1 producto | 2 productos») tienen que traer las mismas formas.

## Con un traductor

En «Traducir fuera de la tienda», «Descargar el CSV» te da los textos de la interfaz con las columnas origen, traducción, zona y procedencia. El traductor rellena la columna de traducción y tú lo subes con «Importar el CSV» (o lo pegas). Al terminar te dice cuántas ha guardado, cuántas estaban igual, cuántas venían vacías y cuántas ha rechazado, con su línea y su motivo.

## Con la IA

Con la IA encendida («Inteligencia artificial» → «Ajustes de la IA»), cada fila tiene «Traducir lo que falta». Antes de lanzarlo te dice lo que va a costar, aproximadamente («unas 4.000 fichas, 2 céntimos»). Se hace en segundo plano, por tandas, y cuenta para el tope diario de la IA. En «Traducciones de la IA» ves cada encargo y puedes «Pausar», «Reanudar» o «Parar»: lo que ya ha propuesto se queda.

Lo que propone espera en la columna «Propuestos por la IA». Lo revisas en «Ver y editar» («Revisar las propuestas de la IA») y lo apruebas texto a texto, o con «Aprobar todo» si te fías de la fila. Al aprobar el nombre de un producto o el título de una página, su dirección en el idioma nuevo se crea a partir del texto traducido.

Para que acierte:

- **«Qué vende la tienda»** (en los ajustes de «Traducciones»): unas pocas palabras que acompañan a cada encargo.
- **El «Glosario»** de cada idioma, en su pestaña: un término por línea. «carrito = panier» dice cómo se traduce; un nombre solo en su línea no se traduce nunca (tu marca, un modelo).

El ajuste «Publicar las traducciones de la IA sin revisar» viene apagado. Enciéndelo solo si prefieres tener la tienda traducida ya y revisar después: la pantalla te lo recuerda con un aviso.

A la IA solo viajan los textos de la tienda. Nunca un dato de un cliente.

## Si algo no sale

- **No veo la pestaña de un idioma.** No está en «Idiomas» de «Ajustes» → «General».
- **«Traducir lo que falta» no aparece.** La IA está apagada o sin clave, o no tienes permiso para usarla.
- **He traducido un texto y la tienda sigue igual.** Si lo tradujo la IA, está esperando: apruébalo. Si lo escribiste tú, mira que hayas pulsado «Guardar esta página».
- **Una traducción «no se ha guardado».** El aviso dice el motivo: casi siempre un marcador `{…}` que falta o que sobra.
- **Las categorías no se pueden traducir aquí.** Todavía se traducen en su ficha; la tabla solo las cuenta.

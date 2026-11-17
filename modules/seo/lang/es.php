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
 * Catálogo castellano del módulo SEO. La clave es la cadena inglesa tal como está en el código; no se toca.
 */
return [
    // ------------------------------------------------------------------ módulo, permisos y menú
    'SEO' => 'SEO',
    'Titles, sitemaps, robots and SEO audit' => 'Títulos, mapas del sitio, robots y auditoría SEO',
    'See the SEO audit' => 'Ver la auditoría SEO',
    'Change the SEO settings' => 'Cambiar los ajustes de SEO',
    'SEO audit' => 'Auditoría SEO',
    'SEO settings' => 'Ajustes de SEO',

    // ------------------------------------------------------------------ ajustes
    'Titles, descriptions, robots and site verification' => 'Títulos, descripciones, robots y verificación del sitio',
    'General: the switch that hides the whole site from search engines while you are building it, and how many pages of a listing can be indexed.' => 'General: el interruptor que oculta todo el sitio a los buscadores mientras lo montas, y cuántas páginas de un listado se pueden indexar.',
    'Titles: a template for the title and the description of each kind of page. It only fills what a page does not have written by hand.' => 'Títulos: una plantilla para el título y la descripción de cada tipo de página. Solo rellena lo que una página no trae escrito a mano.',
    'Robots: who can read the shop. Search engines bring visitors; AI assistants read a page to answer a person; AI training crawlers collect pages to train models.' => 'Robots: quién puede leer la tienda. Los buscadores traen visitas; los asistentes de IA leen una página para contestar a una persona; los robots de entrenamiento recogen páginas para entrenar modelos.',
    'Verification: the codes that Google and Bing give you to prove that the site is yours.' => 'Verificación: los códigos que te dan Google y Bing para demostrar que el sitio es tuyo.',
    'General' => 'General',
    'Titles' => 'Títulos',
    'Robots' => 'Robots',
    'Verification' => 'Verificación',
    'Hide the whole site from search engines' => 'Ocultar todo el sitio a los buscadores',
    'Adds «noindex» to every page and blocks search engines and training robots. An assistant that reads one page for a person at that moment can still read it (it indexes nothing): close that below, in «AI assistants». Turn it off when the shop is ready.' => 'Añade «noindex» a todas las páginas y bloquea a los buscadores y a los robots de entrenamiento. Un asistente que lee una página para una persona en ese momento la puede seguir leyendo (no indexa nada): eso se cierra más abajo, en «Asistentes de IA». Apágalo cuando la tienda esté lista.',
    'Last page of a listing that can be indexed' => 'Última página de un listado que se puede indexar',
    'The following pages are marked «noindex». 0 = no limit.' => 'Las páginas siguientes se marcan «noindex». 0 = sin límite.',
    'Variables: {name}, {shop}, {category}, {brand}, {price} and {page}' => 'Variables: {name}, {shop}, {category}, {brand}, {price} y {page}',
    'A page with its own meta title or description keeps it. A variable that is empty leaves no stray separators. Titles are cut at 65 characters and descriptions at 160.' => 'Una página con su propio meta título o descripción lo conserva. Una variable vacía no deja separadores sueltos. Los títulos se cortan a 65 caracteres y las descripciones a 160.',
    'Title of the home page' => 'Título de la portada',
    'Description of the home page' => 'Descripción de la portada',
    'Title of a product' => 'Título de un producto',
    'Description of a product' => 'Descripción de un producto',
    'Title of a category' => 'Título de una categoría',
    'Description of a category' => 'Descripción de una categoría',
    'Title of a brand' => 'Título de una marca',
    'Description of a brand' => 'Descripción de una marca',
    'Title of a page' => 'Título de una página',
    'Description of a page' => 'Descripción de una página',
    'Title of a post' => 'Título de una entrada',
    'Description of a post' => 'Descripción de una entrada',
    'Title of the blog' => 'Título del blog',
    'Description of the blog' => 'Descripción del blog',
    'Search engines' => 'Buscadores',
    'AI assistants' => 'Asistentes de IA',
    'AI training' => 'Entrenamiento de IA',
    'Googlebot, Bingbot and the like: they send visitors from their results.' => 'Googlebot, Bingbot y similares: envían visitas desde sus resultados.',
    'ChatGPT, Claude, Perplexity and others when they read a page to answer a person.' => 'ChatGPT, Claude, Perplexity y otros cuando leen una página para contestar a una persona.',
    'Crawlers that collect pages to train models.' => 'Robots que recogen páginas para entrenar modelos.',
    'Allow' => 'Permitir',
    'Block' => 'Bloquear',
    'Extra lines for robots.txt' => 'Líneas extra para robots.txt',
    'One per line. Only User-agent, Allow, Disallow, Crawl-delay, Sitemap and comments are kept.' => 'Una por línea. Solo se conservan User-agent, Allow, Disallow, Crawl-delay, Sitemap y los comentarios.',
    'Google Search Console code' => 'Código de Google Search Console',
    'Bing Webmaster Tools code' => 'Código de Bing Webmaster Tools',
    'Only the code, not the whole tag.' => 'Solo el código, no la etiqueta entera.',

    // ------------------------------------------------------------------ inicio del panel
    'The whole site is hidden from search engines. Turn it off when the shop is ready.' => 'Todo el sitio está oculto a los buscadores. Apágalo cuando la tienda esté lista.',
    'Change' => 'Cambiar',
    'Problems found' => 'Problemas encontrados',
    'Hidden from search engines' => 'Oculto a los buscadores',
    'Open to search engines' => 'Abierto a los buscadores',
    'Open the audit' => 'Abrir la auditoría',

    // ------------------------------------------------------------------ auditoría
    'The audit looks at the published products, categories, pages and posts in the main language of the shop.' => 'La auditoría mira los productos, categorías, páginas y entradas publicados en el idioma principal de la tienda.',
    'Open a problem to see some of the items and go straight to the one you want to fix.' => 'Abre un problema para ver algunos casos e ir directo al que quieras arreglar.',
    'The result is kept for 10 minutes: after fixing something, wait a little before checking again.' => 'El resultado se guarda 10 minutos: tras arreglar algo, espera un poco antes de volver a mirar.',
    '{n} problem found|{n} problems found' => '{n} problema encontrado|{n} problemas encontrados',
    'Nothing to check yet' => 'Todavía no hay nada que revisar',
    'Add products, categories or pages and their problems will show up here.' => 'Añade productos, categorías o páginas y sus problemas aparecerán aquí.',
    'Check' => 'Comprobación',
    'Problems' => 'Problemas',
    'Where to fix them' => 'Dónde arreglarlos',
    'Show some' => 'Ver algunos',
    'Open the list' => 'Abrir el listado',
    'All good' => 'Todo correcto',
    'What the robots see' => 'Lo que ven los robots',
    'Sitemap' => 'Mapa del sitio',
    'Products without their own meta description' => 'Productos sin meta descripción propia',
    'Products with a title longer than 65 characters' => 'Productos con el título de más de 65 caracteres',
    'Products with a title that is repeated' => 'Productos con el título repetido',
    'Products without an image' => 'Productos sin imagen',
    'Products with a very short description' => 'Productos con una descripción muy corta',
    'Categories without their own meta description' => 'Categorías sin meta descripción propia',
    'Categories with a title longer than 65 characters' => 'Categorías con el título de más de 65 caracteres',
    'Categories with a title that is repeated' => 'Categorías con el título repetido',
    'Categories without an image' => 'Categorías sin imagen',
    'Pages without their own meta description' => 'Páginas sin meta descripción propia',
    'Pages with a title longer than 65 characters' => 'Páginas con el título de más de 65 caracteres',
    'Pages with a title that is repeated' => 'Páginas con el título repetido',
    'Posts without their own meta description' => 'Entradas sin meta descripción propia',
    'Posts with a title longer than 65 characters' => 'Entradas con el título de más de 65 caracteres',
    'Posts with a title that is repeated' => 'Entradas con el título repetido',
    'Posts without an image' => 'Entradas sin imagen',
    'Images without alternative text' => 'Imágenes sin texto alternativo',
    'Chained redirections' => 'Redirecciones encadenadas',
    'Pages hidden from search engines that are linked in a menu' => 'Páginas ocultas a los buscadores con un enlace en un menú',

    // ------------------------------------------------------------------ páginas de la tienda
    'Page {n}' => 'Página {n}',
    // ------------------------------------------------------------------ menú: «SEO» con sus pestañas
    'Audit' => 'Auditoría',
    'Robots that can read the hidden site' => 'Robots que pueden leer la tienda oculta',
    'Separated by commas, for example «OAI-SearchBot» so that ChatGPT can open the shop. Only with the site hidden; search engines stay out.' => 'Separados por comas; por ejemplo «OAI-SearchBot», para que ChatGPT pueda abrir la tienda. Solo con la tienda oculta; los buscadores siguen fuera.',
    'With the site hidden, open the product pages to search engines' => 'Con la tienda oculta, abrir las fichas de producto a los buscadores',
    'Only the product pages and their sitemap (not the images): the rest stays hidden. Never in a demo site.' => 'Solo las fichas de producto y su mapa del sitio (las fotos no): el resto sigue oculto. Nunca en un sitio de demostración.',
    'Mark the shop as a demo' => 'Marcar la tienda como demostración',
    'IndexNow key' => 'Clave de IndexNow',
    'Published at /indexnow.txt. With it, the console command seo:indexnow tells Bing and the other IndexNow search engines about the product pages. Empty = it is created the first time.' => 'Se publica en /indexnow.txt. Con ella, la orden de consola seo:indexnow avisa a Bing y a los demás buscadores de IndexNow de las fichas de producto. Vacía = se crea la primera vez.',
    'A band on every page and «demo» in the titles and descriptions, so that nobody (and no search engine) takes it for a real shop.' => 'Una banda en todas las páginas y «demo» en los títulos y las descripciones, para que nadie (ni un buscador) la tome por una tienda de verdad.',
    'demo' => 'demo',
    'Demo shop with fictional products and brands.' => 'Tienda de demostración con productos y marcas ficticios.',
    'Demo shop: the products and brands are fictional and nothing is charged.' => 'Tienda de demostración: los productos y las marcas son ficticios y no se cobra nada.',
];

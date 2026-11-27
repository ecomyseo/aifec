<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El botón flotante y la ventana en la tienda (hueco «body.end»): solo con el asistente encendido, sin nada personal,
 * accesible, con el contrato de móvil y con un guion que no lleva cadenas visibles.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** El fichero de un recurso del módulo. */
function assistantAsset(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__) . '/assets/' . $name);
}

test('con el asistente encendido, la portada y la ficha de producto llevan el botón y la ventana con su presentación', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    $product = $app->url()->entity('product', $ids['silla_roja']);

    foreach (['/', $product] as $path) {
        $body = $web->get($path)->body;
        contains('data-af-assistant', $body, $path);
        contains('class="af-assistant-fab"', $body);
        contains('data-af-assistant-open', $body);
        contains('aria-controls="af-assistant-panel"', $body);
        contains('aria-expanded="false"', $body);
        contains('aria-label="Abrir el asistente de la tienda"', $body);
        contains('role="dialog"', $body);
        contains('aria-labelledby="af-assistant-title"', $body);
        contains('id="af-assistant-ai"', $body, 'el aviso está asociado a la ventana');
        contains('aria-describedby="af-assistant-ai"', $body);
        notContains('IA</strong>', $body);
        contains('Revisa los detalles del producto', $body);
        contains('role="log"', $body);
        contains('<label class="af-sr" for="af-assistant-input">Escribe tu mensaje</label>', $body);
        contains('data-af-assistant-url="/asistente/mensaje"', $body);
        contains('Soy el asistente de compras de Muebles Ejemplo.', $body, 'se presenta como una IA y con el nombre de la tienda');
        contains('Solo hablo de la tienda.', $body);
        contains('assistant.css', $body);
        check(preg_match('#<script src="[^"]*launcher\.js" defer></script>#', $body) === 1, 'el guion va con defer');
        contains('maxlength="1000"', $body, 'el campo ya limita el mensaje');
        notContains('href=""', $body);
    }
});

test('el HTML del asistente es el mismo para todo el mundo y la página sigue siendo cacheable: ni cookie, ni nada del visitante', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $product = $app->url()->entity('product', $ids['silla_roja']);

    $anonymous = new TestClient($app);
    $first = $anonymous->get($product);
    same(200, $first->status);
    same(600, $first->cacheTtl, 'la ficha se sigue guardando en la caché de página');
    same([], $first->cookies, 'la página no pone ninguna cookie');

    $talker = new TestClient($app);
    assistantSay($talker, 'Busco una silla; mi correo es ana@cliente.test y mi teléfono 999345678');
    aifecCartRun($talker, static function ($cart) use ($ids): void {
        $cart->add($ids['mesa'], 0, 1);
    });
    $second = $talker->get($product);
    same($first->body, $second->body, 'con conversación y carrito, la página es idéntica');
    same(600, $second->cacheTtl);
    notContains('ana@cliente.test', $second->body);
    notContains('999345678', $second->body);
    notContains($talker->cookies[$app->cookieName('as')], $second->body, 'la ficha de la conversación no se pinta nunca');
});

test('con «assistant.enabled» apagado no hay botón, ni ventana, ni hoja de estilos ni guion en la tienda', function (): void {
    $app = assistantApp(['assistant.enabled' => false]);
    $ids = assistantShop($app);
    $web = new TestClient($app);

    foreach (['/', $app->url()->entity('product', $ids['silla_roja'])] as $path) {
        $body = $web->get($path)->body;
        notContains('data-af-assistant', $body, $path);
        notContains('af-assistant', $body);
        notContains('assistant.js', $body);
        notContains('assistant.css', $body);
        notContains('/asistente/mensaje', $body);
    }
});

test('el tono, el saludo y los ajustes cambian lo que enseña la ventana; guardar los ajustes del asistente tira la caché de páginas', function (): void {
    $app = assistantApp(['assistant.greeting' => 'Entregamos en 24 horas & más.']);
    assistantShop($app);
    $web = new TestClient($app);

    $body = $web->get('/')->body;
    contains('Entregamos en 24 horas &amp; más.', $body, 'el texto del comerciante se escapa');
    notContains('24 horas & más', $body);

    $before = $app->cache()->tagVersion('pages');
    $app->hooks()->fire('settings.saved', 'otro_modulo', ['x.y'], $app);
    same($before, $app->cache()->tagVersion('pages'), 'los ajustes de otro módulo no la tocan');
    $app->hooks()->fire('settings.saved', 'assistant', ['assistant.enabled'], $app);
    check($app->cache()->tagVersion('pages') > $before, 'los del asistente sí: el botón está en todas las páginas');
});

test('el guion no lleva cadenas visibles: cada texto que pinta llega en un atributo data-t-* del HTML', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $body = (new TestClient($app))->get('/')->body;
    $js = assistantAsset('assistant.js');

    preg_match_all("/text\\('([a-z-]+)'\\)/", $js, $used);
    $names = array_values(array_unique($used[1]));
    sort($names);
    // «invoice» y «taxid» (P093): la tarjeta del resumen dice a quién se factura («Factura a: Berg Studio SL · NIF …»).
    same(['accept', 'add', 'busy', 'checkout', 'confirm', 'deliver', 'discount', 'discount-label', 'error', 'featured', 'from', 'invoice', 'items', 'options', 'pay', 'payment', 'rating', 'recovery', 'remove-pref', 'retry', 'shipping', 'subscriptions', 'summary', 'taxid', 'terms', 'total', 'view', 'vieworder'], $names, 'los textos que usa el guion');
    foreach ($names as $name) {
        check(preg_match('/data-t-' . $name . '="[^"]+"/', $body) === 1, 'el HTML trae data-t-' . $name);
    }
    $code = '';
    foreach (explode("\n", $js) as $line) {
        $trim = ltrim($line);
        if ($trim !== '' && strpos($trim, '//') !== 0 && strpos($trim, '/*') !== 0 && strpos($trim, '*') !== 0) {
            $code .= $line . "\n";
        }
    }
    check(preg_match('/[^\x00-\x7F]/', str_replace('…', '', $code)) === 0, 'sin letras con tilde ni frases en el código del guion');
    notContains('innerHTML', $js, 'todo lo que viene del servidor se pinta con textContent');
    notContains('eval(', $js);
    contains('safeUrl', $js, 'las direcciones se comprueban antes de usarse');
    contains('/^(https?:\\/\\/|\\/(?!\\/))/i', $js, 'solo http(s) o rutas de la propia web');
});

test('el panel carga las sugerencias al abrir y usa un único POST para los mensajes del cliente', function (): void {
    $js = assistantAsset('assistant.js');

    foreach (['click', 'submit', 'keydown'] as $event) {
        contains("document.addEventListener('" . $event . "'", $js);
    }
    $before = substr($js, 0, (int) strpos($js, 'function send('));
    notContains('fetch(', $before, 'ninguna petición antes de send()');
    same(2, substr_count($js, 'window.fetch('), 'una petición de bienvenida y la comunicación del chat');
    contains('log = saved;', $js);
    contains('if (!log.length && data.suggestions)', $js);
    contains("'X-Requested-With': 'fetch'", $js);
    contains("cache: 'no-store'", $js, 'las peticiones no se cachean');
    contains("credentials: 'same-origin'", $js);
    contains("event.key === 'Escape'", $js, 'Escape cierra la ventana');
    contains("setAttribute('aria-expanded'", $js);
    contains('box.hidden = false', $js, 'el botón está oculto hasta que el guion lo enseña');
});

test('contrato de móvil: el botón se coloca sobre la barra inferior con la zona segura, la ventana ocupa la pantalla y los controles miden 44 píxeles', function (): void {
    $css = assistantAsset('assistant.css');

    contains('bottom:calc(var(--as-bar) + env(safe-area-inset-bottom) + .75rem + var(--af-consent-h,0px))', $css, 'por encima de la barra y del aviso de cookies mientras está a la vista, no debajo');
    contains('--as-bar:var(--bar-h,3.5rem)', $css, 'la altura de la barra sale del tema');
    contains('.af-assistant-panel{position:fixed;inset:0;', $css, 'en móvil, pantalla completa');
    contains('@media (min-width:900px)', $css, 'la barra del tema acaba en 900 px: ahí pasa a esquina');
    contains('min-width:48px;min-height:48px', $css);
    contains('min-height:44px', $css);
    contains('env(safe-area-inset-bottom)', substr($css, (int) strpos($css, '.af-assistant-form{')), 'el formulario respeta la zona segura');
    contains(':focus-visible', $css, 'foco visible');
    contains('prefers-reduced-motion', $css);
    contains('overscroll-behavior:contain', $css);
    notContains('#fff;', preg_replace('/var\([^)]*\)/', '', $css), 'los colores salen de variables con respaldo, nunca sueltos');
    foreach (['assistant.js', 'assistant.css'] as $file) {
        check(is_file(dirname(__DIR__) . '/assets/' . $file), $file . ' existe');
    }
});

test('con otro idioma de la tienda: la presentación, la ruta con su prefijo y la conversación con su idioma', function (): void {
    $app = assistantApp(['i18n.languages' => ['es', 'en']]);
    $ids = assistantShop($app);
    $web = new TestClient($app);

    $es = $web->get('/')->body;
    contains('Soy el asistente de compras de Muebles Ejemplo', $es);
    $en = $web->get('/en/')->body;
    check(strpos($en, 'data-af-assistant') !== false, 'también en la versión inglesa');
    $url = $app->url()->route('assistant.message', [], 'en');
    contains('/en/asistente/mensaje', $url, 'la ruta respeta el prefijo del idioma');
    $post = $web->postJson('/en/asistente/mensaje', ['message' => 'Hola']);
    same(200, $post->status);
    same('en', assistantRows($app, 'conversations')[0]['lang'], 'la conversación recuerda el idioma');
    check($ids['silla_roja'] > 0);
});

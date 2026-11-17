<?php
/**
 * AIFEC — pruebas de los formularios del contenido en la tienda: el envío de una persona (guardado, aviso a la tienda, texto de
 * gracias), los errores, y las defensas contra robots (campo trampa, marca de tiempo firmada, límite por hora, enlaces de más,
 * origen), por el formulario de toda la vida y por JSON; y que lo que escribe una persona nunca sale sin escapar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Response;

require_once __DIR__ . '/helpers.php';

/**
 * Sitio con el módulo de correo, la página «Contacto» con el formulario de serie y la dirección de la tienda para los avisos.
 *
 * @param array<string,mixed> $settings
 */
function cmsFrontFormApp(array $settings = [], bool $mail = true): App
{
    $app = cmsApp($mail ? ['cms', 'mail'] : ['cms'], $settings + ['store.email' => 'tienda@ejemplo.test']);
    cmsInsertPage($app, ['title' => 'Contacto', 'blocks' => [['type' => 'form', 'attrs' => ['form_id' => 'contact']]]]);

    return $app;
}

/**
 * Lo que rellena una persona en el formulario de contacto (lo demás se pisa con $over).
 *
 * @param array<string,mixed> $over
 *
 * @return array<string,mixed>
 */
function cmsFrontFormFields(array $over = []): array
{
    return $over + ['name' => 'Ana', 'email' => 'ana@cliente.test', 'message' => 'Hola, quería preguntar por una mesa de roble.', 'consent' => '1'];
}

/**
 * Envía el formulario como lo manda el navegador: los campos en «f», el campo trampa vacío y la marca vacía, desde la página «Contacto».
 *
 * @param array<string,mixed>  $fields
 * @param array<string,mixed>  $extra   campos de más (trampa, marca)
 * @param array<string,string> $headers
 */
function cmsFrontFormPost(TestClient $web, array $fields, array $extra = [], array $headers = [], string $code = 'contact'): Response
{
    return $web->post('/formulario/' . $code, ['f' => $fields] + $extra + ['website' => '', '_mark' => ''], $headers + ['referer' => 'https://tienda.test/contacto']);
}

function cmsFrontFormEntries(App $app): int
{
    return (int) $app->db()->value('SELECT COUNT(*) FROM {form_entries}');
}

function cmsFrontFormMails(App $app): int
{
    return (int) $app->db()->value('SELECT COUNT(*) FROM {mail_messages}');
}

/** La marca de tiempo firmada que pide el guion al enfocar el primer campo. */
function cmsFrontFormStamp(TestClient $web, string $code = 'contact'): string
{
    $r = $web->get('/formulario/' . $code . '/marca');
    check($r->status === 200, 'la marca se pide bien');
    $json = $web->json();
    check(isset($json['stamp']) && is_string($json['stamp']) && $json['stamp'] !== '', 'viene una marca');

    return $json['stamp'];
}

test('un envío bueno guarda la entrada, avisa a la tienda y vuelve a la página con el texto de gracias', function (): void {
    $app = cmsFrontFormApp();
    $web = new TestClient($app);
    $r = cmsFrontFormPost($web, cmsFrontFormFields());
    same(302, $r->status);
    same('/contacto?enviado=1#form-contact', $r->headers['Location']);
    same(1, cmsFrontFormEntries($app));
    $row = $app->db()->row('SELECT * FROM {form_entries}');
    same(1, (int) $row['form_id']);
    same('new', $row['status']);
    same('127.0.0.1', $row['ip']);
    $data = json_decode((string) $row['data'], true);
    same(['name' => 'Ana', 'email' => 'ana@cliente.test', 'message' => 'Hola, quería preguntar por una mesa de roble.', 'consent' => '1'], $data);

    same(1, cmsFrontFormMails($app), 'el aviso a la tienda queda en la cola de correo');
    $mail = $app->db()->row('SELECT * FROM {mail_messages}');
    same('tienda@ejemplo.test', $mail['to_email']);
    same('form_entry', $mail['template']);
    contains('Contact', (string) $mail['subject']);
    contains('Ana', (string) $mail['html']);
    contains('ana@cliente.test', (string) $mail['html']);
    contains('https://tienda.test/admin/r/form_entries/1', (string) $mail['html'], 'con el enlace al mensaje en el panel');
    $envelope = json_decode((string) $mail['data'], true);
    same('ana@cliente.test', $envelope['reply_to'], 'se le puede responder a la persona');
    same('form_entry:1', $mail['related']);

    $page = $web->follow();
    same(200, $page->status);
    contains('Gracias. Hemos recibido tu mensaje y te responderemos pronto.', $page->body);
    contains('id="form-contact"', $page->body);
    notContains('<form class="af-form-block"', $page->body, 'el formulario se cambia por el texto de gracias');
    same([], $web->cookies, 'un envío bueno no deja nada en una sesión');
});

test('el texto de gracias propio del formulario sustituye al de serie, también por JSON', function (): void {
    $app = cmsFrontFormApp();
    $app->db()->update('forms', ['settings' => json_encode(['notify_to' => '', 'thanks' => 'Gracias por escribirnos, Ana & compañía'])], ['code' => 'contact']);
    $web = new TestClient($app);
    $r = $web->postJson('/formulario/contact', ['f' => cmsFrontFormFields(), 'website' => '', '_mark' => '']);
    same(200, $r->status);
    same(['ok' => true, 'message' => 'Gracias por escribirnos, Ana & compañía'], $web->json());
    same(1, cmsFrontFormEntries($app));
});

test('por JSON: un envío bueno contesta {ok: true, message}, sin caché y con todo guardado', function (): void {
    $app = cmsFrontFormApp();
    $web = new TestClient($app);
    $r = $web->postJson('/formulario/contact', ['f' => cmsFrontFormFields(), 'website' => '', '_mark' => '']);
    same(200, $r->status);
    $json = $web->json();
    same(true, $json['ok']);
    contains('Gracias', $json['message']);
    contains('no-store', $r->headers['Cache-Control']);
    same(0, $r->cacheTtl, 'una respuesta de estado no se guarda');
    same(1, cmsFrontFormEntries($app));
    same(1, cmsFrontFormMails($app));
});

test('un campo obligatorio vacío no guarda nada: sin JavaScript vuelve a la página con los errores y lo escrito, una sola vez', function (): void {
    $app = cmsFrontFormApp();
    $web = new TestClient($app);
    $r = cmsFrontFormPost($web, cmsFrontFormFields(['name' => '', 'consent' => '']));
    same(302, $r->status);
    same('/contacto#form-contact', $r->headers['Location']);
    same(0, cmsFrontFormEntries($app));
    same(0, cmsFrontFormMails($app));
    check($web->cookies !== [], 'los errores van en la sesión de cliente, solo en este caso');

    $page = $web->follow();
    same(200, $page->status);
    contains('Revisa los campos marcados.', $page->body);
    contains('class="af-error"', $page->body);
    contains('aria-invalid="true"', $page->body);
    contains('value="ana@cliente.test"', $page->body, 'lo que ya había escrito no se pierde');
    contains('<form class="af-form-block"', $page->body);
    same('BYPASS', $page->headers['X-Cache'], 'la página con errores no se guarda en la caché de todos');

    $again = $web->get('/contacto');
    notContains('Revisa los campos marcados.', $again->body, 'los errores se enseñan una sola vez');
    notContains('class="af-error"', $again->body);
});

test('por JSON: los errores salen como {ok: false, errors} por clave de campo, con 422 y sin guardar', function (): void {
    $app = cmsFrontFormApp();
    $web = new TestClient($app);
    $r = $web->postJson('/formulario/contact', ['f' => cmsFrontFormFields(['name' => '', 'email' => 'esto no es un correo']), 'website' => '', '_mark' => '']);
    same(422, $r->status);
    $json = $web->json();
    same(false, $json['ok']);
    same(['name', 'email'], array_keys($json['errors']));
    contains('Revisa los campos marcados.', $json['message']);
    same(0, cmsFrontFormEntries($app));
    same([], $web->cookies, 'por JSON no se usa la sesión');
});

test('el campo trampa relleno contesta «gracias» como si nada y no guarda ni avisa', function (): void {
    $app = cmsFrontFormApp();
    $web = new TestClient($app);
    $r = cmsFrontFormPost($web, cmsFrontFormFields(), ['website' => 'http://spam.test']);
    same(302, $r->status);
    same('/contacto?enviado=1#form-contact', $r->headers['Location'], 'el robot ve lo mismo que una persona');
    same(0, cmsFrontFormEntries($app));
    same(0, cmsFrontFormMails($app));
    same([], $web->cookies);

    $json = $web->postJson('/formulario/contact', ['f' => cmsFrontFormFields(), 'website' => 'x', '_mark' => '']);
    same(200, $json->status);
    same(true, $web->json()['ok']);
    same(0, cmsFrontFormEntries($app));
    same(0, cmsFrontFormEntries($app) + cmsFrontFormMails($app));
});

test('los envíos de robots no gastan el límite de una persona: diez descartados y el envío bueno pasa', function (): void {
    $app = cmsFrontFormApp();
    $web = new TestClient($app);
    for ($i = 0; $i < 10; ++$i) {
        cmsFrontFormPost($web, cmsFrontFormFields(), ['website' => 'robot']);
    }
    same(302, cmsFrontFormPost($web, cmsFrontFormFields())->status);
    same(1, cmsFrontFormEntries($app));
});

test('la marca de tiempo: antes de 3 segundos se descarta en silencio y a partir de 3 se acepta', function (): void {
    $app = cmsFrontFormApp();
    $web = new TestClient($app);
    $stamp = cmsFrontFormStamp($web);

    $now = cmsFrontFormPost($web, cmsFrontFormFields(), ['_mark' => $stamp]);
    same('/contacto?enviado=1#form-contact', $now->headers['Location'], 'se contesta «gracias»');
    same(0, cmsFrontFormEntries($app), 'inmediato: es un robot');

    $app->clock()->advance(2);
    cmsFrontFormPost($web, cmsFrontFormFields(), ['_mark' => $stamp]);
    same(0, cmsFrontFormEntries($app), 'a los 2 segundos todavía no');

    $app->clock()->advance(1);
    cmsFrontFormPost($web, cmsFrontFormFields(), ['_mark' => $stamp]);
    same(1, cmsFrontFormEntries($app), 'a los 3 segundos, una persona');
    same(1, cmsFrontFormMails($app));
});

test('una marca manipulada, de otro formulario o de hace más de 24 horas se descarta; sin marca se acepta', function (): void {
    $app = cmsFrontFormApp();
    $now = $app->clock()->sql();
    $app->db()->insert('forms', [
        'code' => 'otro', 'name' => 'Otro', 'created_at' => $now, 'updated_at' => $now,
        'fields' => json_encode([['key' => 'nombre', 'type' => 'text', 'label' => 'Nombre', 'required' => 1, 'options' => []]]),
        'settings' => json_encode(['notify_to' => '', 'thanks' => '']),
    ]);
    $web = new TestClient($app);
    $stamp = cmsFrontFormStamp($web);
    $app->clock()->advance(10);

    $altered = substr($stamp, 0, -2) . (substr($stamp, -2) === 'AA' ? 'BB' : 'AA');
    cmsFrontFormPost($web, cmsFrontFormFields(), ['_mark' => $altered]);
    same(0, cmsFrontFormEntries($app), 'firma manipulada');
    cmsFrontFormPost($web, cmsFrontFormFields(), ['_mark' => 'basura sin punto']);
    same(0, cmsFrontFormEntries($app), 'una marca que ni es una marca');
    cmsFrontFormPost($web, ['nombre' => 'Ana'], ['_mark' => $stamp], [], 'otro');
    same(0, cmsFrontFormEntries($app), 'la marca de otro formulario no vale');

    cmsFrontFormPost($web, cmsFrontFormFields());
    same(1, cmsFrontFormEntries($app), 'sin marca (sin JavaScript) se acepta');

    $app->clock()->advance(86400 - 10 - 1);
    cmsFrontFormPost($web, cmsFrontFormFields(), ['_mark' => $stamp]);
    same(2, cmsFrontFormEntries($app), 'a punto de cumplir las 24 horas todavía vale');

    $app->clock()->advance(2);
    cmsFrontFormPost($web, cmsFrontFormFields(), ['_mark' => $stamp]);
    same(2, cmsFrontFormEntries($app), 'pasadas las 24 horas se descarta');
});

test('el sexto envío en una hora se rechaza a la vista; pasada la hora vuelve a valer', function (): void {
    $app = cmsFrontFormApp();
    $web = new TestClient($app);
    for ($i = 1; $i <= 5; ++$i) {
        same(302, cmsFrontFormPost($web, cmsFrontFormFields(['message' => 'Mensaje ' . $i]))->status);
    }
    same(5, cmsFrontFormEntries($app));

    $sixth = cmsFrontFormPost($web, cmsFrontFormFields(['message' => 'Mensaje 6']));
    same(429, $sixth->status);
    contains('Demasiados mensajes en poco tiempo', $sixth->body, 'la persona sabe por qué');
    same(5, cmsFrontFormEntries($app));

    $json = $web->postJson('/formulario/contact', ['f' => cmsFrontFormFields(), 'website' => '', '_mark' => '']);
    same(429, $json->status);
    same(false, $web->json()['ok']);
    contains('Demasiados mensajes', $web->json()['message']);
    same('3600', $json->headers['Retry-After']);

    $app->clock()->advance(3601);
    same(302, cmsFrontFormPost($web, cmsFrontFormFields(['message' => 'Mensaje 7']))->status);
    same(6, cmsFrontFormEntries($app));
});

test('el límite es por dirección: otra dirección no queda bloqueada por la primera', function (): void {
    $app = cmsFrontFormApp();
    $web = new TestClient($app);
    for ($i = 0; $i < 5; ++$i) {
        cmsFrontFormPost($web, cmsFrontFormFields());
    }
    same(429, cmsFrontFormPost($web, cmsFrontFormFields())->status);

    $request = \Aifec\Kernel\Request::create('POST', '/formulario/contact', ['f' => cmsFrontFormFields(), 'website' => '', '_mark' => ''], [], ['sec-fetch-site' => 'same-origin', 'referer' => 'https://tienda.test/contacto']);
    $request->ip = '10.0.0.77';
    $request->host = 'tienda.test';
    $request->secure = true;
    same(302, $app->handle($request)->status, 'otra dirección');
    same(6, cmsFrontFormEntries($app));
});

test('un mensaje con más de 3 enlaces se rechaza señalando los campos; con 3 pasa', function (): void {
    $app = cmsFrontFormApp();
    $web = new TestClient($app);
    $four = 'Mira https://a.test/x, http://b.test/y, www.c.test y https://d.test/z';
    $r = cmsFrontFormPost($web, cmsFrontFormFields(['message' => $four]));
    same(302, $r->status);
    same('/contacto#form-contact', $r->headers['Location']);
    same(0, cmsFrontFormEntries($app));
    $page = $web->follow();
    contains('Quita algún enlace: un mensaje puede llevar 3 como máximo.', $page->body);
    contains('Mira https://a.test/x', $page->body, 'lo escrito se conserva');

    $json = $web->postJson('/formulario/contact', ['f' => cmsFrontFormFields(['message' => $four]), 'website' => '', '_mark' => '']);
    same(422, $json->status);
    same(['message'], array_keys($web->json()['errors']));
    same(0, cmsFrontFormEntries($app));

    $split = cmsFrontFormPost($web, cmsFrontFormFields(['name' => 'Ana https://a.test', 'message' => 'https://b.test y https://c.test y https://d.test']));
    same('/contacto#form-contact', $split->headers['Location'], 'los enlaces se cuentan entre todos los campos');
    same(0, cmsFrontFormEntries($app));

    $three = cmsFrontFormPost($web, cmsFrontFormFields(['message' => 'Mira https://a.test/x, http://b.test/y y www.c.test']));
    same('/contacto?enviado=1#form-contact', $three->headers['Location']);
    same(1, cmsFrontFormEntries($app));
});

test('desde otro origen el envío se rechaza con 403 y no guarda nada', function (): void {
    $app = cmsFrontFormApp();
    $web = new TestClient($app);
    $r = cmsFrontFormPost($web, cmsFrontFormFields(), [], ['sec-fetch-site' => 'cross-site']);
    same(403, $r->status);
    same(0, cmsFrontFormEntries($app));

    $web->headers = ['user-agent' => 'AifecTest/1.0'];
    same(403, cmsFrontFormPost($web, cmsFrontFormFields(), [], ['origin' => 'https://otra-web.test'])->status, 'un navegador sin Fetch Metadata manda Origin');
    same(0, cmsFrontFormEntries($app));
    same(302, cmsFrontFormPost($web, cmsFrontFormFields(), [], ['origin' => 'https://tienda.test'])->status, 'desde la propia tienda sí');
    same(1, cmsFrontFormEntries($app));
});

test('un formulario que no existe da 404 en el envío y en la marca', function (): void {
    $app = cmsFrontFormApp();
    $web = new TestClient($app);
    same(404, cmsFrontFormPost($web, cmsFrontFormFields(), [], [], 'no-existe')->status);
    same(404, $web->get('/formulario/no-existe/marca')->status);
    same(0, cmsFrontFormEntries($app));
});

test('la marca de tiempo se pide sin caché y es distinta para cada formulario', function (): void {
    $app = cmsFrontFormApp();
    $now = $app->clock()->sql();
    $app->db()->insert('forms', [
        'code' => 'otro', 'name' => 'Otro', 'created_at' => $now, 'updated_at' => $now,
        'fields' => json_encode([['key' => 'nombre', 'type' => 'text', 'label' => 'Nombre', 'required' => 1, 'options' => []]]),
        'settings' => json_encode(['notify_to' => '', 'thanks' => '']),
    ]);
    $web = new TestClient($app);
    $r = $web->get('/formulario/contact/marca');
    same(200, $r->status);
    contains('no-store', $r->headers['Cache-Control']);
    same('0', $r->headers['Expires']);
    same(0, $r->cacheTtl, 'nunca se guarda en la caché de página');
    check(cmsFrontFormStamp($web, 'contact') !== cmsFrontFormStamp($web, 'otro'));
});

test('un mensaje con script, comillas y «&» llega al correo escapado y a la ficha de la entrada del panel, también', function (): void {
    $app = cmsFrontFormApp();
    $web = new TestClient($app);
    $evil = '<script>alert(1)</script> & más "comillas" <img src=x onerror=alert(2)>';
    cmsFrontFormPost($web, cmsFrontFormFields(['name' => 'Ana <b>Gómez</b>', 'message' => $evil]));
    same(1, cmsFrontFormEntries($app));
    $stored = json_decode((string) $app->db()->value('SELECT data FROM {form_entries}'), true);
    same($evil, $stored['message'], 'en la base de datos queda tal cual lo escribió');

    $mail = $app->db()->row('SELECT * FROM {mail_messages}');
    $html = (string) $mail['html'];
    contains('&lt;script&gt;alert(1)&lt;/script&gt; &amp; más &quot;comillas&quot; &lt;img src=x onerror=alert(2)&gt;', $html);
    contains('Ana &lt;b&gt;Gómez&lt;/b&gt;', $html);
    notContains('<script>alert(1)', $html);
    notContains('<img src=x', $html);
    notContains('<b>Gómez</b>', $html);

    $admin = adminClient($app);
    $ficha = $admin->get('/admin/r/form_entries/1');
    same(200, $ficha->status);
    contains('&lt;script&gt;alert(1)&lt;/script&gt;', $ficha->body);
    notContains('<script>alert(1)', $ficha->body);
    notContains('<img src=x', $ficha->body);
    $list = $admin->get('/admin/r/form_entries');
    notContains('<script>alert(1)', $list->body, 'ni en el listado');
});

test('un salto de línea en un mensaje largo se respeta en el correo y no abre otra fila ni una etiqueta', function (): void {
    $app = cmsFrontFormApp();
    $web = new TestClient($app);
    cmsFrontFormPost($web, cmsFrontFormFields(['message' => "Primera línea\nSegunda línea"]));
    $html = (string) $app->db()->value('SELECT html FROM {mail_messages}');
    contains('Primera línea<br>', $html);
    contains('Segunda línea', $html);
});

test('el aviso va a la dirección del formulario si la tiene y, si no, a la de la tienda; sin ninguna, el mensaje se guarda igual', function (): void {
    $app = cmsFrontFormApp();
    $app->db()->update('forms', ['settings' => json_encode(['notify_to' => 'ventas@ejemplo.test', 'thanks' => ''])], ['code' => 'contact']);
    $web = new TestClient($app);
    cmsFrontFormPost($web, cmsFrontFormFields());
    same('ventas@ejemplo.test', (string) $app->db()->value('SELECT to_email FROM {mail_messages}'));

    $none = cmsFrontFormApp(['store.email' => '']);
    $web = new TestClient($none);
    same(302, cmsFrontFormPost($web, cmsFrontFormFields())->status);
    same(1, cmsFrontFormEntries($none), 'el mensaje se guarda aunque no haya a quién avisar');
    same(0, cmsFrontFormMails($none));
});

test('sin el módulo de correo el envío funciona: se guarda y no se avisa', function (): void {
    $app = cmsFrontFormApp([], false);
    check(!$app->has('mailer'), 'este sitio no tiene correo');
    $web = new TestClient($app);
    $r = cmsFrontFormPost($web, cmsFrontFormFields());
    same(302, $r->status);
    same('/contacto?enviado=1#form-contact', $r->headers['Location']);
    same(1, cmsFrontFormEntries($app));
});

test('la plantilla «form_entry» está declarada y se puede componer con sus datos de ejemplo, en español', function (): void {
    $app = cmsFrontFormApp();
    $mailer = $app->service('mailer');
    $templates = $mailer->templates();
    check(isset($templates['form_entry']), 'el módulo de correo la conoce');
    same('cms', $templates['form_entry']['module']);
    same(false, $templates['form_entry']['system'], 'con el correo retenido, espera a una persona');
    $out = $mailer->render('form_entry', $templates['form_entry']['sample'], 'es');
    contains('Nuevo mensaje en el formulario Contact de', $out['subject']);
    contains('Alguien ha enviado el formulario Contact', $out['html']);
    contains('ana@cliente.test', $out['html']);
    contains('Abrir el mensaje en el panel', $out['html']);
});

test('dónde se vuelve: la página de origen con su consulta, y nunca a otra web ni a una dirección que empiece por «//»', function (): void {
    $app = cmsFrontFormApp();
    $web = new TestClient($app);
    $send = static function (string $referer) use ($web, $app): string {
        $app->clock()->advance(3601); // un envío por hora: aquí no se prueba el límite
        $r = cmsFrontFormPost($web, cmsFrontFormFields(['message' => 'Mensaje ' . $referer]), [], ['referer' => $referer]);

        return $r->headers['Location'];
    };
    same('/contacto?a=1&enviado=1#form-contact', $send('https://tienda.test/contacto?a=1&enviado=1'));
    same('/?enviado=1#form-contact', $send('https://otra-web.test/contacto'), 'otra web: la portada');
    same('/?enviado=1#form-contact', $send('javascript:alert(1)'));
    $slashes = $send('https://tienda.test//otra-web.test/x');
    check(strpos($slashes, '//') !== 0, 'nunca un destino de protocolo relativo: ' . $slashes);
    same('/?enviado=1#form-contact', $send('https://tienda.test/formulario/contact'), 'no se vuelve al propio envío');

    $none = cmsFrontFormPost($web, cmsFrontFormFields(['message' => 'sin origen']), [], ['referer' => '']);
    same('/?enviado=1#form-contact', $none->headers['Location'], 'sin página de origen, la portada');
});

test('un formulario en la portada: se envía desde allí y la portada enseña el texto de gracias', function (): void {
    $app = cmsFrontFormApp();
    $app->db()->update('pages', ['is_home' => 1], ['id' => 1]);
    $app->invalidate(['cms', 'page:1']);
    $web = new TestClient($app);
    $home = $web->get('/');
    contains('data-af-form="contact"', $home->body);
    contains('__afCmsForms', $home->body, 'la portada lleva el guion del formulario');
    $r = cmsFrontFormPost($web, cmsFrontFormFields(), [], ['referer' => 'https://tienda.test/']);
    same('/?enviado=1#form-contact', $r->headers['Location']);
    contains('Gracias. Hemos recibido tu mensaje', $web->follow()->body);
});

test('en inglés el formulario envía a /formulario/… y vuelve a la página en inglés con sus textos', function (): void {
    $app = cmsFrontFormApp();
    cmsInsertPage($app, ['title' => 'Contacto', 'en' => 'Contact us', 'slug' => 'contacto-2', 'blocks' => [['type' => 'form', 'attrs' => ['form_id' => 'contact']]]]);
    $web = new TestClient($app);
    $page = $web->get('/en/contact-us');
    same(200, $page->status);
    contains('action="/en/formulario/contact"', $page->body, 'la dirección del envío lleva el idioma');
    $r = $web->post('/en/formulario/contact', ['f' => cmsFrontFormFields(), 'website' => '', '_mark' => ''], ['referer' => 'https://tienda.test/en/contact-us']);
    same(302, $r->status);
    same('/en/contact-us?enviado=1#form-contact', $r->headers['Location']);
    same(1, cmsFrontFormEntries($app));
});

test('una página con formulario se pinta una sola vez por petición, con la caché fría y con la caliente', function (): void {
    $app = cmsFrontFormApp();
    $painted = 0;
    $app->hooks()->filter('cms.form.state', static function (array $state) use (&$painted): array {
        ++$painted;

        return $state;
    });
    $web = new TestClient($app);
    same(200, $web->get('/contacto')->status);
    same(1, $painted, 'con la caché fría el formulario se pinta una vez, no dos');
    same(200, $web->get('/contacto')->status);
    same(2, $painted, 'con la caché caliente se vuelve a pintar (un formulario no se congela), una vez');
});

<?php
/**
 * AIFEC — textos de los correos editables: asunto, saludo y despedida por idioma, con vista previa y prueba.
 *
 * Fallo que cubre: «Plantillas de correo» solo dejaba ver. Quien quería cambiar el saludo o el asunto de un correo no tenía
 * dónde (lo encontró quien escribía el manual, 13/11/2026).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/MailKit.php';

/** Aplicación con correo en vivo y dos idiomas, con la plantilla de un «plugin» que trae un dato suelto y otro con estructura. */
function mtApp(array $settings = [], ?Aifec\Mail\Transport $transport = null): array
{
    [$app, $transport] = mailTestApp($settings + ['mail.mode' => 'live', 'i18n.languages' => ['es', 'en']], $transport);
    $app->hooks()->filter('mail.templates', static function (array $templates): array {
        $templates['plugin_demo'] = [
            'subject' => 'Your code {code} for {shop}', 'view' => 'mail::emails/mail_test', 'module' => 'un-plugin', 'system' => false,
            'description' => 'A template added by a plugin', 'sample' => ['code' => 'X1', 'lines' => [['name' => 'a']], 'name' => 'Ana'],
        ];

        return $templates;
    });

    return [$app, $transport];
}

/** Envía el formulario de textos de una plantilla con la ficha de la última página abierta. */
function mtPost(TestClient $web, string $key, array $fields, string $lang = 'es'): Aifec\Kernel\Response
{
    $web->get('/admin/mail/templates/' . $key . '/edit?lang=' . $lang);

    return $web->post('/admin/mail/templates/' . $key . '/edit', ['_token' => aifecToken($web), 'lang' => $lang] + $fields);
}

/** @return array<string,mixed> el último mensaje del registro */
function mtLastRow(App $app): array
{
    return mailTestRow($app, (int) $app->db()->value('SELECT MAX(id) FROM {mail_messages}'));
}

$mtData = ['name' => 'Ana', 'url' => 'https://tienda.test/admin/reset/abc', 'minutes' => 30];

test('el asunto editado sale en el correo que se encola, en su idioma; el otro idioma sigue con el de serie', function () use ($mtData): void {
    [$app] = mtApp();
    $mailer = mailTestService($app);
    $mailer->saveTexts('admin_reset', 'es', ['subject' => 'Tu clave nueva de {shop}']);
    $es = mailTestRow($app, $mailer->queue('admin_reset', 'ana@cliente.test', $mtData, ['locale' => 'es']));
    same('Tu clave nueva de AIFEC', $es['subject']);
    $en = mailTestRow($app, $mailer->queue('admin_reset', 'ana@cliente.test', $mtData, ['locale' => 'en']));
    same('Choose a new password for AIFEC', $en['subject'], 'el inglés no tiene texto propio');
    $mailer->saveTexts('admin_reset', 'en', ['subject' => 'Your new key at {shop}']);
    same('Your new key at AIFEC', mailTestRow($app, $mailer->queue('admin_reset', 'ana@cliente.test', $mtData, ['locale' => 'en']))['subject']);
    same('Tu clave nueva de AIFEC', mailTestRow($app, $mailer->queue('admin_reset', 'ana@cliente.test', $mtData, ['locale' => 'es']))['subject']);
});

test('el saludo editado sale en el HTML y en el texto, y el de serie no sale dos veces', function () use ($mtData): void {
    [$app] = mtApp();
    $mailer = mailTestService($app);
    $plain = mailTestRow($app, $mailer->queue('admin_reset', 'ana@cliente.test', $mtData));
    contains('Hola Ana', $plain['html'], 'sin editar sale el de serie');
    $mailer->saveTexts('admin_reset', 'es', ['greeting' => 'Buenas, {name}']);
    $row = mailTestRow($app, $mailer->queue('admin_reset', 'ana@cliente.test', $mtData));
    contains('Buenas, Ana', $row['html']);
    notContains('Hola Ana', $row['html'], 'el de serie se quita');
    contains('Buenas, Ana', $row['text_body']);
    notContains('Hola Ana', $row['text_body']);
    same(1, substr_count($row['html'], 'Buenas, Ana'));
    contains('Hello Ana', mailTestRow($app, $mailer->queue('admin_reset', 'ana@cliente.test', $mtData, ['locale' => 'en']))['html'], 'el otro idioma, intacto');
});

test('la despedida editada sustituye a la de serie, y sin editar sigue la de serie', function () use ($mtData): void {
    [$app] = mtApp();
    $mailer = mailTestService($app);
    contains('Si no lo has pedido, ignora este mensaje', mailTestRow($app, $mailer->queue('admin_reset', 'ana@cliente.test', $mtData))['html']);
    $mailer->saveTexts('admin_reset', 'es', ['farewell' => 'Gracias por confiar en nosotros.']);
    $row = mailTestRow($app, $mailer->queue('admin_reset', 'ana@cliente.test', $mtData));
    contains('Gracias por confiar en nosotros.', $row['html']);
    notContains('Si no lo has pedido', $row['html']);
    contains('Gracias por confiar en nosotros.', $row['text_body']);
    check(strpos($row['html'], 'Gracias por confiar') > strpos($row['html'], 'El enlace vale durante'), 'va al final, detrás del cuerpo');
});

test('un texto editado se escapa siempre: un <script>, un «&» y las comillas salen como texto; también lo que entra por una variable', function () use ($mtData): void {
    [$app] = mtApp();
    $mailer = mailTestService($app);
    $mailer->saveTexts('admin_reset', 'es', ['greeting' => '<script>alert(1)</script> Tom & "Jerry" {name}', 'farewell' => '<img src=x onerror=alert(2)>', 'subject' => '<b>Hola</b> & adiós']);
    $row = mailTestRow($app, $mailer->queue('admin_reset', 'ana@cliente.test', ['name' => '<i>Ana</i>'] + $mtData));
    notContains('<script>alert(1)</script>', $row['html']);
    notContains('<img src=x', $row['html']);
    notContains('<i>Ana</i>', $row['html']);
    contains('&lt;script&gt;alert(1)&lt;/script&gt; Tom &amp; &quot;Jerry&quot; &lt;i&gt;Ana&lt;/i&gt;', $row['html']);
    contains('&lt;img src=x onerror=alert(2)&gt;', $row['html']);
    same('<b>Hola</b> & adiós', $row['subject'], 'el asunto es texto, no HTML');
    contains('&lt;b&gt;Hola&lt;/b&gt; &amp; adiós', $row['html'], 'y en el título del HTML va escapado');
});

test('una variable desconocida no rompe el correo y se deja tal cual; al guardar se avisa; las conocidas se rellenan', function () use ($mtData): void {
    [$app] = mtApp();
    $mailer = mailTestService($app);
    same(['nombre', 'a.b'], $mailer->saveTexts('admin_reset', 'es', ['greeting' => 'Hola {nombre} y {name} {a.b}']));
    $row = mailTestRow($app, $mailer->queue('admin_reset', 'ana@cliente.test', $mtData));
    contains('Hola {nombre} y Ana {a.b}', $row['html']);
    same([], $mailer->saveTexts('admin_reset', 'es', ['greeting' => 'Hola {name}', 'subject' => '{shop}: {minutes} minutos']));
    same('AIFEC: 30 minutos', mailTestRow($app, $mailer->queue('admin_reset', 'ana@cliente.test', $mtData))['subject']);

    $web = adminClient($app);
    $r = mtPost($web, 'admin_reset', ['action' => 'save', 'subject' => 'Clave {pedido}', 'greeting' => 'Hola {name}', 'farewell' => '']);
    same(303, $r->status);
    $page = $web->follow();
    contains('Estas variables no existen en esta plantilla', $page->body);
    contains('{pedido}', $page->body);
    contains('af-alert-warn', $page->body);
});

test('«Volver al texto original» deshace un solo campo y deja los otros', function () use ($mtData): void {
    [$app] = mtApp();
    $mailer = mailTestService($app);
    $mailer->saveTexts('admin_reset', 'es', ['subject' => 'Mi asunto', 'greeting' => 'Mi saludo', 'farewell' => 'Mi despedida']);
    $web = adminClient($app);
    $page = $web->get('/admin/mail/templates/admin_reset/edit');
    same(3, substr_count($page->body, 'Volver al texto original'), 'un botón por cada campo con texto propio');
    $r = mtPost($web, 'admin_reset', ['action' => 'reset:subject', 'subject' => 'Mi asunto', 'greeting' => 'Mi saludo', 'farewell' => 'Mi despedida']);
    same(303, $r->status);
    same(['subject' => '', 'greeting' => 'Mi saludo', 'farewell' => 'Mi despedida'], $mailer->texts('admin_reset', 'es'));
    $row = mailTestRow($app, $mailer->queue('admin_reset', 'ana@cliente.test', $mtData));
    same('Elige una contraseña nueva para AIFEC', $row['subject'], 'vuelve el asunto de serie');
    contains('Mi saludo', $row['html']);
    mtPost($web, 'admin_reset', ['action' => 'reset:nada', 'subject' => 'x']);
    same('Mi saludo', $mailer->texts('admin_reset', 'es')['greeting'], 'un campo que no existe no toca nada');
});

test('un texto vacío, o igual al de serie, borra lo guardado: vuelve el de serie', function (): void {
    [$app] = mtApp();
    $mailer = mailTestService($app);
    $mailer->saveTexts('admin_reset', 'es', ['subject' => 'Otro', 'greeting' => 'Otro saludo']);
    same('Otro', $mailer->texts('admin_reset', 'es')['subject']);
    $mailer->saveTexts('admin_reset', 'es', ['subject' => '', 'greeting' => 'Hola {name}']);
    same(['subject' => '', 'greeting' => '', 'farewell' => ''], $mailer->texts('admin_reset', 'es'), 'vacío o igual al de serie: nada guardado');
    same([], $app->settings()->arr('mail.texts'), 'y no queda ni la estructura vacía');
});

test('los textos de serie: la entrada es «Hola {name}» si la plantilla recibe un nombre, y la despedida la que declara', function (): void {
    [$app] = mtApp();
    $mailer = mailTestService($app);
    same(['subject' => 'Elige una contraseña nueva para {shop}', 'greeting' => 'Hola {name}', 'farewell' => 'Si no lo has pedido, ignora este mensaje: tu contraseña sigue siendo la misma.'], $mailer->defaults('admin_reset', 'es'));
    same(['subject' => 'Correo de prueba de {shop}', 'greeting' => '', 'farewell' => ''], $mailer->defaults('mail_test', 'es'), 'sin nombre, no hay entrada de serie');
    same('Choose a new password for {shop}', $mailer->defaults('admin_reset', 'en')['subject']);
});

test('las variables que se enseñan son los datos sueltos que la plantilla recibe y «shop»; los datos con estructura, no', function (): void {
    [$app] = mtApp();
    $mailer = mailTestService($app);
    same(['shop', 'name', 'url', 'minutes'], $mailer->variables('admin_reset'));
    same(['shop'], $mailer->variables('mail_test'));
    same(['shop', 'code', 'name'], $mailer->variables('plugin_demo'), '«lines» es una lista: no se puede poner entre llaves');
    $web = adminClient($app);
    $page = $web->get('/admin/mail/templates/plugin_demo/edit');
    foreach (['{shop}', '{code}', '{name}'] as $variable) {
        contains($variable, $page->body);
    }
    notContains('{lines}', $page->body);
    notContains('{url}', $page->body, 'y las de otra plantilla, tampoco');
});

test('la vista previa enseña lo que se está escribiendo con los datos de ejemplo y no guarda nada', function (): void {
    [$app] = mtApp();
    $mailer = mailTestService($app);
    $web = adminClient($app);
    $r = mtPost($web, 'admin_reset', ['action' => 'preview', 'subject' => 'PREVIA de {shop}', 'greeting' => 'Saludo de prueba {name}', 'farewell' => '']);
    same(200, $r->status);
    contains('PREVIA de AIFEC', $r->body);
    contains('Saludo de prueba Ana', $r->body, 'con el nombre de ejemplo');
    contains('<iframe sandbox srcdoc="', $r->body);
    contains('value="PREVIA de {shop}"', $r->body, 'y los campos siguen con lo escrito');
    same(['subject' => '', 'greeting' => '', 'farewell' => ''], $mailer->texts('admin_reset', 'es'), 'no se ha guardado');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {mail_messages}'), 'ni se ha enviado nada');
});

test('«Enviarme una prueba» manda la plantilla con los textos escritos a quien pulsa, aunque el correo esté retenido', function (): void {
    [$app, $transport] = mtApp(['mail.mode' => 'hold']);
    $web = adminClient($app);
    $r = mtPost($web, 'plugin_demo', ['action' => 'test', 'subject' => 'Prueba {code} en {shop}', 'greeting' => 'Hola {name}', 'farewell' => 'Hasta pronto']);
    same(200, $r->status);
    contains('Correo de prueba enviado a admin@tienda.test', $r->body);
    same(1, count($transport->sent), 'un correo en el transporte, con el correo retenido');
    $message = $transport->sent[0];
    same('Prueba X1 en AIFEC', $message->getSubject(), 'con los datos de ejemplo y el texto escrito');
    same(['admin@tienda.test'], $message->recipients(), 'a quien lo pide');
    contains('Hasta pronto', quoted_printable_decode($message->toString()));
    $mailer = mailTestService($app);
    same(['subject' => '', 'greeting' => '', 'farewell' => ''], $mailer->texts('plugin_demo', 'es'), 'una prueba no guarda los textos');
    $row = mtLastRow($app);
    same('sent', $row['status']);
    same('plugin_demo', $row['template']);
});

test('la prueba que falla lo dice y no da el aviso de enviada', function (): void {
    [$app] = mtApp(['mail.mode' => 'live'], new MailTestFailingTransport('SMTP caído'));
    $web = adminClient($app);
    $r = mtPost($web, 'mail_test', ['action' => 'test', 'subject' => '', 'greeting' => '', 'farewell' => '']);
    same(200, $r->status);
    contains('El correo de prueba no se ha enviado', $r->body);
    contains('SMTP caído', $r->body);
    notContains('Correo de prueba enviado a', $r->body);
});

test('una plantilla de un plugin aparece sola en la lista, se edita y sus textos salen en su correo', function (): void {
    [$app] = mtApp();
    $web = adminClient($app);
    $page = $web->get('/admin/mail/templates');
    contains('plugin_demo', $page->body);
    contains('/admin/mail/templates/plugin_demo/edit', $page->body);
    contains('A template added by a plugin', $page->body);
    $r = mtPost($web, 'plugin_demo', ['action' => 'save', 'subject' => 'Clave {code}', 'greeting' => '', 'farewell' => 'Fin']);
    same(303, $r->status);
    $row = mailTestRow($app, mailTestService($app)->queue('plugin_demo', 'ana@cliente.test', ['code' => 'Z9']));
    same('Clave Z9', $row['subject']);
    contains('Fin', $row['html']);
    contains('Editar textos', $web->get('/admin/mail/templates/plugin_demo')->body, 'y la vista previa enlaza a la edición');
});

test('editar los textos pide el permiso de correo; ver las plantillas, solo el de ver', function (): void {
    [$app] = mtApp();
    $app->db()->insert('roles', ['code' => 'lector', 'name' => 'Lector de correo', 'caps' => json_encode(['mail.view']), 'system' => 0]);
    $viewer = adminClient($app, 'lector');
    same(200, $viewer->get('/admin/mail/templates')->status);
    notContains('/edit', $viewer->last->body, 'sin el permiso no se ofrece editar');
    same(200, $viewer->get('/admin/mail/templates/admin_reset')->status);
    notContains('Editar textos', $viewer->last->body);
    same(403, $viewer->get('/admin/mail/templates/admin_reset/edit')->status);
    $viewer->get('/admin/profile');
    same(403, $viewer->post('/admin/mail/templates/admin_reset/edit', ['_token' => aifecToken($viewer), 'action' => 'save', 'subject' => 'Pirata'])->status);
    foreach (['manager', 'support'] as $role) {
        $web = adminClient($app, $role);
        same(403, $web->get('/admin/mail/templates/admin_reset/edit')->status, $role);
    }
    same([], $app->settings()->arr('mail.texts'), 'nadie sin permiso ha cambiado nada');
});

test('una plantilla que no existe da 404 al editar y al guardar', function (): void {
    [$app] = mtApp();
    $web = adminClient($app);
    same(404, $web->get('/admin/mail/templates/no_existe/edit')->status);
    $web->get('/admin/profile');
    same(404, $web->post('/admin/mail/templates/no_existe/edit', ['_token' => aifecToken($web), 'action' => 'save', 'subject' => 'x'])->status);
    $mailer = mailTestService($app);
    throws(function () use ($mailer): void {
        $mailer->saveTexts('no_existe', 'es', ['subject' => 'x']);
    }, \InvalidArgumentException::class);
});

test('un asunto con saltos de línea queda en una línea: no se puede colar una cabecera', function () use ($mtData): void {
    [$app] = mtApp();
    $mailer = mailTestService($app);
    $mailer->saveTexts('admin_reset', 'es', ['subject' => "Hola\r\nBcc: pirata@cliente.test\nOtra"]);
    $texts = $mailer->texts('admin_reset', 'es');
    notContains("\n", $texts['subject']);
    notContains("\r", $texts['subject']);
    $row = mailTestRow($app, $mailer->queue('admin_reset', 'ana@cliente.test', $mtData));
    notContains("\n", $row['subject']);
    contains('Bcc:', $row['subject'], 'queda como texto del asunto, no como cabecera');
});

test('los saltos de línea del saludo salen como <br> y un texto largo se corta', function () use ($mtData): void {
    [$app] = mtApp();
    $mailer = mailTestService($app);
    $mailer->saveTexts('admin_reset', 'es', ['greeting' => "Línea uno\nLínea dos"]);
    contains("Línea uno<br>\nLínea dos", mailTestRow($app, $mailer->queue('admin_reset', 'ana@cliente.test', $mtData))['html']);
    $mailer->saveTexts('admin_reset', 'es', ['farewell' => str_repeat('ñ', 5000)]);
    same(2000, mb_strlen($mailer->texts('admin_reset', 'es')['farewell']), 'tope de 2000 caracteres');
});

test('una prueba de una persona aprobada sale aunque el correo esté retenido; la normal, no', function (): void {
    [$app, $transport] = mtApp(['mail.mode' => 'hold']);
    $mailer = mailTestService($app);
    same('held', mailTestRow($app, $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana'], ['now' => true]))['status']);
    same(0, count($transport->sent));
    same('sent', mailTestRow($app, $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana'], ['now' => true, 'approved' => true]))['status']);
    same(1, count($transport->sent));
});

test('los textos se guardan con la forma plantilla → idioma → campo y solo lo que tiene texto', function (): void {
    [$app] = mtApp();
    $mailer = mailTestService($app);
    $mailer->saveTexts('admin_reset', 'es', ['subject' => 'Uno', 'greeting' => '', 'farewell' => 'Tres']);
    $mailer->saveTexts('admin_reset', 'en', ['subject' => 'One']);
    $app->settings()->reload();
    same(['admin_reset' => ['es' => ['subject' => 'Uno', 'farewell' => 'Tres'], 'en' => ['subject' => 'One']]], $app->settings()->arr('mail.texts'));
    $mailer->saveTexts('admin_reset', 'es', []);
    same(['admin_reset' => ['en' => ['subject' => 'One']]], $app->settings()->arr('mail.texts'), 'al vaciar un idioma, el otro se queda');
});

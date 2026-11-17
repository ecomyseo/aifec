<?php
/**
 * AIFEC — pruebas del servicio de correo: modos, cola, reintentos, regla de las 24 horas, idioma y cabeceras.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Mail\MailTasks;

require_once __DIR__ . '/MailKit.php';

test('en modo retener un correo normal queda held y no llega al transporte', function (): void {
    [$app, $transport] = mailTestApp();
    $mailer = mailTestService($app);
    $id = $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana'], ['now' => true]);
    $row = mailTestRow($app, $id);
    same('held', $row['status']);
    same('hold', $row['error']);
    $mailer->sendPending();
    same(0, count($transport->sent));
    same('held', mailTestRow($app, $id)['status']);
});

test('en modo retener una plantilla del sistema sale, y con system_always apagado tampoco', function (): void {
    [$app, $transport] = mailTestApp();
    $mailer = mailTestService($app);
    $id = $mailer->queue('mail_test', 'ana@cliente.test', [], ['now' => true]);
    same('sent', mailTestRow($app, $id)['status']);
    same(1, count($transport->sent));

    $app->settings()->set('mail.system_always', false);
    $held = $mailer->queue('mail_test', 'ana@cliente.test', [], ['now' => true]);
    same('held', mailTestRow($app, $held)['status']);
    $mailer->sendPending();
    same(1, count($transport->sent), 'sigue sin salir nada más');
});

test('en modo live se guarda pending y sale con sendPending; el transporte recibe todo', function (): void {
    [$app, $transport] = mailTestApp(['mail.mode' => 'live', 'mail.from_email' => 'hola@tienda.test', 'mail.from_name' => 'Tienda Ñandú']);
    $mailer = mailTestService($app);
    $id = $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana'], ['to_name' => 'Ana Ruiz', 'related' => 'order:15']);
    $row = mailTestRow($app, $id);
    same('pending', $row['status']);
    same(null, $row['sent_at']);
    same('order:15', $row['related']);
    same(0, count($transport->sent));

    same(['sent' => 1, 'failed' => 0, 'held' => 0], $mailer->sendPending());
    $row = mailTestRow($app, $id);
    same('sent', $row['status']);
    same('2026-11-12 12:00:00', $row['sent_at']);
    same(1, count($transport->sent));
    $message = $transport->sent[0];
    same(['ana@cliente.test'], $message->recipients());
    same('hola@tienda.test', $message->sender());
    same('Hello Ana from AIFEC', $message->getSubject());
    $raw = quoted_printable_decode($message->toString());
    contains('El correo de AIFEC funciona', $raw);
    contains('Ana Ruiz', $raw);
    same(['sent' => 0, 'failed' => 0, 'held' => 0], $mailer->sendPending(), 'una segunda pasada no reenvía');
    same(1, count($transport->sent));
});

test('con now => true sale en la misma petición, sin sendPending', function (): void {
    [$app, $transport] = mailTestApp(['mail.mode' => 'live']);
    $id = mailTestService($app)->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana'], ['now' => true]);
    same('sent', mailTestRow($app, $id)['status']);
    same(1, count($transport->sent));
});

test('en modo redirect todo va a mail.redirect_to y el asunto lleva el destinatario real', function (): void {
    [$app, $transport] = mailTestApp(['mail.mode' => 'redirect', 'mail.redirect_to' => 'pruebas@tienda.test']);
    $mailer = mailTestService($app);
    $mailer->queue('plain_test', 'cliente.real@cliente.test', ['name' => 'Ana'], ['now' => true, 'to_name' => 'Cliente Real']);
    same(1, count($transport->sent));
    same(['pruebas@tienda.test'], $transport->sent[0]->recipients());
    same('[cliente.real@cliente.test] Hello Ana from AIFEC', $transport->sent[0]->getSubject());
    notContains('cliente.real@cliente.test>', $transport->sent[0]->toString(), 'el real no va de destinatario');

    // Sin dirección de pruebas no sale nada, ni siquiera lo del sistema.
    $app->settings()->set('mail.redirect_to', '');
    $id = $mailer->queue('mail_test', 'ana@cliente.test', [], ['now' => true]);
    $row = mailTestRow($app, $id);
    same('held', $row['status']);
    same('redirect_missing', $row['error']);
    same(1, count($transport->sent));
});

test('un transporte que falla: intentos, error sin la contraseña, espera entre intentos y failed al quinto', function (): void {
    $failing = new MailTestFailingTransport('Cannot log in to smtp with S3cr3t! (password: S3cr3t!)');
    [$app] = mailTestApp(['mail.mode' => 'live'], $failing);
    $app->setSecret('mail.smtp_password', 'S3cr3t!');
    $mailer = mailTestService($app);
    $id = $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana']);

    same(['sent' => 0, 'failed' => 1, 'held' => 0], $mailer->sendPending());
    $row = mailTestRow($app, $id);
    same('pending', $row['status']);
    same(1, (int) $row['attempts']);
    contains('Cannot log in', (string) $row['error']);
    notContains('S3cr3t', (string) $row['error'], 'la contraseña no se guarda');

    same(['sent' => 0, 'failed' => 0, 'held' => 0], $mailer->sendPending(), 'todavía no toca');
    $app->clock()->advance(299);
    $mailer->sendPending();
    same(1, (int) mailTestRow($app, $id)['attempts'], 'a los 299 s sigue esperando');
    $app->clock()->advance(1);
    $mailer->sendPending();
    same(2, (int) mailTestRow($app, $id)['attempts'], 'a los 5 minutos reintenta');
    same('pending', mailTestRow($app, $id)['status']);

    foreach ([600, 900, 1200] as $wait) {
        $mailer->sendPending();
        $app->clock()->advance($wait);
        $mailer->sendPending();
    }
    $row = mailTestRow($app, $id);
    same('failed', $row['status']);
    same(5, (int) $row['attempts']);
    same(5, $failing->calls);
    same(['sent' => 0, 'failed' => 0, 'held' => 0], $mailer->sendPending(), 'un failed no se toca más');
});

test('regla de las 24 horas: un pending antiguo pasa a held/expired y release() lo rechaza', function (): void {
    [$app, $transport] = mailTestApp(['mail.mode' => 'live']);
    $mailer = mailTestService($app);
    $old = $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana']);
    $app->clock()->advance(25 * 3600);
    same(['sent' => 0, 'failed' => 0, 'held' => 1], $mailer->sendPending());
    $row = mailTestRow($app, $old);
    same('held', $row['status']);
    same('expired', $row['error']);
    same(0, count($transport->sent));
    same(false, $mailer->release($old), 'liberar uno caducado devuelve false');
    same('held', mailTestRow($app, $old)['status']);

    // Y uno retenido hace más de 24 horas tampoco se libera aunque no haya «expirado»: nació retenido.
    $app->settings()->set('mail.mode', 'hold');
    $held = $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana']);
    same(true, $mailer->release($held), 'recién retenido sí se libera');
    $held2 = $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana']);
    $app->clock()->advance(25 * 3600);
    same(false, $mailer->release($held2));
    same(false, $mailer->release(99999), 'un id que no existe');
});

test('liberar un retenido lo deja pendiente y sale aunque el correo siga retenido', function (): void {
    [$app, $transport] = mailTestApp();
    $mailer = mailTestService($app);
    $id = $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana']);
    same('held', mailTestRow($app, $id)['status']);
    same(true, $mailer->release($id));
    $row = mailTestRow($app, $id);
    same('pending', $row['status']);
    same(null, $row['error']);
    same(['sent' => 1, 'failed' => 0, 'held' => 0], $mailer->sendPending());
    same(1, count($transport->sent));
    same(false, $mailer->release($id), 'uno ya enviado no se libera');
});

test('reenviar copia un mensaje enviado como uno nuevo pendiente', function (): void {
    [$app, $transport] = mailTestApp(['mail.mode' => 'live']);
    $mailer = mailTestService($app);
    $id = $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana'], ['now' => true, 'related' => 'order:7']);
    same('sent', mailTestRow($app, $id)['status']);
    $new = $mailer->resend($id);
    check($new > $id, 'se crea otro mensaje');
    $row = mailTestRow($app, $new);
    same('pending', $row['status']);
    same('order:7', $row['related']);
    same(mailTestRow($app, $id)['subject'], $row['subject']);
    same(0, (int) $row['attempts']);
    $mailer->sendPending();
    same(2, count($transport->sent));
    same(0, $mailer->resend($new + 100), 'un id que no existe');
    $app->settings()->set('mail.mode', 'hold');
    $heldId = $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana']);
    same(0, $mailer->resend($heldId), 'un retenido no se reenvía: se libera');
});

test('idioma: la misma plantilla en es y en en da asuntos distintos y el idioma de la aplicación se restaura', function (): void {
    [$app] = mailTestApp();
    $mailer = mailTestService($app);
    $before = $app->i18n()->locale();
    $es = $mailer->render('admin_reset', ['name' => 'Ana', 'url' => 'https://tienda.test/admin/reset/x', 'minutes' => 60], 'es');
    $en = $mailer->render('admin_reset', ['name' => 'Ana', 'url' => 'https://tienda.test/admin/reset/x', 'minutes' => 60], 'en');
    same('Elige una contraseña nueva para AIFEC', $es['subject']);
    same('Choose a new password for AIFEC', $en['subject']);
    contains('Hola Ana', $es['html']);
    contains('Hello Ana', $en['html']);
    contains('lang="es"', $es['html']);
    same($before, $app->i18n()->locale());

    // Si la vista lanza, el idioma también se restaura.
    $app->i18n()->setLocale('en');
    throws(function () use ($mailer): void {
        $mailer->render('broken_test', [], 'es');
    }, \RuntimeException::class);
    same('en', $app->i18n()->locale());
});

test('el asunto no escapa «&» ni comillas y el cuerpo escapa un dato con script', function (): void {
    [$app] = mailTestApp(['store.name' => 'Tom & "Jerry\'s" <b>']);
    $mailer = mailTestService($app);
    $mail = $mailer->render('admin_reset', ['name' => '<script>alert(1)</script>', 'url' => 'https://tienda.test/admin/reset/x?a=1&b=2', 'minutes' => 5], 'en');
    same('Choose a new password for Tom & "Jerry\'s" <b>', $mail['subject']);
    notContains('<script>', $mail['html']);
    contains('&lt;script&gt;alert(1)&lt;/script&gt;', $mail['html']);
    contains('a=1&amp;b=2', $mail['html']);
    notContains('&amp;amp;', $mail['subject']);
    notContains('<script>', $mail['text'], 'la versión en texto no trae etiquetas de datos');

    $id = mailTestService($app)->queue('admin_reset', 'ana@cliente.test', ['name' => 'Ñu 😀', 'url' => 'https://tienda.test/admin/reset/x', 'minutes' => 5], ['locale' => 'en']);
    same('Choose a new password for Tom & "Jerry\'s" <b>', mailTestRow($app, $id)['subject']);
});

test('el mensaje compuesto no lleva ninguna cabecera X- ni propia', function (): void {
    [$app, $transport] = mailTestApp(['mail.mode' => 'live', 'mail.reply_to' => 'ayuda@tienda.test']);
    mailTestService($app)->queue('admin_reset', 'ana@cliente.test', ['name' => 'Ana', 'url' => 'https://tienda.test/admin/reset/x', 'minutes' => 5], [
        'now' => true,
        'attachments' => [['name' => 'factura.pdf', 'content' => "%PDF-1.4\n%fake", 'mime' => 'application/pdf']],
    ]);
    same(1, count($transport->sent));
    $raw = $transport->sent[0]->toString();
    $names = [];
    $head = explode("\r\n\r\n", $raw, 2)[0];
    foreach (explode("\r\n", $head) as $line) {
        if ($line !== '' && $line[0] !== ' ' && $line[0] !== "\t" && strpos($line, ':') !== false) {
            $names[] = strtolower(substr($line, 0, (int) strpos($line, ':')));
        }
    }
    check($names !== [], 'hay cabeceras');
    foreach ($names as $name) {
        check(strpos($name, 'x-') !== 0, 'cabecera propia: ' . $name);
    }
    same(0, preg_match('/^X-/mi', $raw), 'ninguna línea empieza por X-');
    contains('factura.pdf', $raw);
    contains('Reply-To: ayuda@tienda.test', $raw);
    contains('Date: Thu, 12 Nov 2026 12:00:00 +0000', $raw);
});

test('una plantilla desconocida lanza y no guarda ninguna fila; una dirección mala también', function (): void {
    [$app] = mailTestApp(['mail.mode' => 'live']);
    $mailer = mailTestService($app);
    throws(function () use ($mailer): void {
        $mailer->queue('no_existe', 'ana@cliente.test');
    }, \InvalidArgumentException::class);
    throws(function () use ($mailer): void {
        $mailer->queue('mail_test', 'esto no es un correo');
    }, \InvalidArgumentException::class);
    throws(function () use ($mailer): void {
        $mailer->queue('mail_test', 'ana@cliente.test', [], ['attachments' => [['name' => '', 'content' => 'x']]]);
    }, \InvalidArgumentException::class);
    same(0, mailTestCount($app));
});

test('vaciar lo antiguo: purge deja la fila y quita html, texto y datos', function (): void {
    [$app] = mailTestApp(['mail.mode' => 'live', 'mail.keep_days' => 30]);
    $mailer = mailTestService($app);
    $old = $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana'], ['now' => true]);
    $app->clock()->advance(40 * 86400);
    $recent = $mailer->queue('plain_test', 'bea@cliente.test', ['name' => 'Bea'], ['now' => true]);
    $pending = $mailer->queue('plain_test', 'eva@cliente.test', ['name' => 'Eva']);
    same('1 emptied', MailTasks::purge($app));
    $row = mailTestRow($app, $old);
    same(null, $row['html']);
    same(null, $row['text_body']);
    same(null, $row['data']);
    same('ana@cliente.test', $row['to_email']);
    same('sent', $row['status']);
    check(mailTestRow($app, $recent)['html'] !== null, 'el reciente conserva el cuerpo');
    check(mailTestRow($app, $pending)['html'] !== null, 'un pendiente no se toca');
    same(null, MailTasks::purge($app), 'una segunda pasada no tiene nada que vaciar');
    same(0, mailTestService($app)->resend($old), 'sin contenido no se puede reenviar');
    $app->settings()->set('mail.keep_days', 0);
    same(null, MailTasks::purge($app), 'cero días = conservar todo');
});

test('MailTasks::send devuelve una nota corta con lo enviado', function (): void {
    [$app] = mailTestApp(['mail.mode' => 'live']);
    $mailer = mailTestService($app);
    same(null, MailTasks::send($app));
    $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana']);
    $mailer->queue('plain_test', 'bea@cliente.test', ['name' => 'Bea']);
    $mailer->queue('plain_test', 'eva@cliente.test', ['name' => 'Eva']);
    same('3 sent', MailTasks::send($app));
});

test('sendTest: sale con la prueba, y devuelve el motivo si el correo está retenido sin system_always', function (): void {
    [$app, $transport] = mailTestApp();
    $mailer = mailTestService($app);
    same(null, $mailer->sendTest('ana@cliente.test'));
    same(1, count($transport->sent));
    $row = $app->db()->row("SELECT * FROM {mail_messages} WHERE template = 'mail_test'");
    same('sent', $row['status']);

    $app->settings()->set('mail.system_always', false);
    same('El correo está retenido: no se está enviando nada', $mailer->sendTest('ana@cliente.test'));
    same(1, count($transport->sent));
    same('Esa dirección de correo no es válida', $mailer->sendTest('malo'));
});

test('con «retries», un envío inmediato que falla se reintenta en el momento sin gastar intentos de la cola; sin la opción, espera a la cola', function (): void {
    \Aifec\Modules\Mail\Mailer::$quickRetryPauseMs = 0;
    $failing = new MailTestFailingTransport('Temporary failure in name resolution');
    [$app] = mailTestApp(['mail.mode' => 'live'], $failing);
    $mailer = mailTestService($app);

    $plain = $mailer->queue('mail_test', 'ana@cliente.test', [], ['now' => true]);
    same(1, $failing->calls, 'sin la opción, un solo intento');
    same('pending', (string) $app->db()->value('SELECT status FROM {mail_messages} WHERE id = ?', [$plain]));

    $failing->calls = 0;
    $urgent = $mailer->queue('mail_test', 'luis@cliente.test', [], ['now' => true, 'retries' => 2]);
    same(3, $failing->calls, 'el intento y dos reintentos inmediatos');
    $row = $app->db()->row('SELECT status, attempts, error FROM {mail_messages} WHERE id = ?', [$urgent]);
    same('pending', (string) $row['status'], 'si tampoco sale, sigue pendiente: decide quien lo pidió');
    same(1, (int) $row['attempts'], 'los reintentos inmediatos no cuentan');
    same('Temporary failure in name resolution', (string) $row['error']);

    $failing->calls = 0;
    $mailer->queue('mail_test', 'eva@cliente.test', [], ['now' => true, 'retries' => 99]);
    same(1 + \Aifec\Modules\Mail\Mailer::MAX_QUICK_RETRIES, $failing->calls, 'con tope');

    same(true, $mailer->cancel($urgent), 'un pendiente se puede retirar');
    same('failed', (string) $app->db()->value('SELECT status FROM {mail_messages} WHERE id = ?', [$urgent]));
    same(false, $mailer->cancel($urgent), 'una sola vez');
    \Aifec\Modules\Mail\Mailer::$quickRetryPauseMs = 400;
});

test('sendTest con un transporte que falla devuelve el error y no se reintenta', function (): void {
    $failing = new MailTestFailingTransport('Connection refused');
    [$app] = mailTestApp(['mail.mode' => 'live'], $failing);
    $mailer = mailTestService($app);
    same('Connection refused', $mailer->sendTest('ana@cliente.test'));
    $row = $app->db()->row("SELECT * FROM {mail_messages} WHERE template = 'mail_test'");
    same('failed', $row['status']);
    same(1, (int) $row['attempts']);
});

test('transporte log: escribe un .eml en la carpeta privada del sitio y lo da por enviado', function (): void {
    // 'mail_transport' => false: sin el transporte de mentira de las pruebas, para que mande el que eligen los ajustes.
    $app = testApp(['modules' => ['mail'], 'mail_transport' => false, 'settings' => ['mail.mode' => 'live', 'mail.transport' => 'log', 'mail.from_email' => 'tienda@tienda.test']]);
    $app->boot();
    $mailer = mailTestService($app);
    $id = $mailer->queue('mail_test', 'ana@cliente.test', [], ['now' => true]);
    same('sent', mailTestRow($app, $id)['status']);
    $files = glob($app->site()->path('mail') . '/*.eml');
    same(1, is_array($files) ? count($files) : 0);
    contains('To: ana@cliente.test', (string) file_get_contents((string) $files[0]));
});

test('SMTP sin servidor configurado falla con un mensaje claro y sin contraseñas', function (): void {
    $app = testApp(['modules' => ['mail'], 'mail_transport' => false, 'settings' => ['mail.mode' => 'live', 'mail.transport' => 'smtp', 'mail.from_email' => 'tienda@tienda.test']]);
    $app->boot();
    $mailer = mailTestService($app);
    $id = $mailer->queue('mail_test', 'ana@cliente.test', [], ['now' => true]);
    $row = mailTestRow($app, $id);
    same('pending', $row['status']);
    same('The SMTP server is not set', $row['error']);
});

test('las plantillas del módulo están declaradas y se pintan con sus datos de ejemplo', function (): void {
    [$app] = mailTestApp();
    $mailer = mailTestService($app);
    $templates = $mailer->templates();
    check(isset($templates['admin_reset'], $templates['mail_test']), 'plantillas del módulo');
    same('mail', $templates['admin_reset']['module']);
    same(true, $templates['admin_reset']['system']);
    foreach (['admin_reset', 'mail_test'] as $key) {
        $mail = $mailer->render($key, $templates[$key]['sample'], 'es');
        check($mail['subject'] !== '' && $mail['html'] !== '' && $mail['text'] !== '', $key . ' se pinta');
        contains('<table', $mail['html']);
        contains('width="600"', $mail['html']);
        notContains('<style', $mail['html'], 'estilos en línea, sin hoja de estilo');
    }
});

test('integración: pedir un enlace de acceso deja un admin_reset enviado con el enlace', function (): void {
    [$app, $transport] = mailTestApp();
    aifecAdmin($app, 'admin', 'ana@tienda.test', 'Ana Ruiz');
    $web = new TestClient($app);
    $r = $web->post('/admin/forgot', ['email' => 'ana@tienda.test']);
    same(200, $r->status);
    $row = $app->db()->row("SELECT * FROM {mail_messages} WHERE template = 'admin_reset'");
    check($row !== null, 'hay un mensaje admin_reset');
    same('sent', $row['status']);
    same('ana@tienda.test', $row['to_email']);
    contains('/admin/reset/', (string) $row['html']);
    same(1, count($transport->sent));
    contains('/admin/reset/', quoted_printable_decode($transport->sent[0]->toString()));

    $web->post('/admin/forgot', ['email' => 'nadie@tienda.test']);
    same(1, mailTestCount($app), 'una cuenta que no existe no genera correo');
});

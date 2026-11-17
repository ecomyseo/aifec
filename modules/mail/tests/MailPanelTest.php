<?php
/**
 * AIFEC — pruebas del panel del correo: registro, ficha, liberar, reenviar, prueba, plantillas, ajustes y avisos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/MailKit.php';

test('el registro se abre con permiso, da 403 sin él y lista los mensajes', function (): void {
    [$app] = mailTestApp();
    $mailer = mailTestService($app);
    $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana']);
    $web = adminClient($app);
    $r = $web->get('/admin/r/mail');
    same(200, $r->status);
    contains('ana@cliente.test', $r->body);
    contains('Hello Ana from AIFEC', $r->body);
    $filtered = $web->get('/admin/r/mail?f[status]=sent');
    same(200, $filtered->status);
    notContains('ana@cliente.test', $filtered->body, 'el filtro por estado deja fuera lo retenido');

    $support = adminClient($app, 'support');
    same(403, $support->get('/admin/r/mail')->status);
    same(403, $support->get('/admin/mail/templates')->status);
    same(403, $support->get('/admin/settings/mail')->status);
});

test('la ficha enseña el cuerpo en un iframe aislado y escapado, con sus botones', function (): void {
    [$app] = mailTestApp(['mail.mode' => 'live']);
    $mailer = mailTestService($app);
    $id = $mailer->queue('admin_reset', 'ana@cliente.test', ['name' => '<script>alert(1)</script> & Co', 'url' => 'https://tienda.test/admin/reset/zzz', 'minutes' => 30], ['now' => true]);
    $web = adminClient($app);
    $r = $web->get('/admin/r/mail/' . $id);
    same(200, $r->status);
    contains('<iframe sandbox srcdoc="', $r->body);
    contains('/admin/reset/zzz', $r->body);
    notContains('<script>alert(1)</script>', $r->body, 'ningún script suelto en la ficha');
    contains('&amp;lt;script&amp;gt;', $r->body, 'el srcdoc va escapado dos veces: como atributo y como contenido');
    contains('/admin/mail/' . $id . '/resend', $r->body);
    notContains('/admin/mail/' . $id . '/release', $r->body, 'uno enviado no se libera');
    contains('Enviado', $r->body);
});

test('Release libera un retenido y Resend copia uno enviado, releyendo la base', function (): void {
    [$app] = mailTestApp();
    $mailer = mailTestService($app);
    $held = $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana']);
    $sent = $mailer->queue('mail_test', 'bea@cliente.test', [], ['now' => true]);
    same('held', mailTestRow($app, $held)['status']);
    same('sent', mailTestRow($app, $sent)['status']);

    $web = adminClient($app);
    $page = $web->get('/admin/r/mail/' . $held);
    contains('/admin/mail/' . $held . '/release', $page->body);
    notContains('/admin/mail/' . $held . '/resend', $page->body);
    $r = $web->post('/admin/mail/' . $held . '/release', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same('pending', mailTestRow($app, $held)['status']);
    same(null, mailTestRow($app, $held)['error']);

    $before = mailTestCount($app);
    $web->get('/admin/r/mail/' . $sent);
    $r = $web->post('/admin/mail/' . $sent . '/resend', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same($before + 1, mailTestCount($app));
    $copy = $app->db()->row('SELECT * FROM {mail_messages} WHERE id <> ? AND to_email = ? ORDER BY id DESC', [$sent, 'bea@cliente.test']);
    same('pending', $copy['status']);
    same('mail_test', $copy['template']);

    // Un retenido de más de 24 horas ya no se libera, y la ficha ni lo ofrece.
    $old = $mailer->queue('plain_test', 'eva@cliente.test', ['name' => 'Eva']);
    $app->db()->update('mail_messages', ['created_at' => $app->clock()->sql($app->clock()->now() - 25 * 3600)], ['id' => $old]);
    $page = $web->get('/admin/r/mail/' . $old);
    notContains('/admin/mail/' . $old . '/release', $page->body);
    $r = $web->post('/admin/mail/' . $old . '/release', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same('held', mailTestRow($app, $old)['status']);
});

test('sin permiso de edición no hay botones y las acciones dan 403', function (): void {
    [$app] = mailTestApp();
    $id = mailTestService($app)->queue('mail_test', 'ana@cliente.test', [], ['now' => true]);
    $role = $app->db()->row("SELECT * FROM {roles} WHERE code = 'support'");
    $caps = json_decode((string) $role['caps'], true);
    $caps[] = 'mail.view';
    $app->db()->update('roles', ['caps' => json_encode($caps)], ['code' => 'support']);
    $web = adminClient($app, 'support');
    $page = $web->get('/admin/r/mail/' . $id);
    same(200, $page->status);
    notContains('/admin/mail/' . $id . '/resend', $page->body);
    $token = aifecToken($web);
    same(403, $web->post('/admin/mail/' . $id . '/resend', ['_token' => $token])->status);
    same(403, $web->post('/admin/mail/test', ['_token' => $token])->status);
    same(1, mailTestCount($app));
});

test('POST /admin/mail/test deja un mensaje mail_test para quien ha entrado', function (): void {
    [$app, $transport] = mailTestApp();
    $web = adminClient($app);
    $page = $web->get('/admin/mail/templates');
    same(200, $page->status);
    contains('Enviar un correo de prueba', $page->body);
    contains('/admin/mail/test', $page->body);
    $r = $web->post('/admin/mail/test', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    $row = $app->db()->row("SELECT * FROM {mail_messages} WHERE template = 'mail_test'");
    check($row !== null, 'hay un mail_test');
    same('admin@tienda.test', $row['to_email']);
    same('sent', $row['status'], 'las pruebas salen aunque el correo esté retenido');
    same(1, count($transport->sent));
    contains('Correo de prueba enviado a admin@tienda.test', $web->follow()->body);
});

test('la pantalla de plantillas lista las declaradas y la vista previa respeta el idioma', function (): void {
    [$app] = mailTestApp(['i18n.languages' => ['es', 'en']]);
    $web = adminClient($app);
    $list = $web->get('/admin/mail/templates');
    contains('admin_reset', $list->body);
    contains('mail_test', $list->body);
    contains('/admin/mail/templates/admin_reset', $list->body);
    $es = $web->get('/admin/mail/templates/admin_reset?lang=es');
    same(200, $es->status);
    contains('Elige una contraseña nueva para AIFEC', $es->body);
    contains('<iframe sandbox srcdoc="', $es->body);
    $en = $web->get('/admin/mail/templates/admin_reset?lang=en');
    contains('Choose a new password for AIFEC', $en->body);
    same(404, $web->get('/admin/mail/templates/no_existe')->status);
});

test('la pantalla de ajustes nunca contiene la contraseña SMTP guardada', function (): void {
    [$app] = mailTestApp();
    $app->setSecret('mail.smtp_password', 'Sup3rS3cret-Pass');
    $web = adminClient($app);
    $r = $web->get('/admin/settings/mail');
    same(200, $r->status);
    notContains('Sup3rS3cret', $r->body);
    notContains(base64_encode('Sup3rS3cret-Pass'), $r->body);
    contains('mlmode', $r->body);
    contains('mlsmtp', $r->body);
    contains('/admin/mail/templates', $r->body, 'el botón de prueba lleva a la pantalla de plantillas');
});

test('guardar los ajustes del correo conserva el secreto si se deja vacío y cambia el modo', function (): void {
    [$app] = mailTestApp();
    $app->setSecret('mail.smtp_password', 'Sup3rS3cret-Pass');
    $web = adminClient($app);
    $web->get('/admin/settings/mail');
    $r = $web->post('/admin/settings/mail', [
        '_token' => aifecToken($web),
        'f' => ['mail.mode' => 'live', 'mail.redirect_to' => '', 'mail.system_always' => '1', 'mail.from_email' => 'hola@tienda.test', 'mail.from_name' => 'Tom & Co',
            'mail.reply_to' => '', 'mail.transport' => 'smtp', 'mail.smtp_host' => 'smtp.tienda.test', 'mail.smtp_port' => '587', 'mail.smtp_encryption' => 'tls',
            'mail.smtp_user' => 'u', 'mail.smtp_password' => '', 'mail.smtp_timeout' => '15', 'mail.keep_days' => '90'],
    ]);
    same(302, $r->status);
    $app->settings()->reload();
    same('live', $app->settings()->str('mail.mode'));
    same('Tom & Co', $app->settings()->str('mail.from_name'));
    same('Sup3rS3cret-Pass', $app->secret('mail.smtp_password'));
});

test('el inicio avisa si el correo está retenido y si hay fallos en las últimas 24 horas', function (): void {
    [$app] = mailTestApp();
    $web = adminClient($app);
    $home = $web->get('/admin');
    contains('Los correos están retenidos: los clientes no reciben nada', $home->body);
    notContains('El correo está retenido: no se está enviando nada', $home->body, 'un solo aviso, no dos');
    notContains('en las últimas 24 horas', $home->body);

    $app->settings()->set('mail.mode', 'live');
    $app->db()->insert('mail_messages', [
        'to_email' => 'ana@cliente.test', 'subject' => 'Roto', 'template' => 'mail_test', 'locale' => 'es', 'status' => 'failed', 'attempts' => 5,
        'error' => 'x', 'created_at' => $app->clock()->sql($app->clock()->now() - 3600),
    ]);
    $app->db()->insert('mail_messages', [
        'to_email' => 'bea@cliente.test', 'subject' => 'Viejo roto', 'template' => 'mail_test', 'locale' => 'es', 'status' => 'failed', 'attempts' => 5,
        'error' => 'x', 'created_at' => $app->clock()->sql($app->clock()->now() - 3 * 86400),
    ]);
    $home = $web->get('/admin');
    notContains('El correo está retenido', $home->body);
    contains('1 correo ha fallado en las últimas 24 horas', $home->body);
});

<?php
/**
 * AIFEC — un correo con una credencial (enlace de un solo uso, código, clave) no deja su contenido en el registro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/MailKit.php';

test('un correo sensible enviado no guarda sus datos ni su contenido, y uno normal sí', function (): void {
    [$app, $transport] = mailTestApp(['mail.mode' => 'live']);
    $mailer = mailTestService($app);
    $url = 'https://tienda.test/admin/reset/SECRETO-DE-UN-SOLO-USO';
    $id = $mailer->queue('admin_reset', 'ana@cliente.test', ['name' => 'Ana', 'url' => $url, 'minutes' => 60], ['now' => true]);
    $row = mailTestRow($app, $id);
    same('sent', (string) $row['status']);
    same(1, count($transport->sent), 'el correo salió con su enlace');
    same('', (string) $row['html']);
    same('', (string) $row['text_body']);
    check(strpos((string) $row['data'], 'SECRETO-DE-UN-SOLO-USO') === false, 'el sobre ya no lleva el enlace');
    same(0, $mailer->resend($id), 'un mensaje vaciado no se puede reenviar');

    $normal = $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana'], ['now' => true, 'approved' => true]);
    check((string) mailTestRow($app, $normal)['html'] !== '', 'un correo normal conserva su contenido');
});

test('la ficha del registro no enseña los datos de un correo sensible aunque aún esté pendiente', function (): void {
    [$app] = mailTestApp(['mail.mode' => 'hold']);
    $mailer = mailTestService($app);
    $id = $mailer->queue('admin_reset', 'ana@cliente.test', ['name' => 'Ana', 'url' => 'https://tienda.test/admin/reset/OTRO-SECRETO', 'minutes' => 60]);
    $row = mailTestRow($app, $id);
    $html = \Aifec\Modules\Mail\MailAdmin::show($row, $app);
    check(strpos($html, 'OTRO-SECRETO') === false, 'ni en los datos ni en el mensaje');
    check($mailer->isSensitive('admin_reset'));
    check(!$mailer->isSensitive('plain_test'));
});

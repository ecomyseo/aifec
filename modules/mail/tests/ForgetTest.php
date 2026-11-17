<?php
/**
 * AIFEC — al anonimizar a un cliente, su correspondencia sale del registro de correo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/MailKit.php';

test('olvidar a una persona borra lo enviado a su correo y lo ligado a su ficha, y nada de los demás', function (): void {
    [$app] = mailTestApp();
    $mailer = mailTestService($app);
    $own = $mailer->queue('plain_test', 'Ana@Cliente.test', ['name' => 'Ana'], ['related' => 'order:7']);
    $byFile = $mailer->queue('plain_test', 'otro-correo@cliente.test', ['name' => 'Ana'], ['related' => 'customer:12']);
    $other = $mailer->queue('plain_test', 'luis@cliente.test', ['name' => 'Luis'], ['related' => 'customer:13']);
    same(3, mailTestCount($app));

    same(0, $mailer->forget('', ''), 'sin correo ni ficha no borra nada');
    same(3, mailTestCount($app));

    same(2, $mailer->forget('ana@cliente.test', 'customer:12'), 'el correo se compara sin mayúsculas');
    same(1, mailTestCount($app));
    same('luis@cliente.test', mailTestRow($app, $other)['to_email'], 'lo de otra persona sigue en su sitio');
    check($own > 0 && $byFile > 0);
});

test('el aviso «customer.anonymised» limpia el registro aunque llegue sin el correo', function (): void {
    [$app] = mailTestApp();
    $mailer = mailTestService($app);
    $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana'], ['related' => 'customer:12']);
    $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana'], ['related' => 'order:3']);
    $mailer->queue('plain_test', 'luis@cliente.test', ['name' => 'Luis'], ['related' => 'customer:13']);

    // Quien dispare el aviso a la antigua (solo el id) limpia al menos lo ligado a la ficha.
    $app->hooks()->fire('customer.anonymised', 12);
    same(2, mailTestCount($app));

    $app->hooks()->fire('customer.anonymised', 12, 'ana@cliente.test');
    same(1, mailTestCount($app), 'con el correo, también lo que se le envió por un pedido');
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {mail_messages} WHERE to_email = 'ana@cliente.test'"));
});

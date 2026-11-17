<?php
/**
 * AIFEC — modo silencioso: mientras dura una migración, el correo ni se compone ni se guarda ni sale.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/MailKit.php';

test('en silencio no sale ni se guarda nada, ni con el correo en modo real ni las plantillas del sistema', function (): void {
    [$app, $transport] = mailTestApp(['mail.mode' => 'live']);
    $mailer = mailTestService($app);
    same(false, $app->silenced());

    $before = $app->silence(true);
    same(false, $before, 'silence() devuelve cómo estaba');
    same(true, $app->silenced());
    same(0, $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana'], ['now' => true]));
    same(0, $mailer->queue('mail_test', 'ana@cliente.test', [], ['now' => true]), 'tampoco las del sistema');
    same(0, $mailer->queue('no_existe', 'no es un correo'), 'ni siquiera valida: no hace nada');
    $mailer->sendPending();
    same(0, mailTestCount($app));
    same(0, count($transport->sent));

    same(true, $app->silence($before));
    same(false, $app->silenced());
    $id = $mailer->queue('plain_test', 'ana@cliente.test', ['name' => 'Ana'], ['now' => true]);
    check($id > 0, 'al quitar el silencio vuelve a funcionar');
    same(1, count($transport->sent));
    same(1, mailTestCount($app), 'y lo que se pidió en silencio no reaparece');
});

test('el silencio se repone aunque lo de dentro falle', function (): void {
    [$app] = mailTestApp(['mail.mode' => 'live']);
    $before = $app->silence(true);
    try {
        throw new RuntimeException('algo falla a mitad de una migración');
    } catch (RuntimeException $e) {
        // sigue
    } finally {
        $app->silence($before);
    }
    same(false, $app->silenced());
});

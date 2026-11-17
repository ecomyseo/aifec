<?php
/**
 * AIFEC — correo: pantallas propias del panel (plantillas, prueba, liberar y reenviar).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Mail\MailController;

return static function (Router $r, App $app): void {
    $r->group(['prefix' => '/admin/mail', 'middleware' => ['admin']], static function (Router $r): void {
        $r->post('/test', [MailController::class, 'test'])->name('admin.mail.test');
        $r->get('/templates', [MailController::class, 'templates'])->name('admin.mail.templates');
        $r->get('/templates/{key:[A-Za-z0-9_.-]+}', [MailController::class, 'preview'])->name('admin.mail.preview');
        $r->get('/templates/{key:[A-Za-z0-9_.-]+}/edit', [MailController::class, 'edit'])->name('admin.mail.edit');
        $r->post('/templates/{key:[A-Za-z0-9_.-]+}/edit', [MailController::class, 'save'])->name('admin.mail.save');
        // La ayuda de la IA para los textos (solo responden con el módulo «ai» encendido y el permiso «ai.use»).
        $r->post('/templates/{key:[A-Za-z0-9_.-]+}/ai/propose', [MailController::class, 'aiPropose'])->name('admin.mail.ai.propose');
        $r->post('/templates/{key:[A-Za-z0-9_.-]+}/ai/translate', [MailController::class, 'aiTranslate'])->name('admin.mail.ai.translate');
        $r->post('/templates/{key:[A-Za-z0-9_.-]+}/ai/translate/save', [MailController::class, 'aiTranslateSave'])->name('admin.mail.ai.translate_save');
        $r->post('/{id:\d+}/release', [MailController::class, 'release'])->name('admin.mail.release');
        $r->post('/{id:\d+}/resend', [MailController::class, 'resend'])->name('admin.mail.resend');
    });
};

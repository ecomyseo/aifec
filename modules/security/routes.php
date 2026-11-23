<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Security\Passkeys;
use Aifec\Modules\Security\SecurityAdmin;

return static function (Router $r, App $app): void {
    // Entrar con passkey: sin sesión, solo desde la propia web (el controlador lo comprueba).
    $r->post('/admin/passkey/options', [Passkeys::class, 'loginOptions'])->name('admin.passkey.options');
    $r->post('/admin/passkey/login', [Passkeys::class, 'login'])->name('admin.passkey.login');

    $r->group(['prefix' => '/admin', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('/security', [SecurityAdmin::class, 'audit'])->name('admin.security');
        $r->post('/security/check-version', [SecurityAdmin::class, 'run'])->name('admin.security.version');
        $r->get('/security/passkeys', [SecurityAdmin::class, 'passkeys'])->name('admin.security.passkeys');
        $r->post('/passkeys/options', [Passkeys::class, 'registerOptions'])->name('admin.passkeys.options');
        $r->post('/passkeys/register', [Passkeys::class, 'register'])->name('admin.passkeys.register');
        $r->post('/passkeys/delete', [Passkeys::class, 'delete'])->name('admin.passkeys.delete');
    });
};

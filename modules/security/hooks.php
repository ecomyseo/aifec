<?php
/**
 * AIFEC — seguridad: el botón de entrar con passkey en la pantalla de acceso y los avisos del inicio del panel.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Security\Passkeys;
use Aifec\Modules\Security\SecurityAdmin;

return static function (App $app): void {
    // Debajo del botón de entrar: «Entrar con passkey» (el guion pide el desafío, abre el navegador y manda la firma).
    $app->hooks()->slot('admin.login.after', static function () use ($app): string {
        if (!Passkeys::enabled($app)) {
            return '';
        }

        return '<div class="af-passkey" style="margin-top: var(--s3)">'
            . '<button type="button" class="af-btn" id="af-passkey-login" style="width:100%" hidden>' . e(t('Sign in with a passkey')) . '</button>'
            . '<p class="af-small af-muted" id="af-passkey-error" role="alert" hidden></p></div>'
            . '<script src="' . e($app->url()->asset('m', 'security', 'passkey.js')) . '" defer'
            . ' data-options="' . e($app->url()->plain('/admin/passkey/options')) . '" data-login="' . e($app->url()->plain('/admin/passkey/login')) . '"'
            . ' data-fail="' . e(t('That passkey is not valid.')) . '"></script>';
    });

    $app->hooks()->filter('admin.status', static function ($status) use ($app) {
        return SecurityAdmin::status($status, $app);
    });
};

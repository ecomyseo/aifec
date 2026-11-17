<?php
/**
 * AIFEC — correo: publica el servicio «mailer» y los avisos de estado del inicio del panel.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Mail\MailAdmin;
use Aifec\Modules\Mail\Mailer;

return static function (App $app): void {
    $app->bind('mailer', static function (App $app): Mailer {
        return new Mailer($app);
    });
    $app->hooks()->filter('admin.status', [MailAdmin::class, 'status']);
    // Al anonimizar a un cliente, su correspondencia sale del registro (lleva su nombre, su correo y lo que compró).
    $app->hooks()->on('customer.anonymised', static function ($customerId, $email = '') use ($app): void {
        $app->service('mailer')->forget((string) $email, 'customer:' . (int) $customerId);
    });
};

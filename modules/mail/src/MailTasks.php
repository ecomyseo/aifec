<?php
/**
 * AIFEC — tareas programadas del correo: enviar lo pendiente y vaciar el contenido antiguo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Mail;

use Aifec\Kernel\App;

final class MailTasks
{
    /** Envía hasta 30 mensajes pendientes. Devuelve una nota corta («3 sent») o null si no había nada. */
    public static function send(App $app): ?string
    {
        $result = $app->service('mailer')->sendPending(30);
        $parts = [];
        foreach (['sent', 'failed', 'held'] as $key) {
            if ($result[$key] > 0) {
                $parts[] = $result[$key] . ' ' . $key;
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * Vacía el cuerpo (html, texto y datos) de los mensajes más antiguos que mail.keep_days; la línea del registro se conserva.
     * Cero días = no se vacía nada. Un mensaje pendiente no se toca.
     */
    public static function purge(App $app): ?string
    {
        $days = $app->settings()->int('mail.keep_days', 90);
        if ($days < 1) {
            return null;
        }
        $cutoff = $app->clock()->sql($app->clock()->now() - $days * 86400);
        $emptied = $app->db()->run(
            "UPDATE {mail_messages} SET html = NULL, text_body = NULL, data = NULL WHERE created_at < ? AND status <> 'pending' AND (html IS NOT NULL OR text_body IS NOT NULL OR data IS NOT NULL)",
            [$cutoff]
        );

        return $emptied > 0 ? $emptied . ' emptied' : null;
    }
}

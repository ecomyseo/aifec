<?php
/**
 * AIFEC — comprobaciones del correo (solo si el módulo de correo está): modo, transporte y mensajes fallidos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools\Checks;

use Aifec\Kernel\App;
use Aifec\Modules\Tools\Doctor;

final class MailChecks
{
    /** @return array<int,array<string,mixed>> */
    public static function all(App $app): array
    {
        if (!$app->has('mailer')) {
            return [];
        }

        return [
            ['id' => 'mail.setup', 'group' => 'mail', 'label' => t('Email mode and sending method'), 'run' => [self::class, 'setup']],
            ['id' => 'mail.failed', 'group' => 'mail', 'label' => t('Emails that failed in 24 hours'), 'run' => [self::class, 'failed']],
        ];
    }

    /** @return array<string,string> */
    public static function setup(App $app): array
    {
        $fix = $app->url()->plain('/admin/settings/mail');
        $mode = $app->service('mailer')->mode();
        $transport = $app->settings()->str('mail.transport', 'mail');
        $modes = ['live' => t('sending for real'), 'hold' => t('held: nothing leaves the shop'), 'redirect' => t('redirected to the test address')];
        $methods = ['smtp' => 'SMTP', 'mail' => t('server mail function'), 'log' => t('saved to files')];
        $detail = (isset($modes[$mode]) ? $modes[$mode] : $mode) . ' · ' . (isset($methods[$transport]) ? $methods[$transport] : $transport);
        if ($transport === 'smtp' && $app->settings()->str('mail.smtp_host') === '') {
            return Doctor::result('bad', $detail . ' — ' . t('the SMTP server is not set'), $fix);
        }
        if ($mode !== 'live' || $transport === 'log') {
            return Doctor::result('warn', $detail, $fix);
        }

        return Doctor::result('ok', $detail);
    }

    /** @return array<string,string> */
    public static function failed(App $app): array
    {
        $since = $app->clock()->sql($app->clock()->now() - 86400);
        $n = (int) $app->db()->value("SELECT COUNT(*) FROM {mail_messages} WHERE status = 'failed' AND created_at >= ?", [$since]);
        if ($n === 0) {
            return Doctor::result('ok', t('None'));
        }

        return Doctor::result('warn', tn('{n} email failed', '{n} emails failed', $n), $app->url()->plain('/admin/r/mail'));
    }
}

<?php
/**
 * AIFEC — piezas del panel del correo: la ficha de un mensaje del registro y los avisos de estado del inicio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Mail;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;

final class MailAdmin
{
    /**
     * Ficha de solo lectura de un mensaje (recurso «mail»): datos, error, cuerpo dentro de un iframe aislado y botones.
     *
     * @param array<string,mixed> $row
     */
    public static function show(array $row, App $app): string
    {
        $mailer = self::mailer($app);
        $id = (int) $row['id'];
        $status = (string) $row['status'];
        $canEdit = $app->auth()->can('mail.edit');
        $html = isset($row['html']) ? (string) $row['html'] : '';
        $text = isset($row['text_body']) ? (string) $row['text_body'] : '';
        $envelope = $mailer->envelope($row);

        $buttons = '';
        if ($canEdit && in_array($status, ['sent', 'failed'], true) && ($html !== '' || $text !== '')) {
            $buttons .= self::postButton($app, '/admin/mail/' . $id . '/resend', t('Resend'), 'refresh', false);
        }
        if ($canEdit && $status === 'held' && !$mailer->isExpired($row)) {
            $buttons .= self::postButton($app, '/admin/mail/' . $id . '/release', t('Release'), 'check', true);
        }

        $tz = $app->site()->timezone();
        $when = static function ($sql) use ($app, $tz): string {
            $ts = Clock::parse(is_string($sql) ? $sql : '');

            return $ts > 0 ? $app->i18n()->date($ts, 'medium', $tz) : '';
        };
        $to = (string) $row['to_email'];
        if (isset($row['to_name']) && (string) $row['to_name'] !== '') {
            $to = (string) $row['to_name'] . ' <' . $to . '>';
        }
        $problem = isset($row['error']) ? (string) $row['error'] : '';
        if ($status === 'held') {
            $problem = $mailer->reasonText($problem);
        }

        $out = '';
        if ($buttons !== '') {
            $out .= '<div class="af-toolbar">' . $buttons . '</div>';
        }
        $out .= '<div class="af-card"><div class="af-card-body"><dl class="af-kv">'
            . '<dt>' . e(t('To')) . '</dt><dd>' . e($to) . '</dd>'
            . '<dt>' . e(t('Subject')) . '</dt><dd>' . e((string) $row['subject']) . '</dd>'
            . '<dt>' . e(t('Status')) . '</dt><dd>' . self::badge($status) . '</dd>'
            . ($problem !== '' ? '<dt>' . e(t('Problem')) . '</dt><dd>' . e($problem) . '</dd>' : '')
            . '<dt>' . e(t('Template')) . '</dt><dd class="af-mono">' . e((string) $row['template']) . '</dd>'
            . '<dt>' . e(t('Language')) . '</dt><dd>' . e((string) $row['locale']) . '</dd>'
            . '<dt>' . e(t('Created')) . '</dt><dd>' . e($when($row['created_at'])) . '</dd>'
            . '<dt>' . e(t('Sent')) . '</dt><dd>' . e($when(isset($row['sent_at']) ? $row['sent_at'] : '')) . '</dd>'
            . '<dt>' . e(t('Attempts')) . '</dt><dd>' . (int) $row['attempts'] . '</dd>'
            . (isset($row['related']) && (string) $row['related'] !== '' ? '<dt>' . e(t('Related to')) . '</dt><dd class="af-mono">' . e((string) $row['related']) . '</dd>' : '')
            . '</dl></div></div>';

        if ($envelope['data'] !== []) {
            $json = json_encode($envelope['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $out .= '<div class="af-card"><div class="af-card-head"><h2>' . e(t('Data')) . '</h2></div><div class="af-card-body"><pre class="af-mono af-small">'
                . e(is_string($json) ? $json : '') . '</pre></div></div>';
        }

        $out .= '<div class="af-card"><div class="af-card-head"><h2>' . e(t('Message')) . '</h2></div>';
        if ($html !== '') {
            $out .= '<div class="af-card-body"><iframe sandbox srcdoc="' . e($html) . '" title="' . e(t('Message')) . '" '
                . 'class="af-frame" style="height:560px"></iframe></div>';
        } else {
            $out .= '<div class="af-empty"><p>' . e(t('The content of this message was emptied to save space.')) . '</p></div>';
        }
        if ($text !== '') {
            $out .= '<details class="af-help"><summary>' . e(t('Plain text version')) . '</summary><pre class="af-mono af-small">' . e($text) . '</pre></details>';
        }
        $out .= '</div>';

        return $out;
    }

    /**
     * Filtro «admin.status»: avisos del inicio del panel.
     *
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<int,array<string,mixed>>
     */
    public static function status(array $items, App $app): array
    {
        if (!$app->has('mailer')) {
            return $items;
        }
        $auth = $app->auth();
        if ($auth->can('mail.edit') && self::mailer($app)->mode() === Mailer::MODE_HOLD) {
            $items[] = [
                'kind' => 'warn', 'text' => t('Email is on hold: nothing is being sent'),
                'url' => $app->url()->plain('/admin/settings/mail'), 'label' => t('Open the settings'),
            ];
        }
        if ($auth->can('mail.view')) {
            $since = $app->clock()->sql($app->clock()->now() - 86400);
            $failed = (int) $app->db()->value("SELECT COUNT(*) FROM {mail_messages} WHERE status = 'failed' AND created_at >= ?", [$since]);
            if ($failed > 0) {
                $items[] = [
                    'kind' => 'bad',
                    'text' => tn('{n} email failed in the last 24 hours', '{n} emails failed in the last 24 hours', $failed),
                    'url' => Admin::resourceUrl($app, 'mail', null, ['f' => ['status' => 'failed']]), 'label' => t('Open the log'),
                ];
            }
        }

        return $items;
    }

    /** Insignia del estado de un mensaje. */
    public static function badge(string $status): string
    {
        switch ($status) {
            case 'sent':
                return Admin::badge(t('Sent'), 'ok');
            case 'pending':
                return Admin::badge(t('Pending'), 'info');
            case 'held':
                return Admin::badge(t('Held'), 'warn');
            case 'failed':
                return Admin::badge(t('Failed'), 'bad');
            default:
                return Admin::badge($status);
        }
    }

    /** Formulario POST de un solo botón (con la ficha del panel). El texto llega ya traducido. */
    public static function postButton(App $app, string $path, string $label, string $icon, bool $primary): string
    {
        return '<form method="post" action="' . e($app->url()->plain($path)) . '" style="display:inline-block;margin: 0 var(--s2) var(--s2) 0">' . Admin::tokenField($app)
            . '<button type="submit" class="af-btn' . ($primary ? ' af-btn-primary' : '') . '">' . Admin::icon($icon) . e($label) . '</button></form>';
    }

    private static function mailer(App $app): Mailer
    {
        $mailer = $app->service('mailer');
        if (!$mailer instanceof Mailer) {
            throw new \LogicException('The mailer service is not the mail module one');
        }

        return $mailer;
    }
}

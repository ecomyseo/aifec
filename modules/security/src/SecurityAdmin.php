<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Security;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * La seguridad en el panel: la auditoría, las passkeys de quien ha entrado, el aviso de versión y la pieza del inicio.
 */
final class SecurityAdmin
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/security: la auditoría.
     *
     * @param array<string,string> $params
     */
    public function audit(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('security.view');
        $audit = new SecurityAudit($this->app);
        $rows = $audit->run();
        $audit->save($rows);
        $newer = (new UpdateCheck($this->app))->newer();

        return Admin::page($this->app, 'security::admin/audit', ['rows' => $rows, 'score' => SecurityAudit::score($rows), 'counts' => SecurityAudit::counts($rows), 'newer' => $newer], [
            'title' => t('Security audit'),
            'active' => '/admin/security',
            'help' => Admin::helpText("The audit only looks: it changes nothing and does not go out to any network.\nEach point says what is wrong and what to do. Red is worth fixing today; yellow is worth fixing.\nIt runs by itself every day and the Dashboard warns if something is red."),
        ]);
    }

    /**
     * GET /admin/security/passkeys: las passkeys de quien ha entrado.
     *
     * @param array<string,string> $params
     */
    public function passkeys(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('security.view');
        $user = $this->app->auth()->admin();
        $keys = $user !== null ? $this->app->db()->select('SELECT id, name, created_at, last_used_at FROM {passkeys} WHERE user_id = ? ORDER BY id', [(int) $user['id']]) : [];

        return Admin::page($this->app, 'security::admin/passkeys', ['keys' => $keys, 'enabled' => Passkeys::enabled($this->app)], [
            'title' => t('Passkeys'),
            'active' => '/admin/security/passkeys',
            'help' => Admin::helpText("A passkey lives in your phone, your computer or a security key. To sign in you unlock it with your fingerprint, your face or your PIN: there is no password to steal.\nAdd one for each device you use. Keep your password and the recovery codes in case you lose them all."),
        ]);
    }

    /** @param array<string,string> $params */
    public function run(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('security.view');
        (new UpdateCheck($this->app))->refresh();
        Admin::flash($this->app, 'ok', t('Version checked.'));

        return Response::redirect($this->app->url()->plain('/admin/security'));
    }

    /**
     * Los avisos del inicio: versión nueva y auditoría con algo en rojo.
     *
     * @param mixed $status
     *
     * @return mixed
     */
    public static function status($status, App $app)
    {
        if (!is_array($status) || !$app->auth()->can('security.view')) {
            return $status;
        }
        $url = $app->url()->plain('/admin/security');
        $newer = (new UpdateCheck($app))->newer();
        if ($newer !== null) {
            $status[] = ['kind' => 'warn', 'key' => 'aifec_update', 'url' => (string) $newer['url'], 'label' => t('See what changed'),
                'text' => t('AIFEC {new} is out and this shop has {v}.', ['new' => (string) $newer['version'], 'v' => App::VERSION])];
        }
        $last = (new SecurityAudit($app))->last();
        if ($last !== null && (int) $last['bad'] > 0) {
            $status[] = ['kind' => 'bad', 'key' => 'security_audit', 'url' => $url, 'label' => t('Open the audit'),
                'text' => tn('The security audit found {n} serious problem', 'The security audit found {n} serious problems', (int) $last['bad'])];
        }

        return $status;
    }

    /** La pieza del inicio: la nota de la última auditoría. */
    public static function widget(App $app): string
    {
        $last = (new SecurityAudit($app))->last();
        if ($last === null) {
            return '';
        }

        return $app->view()->render('security::admin/widget', ['last' => $last]);
    }
}

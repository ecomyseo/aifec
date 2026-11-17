<?php
/**
 * AIFEC — comprobaciones de seguridad: administradores y segundo paso, y personas de fábrica de una demo en un sitio real.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools\Checks;

use Aifec\Kernel\App;
use Aifec\Modules\Tools\Doctor;

final class SecurityChecks
{
    /** @return array<int,array<string,mixed>> */
    public static function all(App $app): array
    {
        return [
            ['id' => 'security.admins', 'group' => 'security', 'label' => t('Administrators and second step'), 'run' => [self::class, 'admins']],
            ['id' => 'security.demo_users', 'group' => 'security', 'label' => t('No demo accounts in a real shop'), 'run' => [self::class, 'demoUsers']],
        ];
    }

    /** @return array<string,string> */
    public static function admins(App $app): array
    {
        $fix = $app->url()->plain('/admin/r/users');
        $all = [];
        foreach ($app->db()->select('SELECT code, caps FROM {roles}') as $role) {
            $caps = json_decode((string) $role['caps'], true);
            if (is_array($caps) && in_array('*', $caps, true)) {
                $all[(string) $role['code']] = true;
            }
        }
        $total = 0;
        $withSecondStep = 0;
        foreach ($app->db()->select('SELECT role, totp_secret FROM {users} WHERE active = 1') as $user) {
            if (!isset($all[(string) $user['role']])) {
                continue;
            }
            ++$total;
            if ($user['totp_secret'] !== null && $user['totp_secret'] !== '') {
                ++$withSecondStep;
            }
        }
        if ($total === 0) {
            return Doctor::result('warn', t('There is no active administrator'), $fix);
        }
        if ($total >= 2 || $withSecondStep === $total) {
            return Doctor::result('ok', tn('{n} administrator', '{n} administrators', $total) . ' · ' . t('{n} with the second step', ['n' => $withSecondStep]));
        }

        return Doctor::result('warn', t('Only one administrator and without the second step: if that account is lost, so is the panel'), $fix);
    }

    /** @return array<string,string> */
    public static function demoUsers(App $app): array
    {
        if (ShopChecks::isLocal($app)) {
            return Doctor::result('ok', t('Local or demo site'));
        }
        $found = 0;
        foreach ($app->db()->select('SELECT email, role FROM {users} WHERE active = 1') as $user) {
            $email = strtolower((string) $user['email']);
            $reserved = preg_match('/@([a-z0-9-]+\.)*(test|example|invalid|localhost)$/', $email) === 1 || preg_match('/@example\.(com|org|net)$/', $email) === 1;
            if ($reserved || (string) $user['role'] === 'demo') {
                ++$found;
            }
        }
        if ($found === 0) {
            return Doctor::result('ok', t('None'));
        }

        return Doctor::result('warn', tn('{n} account looks like a demo account', '{n} accounts look like demo accounts', $found), $app->url()->plain('/admin/r/users'));
    }
}

<?php
/**
 * AIFEC — órdenes de consola del núcleo: personas del equipo.
 *
 *   php aifec user:create ana@tienda.es "Ana Ruiz" [--role=admin] [--password=…] [--site=id]
 *   php aifec user:password ana@tienda.es [--password=…]
 *   php aifec user:2fa-reset ana@tienda.es
 *
 * Sin --password se genera una y se enseña una sola vez.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\DocsReference;
use Aifec\Cli\I18nCheck;
use Aifec\Security\Passwords;
use Aifec\Security\Random;
use Aifec\Security\RecoveryCodes;

$commands = [
    'user:create' => ['help' => 'Creates a team member: user:create <email> <name> [--role=admin] [--password=…]', 'run' => static function ($app, array $args, $io): int {
        $email = isset($args[0]) ? strtolower(trim((string) $args[0])) : '';
        $name = isset($args[1]) ? trim((string) $args[1]) : '';
        $role = isset($args['role']) && is_string($args['role']) ? $args['role'] : 'admin';
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $name === '') {
            $io->error('Usage: user:create <email> <name> [--role=admin] [--password=…]');

            return 2;
        }
        if ($app->db()->value('SELECT COUNT(*) FROM {roles} WHERE code = ?', [$role]) < 1) {
            $io->error('Unknown role: ' . $role);

            return 2;
        }
        if ($app->db()->value('SELECT COUNT(*) FROM {users} WHERE email = ?', [$email]) > 0) {
            $io->error('That email already exists');

            return 1;
        }
        $given = isset($args['password']) && is_string($args['password']) && $args['password'] !== '';
        $password = $given ? (string) $args['password'] : Random::pick('abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', 16);
        if (mb_strlen($password) < 10) {
            $io->error('The password needs at least 10 characters');

            return 2;
        }
        $now = $app->clock()->sql();
        $app->db()->insert('users', ['email' => $email, 'name' => $name, 'password' => Passwords::hash($password), 'role' => $role, 'locale' => $app->defaultLang(), 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $io->line('Created ' . $email . ' (' . $role . ')' . ($given ? '' : ' — password: ' . $password));

        return 0;
    }],
    'user:password' => ['help' => 'Sets a new password: user:password <email> [--password=…]', 'run' => static function ($app, array $args, $io): int {
        $email = isset($args[0]) ? strtolower(trim((string) $args[0])) : '';
        $user = $app->db()->row('SELECT id FROM {users} WHERE email = ?', [$email]);
        if ($user === null) {
            $io->error('No such user');

            return 1;
        }
        $given = isset($args['password']) && is_string($args['password']) && $args['password'] !== '';
        $password = $given ? (string) $args['password'] : Random::pick('abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', 16);
        if (mb_strlen($password) < 10) {
            $io->error('The password needs at least 10 characters');

            return 2;
        }
        $app->db()->update('users', ['password' => Passwords::hash($password), 'updated_at' => $app->clock()->sql()], ['id' => (int) $user['id']]);
        $app->db()->delete('sessions', ['guard' => 'admin', 'user_id' => (int) $user['id']]);
        $io->line('Password changed for ' . $email . ($given ? '' : ' — password: ' . $password));

        return 0;
    }],
    'user:2fa-reset' => ['help' => 'Turns off two-step verification for someone locked out: user:2fa-reset <email>', 'run' => static function ($app, array $args, $io): int {
        $email = isset($args[0]) ? strtolower(trim((string) $args[0])) : '';
        $user = $email !== '' ? $app->db()->row('SELECT id, email, totp_secret FROM {users} WHERE email = ?', [$email]) : null;
        if ($user === null) {
            $io->error('No such user');

            return 1;
        }
        $id = (int) $user['id'];
        $had = !empty($user['totp_secret']);
        $app->db()->update('users', ['totp_secret' => null, 'updated_at' => $app->clock()->sql()], ['id' => $id]);
        $codes = (new RecoveryCodes($app->db(), $app->site()->secret()))->clear($id);
        $sessions = $app->db()->delete('sessions', ['guard' => 'admin', 'user_id' => $id]);
        if ($had) {
            // Sin persona en la sesión (es la consola): se apunta a mano, para que quede en el registro de actividad.
            $app->db()->insert('audit', ['user_id' => null, 'user_name' => 'console', 'action' => 'team.two_step_removed', 'entity' => 'user', 'entity_id' => (string) $id,
                'summary' => $email . ' (by console)', 'ip' => 'cli', 'created_at' => $app->clock()->sql()]);
        }
        $io->line($had
            ? 'Two-step verification turned off for ' . $email . ' (' . $codes . ' recovery codes deleted, ' . $sessions . ' sessions closed)'
            : 'Two-step verification was already off for ' . $email . ' (' . $codes . ' recovery codes deleted, ' . $sessions . ' sessions closed)');

        return 0;
    }],
    'setting:get' => ['help' => 'Shows a setting: setting:get <key>', 'run' => static function ($app, array $args, $io): int {
        $key = isset($args[0]) ? trim((string) $args[0]) : '';
        if ($key === '') {
            $io->error('Usage: setting:get <key>');

            return 2;
        }
        $value = $app->settings()->get($key);
        // Un secreto no se enseña nunca, tampoco por consola.
        if (is_string($value) && strncmp($value, 'enc:', 4) === 0) {
            $io->line($key . ' = (secret)');

            return 0;
        }
        $io->line($key . ' = ' . ($value === null ? '(not set)' : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

        return 0;
    }],
    'setting:set' => ['help' => 'Changes a setting: setting:set <key> <value> (true, false, numbers and JSON are understood; --text keeps it as text)', 'run' => static function ($app, array $args, $io): int {
        $key = isset($args[0]) ? trim((string) $args[0]) : '';
        if ($key === '' || !isset($args[1]) || preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $key) !== 1) {
            $io->error('Usage: setting:set <key> <value> [--text]');

            return 2;
        }
        $raw = (string) $args[1];
        $value = $raw;
        if (empty($args['text'])) {
            $decoded = json_decode($raw, true);
            if ($decoded !== null || strtolower($raw) === 'null') {
                $value = $decoded;
            }
        }
        $before = $app->settings()->get($key);
        $app->settings()->set($key, $value);
        $app->cache()->flush();
        if ($before !== $value) {
            // Lo mismo que hace el panel al guardar: quien dependa de ese ajuste (las direcciones, lo que haya calculado
            // con él) se entera. El aviso lleva el módulo que declara la clave en su settings.php.
            foreach ($app->modules()->definitions('settings') as $module => $definition) {
                foreach (isset($definition['tabs']) && is_array($definition['tabs']) ? $definition['tabs'] : [] as $tab) {
                    if (is_array($tab) && isset($tab['fields']) && is_array($tab['fields']) && isset($tab['fields'][$key])) {
                        $app->hooks()->fire('settings.saved', (string) $module, [$key], $app);
                        break 2;
                    }
                }
            }
        }
        $io->line($key . ' = ' . (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return 0;
    }],
    'i18n:check' => ['help' => 'Checks that every text of a folder has a translation: i18n:check <core|modules/x|plugins/x|themes/x> [lang] [--unused]', 'run' => static function ($app, array $args, $io): int {
        $target = isset($args[0]) ? trim(str_replace('\\', '/', (string) $args[0]), '/') : '';
        $lang = isset($args[1]) && is_string($args[1]) ? $args[1] : 'es';
        try {
            $result = I18nCheck::run($app->root(), $target, $lang);
        } catch (\InvalidArgumentException $e) {
            $io->error('Usage: i18n:check <core|modules/x|plugins/x|themes/x> [lang] [--unused]');

            return 2;
        }
        // Las que faltan salen listas para pegar en lang/<idioma>.php.
        foreach (explode("\n", rtrim(I18nCheck::snippet($result['missing']))) as $line) {
            if ($line !== '') {
                $io->line($line);
            }
        }
        if (!empty($args['unused'])) {
            foreach ($result['unused'] as $key) {
                $io->line('UNUSED: ' . $key);
            }
        }
        foreach ($result['duplicates'] as $key => $where) {
            $io->line('DUPLICATE: ' . $key . ' (lines ' . implode(', ', $where) . ')');
        }
        $io->line('I18N ' . $target . ' [' . $lang . ']: ' . $result['strings'] . ' strings, ' . count($result['missing']) . ' missing'
            . ($result['duplicates'] !== [] ? ', ' . count($result['duplicates']) . ' duplicated' : ''));

        return $result['ok'] ? 0 : 1;
    }],
    'docs:reference' => ['help' => 'Writes the developer reference (commands, settings, routes, permissions, hooks, tables) from what the system declares: docs:reference [--out=docs/07-referencia/_generado]', 'run' => static function ($app, array $args, $io): int {
        $out = isset($args['out']) ? $args['out'] : 'docs/07-referencia/_generado';
        if (!is_string($out) || trim($out) === '') {
            $io->error('Usage: docs:reference [--out=docs/07-referencia/_generado]');

            return 2;
        }
        // Relativa a la raíz del proyecto, salvo que sea absoluta (/ruta o C:\ruta).
        $dir = preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $out) === 1 ? $out : $app->root() . '/' . $out;
        try {
            $result = DocsReference::write($app, $dir);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }
        foreach ($result['files'] as $name => $rows) {
            $io->line($name . '  ' . $rows);
        }
        $io->line('REFERENCE: ' . count($result['files']) . ' files');

        return 0;
    }],
];

return $commands;

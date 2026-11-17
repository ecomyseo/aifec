<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Writers;

use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\WriteContext;

/**
 * El equipo del origen. Entran INACTIVOS y SIN contraseña, con el papel de aquí que corresponde a lo que hacían allí
 * (role_meaning): el administrador del origen no llega como administrador salvo que la opción «team_admins» lo permita; sin ella
 * entra con el papel de gestión más alto que no es de administrador. No pueden entrar hasta que alguien los active y les ponga una
 * contraseña, y no se les manda nada. Una persona cuyo correo ya existe aquí no se toca.
 */
final class TeamWriter extends WriterBase
{
    /** Papel de aquí por lo que hacía la persona en el origen. */
    private const ROLES = ['admin' => 'admin', 'manager' => 'manager', 'editor' => 'editor', 'support' => 'support', 'other' => 'support'];

    public function type(): string
    {
        return 'team';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $email = strtolower(trim((string) $record['email']));
        if ($email === '' || strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->result(self::SKIPPED, null, 'The email address is not valid');
        }
        $found = $this->resolve('team', $record, $ctx, static function (int $localId) use ($db): ?array {
            return $db->find('users', ['id' => $localId]);
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($row === null) {
            $other = $db->row('SELECT id FROM {users} WHERE email = ?', [$email]);
            if ($other !== null) {
                $ctx->note('team', $id, 'note', 'A user with this email address already exists here and was left as it is');

                return $this->result(self::UNCHANGED, (int) $other['id']);
            }
        }
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($action === self::CONFLICT) {
            return $this->conflict((int) $row['id']);
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }

        $now = $this->now();
        $locale = isset($record['locale']) && is_string($record['locale']) ? $this->shortLang($record['locale']) : '';
        $data = [
            'name' => $this->cut((string) $record['name'], 120), 'role' => $this->role($record, $ctx, $id),
            'locale' => in_array($locale, $ctx->languages(), true) ? $locale : $this->app->defaultLang(),
            'last_login_at' => $this->sqlDate(isset($record['last_login_at']) ? $record['last_login_at'] : null), 'updated_at' => $now,
        ];
        if ($row === null) {
            // Sin contraseña: la columna no admite nulos y un texto vacío no se puede verificar nunca.
            $localId = $db->insert('users', $data + ['email' => $email, 'password' => '', 'active' => 0, 'created_at' => $now]);
            $ctx->note('team', $id, 'note', 'The person was brought inactive and without a password: switch them on and set a password before they can sign in');
        } else {
            $localId = (int) $row['id'];
            $db->update('users', $data, ['id' => $localId]);
        }
        $ctx->remember('team', $id, $localId, Neutral::hash($record));

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    /**
     * El papel de aquí. El administrador del origen solo llega como administrador con la opción «team_admins».
     *
     * @param array<string,mixed> $record
     */
    private function role(array $record, WriteContext $ctx, string $id): string
    {
        $meaning = isset($record['role_meaning']) && is_string($record['role_meaning']) && isset(self::ROLES[$record['role_meaning']]) ? $record['role_meaning'] : 'other';
        $role = self::ROLES[$meaning];
        if ($role === 'admin' && !(bool) $ctx->option('team_admins', false)) {
            $role = 'manager';
            $ctx->note('team', $id, 'note', 'An administrator of the source was brought as a store manager (the option team_admins is off)');
        }
        if ($this->db()->value('SELECT code FROM {roles} WHERE code = ?', [$role]) === null) {
            $first = $this->db()->value("SELECT code FROM {roles} WHERE code <> 'admin' ORDER BY id");
            $role = $first !== null ? (string) $first : 'support';
        }

        return $role;
    }
}

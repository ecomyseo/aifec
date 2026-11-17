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
 * Los suscritos al boletín. Solo entra quien trae la fecha en que dio su consentimiento: es la prueba. Sin ella no se trae
 * (se cuenta como no traído con su motivo). Cada uno es una fila de «consents» de tipo marketing con su fecha y su origen y,
 * si ese correo es de un cliente, su marca de marketing. Nunca se manda nada: no hay correo de confirmación.
 *
 * «consents» no lleva fecha de modificación: se considera tocado aquí si, después de traerlo, ese correo dio otro consentimiento
 * de marketing (por ejemplo, se dio de baja). Entonces choca y no se toca; y a quien se dio de baja después no se le vuelve a
 * marcar como suscrito.
 */
final class SubscribersWriter extends WriterBase
{
    public function type(): string
    {
        return 'subscribers';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $email = strtolower(trim((string) $record['email']));
        if ($email === '' || strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->result(self::SKIPPED, null, 'The email address is not valid');
        }
        $at = $this->sqlDate(isset($record['granted_at']) ? $record['granted_at'] : null);
        if ($at === null) {
            return $this->result(self::SKIPPED, null, 'There is no proof of consent (the subscriber has no consent date)');
        }
        $context = isset($record['source']) && is_string($record['source']) ? $this->cut($record['source'], 60) : '';
        $found = $this->resolve('subscribers', $record, $ctx, static function (int $localId) use ($db): ?array {
            return $db->find('consents', ['id' => $localId]);
        }, static function () use ($db, $email, $at): ?array {
            return $db->row("SELECT * FROM {consents} WHERE email = ? AND kind = 'marketing' AND granted = 1 AND created_at = ?", [$email, $at]);
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($row !== null) {
            $mapped = $ctx->mapped('subscribers', $id);
            if ($mapped !== null && !(bool) $ctx->option('overwrite_local', false)
                && $db->value("SELECT id FROM {consents} WHERE email = ? AND kind = 'marketing' AND created_at > ? AND id <> ?", [$email, $mapped['written_at'], (int) $row['id']]) !== null) {
                return $this->conflict((int) $row['id']);
            }
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }

        $customer = $db->row('SELECT id FROM {customers} WHERE email = ?', [$email]);
        $customerId = $customer !== null ? (int) $customer['id'] : null;
        $data = ['customer_id' => $customerId, 'email' => $email, 'kind' => 'marketing', 'granted' => 1, 'context' => $context !== '' ? $context : null, 'created_at' => $at];
        if ($row === null) {
            $localId = $db->insert('consents', $data + ['version' => null, 'ip' => null]);
        } else {
            $localId = (int) $row['id'];
            $db->update('consents', $data, ['id' => $localId]);
        }
        if ($customerId !== null && $db->value("SELECT id FROM {consents} WHERE email = ? AND kind = 'marketing' AND granted = 0 AND created_at > ?", [$email, $at]) === null) {
            $db->run('UPDATE {customers} SET marketing = 1, marketing_at = ? WHERE id = ? AND marketing = 0', [$at, $customerId]);
        }
        $ctx->remember('subscribers', $id, $localId, Neutral::hash($record));

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }
}

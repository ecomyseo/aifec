<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Licencias;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Security\Random;

/**
 * Las licencias: emitirlas al pagar, revocarlas al devolver, comprobarlas desde los programas instalados y gestionarlas en el panel.
 *
 * Reglas que se mantienen en todos los caminos:
 *  - Una licencia por unidad comprada (la firmada, una por línea, con las unidades dentro de la clave). La pareja (línea del
 *    pedido, unidad) es única en la base de datos: emitir dos veces el mismo pedido no duplica nada.
 *  - La clave se guarda tal cual (el cliente tiene que poder verla) y no se escribe en ningún registro.
 *  - La comprobación contesta siempre con la misma forma, exista la clave o no, y nunca guarda dirección, dominio ni correo.
 */
final class Licenses
{
    /** Días que se guarda el registro de comprobaciones. */
    public const CHECK_DAYS = 90;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ emitir

    /**
     * Emite las licencias de un pedido pagado. Lo que ya está emitido no se repite. Devuelve las licencias nuevas.
     *
     * @param array<string,mixed> $order el pedido completo (Orders::find)
     *
     * @return array<int,array<string,mixed>>
     */
    public function issueForOrder(array $order): array
    {
        $created = [];
        foreach (isset($order['items']) && is_array($order['items']) ? $order['items'] : [] as $item) {
            if (!is_array($item) || !isset($item['kind'], $item['product_id']) || $item['kind'] !== 'product' || (int) $item['product_id'] < 1) {
                continue;
            }
            $config = Products::get($this->app, (int) $item['product_id']);
            if ($config === null) {
                continue;
            }
            $qty = max(1, (int) $item['qty']);
            if ($config['kind'] === 'signed') {
                $row = $this->issueOne($order, $item, $config, 1, $qty);
                if ($row !== null) {
                    $created[] = $row;
                }
                continue;
            }
            for ($unit = 1; $unit <= min($qty, 100); ++$unit) {
                $row = $this->issueOne($order, $item, $config, $unit, 1);
                if ($row !== null) {
                    $created[] = $row;
                }
            }
        }

        return $created;
    }

    /**
     * @param array<string,mixed>                                       $order
     * @param array<string,mixed>                                       $item
     * @param array{kind:string,max_domains:int,update_months:int}      $config
     *
     * @return array<string,mixed>|null null si esa unidad ya tenía su licencia
     */
    private function issueOne(array $order, array $item, array $config, int $unit, int $units): ?array
    {
        $db = $this->app->db();
        $clock = $this->app->clock();
        if ($db->value('SELECT id FROM {gm_licenses} WHERE order_item_id = ? AND unit = ?', [(int) $item['id'], $unit]) !== null) {
            return null;
        }
        $now = $clock->now();
        $updatesUntil = $config['update_months'] > 0 ? (new \DateTimeImmutable('@' . $now))->modify('+' . $config['update_months'] . ' months')->getTimestamp() : null;
        $billing = isset($order['billing']) && is_array($order['billing']) ? $order['billing'] : [];
        $holder = '';
        $note = '';
        $status = 'active';
        if ($config['kind'] === 'signed') {
            $holder = Nif::clean(isset($billing['vat_id']) ? (string) $billing['vat_id'] : '');
            $signed = $this->sign($holder, $units, $updatesUntil, $now);
            $key = $signed['key'];
            if ($key === '') {
                $status = 'pending';
                $note = $signed['note'];
                $key = 'PENDING-' . Random::pick(KeyFormat::ALPHABET, 14);
            }
        } else {
            $key = KeyFormat::generate();
        }
        $row = [
            'license_key' => $key, 'kind' => $config['kind'], 'status' => $status, 'order_id' => (int) $order['id'], 'order_item_id' => (int) $item['id'], 'unit' => $unit,
            'customer_id' => isset($order['customer_id']) && $order['customer_id'] !== null ? (int) $order['customer_id'] : null, 'email' => (string) $order['email'],
            'product_id' => (int) $item['product_id'], 'product_name' => mb_substr((string) $item['name'], 0, 191, 'UTF-8'), 'max_domains' => $config['max_domains'],
            'units' => $units, 'holder' => $holder, 'updates_until' => $updatesUntil !== null ? $clock->sql($updatesUntil) : null, 'note' => $note !== '' ? $note : null,
            'created_at' => $clock->sql(), 'updated_at' => $clock->sql(),
        ];
        // Con una clave al azar que ya existiera (casi imposible) se prueba otra.
        for ($try = 0; $try < 5; ++$try) {
            if ($db->insertIgnore('gm_licenses', $row)) {
                $found = $this->findByKey($row['license_key']);

                return $found;
            }
            if ($db->value('SELECT id FROM {gm_licenses} WHERE order_item_id = ? AND unit = ?', [(int) $item['id'], $unit]) !== null) {
                return null;
            }
            $row['license_key'] = $config['kind'] === 'signed' ? 'PENDING-' . Random::pick(KeyFormat::ALPHABET, 14) : KeyFormat::generate();
        }

        return null;
    }

    /**
     * Firma la clave de una línea. Sin NIF válido o sin clave de firma no se emite: la licencia queda «pendiente» con el motivo.
     *
     * @return array{key:string,note:string}
     */
    public function sign(string $nif, int $units, ?int $updatesUntil, int $now): array
    {
        if ($nif === '') {
            return ['key' => '', 'note' => t('The order has no tax ID. Add it and reissue the key.')];
        }
        if (!Nif::valid($nif)) {
            return ['key' => '', 'note' => t('The tax ID is not valid. Correct it and reissue the key.')];
        }
        $pem = (string) $this->app->secret('gm_licencias.signing_key');
        if (trim($pem) === '') {
            return ['key' => '', 'note' => t('There is no signing key in the settings. Add it and reissue the key.')];
        }
        $result = SignedKey::generate($pem, $nif, $units, $updatesUntil, $now);

        return $result['key'] !== '' ? ['key' => $result['key'], 'note' => ''] : ['key' => '', 'note' => t('The key could not be signed. Check the signing key in the settings.')];
    }

    // ------------------------------------------------------------------ devoluciones

    /** Revoca todas las licencias de un pedido (devuelto del todo o cancelado). Devuelve cuántas. */
    public function revokeForOrder(int $orderId, string $reason): int
    {
        $now = $this->app->clock()->sql();

        return $this->app->db()->run(
            "UPDATE {gm_licenses} SET status = 'revoked', revoked_at = ?, updated_at = ?, note = ? WHERE order_id = ? AND status <> 'revoked'",
            [$now, $now, $reason, $orderId]
        );
    }

    /**
     * Revoca las licencias de las unidades devueltas de cada línea (una devolución parcial). Se revocan las últimas unidades.
     * La licencia firmada de una línea (que cubre todas sus unidades) se revoca cuando se devuelven todas.
     *
     * @param array<int|string,int|string> $itemQty línea del pedido => unidades devueltas EN ESTA devolución
     */
    public function revokeItems(int $orderId, array $itemQty, string $reason): int
    {
        $db = $this->app->db();
        $now = $this->app->clock()->sql();
        $total = 0;
        foreach ($itemQty as $itemId => $qty) {
            $itemId = (int) $itemId;
            $qty = (int) $qty;
            if ($itemId < 1 || $qty < 1) {
                continue;
            }
            $rows = $db->select("SELECT id, kind, units FROM {gm_licenses} WHERE order_id = ? AND order_item_id = ? AND status <> 'revoked' ORDER BY unit DESC", [$orderId, $itemId]);
            foreach ($rows as $row) {
                if ($qty < 1) {
                    break;
                }
                if ((string) $row['kind'] === 'signed') {
                    // Cubre «units» unidades: solo se revoca cuando se devuelven todas.
                    if ($qty < (int) $row['units']) {
                        continue;
                    }
                    $qty -= (int) $row['units'];
                } else {
                    --$qty;
                }
                $total += $db->run("UPDATE {gm_licenses} SET status = 'revoked', revoked_at = ?, updated_at = ?, note = ? WHERE id = ?", [$now, $now, $reason, (int) $row['id']]);
            }
        }

        return $total;
    }

    // ------------------------------------------------------------------ leer

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->app->db()->row('SELECT * FROM {gm_licenses} WHERE id = ?', [$id]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByKey(string $key): ?array
    {
        return $key === '' ? null : $this->app->db()->row('SELECT * FROM {gm_licenses} WHERE license_key = ?', [$key]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function forOrder(int $orderId): array
    {
        return $this->app->db()->select('SELECT * FROM {gm_licenses} WHERE order_id = ? ORDER BY id', [$orderId]);
    }

    /**
     * Las licencias de un cliente: las de sus pedidos y, si su correo está verificado, las que se compraron con ese correo.
     *
     * @param array<string,mixed> $me el cliente (Customers::current)
     *
     * @return array<int,array<string,mixed>>
     */
    public function forCustomer(array $me): array
    {
        return $this->app->db()->select('SELECT * FROM {gm_licenses} WHERE customer_id = ? OR (email = ? AND ? = 1) ORDER BY id DESC', [(int) $me['id'], (string) $me['email'], !empty($me['verified']) ? 1 : 0]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function domains(int $licenseId): array
    {
        return $this->app->db()->select('SELECT * FROM {gm_license_domains} WHERE license_id = ? ORDER BY id', [$licenseId]);
    }

    // ------------------------------------------------------------------ la API de los programas instalados

    /**
     * Comprueba una clave para un dominio y, si queda sitio, lo activa. SIEMPRE devuelve la misma forma de respuesta.
     *
     * reason: ok · invalid (no existe, está revocada o pendiente, o no vale para este uso: no se distingue) · domain_limit.
     *
     * @return array{valid:bool,reason:string,updates_until:?string,updates_active:bool,domains_used:int,domains_max:int,activated:bool}
     */
    public function verify(string $rawKey, string $rawDomain): array
    {
        $key = KeyFormat::normalize($rawKey);
        $domain = KeyFormat::domain($rawDomain);
        $license = $this->findByKey($key);
        if ($license === null || (string) $license['status'] !== 'active') {
            $this->log(null, 'verify', 'invalid');

            return $this->answer(false, 'invalid');
        }
        $id = (int) $license['id'];
        $db = $this->app->db();
        $clock = $this->app->clock();
        $activated = false;
        if ((string) $license['kind'] === 'domain') {
            if ($domain === '') {
                $this->log($id, 'verify', 'invalid');

                return $this->answer(false, 'invalid');
            }
            $known = $db->value('SELECT id FROM {gm_license_domains} WHERE license_id = ? AND domain = ?', [$id, $domain]);
            if ($known !== null) {
                $db->update('gm_license_domains', ['last_seen_at' => $clock->sql()], ['id' => (int) $known]);
            } else {
                $max = (int) $license['max_domains'];
                $used = (int) $db->value('SELECT COUNT(*) FROM {gm_license_domains} WHERE license_id = ?', [$id]);
                if ($max > 0 && $used >= $max) {
                    $this->log($id, 'verify', 'domain_limit');

                    return $this->answer(false, 'domain_limit', $license, $used);
                }
                $db->insertIgnore('gm_license_domains', ['license_id' => $id, 'domain' => $domain, 'activated_at' => $clock->sql(), 'last_seen_at' => $clock->sql()]);
                // Dos activaciones a la vez no pasan del tope: si al contar nos hemos pasado, esta se deshace.
                $after = (int) $db->value('SELECT COUNT(*) FROM {gm_license_domains} WHERE license_id = ?', [$id]);
                if ($max > 0 && $after > $max) {
                    $db->delete('gm_license_domains', ['license_id' => $id, 'domain' => $domain]);
                    $this->log($id, 'verify', 'domain_limit');

                    return $this->answer(false, 'domain_limit', $license, $max);
                }
                $activated = true;
            }
        }
        $this->log($id, 'verify', 'ok');
        $used = (int) $db->value('SELECT COUNT(*) FROM {gm_license_domains} WHERE license_id = ?', [$id]);

        return $this->answer(true, 'ok', $license, $used, $activated);
    }

    /**
     * Quita un dominio de una licencia (el cliente cambia de tienda). Contesta siempre igual: solo «deactivated» dice si se quitó algo.
     *
     * @return array{ok:bool,deactivated:bool}
     */
    public function deactivate(string $rawKey, string $rawDomain): array
    {
        $license = $this->findByKey(KeyFormat::normalize($rawKey));
        $domain = KeyFormat::domain($rawDomain);
        $removed = 0;
        if ($license !== null && (string) $license['status'] === 'active' && $domain !== '') {
            $removed = $this->app->db()->delete('gm_license_domains', ['license_id' => (int) $license['id'], 'domain' => $domain]);
        }
        $this->log($license !== null ? (int) $license['id'] : null, 'deactivate', $removed > 0 ? 'ok' : 'none');

        return ['ok' => true, 'deactivated' => $removed > 0];
    }

    /**
     * La respuesta de la API: siempre las mismas claves.
     *
     * @param array<string,mixed>|null $license
     *
     * @return array{valid:bool,reason:string,updates_until:?string,updates_active:bool,domains_used:int,domains_max:int,activated:bool}
     */
    private function answer(bool $valid, string $reason, ?array $license = null, int $used = 0, bool $activated = false): array
    {
        $until = $license !== null && $license['updates_until'] !== null ? substr((string) $license['updates_until'], 0, 10) : null;
        $active = $license !== null && ($license['updates_until'] === null || Clock::parse((string) $license['updates_until']) >= $this->app->clock()->now());

        return [
            'valid' => $valid, 'reason' => $reason, 'updates_until' => $valid || $reason === 'domain_limit' ? $until : null,
            'updates_active' => $valid && $active, 'domains_used' => $valid || $reason === 'domain_limit' ? $used : 0,
            'domains_max' => $valid || $reason === 'domain_limit' ? (int) $license['max_domains'] : 0, 'activated' => $activated,
        ];
    }

    /**
     * La respuesta de un rechazo (límite de uso, petición mal hecha): la misma forma que las demás.
     *
     * @return array{valid:bool,reason:string,updates_until:?string,updates_active:bool,domains_used:int,domains_max:int,activated:bool}
     */
    public function refusal(string $reason): array
    {
        return $this->answer(false, $reason);
    }

    /** Apunta una comprobación: qué pasó y cuándo. Nada de direcciones, dominios ni correos. */
    public function log(?int $licenseId, string $action, string $result): void
    {
        $this->app->db()->insert('gm_license_checks', ['license_id' => $licenseId, 'action' => $action, 'result' => $result, 'created_at' => $this->app->clock()->sql()]);
    }

    /** Tarea diaria: borra el registro de comprobaciones de más de 90 días. Devuelve cuántas. */
    public function purgeChecks(): int
    {
        $clock = $this->app->clock();

        return $this->app->db()->run('DELETE FROM {gm_license_checks} WHERE created_at < ?', [$clock->sql($clock->now() - self::CHECK_DAYS * 86400)]);
    }

    // ------------------------------------------------------------------ el panel

    public function revoke(int $id, string $note = ''): bool
    {
        $now = $this->app->clock()->sql();

        return $this->app->db()->run("UPDATE {gm_licenses} SET status = 'revoked', revoked_at = ?, updated_at = ?, note = ? WHERE id = ? AND status <> 'revoked'", [$now, $now, $note !== '' ? $note : null, $id]) === 1;
    }

    public function reactivate(int $id): bool
    {
        $now = $this->app->clock()->sql();

        return $this->app->db()->run("UPDATE {gm_licenses} SET status = 'active', revoked_at = NULL, updated_at = ? WHERE id = ? AND status = 'revoked'", [$now, $id]) === 1;
    }

    /**
     * Amplía las actualizaciones: suma meses a la fecha (o a hoy, si ya pasó o no tenía). Devuelve la fecha nueva, o '' si no existe.
     * Una licencia firmada ya lleva la fecha dentro de la clave: hay que reemitirla para que el programa lo sepa.
     */
    public function extend(int $id, int $months): string
    {
        $license = $this->find($id);
        if ($license === null || $months < 1) {
            return '';
        }
        $now = $this->app->clock()->now();
        $base = $license['updates_until'] !== null ? max($now, Clock::parse((string) $license['updates_until'])) : $now;
        $until = $this->app->clock()->sql((new \DateTimeImmutable('@' . $base))->modify('+' . min($months, 240) . ' months')->getTimestamp());
        $this->app->db()->update('gm_licenses', ['updates_until' => $until, 'updated_at' => $this->app->clock()->sql()], ['id' => $id]);

        return $until;
    }

    public function removeDomain(int $licenseId, int $domainId): bool
    {
        return $this->app->db()->delete('gm_license_domains', ['id' => $domainId, 'license_id' => $licenseId]) === 1;
    }

    /**
     * Vuelve a firmar la clave de una licencia firmada, con un NIF (el que tenía mal escrito o el que faltaba). La clave anterior
     * se apunta en la nota por si hace falta. Devuelve ['ok' => bool, 'error' => texto].
     *
     * @return array{ok:bool,error:string}
     */
    public function reissue(int $id, string $nif): array
    {
        $license = $this->find($id);
        if ($license === null || (string) $license['kind'] !== 'signed') {
            return ['ok' => false, 'error' => t('Only signed keys can be reissued.')];
        }
        $nif = Nif::clean($nif);
        $until = $license['updates_until'] !== null ? Clock::parse((string) $license['updates_until']) : null;
        $signed = $this->sign($nif, (int) $license['units'], $until, $this->app->clock()->now());
        if ($signed['key'] === '') {
            return ['ok' => false, 'error' => $signed['note']];
        }
        $previous = (string) $license['license_key'];
        $note = strpos($previous, 'PENDING-') === 0 ? null : t('Replaced key: {key}', ['key' => $previous]);
        $this->app->db()->update('gm_licenses', ['license_key' => $signed['key'], 'holder' => $nif, 'status' => 'active', 'revoked_at' => null, 'note' => $note, 'updated_at' => $this->app->clock()->sql()], ['id' => $id]);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Da de alta una licencia a mano. Con la clave vacía se genera una. Devuelve el id.
     *
     * @param array<string,mixed> $data email, product_name, kind, max_domains, units, holder, updates_until, note
     */
    public function createManual(array $data): int
    {
        $clock = $this->app->clock();
        $kind = isset($data['kind']) && $data['kind'] === 'signed' ? 'signed' : 'domain';
        $key = isset($data['license_key']) ? KeyFormat::normalize((string) $data['license_key']) : '';
        if ($key === '') {
            $key = $kind === 'signed' ? 'PENDING-' . Random::pick(KeyFormat::ALPHABET, 14) : KeyFormat::generate();
        }

        return $this->app->db()->insert('gm_licenses', [
            'license_key' => $key, 'kind' => $kind, 'status' => strpos($key, 'PENDING-') === 0 ? 'pending' : 'active', 'order_id' => null, 'order_item_id' => null, 'unit' => null,
            'customer_id' => null, 'email' => (string) $data['email'], 'product_id' => null, 'product_name' => mb_substr((string) (isset($data['product_name']) ? $data['product_name'] : ''), 0, 191, 'UTF-8'),
            'max_domains' => isset($data['max_domains']) ? max(0, (int) $data['max_domains']) : 1, 'units' => isset($data['units']) ? max(1, (int) $data['units']) : 1,
            'holder' => isset($data['holder']) ? Nif::clean((string) $data['holder']) : '', 'updates_until' => isset($data['updates_until']) && $data['updates_until'] !== '' ? (string) $data['updates_until'] : null,
            'note' => isset($data['note']) && $data['note'] !== '' ? (string) $data['note'] : null, 'created_at' => $clock->sql(), 'updated_at' => $clock->sql(),
        ]);
    }
}

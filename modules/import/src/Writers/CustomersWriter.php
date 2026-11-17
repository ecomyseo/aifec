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
use Aifec\Modules\Import\Redactor;
use Aifec\Modules\Import\WriteContext;

/**
 * Los clientes del origen con sus direcciones y sus consentimientos. Se escribe por la capa de datos: no se llama a
 * Customers::register() ni a nada que avise (ni «cliente registrado», ni correo de bienvenida). Una cuenta con registro propio en el
 * origen entra con el correo ya verificado (en la fecha de su alta): es lo que deja ver en su cuenta los pedidos antiguos, que el origen
 * ya le asociaba. Un invitado no se verifica nunca.
 *
 * Casar: por la pareja guardada y, la primera vez, por el correo. Un cliente que ya existía aquí con ese correo se ENLAZA:
 * se completan sus campos vacíos y se le añaden las direcciones que no tenga; no se le cambia la contraseña, ni el nombre,
 * ni el consentimiento de marketing, ni se le marca como traído de fuera.
 *
 * Contraseñas: el resumen se guarda tal cual en customers.password y Passwords lo reconoce por su forma (WordPress «$P$» y
 * «$wp$», bcrypt, Argon2, md5). Los antiguos de PrestaShop (md5 con la clave de cookies) necesitan esa clave: llega en la
 * opción secreta «cookie_key» de la migración y se guarda UNA vez como el secreto «customers.legacy_cookie_key», que es
 * donde Customers la busca; nunca en la fila del cliente. Un esquema desconocido, o sin resumen, deja al cliente sin
 * contraseña (entra con enlace de acceso o restableciéndola). Ningún resumen sale en un motivo ni en el informe.
 */
final class CustomersWriter extends WriterBase
{
    /** Clave del secreto donde Customers busca la clave de cookies de la tienda antigua. */
    private const COOKIE_SECRET = 'customers.legacy_cookie_key';

    /** @var bool */
    private $cookieKeyChecked = false;

    public function type(): string
    {
        return 'customers';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $this->secrets = Redactor::secretsOf('customers', $record);
        $db = $this->db();
        $id = (string) $record['id'];
        $email = strtolower(trim((string) $record['email']));
        if ($email === '' || strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->result(self::SKIPPED, null, 'The email address is not valid');
        }
        $found = $this->resolve('customers', $record, $ctx, static function (int $localId) use ($db): ?array {
            return $db->find('customers', ['id' => $localId]);
        }, function () use ($db, $email, $id): ?array {
            $row = $db->row('SELECT * FROM {customers} WHERE email = ?', [$email]);

            return $row !== null && !$this->claimed('customers', (int) $row['id'], $id) ? $row : null;
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($action === self::CONFLICT) {
            return $this->conflict((int) $row['id']);
        }
        if ($row === null && $db->value('SELECT id FROM {customers} WHERE email = ?', [$email]) !== null) {
            return $this->result(self::SKIPPED, null, 'Another customer of the source already has this email address');
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }

        $owned = $row === null || (string) $row['source'] === $ctx->source();
        $now = $this->now();
        $fields = $this->fields($record, $ctx);
        if ($row === null) {
            $data = $fields + [
                'email' => $email, 'guest' => !empty($record['guest']) ? 1 : 0, 'active' => !isset($record['active']) || $record['active'] !== false ? 1 : 0,
                'orders_count' => 0, 'total_spent' => 0, 'source' => $this->cut($ctx->source(), 40), 'legacy_id' => $this->cut($id, 60),
                'created_at' => $this->sqlDate(isset($record['created_at']) ? $record['created_at'] : null) ?: $now, 'updated_at' => $now,
            ];
            $hash = $this->password($record, $ctx);
            $data['password'] = $hash;
            $data['email_verified_at'] = empty($record['guest']) ? $data['created_at'] : null;
            $data['group_id'] = $this->group($record, $ctx);
            $data['last_login_at'] = $this->sqlDate(isset($record['last_login_at']) ? $record['last_login_at'] : null);
            $data['marketing'] = !empty($record['marketing']) ? 1 : 0;
            $data['marketing_at'] = !empty($record['marketing']) ? $this->sqlDate(isset($record['marketing_at']) ? $record['marketing_at'] : null) : null;
            $localId = $db->insert('customers', $data);
        } elseif ($owned) {
            $localId = (int) $row['id'];
            $data = $fields + [
                'guest' => !empty($record['guest']) ? 1 : 0, 'active' => !isset($record['active']) || $record['active'] !== false ? 1 : 0,
                'legacy_id' => $this->cut($id, 60), 'group_id' => $this->group($record, $ctx), 'updated_at' => $now,
                'marketing' => !empty($record['marketing']) ? 1 : 0,
                'marketing_at' => !empty($record['marketing']) ? $this->sqlDate(isset($record['marketing_at']) ? $record['marketing_at'] : null) : null,
            ];
            $hash = $this->password($record, $ctx);
            if ($hash !== null) {
                $data['password'] = $hash;
            }
            if (empty($record['guest']) && trim((string) $row['email_verified_at']) === '') {
                $data['email_verified_at'] = trim((string) $row['created_at']) !== '' ? (string) $row['created_at'] : $now;
            }
            $db->update('customers', $data, ['id' => $localId]);
        } else {
            $localId = (int) $row['id'];
            $db->update('customers', $this->onlyEmpty($row, $fields, $record, $ctx, $id) + ['updated_at' => $now], ['id' => $localId]);
        }
        $this->addresses($localId, $record, $ctx, $owned);
        $this->consents($localId, $email, $record, $ctx);
        $ctx->remember('customers', $id, $localId, Neutral::hash($record));

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    public function checksums(WriteContext $ctx): array
    {
        return [
            'customers_count' => $this->mappedCount('customers', 'customers'),
            'addresses_count' => $this->mappedCount('addresses', 'addresses'),
        ];
    }

    // ------------------------------------------------------------------ datos

    /**
     * Los datos de la persona tal como se guardan (sin correo, contraseña ni marcas).
     *
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>
     */
    private function fields(array $record, WriteContext $ctx): array
    {
        $out = [];
        foreach (['first_name' => 80, 'last_name' => 120, 'company' => 160, 'vat_id' => 40, 'phone' => 40] as $field => $max) {
            // La anchura de la columna la pone FittedDb al escribir ($max es la de siempre, solo orienta a quien lee).
            $value = isset($record[$field]) && is_string($record[$field]) ? trim($record[$field]) : '';
            $out[$field] = $value !== '' ? $value : null;
        }
        $notes = isset($record['notes']) && is_string($record['notes']) ? trim($record['notes']) : '';
        $out['notes'] = $notes !== '' ? $notes : null;
        $locale = isset($record['locale']) && is_string($record['locale']) ? $this->shortLang($record['locale']) : '';
        $out['locale'] = in_array($locale, $ctx->languages(), true) ? $locale : $this->app->defaultLang();

        return $out;
    }

    /**
     * De un cliente que ya existía aquí, solo lo que tiene vacío.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>
     */
    private function onlyEmpty(array $row, array $fields, array $record, WriteContext $ctx, string $id): array
    {
        $set = [];
        foreach (['first_name', 'last_name', 'company', 'vat_id', 'phone', 'notes'] as $field) {
            if ($fields[$field] !== null && trim((string) $row[$field]) === '') {
                $set[$field] = $fields[$field];
            }
        }
        if ($row['group_id'] === null) {
            $group = $this->group($record, $ctx);
            if ($group !== null) {
                $set['group_id'] = $group;
            }
        }
        if (trim((string) $row['legacy_id']) === '') {
            $set['legacy_id'] = $this->cut($id, 60);
        }
        $this->noteOnce($ctx, 'linked', 'The customer already existed here: its empty fields were filled in and its addresses added; the password and the name were left as they are');

        return $set;
    }

    /**
     * El grupo de aquí: la equivalencia elegida, la pareja guardada o el de por defecto.
     *
     * @param array<string,mixed> $record
     */
    private function group(array $record, WriteContext $ctx): ?int
    {
        $db = $this->db();
        $source = isset($record['group_id']) && is_scalar($record['group_id']) ? (string) $record['group_id'] : '';
        if ($source !== '') {
            $eq = $ctx->equivalent('customer_group', $source);
            if ($eq !== null && ctype_digit($eq) && $db->value('SELECT id FROM {customer_groups} WHERE id = ?', [(int) $eq]) !== null) {
                return (int) $eq;
            }
            $local = $ctx->localId('customer_groups', $source);
            if ($local !== null && $db->value('SELECT id FROM {customer_groups} WHERE id = ?', [$local]) !== null) {
                return $local;
            }
            $this->noteOnce($ctx, 'group', 'The customer group of the source was not brought: the default group was used');
        }
        $default = $db->value('SELECT id FROM {customer_groups} WHERE is_default = 1');

        return $default !== null ? (int) $default : null;
    }

    // ------------------------------------------------------------------ contraseña

    /**
     * El resumen de la contraseña para guardarlo tal cual, o null (sin contraseña). Nunca se genera una.
     *
     * @param array<string,mixed> $record
     */
    private function password(array $record, WriteContext $ctx): ?string
    {
        if (!empty($record['guest'])) {
            return null;
        }
        $hash = isset($record['password_hash']) && is_string($record['password_hash']) ? trim($record['password_hash']) : '';
        if ($hash === '') {
            return null;
        }
        $scheme = isset($record['password_scheme']) && is_string($record['password_scheme']) ? $record['password_scheme'] : 'unknown';
        $valid = false;
        $md5 = preg_match('/^[0-9a-fA-F]{32}$/', $hash) === 1;
        switch ($scheme) {
            case 'bcrypt':
                $valid = preg_match('/^\$2[abxy]\$\d{2}\$[.\/A-Za-z0-9]{20,}$/', $hash) === 1;
                break;
            case 'argon2':
                $valid = preg_match('/^\$argon2(id|i)\$/', $hash) === 1;
                break;
            case 'wordpress':
                $valid = $md5 || preg_match('/^\$[PH]\$[.\/A-Za-z0-9]{31}$/', $hash) === 1 || preg_match('/^\$wp\$2y\$\d{2}\$[.\/A-Za-z0-9]{20,}$/', $hash) === 1;
                break;
            case 'prestashop-md5':
                $valid = $md5;
                if ($valid) {
                    $this->cookieKey($ctx);
                }
                break;
        }
        if (!$valid) {
            $ctx->unmapped('password', 'Password format that cannot be checked here', 1, 'These customers have to reset their password');

            return null;
        }

        return $hash;
    }

    /**
     * Guarda UNA vez la clave de cookies de la tienda antigua donde Customers la busca. Si ya hay otra, no se pisa.
     */
    private function cookieKey(WriteContext $ctx): void
    {
        if ($this->cookieKeyChecked) {
            return;
        }
        $this->cookieKeyChecked = true;
        $key = $ctx->option('cookie_key', '');
        $key = is_string($key) ? $key : '';
        $current = $this->app->secret(self::COOKIE_SECRET);
        if ($key === '') {
            if ($current === '') {
                $ctx->note('customers', '', 'note', 'The old PrestaShop passwords need the cookie key of the old shop (option cookie_key) and it was not given: those customers have to reset their password');
            }

            return;
        }
        $this->secrets[] = $key;
        if ($current === '') {
            $this->app->setSecret(self::COOKIE_SECRET, $key);
        } elseif ($current !== $key) {
            $ctx->note('customers', '', 'note', 'A different cookie key of an old shop was already set in the customer settings and was left as it is');
        }
    }

    // ------------------------------------------------------------------ direcciones

    /**
     * Las direcciones del origen: casadas por su identificador, sin duplicar las que el cliente ya tiene. Las que no tienen país
     * (o calle) se dejan fuera con una nota.
     *
     * @param array<string,mixed> $record
     */
    private function addresses(int $customerId, array $record, WriteContext $ctx, bool $owned): void
    {
        $db = $this->db();
        $list = isset($record['addresses']) && is_array($record['addresses']) ? $record['addresses'] : [];
        $existing = $db->select('SELECT * FROM {addresses} WHERE customer_id = ?', [$customerId]);
        $hasShipping = false;
        $hasBilling = false;
        foreach ($existing as $row) {
            $hasShipping = $hasShipping || !empty($row['default_shipping']);
            $hasBilling = $hasBilling || !empty($row['default_billing']);
        }
        $now = $this->now();
        foreach ($list as $index => $address) {
            if (!is_array($address)) {
                continue;
            }
            $country = isset($address['country']) && is_string($address['country']) ? strtoupper(trim($address['country'])) : '';
            $line1 = isset($address['line1']) && is_string($address['line1']) ? trim($address['line1']) : '';
            if ($country === '') {
                $this->note($ctx, $record, 'An address has no country and was left out');
                continue;
            }
            if ($line1 === '') {
                $this->note($ctx, $record, 'An address has no street and was left out');
                continue;
            }
            $key = isset($address['id']) && is_string($address['id']) && $address['id'] !== '' ? $address['id'] : 'c' . (string) $record['id'] . '-' . $index;
            $key = strlen($key) <= 64 ? $key : 'h' . sha1($key);
            $cols = [
                'label' => $this->nullable(self::addressLabel(isset($address['label']) ? (string) $address['label'] : ''), 60),
                'first_name' => isset($address['first_name']) && is_string($address['first_name']) ? trim($address['first_name']) : '',
                'last_name' => $this->nullable(isset($address['last_name']) ? $address['last_name'] : '', 120),
                'company' => $this->nullable(isset($address['company']) ? $address['company'] : '', 160),
                'vat_id' => $this->nullable(isset($address['vat_id']) ? $address['vat_id'] : '', 40),
                'phone' => $this->nullable(isset($address['phone']) ? $address['phone'] : '', 40),
                'line1' => $line1,
                'line2' => $this->nullable(isset($address['line2']) ? $address['line2'] : '', 191),
                'postcode' => isset($address['postcode']) && is_string($address['postcode']) ? trim($address['postcode']) : '',
                'city' => isset($address['city']) && is_string($address['city']) ? trim($address['city']) : '',
                'region' => $this->nullable($this->region($country, isset($address['region']) && is_string($address['region']) ? $address['region'] : ''), 16),
                'country' => $country,
            ];
            $hash = Neutral::hash($address);
            $mapped = $ctx->mapped('addresses', $key);
            $row = $mapped !== null ? $db->find('addresses', ['id' => $mapped['local_id']]) : null;
            if ($row !== null) {
                if ($owned && $mapped['source_hash'] !== $hash) {
                    $db->update('addresses', $cols + ['updated_at' => $now], ['id' => (int) $row['id']]);
                }
                $ctx->remember('addresses', $key, (int) $row['id'], $hash);
                continue;
            }
            $same = $this->sameAddress($existing, $cols);
            if ($same !== null) {
                $ctx->remember('addresses', $key, (int) $same['id'], $hash);
                continue;
            }
            $shipping = !empty($address['default_shipping']) && !$hasShipping;
            $billing = !empty($address['default_billing']) && !$hasBilling;
            $localId = $db->insert('addresses', $cols + [
                'customer_id' => $customerId, 'default_shipping' => $shipping ? 1 : 0, 'default_billing' => $billing ? 1 : 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $hasShipping = $hasShipping || $shipping;
            $hasBilling = $hasBilling || $billing;
            $existing[] = $cols + ['id' => $localId];
            $ctx->remember('addresses', $key, $localId, $hash);
        }
    }

    /**
     * ¿Ya tiene el cliente esta dirección? (misma calle, código postal, ciudad y país, sin mirar mayúsculas ni espacios)
     *
     * @param array<int,array<string,mixed>> $existing
     * @param array<string,mixed>            $cols
     *
     * @return array<string,mixed>|null
     */
    private function sameAddress(array $existing, array $cols): ?array
    {
        $norm = static function ($value): string {
            return strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)) ?: '');
        };
        foreach ($existing as $row) {
            if ($norm($row['line1']) === $norm($cols['line1']) && $norm($row['postcode']) === $norm($cols['postcode'])
                && $norm($row['city']) === $norm($cols['city']) && strtoupper((string) $row['country']) === $cols['country']) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function nullable($value, int $max): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text !== '' ? $text : null;
    }

    /**
     * @param array<string,mixed> $record
     */
    private function note(WriteContext $ctx, array $record, string $text): void
    {
        $ctx->note('customers', (string) $record['id'], 'note', $text);
    }

    // ------------------------------------------------------------------ consentimientos

    /**
     * Los consentimientos con su fecha y su contexto de origen. Uno que ya está (mismo correo, tipo, fecha y valor) no se repite.
     *
     * @param array<string,mixed> $record
     */
    private function consents(int $customerId, string $email, array $record, WriteContext $ctx): void
    {
        $db = $this->db();
        $list = isset($record['consents']) && is_array($record['consents']) ? $record['consents'] : [];
        $fallback = $this->sqlDate(isset($record['created_at']) ? $record['created_at'] : null);
        foreach ($list as $consent) {
            if (!is_array($consent) || !isset($consent['kind']) || !is_string($consent['kind'])) {
                continue;
            }
            $at = $this->sqlDate(isset($consent['at']) ? $consent['at'] : null) ?: ($fallback ?: $this->now());
            $granted = !empty($consent['granted']) ? 1 : 0;
            $context = $this->nullable(isset($consent['context']) ? $consent['context'] : '', 60);
            $where = 'email = ? AND kind = ? AND created_at = ? AND granted = ?' . ($context !== null ? ' AND context = ?' : ' AND context IS NULL');
            $params = [$email, $consent['kind'], $at, $granted];
            if ($context !== null) {
                $params[] = $context;
            }
            if ($db->value('SELECT id FROM {consents} WHERE ' . $where, $params) !== null) {
                continue;
            }
            $db->insert('consents', [
                'customer_id' => $customerId, 'email' => $email, 'kind' => $consent['kind'], 'granted' => $granted, 'version' => null,
                'context' => $context, 'ip' => null, 'created_at' => $at,
            ]);
        }
    }

    /**
     * El nombre de una dirección tal como lo verá el cliente en su cuenta. WooCommerce no les pone nombre y su lector las
     * llama «Billing» y «Shipping»: aquí, que ya hay tienda delante, pasan a su idioma. Cualquier otro nombre (el alias que
     * el cliente escribió en PrestaShop) se respeta.
     */
    private static function addressLabel(string $label): string
    {
        $label = trim($label);
        if ($label === 'Billing') {
            return t('Billing address');
        }
        if ($label === 'Shipping') {
            return t('Shipping address');
        }

        return $label;
    }
}

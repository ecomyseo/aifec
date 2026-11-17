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

/**
 * Las claves en el correo.
 *
 * Van DENTRO del correo de confirmación del pedido, como un bloque (filtro «order.mail.blocks»): al componerlo se emiten las licencias
 * (emitir es idempotente) y se escriben una por línea. Entonces no sale correo aparte. El correo propio («license_issued») queda de
 * recambio, para cuando las claves no caben en el bloque (más líneas de las que admite el correo del pedido): las licencias recién
 * emitidas que el bloque no llevó se mandan cuando llega el aviso de pago. Un correo que no sale (sin servicio de correo, modo
 * silencioso) no tumba nada: las claves siguen en la página del pedido y en la zona del cliente.
 */
final class Mails
{
    /** Líneas que caben en un bloque del correo del pedido (el límite de OrderMails::MAX_LINES). */
    private const BLOCK_LINES = 12;

    /** @var array<int,array<int,array<string,mixed>>> pedido => licencias emitidas al componer el correo que el bloque no pudo llevar */
    private static $pending = [];

    /**
     * El filtro «order.mail.blocks»: las licencias del pedido en el correo de confirmación.
     *
     * @param mixed $blocks   los bloques que ya hay
     * @param mixed $order    el pedido completo
     * @param mixed $template el nombre de la plantilla
     *
     * @return mixed
     */
    public static function blocks($blocks, $order, $template, App $app)
    {
        if (!is_array($blocks) || $template !== 'order_confirmed' || !is_array($order) || !isset($order['id']) || $app->silenced()) {
            return $blocks;
        }
        // Solo con el pedido pagado: es cuando se emiten las licencias.
        if (!isset($order['payment_status']) || $order['payment_status'] !== 'paid') {
            return $blocks;
        }
        $orderId = (int) $order['id'];
        $service = $app->service('gm_licenses');
        $created = $service->issueForOrder($order);
        $rows = array_values(array_filter($service->forOrder($orderId), static function (array $row): bool {
            return in_array((string) $row['status'], ['active', 'pending'], true);
        }));
        if ($rows === []) {
            return $blocks;
        }
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = (string) $row['product_name'] . ': ' . self::describe($row);
        }
        $account = $app->router()->has('gm.licenses.account') ? $app->url()->origin() . $app->url()->route('gm.licenses.account') : '';
        if ($account !== '') {
            $lines[] = t('See my licences') . ': ' . $account;
        }
        if (count($lines) > self::BLOCK_LINES) {
            // No caben: se apuntan las nuevas para el correo propio y el correo del pedido sale sin ellas.
            if ($created !== []) {
                self::$pending[$orderId] = array_merge(isset(self::$pending[$orderId]) ? self::$pending[$orderId] : [], $created);
            }

            return $blocks;
        }
        $blocks[] = ['title' => t('Your licences'), 'lines' => $lines];

        return $blocks;
    }

    /**
     * Las licencias que el bloque del correo del pedido no pudo llevar (y se emitieron al componerlo): se mandan aparte, una vez.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function takePending(int $orderId): array
    {
        $rows = isset(self::$pending[$orderId]) ? self::$pending[$orderId] : [];
        unset(self::$pending[$orderId]);

        return $rows;
    }

    /**
     * Una licencia en una línea: su clave y para qué vale (o que aún se está preparando).
     *
     * @param array<string,mixed> $row fila de gm_licenses
     */
    private static function describe(array $row): string
    {
        if ((string) $row['status'] !== 'active') {
            return t('We are preparing this key. We will email you when it is ready.');
        }
        $detail = (string) $row['kind'] === 'domain'
            ? ((int) $row['max_domains'] > 0 ? tn('Valid for {n} domain.', 'Valid for {n} domains.', (int) $row['max_domains']) : t('Valid for any number of domains.'))
            : t('It works without internet: paste it once in the program.');
        if ($row['updates_until'] !== null && (string) $row['updates_until'] !== '') {
            $detail .= ' ' . t('Updates included until {date}.', ['date' => substr((string) $row['updates_until'], 0, 10)]);
        }

        return (string) $row['license_key'] . ' (' . $detail . ')';
    }

    /**
     * El correo propio con las claves, de recambio.
     *
     * @param array<string,mixed>              $order
     * @param array<int,array<string,mixed>>   $licenses las licencias recién emitidas
     */
    public static function issued(App $app, array $order, array $licenses): int
    {
        if ($licenses === [] || !$app->has('mailer')) {
            return 0;
        }
        try {
            $billing = isset($order['billing']) && is_array($order['billing']) ? $order['billing'] : [];
            $name = trim((isset($billing['first_name']) ? (string) $billing['first_name'] : '') . ' ' . (isset($billing['last_name']) ? (string) $billing['last_name'] : ''));
            $rows = [];
            foreach ($licenses as $license) {
                $rows[] = [
                    'product' => (string) $license['product_name'], 'key' => (string) $license['license_key'], 'kind' => (string) $license['kind'],
                    'pending' => (string) $license['status'] !== 'active', 'domains' => (int) $license['max_domains'], 'units' => (int) $license['units'],
                    'updates_until' => $license['updates_until'] !== null ? substr((string) $license['updates_until'], 0, 10) : '',
                ];
            }

            return (int) $app->service('mailer')->queue('license_issued', (string) $order['email'], [
                'number' => (string) $order['number'], 'name' => $name !== '' ? $name : (string) $order['email'], 'licenses' => $rows,
                'account_url' => $app->router()->has('gm.licenses.account') ? $app->url()->origin() . $app->url()->route('gm.licenses.account') : '',
            ], ['locale' => (string) $order['lang'], 'to_name' => $name, 'related' => 'order:' . (int) $order['id']]);
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'gm-licencias.mail');

            return 0;
        }
    }
}

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Returns;

use Aifec\Kernel\App;

/**
 * La herramienta «return_options» del asistente (se engancha con el filtro «assistant.tools», sin tocar ese módulo): qué se puede
 * devolver de un pedido y hasta cuándo, con el enlace al formulario.
 *
 * Como «order_status», pide el número del pedido y el correo del pedido; un número que no existe y un correo que no coincide dan la
 * MISMA respuesta, así no se confirma que un pedido exista. El enlace lleva solo el número del pedido (nunca su ficha secreta): el
 * formulario pide el número y el correo.
 */
final class ReturnTools
{
    /**
     * @param array<string,mixed> $tools las herramientas que ya hay
     *
     * @return array<string,mixed>
     */
    public static function add(App $app, array $tools): array
    {
        $tools['return_options'] = [
            'description' => 'What can be returned from an order and until when (returns and the right of withdrawal), with the link to the return form. Needs the order number and the email used in the order; if they do not match an order nothing is revealed.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'number' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 32],
                    'email' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 191],
                ],
                'required' => ['number', 'email'],
                'additionalProperties' => false,
            ],
            'handler' => static function (array $in) use ($app): array {
                return self::options($app, $in);
            },
            'writes' => false,
        ];

        return $tools;
    }

    /**
     * @param array<string,mixed> $in number y email
     *
     * @return array<string,mixed>
     */
    public static function options(App $app, array $in): array
    {
        $missing = ['error' => t('I could not find an order with those details')];
        if (!$app->has('orders') || !$app->has('returns')) {
            return $missing;
        }
        $order = $app->service('orders')->findByNumber(isset($in['number']) ? (string) $in['number'] : '');
        $email = strtolower(trim(isset($in['email']) ? (string) $in['email'] : ''));
        if ($order === null || $email === '' || !hash_equals(strtolower(trim((string) $order['email'])), $email)) {
            return $missing;
        }
        $eligibility = $app->service('returns')->eligibility((int) $order['id']);
        $out = [
            'number' => (string) $order['number'],
            'can_return' => (bool) $eligibility['ok'],
            'link' => $app->url()->origin() . $app->url()->route('returns.withdrawal') . '?number=' . rawurlencode((string) $order['number']),
        ];
        if (!$eligibility['ok']) {
            $out['reason'] = (string) $eligibility['message'];
            if ($eligibility['open_return'] !== null) {
                $out['open_return'] = (string) $eligibility['open_return']['number'];
            }
        }
        if ($eligibility['return_until'] !== null) {
            $out['return_until'] = substr((string) $eligibility['return_until'], 0, 10);
            $out['return_open'] = (bool) $eligibility['return_open'];
        }
        if ($eligibility['withdrawal_until'] !== null) {
            $out['withdrawal_until'] = substr((string) $eligibility['withdrawal_until'], 0, 10);
            $out['withdrawal_open'] = (bool) $eligibility['withdrawal_open'];
        }
        $lines = [];
        foreach ($eligibility['lines'] as $line) {
            $lines[] = [
                'name' => (string) $line['name'], 'available' => (int) $line['available'],
                'withdrawal' => (bool) $line['withdrawal_ok'],
            ];
        }
        $out['lines'] = $lines;

        return $out;
    }
}

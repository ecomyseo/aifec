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

use Aifec\Kernel\App;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\WriteContext;

/**
 * Las formas de pago del origen, casadas con las de aquí (transferencia, contra reembolso, Redsys y PayPal).
 *
 * Se copia lo que casa (título, titular, banco, recargo, código de comercio…), pero la forma de pago queda APAGADA y en PRUEBAS pase lo
 * que pase en el origen. Los módulos de pago no tienen un interruptor: se ofrecen cuando su configuración está completa. Por eso la pieza
 * que las enciende (el IBAN de la transferencia, los países del contra reembolso, la clave de Redsys o de PayPal) NO se escribe en su
 * ajuste: se guarda aparte («import.payment.<forma>.…», las claves cifradas con App::setSecret()) y activate() la pone donde va cuando el
 * comerciante lo decide. El entorno de Redsys y de PayPal queda en pruebas. Ni una clave sale en una nota, un motivo o el registro.
 */
final class PaymentMethodsWriter extends WriterBase
{
    /** Nombres con los que los orígenes llaman a cada forma de pago de aquí. */
    private const ALIASES = [
        'transfer' => ['transfer', 'bacs', 'bank_transfer', 'banktransfer', 'bankwire', 'ps_wirepayment', 'wire', 'transferencia'],
        'cod' => ['cod', 'cash_on_delivery', 'ps_cashondelivery', 'contrareembolso'],
        'paypal' => ['paypal', 'ppcp-gateway', 'ppcp', 'ps_checkout'],
    ];

    public function type(): string
    {
        return 'payment_methods';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $id = (string) $record['id'];
        $target = $this->target($record, $ctx);
        if ($target === null) {
            $ctx->unmapped('payment_method', (string) $record['code'], 1, 'This payment method has no equivalent here');

            return $this->result(self::SKIPPED, null, 'There is no payment method here equivalent to this one');
        }
        $mapped = $ctx->mapped('payment_methods', $id);
        $action = $this->decide('payment_methods', $record, $ctx, $mapped !== null ? ['id' => $mapped['local_id']] : null);
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, 1);
        }
        if ($ctx->dryRun()) {
            return $this->result($action, 1);
        }
        $settings = isset($record['settings']) && is_array($record['settings']) ? $record['settings'] : [];
        foreach ($settings as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $this->secrets[] = (string) $value;
            }
        }
        $title = $this->cut($this->mainText(isset($record['title']) && is_array($record['title']) ? $record['title'] : [], $ctx), 120);
        if ($title !== '') {
            $this->app->settings()->set('payments.' . $target . '.title', $title);
        }
        $lower = [];
        foreach ($settings as $key => $value) {
            if (is_scalar($value)) {
                $lower[strtolower((string) $key)] = trim((string) $value);
            }
        }
        $sandbox = isset($record['sandbox']) ? $record['sandbox'] === true : false;
        if ($target === 'transfer') {
            $this->transfer($lower);
        } elseif ($target === 'cod') {
            $this->cod($lower, $record);
        } elseif ($target === 'redsys') {
            $this->redsys($lower, $sandbox);
        } else {
            $this->paypal($lower, $sandbox);
        }
        if ($target !== 'cod' && (isset($record['min_amount']) || isset($record['max_amount']))) {
            $ctx->unmapped('review', 'Amount limits of «' . $target . '»', 1, 'This payment method has no limits by amount here');
        }
        $ctx->unmapped('review', 'Payment method «' . $target . '»', 1, 'Brought switched off and in test mode: set it up before you sell');
        $ctx->remember('payment_methods', $id, 1, Neutral::hash($record));

        return $this->result($mapped === null ? self::CREATED : $action, 1);
    }

    /**
     * Pone en su sitio lo que la migración dejó aparte, y la forma de pago queda lista para ofrecerse (en pruebas). Lo llama quien
     * decide encenderla (una pantalla o una orden de consola), nunca la migración. Devuelve false si no había nada guardado.
     */
    public static function activate(App $app, string $code): bool
    {
        $settings = $app->settings();
        $key = 'import.payment.' . $code . '.';
        $live = $settings->str($key . 'env') === 'live';
        if ($code === 'transfer') {
            $iban = $app->secret($key . 'iban');
            if ($iban === '') {
                return false;
            }
            $settings->set('payments.transfer.iban', $iban);
            $app->setSecret($key . 'iban', '');

            return true;
        }
        if ($code === 'cod') {
            if (!$settings->has($key . 'countries')) {
                return false;
            }
            $settings->set('payments.cod.countries', $settings->arr($key . 'countries'));
            $settings->delete($key . 'countries');

            return true;
        }
        if ($code === 'redsys' || $code === 'paypal') {
            $secret = $app->secret($key . 'secret');
            if ($secret === '') {
                return false;
            }
            $target = $code === 'redsys' ? ($live ? 'payments.redsys.secret_live' : 'payments.redsys.secret_test') : ($live ? 'payments.paypal.secret_live' : 'payments.paypal.secret_sandbox');
            $app->setSecret($target, $secret);
            $app->setSecret($key . 'secret', '');

            return true;
        }

        return false;
    }

    // ------------------------------------------------------------------ piezas

    /**
     * La forma de pago de aquí que corresponde a la del origen: la equivalencia que eligió el comerciante o la que se deduce del código.
     *
     * @param array<string,mixed> $record
     */
    private function target(array $record, WriteContext $ctx): ?string
    {
        $known = ['transfer', 'cod', 'redsys', 'paypal'];
        $chosen = $ctx->equivalent('payment_method', (string) $record['id']);
        if ($chosen !== null) {
            $chosen = strtolower($chosen);
            if (in_array($chosen, $known, true)) {
                return $chosen;
            }
        }
        $code = strtolower(trim((string) $record['code']));
        if (strpos($code, 'redsys') !== false) {
            return 'redsys';
        }
        foreach (self::ALIASES as $target => $names) {
            if (in_array($code, $names, true)) {
                return $target;
            }
        }

        return null;
    }

    /**
     * @param array<string,string> $s
     * @param string[]             $names
     */
    private function pick(array $s, array $names): string
    {
        foreach ($names as $name) {
            if (isset($s[$name]) && $s[$name] !== '') {
                return $s[$name];
            }
        }

        return '';
    }

    /**
     * @param array<string,string> $s
     */
    private function transfer(array $s): void
    {
        $settings = $this->app->settings();
        $copy = ['holder' => ['holder', 'account_name', 'account_holder', 'owner', 'bank_wire_owner'], 'bank' => ['bank', 'bank_name'],
            'note' => ['note', 'instructions', 'bank_wire_details'], 'description' => ['description']];
        foreach ($copy as $field => $names) {
            $value = $this->pick($s, $names);
            if ($value !== '') {
                $settings->set('payments.transfer.' . $field, $this->cut($value, $field === 'note' ? 1000 : 255));
            }
        }
        $days = $this->pick($s, ['days']);
        if ($days !== '' && ctype_digit($days)) {
            $settings->set('payments.transfer.days', (int) $days);
        }
        $iban = $this->pick($s, ['iban', 'account_iban']);
        if ($iban !== '') {
            $this->app->setSecret('import.payment.transfer.iban', $iban);
        }
        // Mientras no se active, no hay IBAN en su ajuste y la transferencia no se ofrece (el que el comerciante ya tuviera no se toca).
        if (!$settings->has('payments.transfer.iban')) {
            $settings->set('payments.transfer.iban', '');
        }
    }

    /**
     * @param array<string,string> $s
     * @param array<string,mixed>  $record
     */
    private function cod(array $s, array $record): void
    {
        $settings = $this->app->settings();
        $numbers = ['fee' => ['fee', 'fixed_fee', 'extra_fee'], 'percent' => ['percent', 'fee_percent'], 'min_fee' => ['min_fee']];
        foreach ($numbers as $field => $names) {
            $value = $this->pick($s, $names);
            if ($value !== '' && ctype_digit($value)) {
                $settings->set('payments.cod.' . $field, (int) $value);
            }
        }
        if (isset($record['max_amount']) && is_int($record['max_amount'])) {
            $settings->set('payments.cod.max_total', max(0, $record['max_amount']));
        }
        $countries = [];
        foreach (isset($record['countries']) && is_array($record['countries']) ? $record['countries'] : [] as $country) {
            if (is_string($country) && preg_match('/^[A-Za-z]{2}$/D', $country) === 1) {
                $countries[] = strtoupper($country);
            }
        }
        $settings->set('import.payment.cod.countries', $countries !== [] ? array_values(array_unique($countries)) : ['ES']);
        // Sin países no se ofrece en ninguna parte (los que el comerciante ya tuviera no se tocan).
        if (!$settings->has('payments.cod.countries')) {
            $settings->set('payments.cod.countries', []);
        }
    }

    /**
     * @param array<string,string> $s
     */
    private function redsys(array $s, bool $sandbox): void
    {
        $settings = $this->app->settings();
        $code = $this->pick($s, ['merchant_code', 'merchant', 'fuc', 'commerce_code']);
        if ($code !== '') {
            $settings->set('payments.redsys.merchant_code', $this->cut($code, 15));
        }
        $terminal = $this->pick($s, ['terminal']);
        if ($terminal !== '') {
            $settings->set('payments.redsys.terminal', $this->cut($terminal, 3));
        }
        if (!$settings->has('payments.redsys.environment')) {
            $settings->set('payments.redsys.environment', 'test');
        }
        $secret = $this->pick($s, ['secret_key', 'secret', 'key', 'signature_key']);
        if ($secret !== '') {
            $this->app->setSecret('import.payment.redsys.secret', $secret);
            $settings->set('import.payment.redsys.env', $sandbox ? 'test' : 'live');
        }
    }

    /**
     * @param array<string,string> $s
     */
    private function paypal(array $s, bool $sandbox): void
    {
        $settings = $this->app->settings();
        $client = $this->pick($s, ['client_id', 'clientid']);
        if ($client !== '') {
            $settings->set($sandbox ? 'payments.paypal.client_id_sandbox' : 'payments.paypal.client_id_live', $this->cut($client, 255));
        }
        if (!$settings->has('payments.paypal.environment')) {
            $settings->set('payments.paypal.environment', 'sandbox');
        }
        $secret = $this->pick($s, ['secret', 'client_secret', 'secret_key']);
        if ($secret !== '') {
            $this->app->setSecret('import.payment.paypal.secret', $secret);
            $settings->set('import.payment.paypal.env', $sandbox ? 'test' : 'live');
        }
    }
}

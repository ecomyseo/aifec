<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\PaymentsPaypal;

use Aifec\Commerce\PaymentAction;
use Aifec\Commerce\PaymentMethod;
use Aifec\Kernel\App;
use Aifec\Payments\PayPal\PayPalConfig;
use Aifec\Payments\PayPal\PayPalException;
use Aifec\Payments\PayPal\PayPalFundingSources;

/**
 * Cobro por PayPal: una instancia por forma de pago (la cartera, la tarjeta en la página de PayPal, iDEAL, BLIK…), cada una
 * con su código («paypal», «paypal_card», «paypal_ideal»…) y todas cobrando por la misma cuenta.
 *
 * start() no cobra nada: crea la orden en PayPal con su payment_source, apunta el intento y lleva al cliente a aprobar el pago
 * en la página que dice PayPal (la suya o la del banco). La cartera y la tarjeta se capturan en el servidor cuando el cliente
 * vuelve (PayPalController::back); las formas locales las cobra PayPal al aprobarlas, y la vuelta solo consulta la orden. En
 * todas, el aviso firmado de PayPal (PayPalController::notify) también da el pedido por pagado, por si el cliente no vuelve o
 * el banco confirma tarde.
 */
final class PayPalPayment implements PaymentMethod
{
    public const CODE = 'paypal';

    /** Tipo de fila de order_payments con el que se apunta un intento de pago que aún no está resuelto. */
    public const ATTEMPT = 'attempt';

    /** @var App */
    private $app;

    /** @var PayPalSettings */
    private $settings;

    /** @var string la forma de pago del catálogo (paypal, card, ideal…) */
    private $source;

    public function __construct(App $app, string $source = PayPalSources::WALLET)
    {
        $source = strtolower(trim($source));
        if (!PayPalFundingSources::isOfferable($source)) {
            throw new \InvalidArgumentException('PayPal cannot offer this funding source: ' . $source);
        }
        $this->app = $app;
        $this->settings = new PayPalSettings($app);
        $this->source = $source;
    }

    public function code(): string
    {
        return PayPalSources::code($this->source);
    }

    /** La forma de pago del catálogo (paypal, card, ideal…). */
    public function source(): string
    {
        return $this->source;
    }

    public function info(): array
    {
        $custom = $this->source === PayPalSources::WALLET ? trim($this->app->settings()->str(PayPalSettings::TITLE)) : '';

        return [
            'title' => $custom !== '' ? $custom : PayPalSources::title($this->source),
            'description' => PayPalSources::description($this->source),
            'icon' => '',
        ];
    }

    /**
     * Se ofrece con la cuenta configurada, la forma encendida, y el país del comprador, la moneda y el importe dentro de lo que
     * admite. El país es el de facturación si la caja lo da («billing_country») y, si no, el del destino.
     */
    /** ¿Está en el entorno de pruebas de PayPal (sandbox), donde no se cobra nada? Una demostración solo lo ofrece así. */
    public function sandboxOnly(): bool
    {
        return $this->settings->isTest();
    }

    public function available(array $context): bool
    {
        if (!$this->settings->complete() || !$this->settings->enabled($this->source)) {
            return false;
        }
        // Apple Pay solo con el dominio acreditado: sin el fichero, Apple no deja abrir la hoja de pago.
        if ($this->source === 'apple_pay' && $this->settings->applePayFile() === '') {
            return false;
        }
        $country = isset($context['billing_country']) && is_string($context['billing_country']) && trim($context['billing_country']) !== ''
            ? $context['billing_country']
            : (isset($context['country']) && is_string($context['country']) ? $context['country'] : '');
        $currency = isset($context['currency']) && is_string($context['currency']) ? $context['currency'] : '';
        $total = isset($context['total']) && is_int($context['total']) ? $context['total'] : null;

        return PayPalFundingSources::eligible($this->source, $country, $currency, $total);
    }

    public function fee(array $context): int
    {
        return 0;
    }

    public function isTest(): bool
    {
        return $this->settings->isTest();
    }

    public function start(array $order): PaymentAction
    {
        $orderId = isset($order['id']) ? (int) $order['id'] : 0;
        $number = isset($order['number']) ? (string) $order['number'] : '';
        // La ficha del pedido en claro solo la lleva el pedido recién creado: sin ella no hay a dónde volver.
        $secret = isset($order['secret']) && is_string($order['secret']) ? $order['secret'] : '';
        if ($orderId < 1 || $number === '' || $secret === '') {
            throw new \RuntimeException('PayPal needs the order id, its number and its secret');
        }
        $currency = strtoupper(isset($order['currency']) ? (string) $order['currency'] : '');
        $total = isset($order['total']) ? (int) $order['total'] : 0;
        $payer = self::payer($order);

        // La caja decide con el país del destino; PayPal exige el del titular (la factura). Si no vale, ni se le pregunta.
        if (!$this->fits($order)) {
            $this->app->log()->warning('A PayPal payment method does not fit the buyer of an order', ['order' => $number, 'method' => $this->code(), 'country' => $payer['country'], 'currency' => $currency]);
            $this->note($orderId, t('{method} is not offered to a buyer from {country} paying in {currency}: the order was not sent to PayPal.', [
                'method' => PayPalSources::name($this->source), 'country' => $payer['country'] !== '' ? $payer['country'] : '?', 'currency' => $currency,
            ]));

            return PaymentAction::failed($this->failure());
        }

        $url = $this->app->url();
        $params = ['number' => $number, 'secret' => $secret];
        if (PayPalSources::usesSdk($this->source)) {
            // Las del guion se pagan en la página de pago del módulo: allí el guion de PayPal aprueba y el servidor crea la
            // orden y la captura. Aquí todavía no se habla con PayPal.
            return PaymentAction::redirect($url->origin() . $url->route('payment.paypal.pay', $params));
        }

        $client = $this->settings->client();
        $input = $this->orderInput($order, $secret);

        try {
            $created = $client->createOrder($input, $this->app->clock()->now());
        } catch (PayPalException $e) {
            // El mensaje de PayPalException no lleva secretos ni la ficha: se puede apuntar.
            $this->app->log()->warning('PayPal refused to create an order', ['order' => $number, 'method' => $this->code(), 'status' => $e->status(), 'debug_id' => $e->debugId(), 'error' => $e->getMessage()]);
            if (!$e->isTransient() && $e->status() > 0) {
                $this->note($orderId, PayPalSources::explain($this->source, $e->issue()));
            }

            return PaymentAction::failed($this->failure());
        } catch (\InvalidArgumentException $e) {
            $this->app->log()->warning('An order cannot be sent to PayPal', ['order' => $number, 'method' => $this->code(), 'error' => $e->getMessage()]);

            return PaymentAction::failed($this->failure());
        }
        if ($created['approveUrl'] === '') {
            $this->app->log()->warning('PayPal answered without an approval link', ['order' => $number, 'method' => $this->code()]);

            return PaymentAction::failed($this->failure());
        }

        // El intento lleva el código de esta forma de pago: la vuelta y el aviso saben así cómo se cobra.
        $this->app->db()->insertIgnore('order_payments', [
            'order_id' => $orderId, 'method' => $this->code(), 'kind' => self::ATTEMPT, 'reference' => $created['id'],
            'amount' => $total, 'currency' => $currency, 'status' => 'pending',
            'test' => $this->settings->isTest() ? 1 : 0, 'raw' => null, 'created_at' => $this->app->clock()->sql(),
        ]);

        return PaymentAction::redirect($created['approveUrl']);
    }

    /** ¿Puede pagar este pedido con esta forma? El país del titular (la factura), la moneda y el importe del pedido. */
    public function fits(array $order): bool
    {
        $currency = strtoupper(isset($order['currency']) ? (string) $order['currency'] : '');
        $total = isset($order['total']) ? (int) $order['total'] : 0;

        return PayPalFundingSources::eligible($this->source, self::payer($order)['country'], $currency, $total);
    }

    /**
     * Lo que se manda a PayPalClient::createOrder para un pedido: SIEMPRE el importe y la moneda del pedido guardado, el número
     * como referencia, las direcciones de vuelta y de cancelación, la forma de pago, el titular y la dirección de envío.
     *
     * @param array<string,mixed> $order
     *
     * @return array<string,mixed>
     */
    public function orderInput(array $order, string $secret): array
    {
        $number = isset($order['number']) ? (string) $order['number'] : '';
        $url = $this->app->url();
        $params = ['number' => $number, 'secret' => $secret];
        $input = [
            'reference' => $number,
            'amount' => isset($order['total']) ? (int) $order['total'] : 0,
            'currency' => strtoupper(isset($order['currency']) ? (string) $order['currency'] : ''),
            'description' => t('Order {number}', ['number' => $number]),
            'returnUrl' => $url->origin() . $url->route('payment.paypal.back', $params),
            'cancelUrl' => $url->origin() . $url->route('payment.paypal.cancelled', $params),
            'source' => $this->source,
            'payer' => self::payer($order),
        ];
        if (isset($order['fulfillment_status']) && (string) $order['fulfillment_status'] === 'not_needed') {
            $input['noShipping'] = true;
        } else {
            $address = self::shippingAddress($order);
            if ($address !== []) {
                $input['shippingAddress'] = $address;
            }
        }

        return $input;
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function refund(array $order, int $amount, string $reason): string
    {
        if ($amount < 1) {
            throw new \RuntimeException('The refund amount must be greater than zero');
        }
        $captureId = self::chargeReference($order, $this->code());
        // El entorno es el del cobro, no el que esté elegido ahora: un pedido de pruebas se devuelve contra el sandbox
        // aunque la tienda ya esté en producción, y al revés.
        $environment = !empty($order['test']) ? PayPalConfig::ENV_SANDBOX : PayPalConfig::ENV_LIVE;
        $client = $this->settings->client($environment);
        $paid = isset($order['paid_total']) ? (int) $order['paid_total'] : 0;
        $currency = isset($order['currency']) ? (string) $order['currency'] : '';

        try {
            // Devolver todo lo cobrado se pide sin importe (devolución total); lo demás, con su importe.
            $done = $client->refund($captureId, $amount === $paid ? null : $amount, $currency, $reason, $this->app->clock()->now());
        } catch (PayPalException $e) {
            $this->app->log()->warning('PayPal refused a refund', ['order' => isset($order['number']) ? (string) $order['number'] : '', 'status' => $e->status(), 'debug_id' => $e->debugId(), 'error' => $e->getMessage()]);

            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        if ($done['id'] === '') {
            throw new \RuntimeException('PayPal did not give an identifier for the refund');
        }
        if (in_array(strtoupper($done['status']), ['FAILED', 'CANCELLED'], true)) {
            throw new \RuntimeException('PayPal did not complete the refund (' . $done['status'] . ')');
        }

        return $done['id'];
    }

    /** El mensaje que ve el cliente cuando PayPal no deja empezar el pago (el detalle, al registro y al historial). */
    private function failure(): string
    {
        if ($this->source === PayPalSources::WALLET) {
            return t('PayPal could not start the payment. Try again or choose another payment method.');
        }

        return t('{method} cannot be used for this payment. Choose another payment method.', ['method' => PayPalSources::name($this->source)]);
    }

    /** Una nota en el historial del pedido, solo para quien lleva la tienda. */
    private function note(int $orderId, string $note): void
    {
        try {
            $this->app->service('orders')->addNote($orderId, $note, false);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'paypal.note');
        }
    }

    /**
     * El titular del pago, como lo piden las formas locales: nombre (o empresa), país de facturación y correo del pedido.
     *
     * @param array<string,mixed> $order
     *
     * @return array{name:string,country:string,email:string}
     */
    private static function payer(array $order): array
    {
        $billing = isset($order['billing']) && is_array($order['billing']) ? $order['billing'] : [];
        $name = trim(self::text($billing, 'first_name') . ' ' . self::text($billing, 'last_name'));
        if ($name === '') {
            $name = self::text($billing, 'company');
        }
        $country = strtoupper(self::text($billing, 'country'));
        if ($country === '') {
            $shipping = isset($order['shipping']) && is_array($order['shipping']) ? $order['shipping'] : [];
            $country = strtoupper(self::text($shipping, 'country'));
        }

        return ['name' => $name, 'country' => $country, 'email' => self::text($order, 'email')];
    }

    /**
     * La dirección de envío del pedido (o, si no hay, la de facturación) como la entiende el cliente de PayPal. Vacía si
     * falta la calle o el país.
     *
     * @param array<string,mixed> $order
     *
     * @return array<string,string>
     */
    private static function shippingAddress(array $order): array
    {
        foreach (['shipping', 'billing'] as $key) {
            $a = isset($order[$key]) && is_array($order[$key]) ? $order[$key] : [];
            $line1 = self::text($a, 'line1');
            $country = self::text($a, 'country');
            if ($line1 === '' || $country === '') {
                continue;
            }

            return [
                'name' => trim(self::text($a, 'first_name') . ' ' . self::text($a, 'last_name')),
                'line1' => $line1,
                'line2' => self::text($a, 'line2'),
                'city' => self::text($a, 'city'),
                // La provincia, con su nombre («Valencia»), no con el código con que se guarda («46»).
                'state' => \Aifec\Geo\Address::regionName($country, self::text($a, 'region')),
                'postalCode' => self::text($a, 'postcode'),
                'country' => $country,
            ];
        }

        return [];
    }

    /**
     * @param array<string,mixed> $a
     */
    private static function text(array $a, string $key): string
    {
        return isset($a[$key]) && is_scalar($a[$key]) ? trim((string) $a[$key]) : '';
    }

    /**
     * El identificador de la captura que se devuelve: la referencia del último cobro bueno con esta forma de pago.
     *
     * @param array<string,mixed> $order
     */
    private static function chargeReference(array $order, string $code): string
    {
        $found = '';
        foreach (isset($order['payments']) && is_array($order['payments']) ? $order['payments'] : [] as $payment) {
            if (
                is_array($payment) && isset($payment['kind'], $payment['status'], $payment['method'], $payment['reference'])
                && (string) $payment['kind'] === 'charge' && (string) $payment['status'] === 'ok' && (string) $payment['method'] === $code
            ) {
                $found = (string) $payment['reference'];
            }
        }
        if ($found === '') {
            throw new \RuntimeException('The order has no payment made through PayPal to refund');
        }

        return $found;
    }
}

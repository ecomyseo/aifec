<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\PaymentsRedsys;

use Aifec\Commerce\PaymentAction;
use Aifec\Commerce\PaymentMethod;
use Aifec\Kernel\App;
use Aifec\Payments\Redsys\RedsysRequest;

/**
 * Cobro con tarjeta o con Bizum por Redsys. Una misma clase con dos códigos: «redsys» (tarjeta) y «bizum».
 *
 * start() no cobra nada: apunta el intento y devuelve el formulario firmado que el navegador del cliente envía al banco.
 * El pedido se da por pagado solo cuando el banco avisa con su firma (RedsysController::notify); lo que vuelve el
 * navegador del cliente no cuenta como pago.
 */
final class RedsysPayment implements PaymentMethod
{
    public const CODE_CARD = 'redsys';
    public const CODE_BIZUM = 'bizum';

    /** Tipo de fila de order_payments con el que se apunta un intento de pago que aún no tiene respuesta. */
    public const ATTEMPT = 'attempt';

    /** @var App */
    private $app;

    /** @var string */
    private $code;

    /** @var RedsysSettings */
    private $settings;

    public function __construct(App $app, string $code = self::CODE_CARD)
    {
        if ($code !== self::CODE_CARD && $code !== self::CODE_BIZUM) {
            throw new \InvalidArgumentException('Unknown Redsys payment code: ' . $code);
        }
        $this->app = $app;
        $this->code = $code;
        $this->settings = new RedsysSettings($app);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function info(): array
    {
        if ($this->code === self::CODE_BIZUM) {
            $custom = trim($this->app->settings()->str(RedsysSettings::BIZUM_TITLE));

            return [
                'title' => $custom !== '' ? $custom : t('Bizum'),
                'description' => t('Pay with Bizum on the secure page of the bank.'),
                'icon' => '',
            ];
        }
        $custom = trim($this->app->settings()->str(RedsysSettings::TITLE));

        return [
            'title' => $custom !== '' ? $custom : t('Card payment'),
            'description' => t('Pay with your card on the secure page of the bank.'),
            'icon' => '',
        ];
    }

    public function available(array $context): bool
    {
        if ($this->code === self::CODE_BIZUM && !$this->settings->bizumEnabled()) {
            return false;
        }
        $currency = isset($context['currency']) && is_string($context['currency']) ? strtoupper($context['currency']) : '';

        return $currency === 'EUR' && $this->settings->complete();
    }

    /** ¿Está en el entorno de pruebas de Redsys (sis-t), donde no se cobra nada? Una demostración solo la ofrece así. */
    public function sandboxOnly(): bool
    {
        return $this->settings->isTest();
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
            throw new \RuntimeException('Redsys needs the order id, its number and its secret');
        }
        if (strtoupper(isset($order['currency']) ? (string) $order['currency'] : '') !== 'EUR') {
            throw new \RuntimeException('Redsys only charges in euros');
        }
        $config = $this->settings->config();
        $redsysOrder = $this->reserveAttempt($order);

        $url = $this->app->url();
        try {
            $request = RedsysRequest::build($config, [
                'order' => $redsysOrder,
                'amount' => isset($order['total']) ? (int) $order['total'] : 0,
                'urlNotify' => $this->settings->notifyUrl(),
                'urlOk' => $url->origin() . $url->route('payment.redsys.ok', ['number' => $number, 'secret' => $secret]),
                'urlKo' => $url->origin() . $url->route('payment.redsys.ko', ['number' => $number, 'secret' => $secret]),
                'description' => t('Order {number}', ['number' => $number]),
                'language' => isset($order['lang']) ? (string) $order['lang'] : '',
                'method' => $this->code === self::CODE_BIZUM ? 'bizum' : 'card',
            ]);
        } catch (\Throwable $e) {
            // Un formulario que no sale no deja un intento pendiente colgado.
            $this->app->db()->delete('order_payments', ['method' => self::CODE_CARD, 'kind' => self::ATTEMPT, 'reference' => $redsysOrder]);
            throw $e;
        }

        return PaymentAction::form($request['url'], $request['fields']);
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function refund(array $order, int $amount, string $reason): string
    {
        return (new RedsysRefund($this->app, $this->settings))->run($order, $amount);
    }

    /**
     * Apunta un intento de cobro nuevo en order_payments (pendiente) y devuelve su número de pedido para Redsys: el
     * identificador del pedido con ceros a la izquierda y una letra de intento («0012A», «0012B»…). Distinto en cada
     * intento: Redsys rechaza un número que ya usó. Es lo que luego permite encontrar el pedido cuando llega el aviso.
     *
     * @param array<string,mixed> $order
     */
    private function reserveAttempt(array $order): string
    {
        $db = $this->app->db();
        $orderId = (int) $order['id'];
        $previous = (int) $db->value(
            'SELECT COUNT(*) FROM {order_payments} WHERE order_id = ? AND method = ? AND kind = ?',
            [$orderId, self::CODE_CARD, self::ATTEMPT]
        );
        $now = $this->app->clock()->sql();
        for ($i = 1; $i <= 5; ++$i) {
            $reference = RedsysRequest::orderNumber($orderId, self::suffix($previous + $i));
            $saved = $db->insertIgnore('order_payments', [
                'order_id' => $orderId, 'method' => self::CODE_CARD, 'kind' => self::ATTEMPT, 'reference' => $reference,
                'amount' => isset($order['total']) ? (int) $order['total'] : 0, 'currency' => (string) $order['currency'], 'status' => 'pending',
                'test' => $this->settings->isTest() ? 1 : 0, 'raw' => null, 'created_at' => $now,
            ]);
            if ($saved) {
                return $reference;
            }
        }

        throw new \RuntimeException('A free Redsys order number could not be reserved');
    }

    /** La marca del intento n: A, B, C… Z, 10, 11… (siempre distinta, y de dos o tres caracteres como mucho en la práctica). */
    private static function suffix(int $attempt): string
    {
        return strtoupper(base_convert((string) ($attempt + 9), 10, 36));
    }
}

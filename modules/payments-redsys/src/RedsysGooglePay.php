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
use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Kernel\App;
use Aifec\Payments\Redsys\RedsysException;
use Aifec\Payments\Redsys\RedsysNotification;
use Aifec\Payments\Redsys\RedsysRequest;
use Aifec\Payments\Redsys\RedsysSigner;

/**
 * Google Pay cobrado por Redsys en el momento, con el token que manda la plataforma (Google, por UCP).
 *
 * start() hace la petición REST de autorización (tipo 0) con el token en DS_XPAYDATA (el paymentMethodData de Google Pay
 * en Base64), DS_XPAYTYPE «Google» y DS_XPAYORIGEN «WEB», verifica la firma de la respuesta y devuelve «pagado» o
 * «rechazado». No hay página del banco ni aviso posterior: la respuesta es el resultado.
 *
 * Solo se ofrece cuando hay un token en la petición (GooglePayCredential): no sale en la caja normal.
 */
final class RedsysGooglePay implements PaymentMethod
{
    public const CODE = 'googlepay';

    /** Tipo de operación de Redsys para una autorización (cobro). */
    private const TRANSACTION_CHARGE = '0';

    /** Respuestas de Redsys que piden autenticar al titular (3D Secure): por API no se puede, se termina en la caja. */
    private const NEEDS_AUTHENTICATION = [195, 184];

    /** @var App */
    private $app;

    /** @var RedsysSettings */
    private $settings;

    /** @var string motivo del último rechazo, para quien hace el pedido (declined, failed, authentication) */
    private static $lastOutcome = '';

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->settings = new RedsysSettings($app);
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function info(): array
    {
        return [
            'title' => t('Google Pay'),
            'description' => t('Paid with Google Pay from the assistant, through the bank of the shop.'),
            'icon' => '',
            // No sale nunca en la caja: no tiene puesto en el orden de las formas de pago del panel.
            'checkout' => false,
        ];
    }

    public function available(array $context): bool
    {
        $currency = isset($context['currency']) && is_string($context['currency']) ? strtoupper($context['currency']) : '';

        return $currency === 'EUR' && GooglePayCredential::current() !== null && $this->settings->googlePayReady();
    }

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

    /** Cómo acabó el último cobro: «paid», «declined», «failed» o «authentication» (pide 3D Secure). */
    public static function lastOutcome(): string
    {
        return self::$lastOutcome;
    }

    public function start(array $order): PaymentAction
    {
        self::$lastOutcome = 'failed';
        $credential = GooglePayCredential::current();
        $orderId = isset($order['id']) ? (int) $order['id'] : 0;
        $amount = isset($order['total']) ? (int) $order['total'] : 0;
        if ($credential === null) {
            return PaymentAction::failed(t('There is no Google Pay payment to charge.'));
        }
        if ($orderId < 1 || $amount < 1) {
            throw new \RuntimeException('Google Pay needs the order id and its total');
        }
        if (strtoupper(isset($order['currency']) ? (string) $order['currency'] : '') !== 'EUR') {
            return PaymentAction::failed(t('Google Pay through Redsys only charges in euros.'));
        }
        $config = $this->settings->config();
        $reference = $this->reserveAttempt($order);

        $params = [
            'DS_MERCHANT_AMOUNT' => (string) $amount,
            'DS_MERCHANT_ORDER' => $reference,
            'DS_MERCHANT_MERCHANTCODE' => $config->merchantCode(),
            'DS_MERCHANT_CURRENCY' => $config->currency(),
            'DS_MERCHANT_TRANSACTIONTYPE' => self::TRANSACTION_CHARGE,
            'DS_MERCHANT_TERMINAL' => $config->terminal(),
            'DS_MERCHANT_PRODUCTDESCRIPTION' => mb_substr(t('Order {number}', ['number' => isset($order['number']) ? (string) $order['number'] : '']), 0, 125, 'UTF-8'),
            'DS_XPAYDATA' => base64_encode((string) json_encode(GooglePayCredential::paymentMethodData($credential), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'DS_XPAYTYPE' => 'Google',
            'DS_XPAYORIGEN' => 'WEB',
        ];
        try {
            $b64 = base64_encode(json_encode($params, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $signature = RedsysSigner::sign($b64, $reference, $config->secretKey());
        } catch (\Throwable $e) {
            $this->attemptFailed($reference);

            return PaymentAction::failed(t('The Google Pay payment could not be prepared.'));
        }

        $url = str_replace('/sis/realizarPago', '/sis/rest/trataPeticionREST', $config->endpoint());
        try {
            $response = $this->app->http()->send(HttpRequest::postJson($url, [
                'Ds_SignatureVersion' => RedsysSigner::VERSION,
                'Ds_MerchantParameters' => $b64,
                'Ds_Signature' => $signature,
            ]));
        } catch (HttpException $e) {
            $this->attemptFailed($reference);

            return PaymentAction::failed(t('The bank could not be reached. Nothing was charged.'));
        }
        $data = $response->ok() ? $response->json() : null;
        if (!is_array($data)) {
            $this->attemptFailed($reference);

            return PaymentAction::failed(t('The bank sent an answer that cannot be read. Nothing was charged.'));
        }
        if (isset($data['errorCode'])) {
            // Un error de Redsys (SIS…): la operación no llegó al emisor de la tarjeta.
            $this->attemptFailed($reference);
            $this->app->log()->warning('Redsys refused a Google Pay request', ['code' => is_scalar($data['errorCode']) ? (string) $data['errorCode'] : '']);

            return PaymentAction::failed(t('The bank refused the Google Pay request ({code}). Nothing was charged.', ['code' => is_scalar($data['errorCode']) ? (string) $data['errorCode'] : '?']));
        }
        try {
            $answer = RedsysNotification::fromPost($data, $config);
        } catch (RedsysException $e) {
            // Una respuesta sin firma válida no se cree: no se da nada por cobrado.
            $this->attemptFailed($reference);
            $this->app->log()->warning('A Redsys Google Pay answer was refused', ['reason' => $e->getMessage()]);

            return PaymentAction::failed(t('The answer of the bank could not be verified. Nothing was marked as paid.'));
        }
        if ($answer->order !== $reference || (int) $answer->amount !== $amount) {
            $this->attemptFailed($reference);

            return PaymentAction::failed(t('The answer of the bank does not match this order. Nothing was marked as paid.'));
        }
        if (!$answer->authorised()) {
            $this->attemptFailed($reference);
            self::$lastOutcome = in_array((int) $answer->responseCode, self::NEEDS_AUTHENTICATION, true) ? 'authentication' : 'declined';

            return PaymentAction::failed(t('The bank declined the payment (code {code}): {text}', ['code' => sprintf('%04d', (int) $answer->responseCode), 'text' => RedsysResponses::text($answer->responseCode)]));
        }

        // Cobrado: el pedido lo da por pagado Orders::place() con esta referencia (la misma que usa la devolución).
        $this->app->db()->delete('order_payments', ['method' => self::CODE, 'kind' => RedsysPayment::ATTEMPT, 'reference' => $reference]);
        self::$lastOutcome = 'paid';

        return PaymentAction::paid($reference);
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
     * Apunta el intento (pendiente) y devuelve su número de pedido para Redsys: el del pedido con ceros a la izquierda y una
     * marca «G» más el número de intento. Distinto de los de la tarjeta (letras A, B, C…): Redsys no admite un número repetido.
     *
     * @param array<string,mixed> $order
     */
    private function reserveAttempt(array $order): string
    {
        $db = $this->app->db();
        $orderId = (int) $order['id'];
        $now = $this->app->clock()->sql();
        for ($i = 1; $i <= 9; ++$i) {
            $reference = RedsysRequest::orderNumber($orderId, 'G' . $i);
            $saved = $db->insertIgnore('order_payments', [
                'order_id' => $orderId, 'method' => self::CODE, 'kind' => RedsysPayment::ATTEMPT, 'reference' => $reference,
                'amount' => (int) $order['total'], 'currency' => (string) $order['currency'], 'status' => 'pending',
                'test' => $this->settings->isTest() ? 1 : 0, 'raw' => null, 'created_at' => $now,
            ]);
            if ($saved) {
                return $reference;
            }
        }

        throw new \RuntimeException('A free Redsys order number could not be reserved for Google Pay');
    }

    private function attemptFailed(string $reference): void
    {
        $this->app->db()->update('order_payments', ['status' => 'failed'], ['method' => self::CODE, 'kind' => RedsysPayment::ATTEMPT, 'reference' => $reference]);
    }
}

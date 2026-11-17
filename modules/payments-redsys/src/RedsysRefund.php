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

use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Kernel\App;
use Aifec\Payments\Redsys\RedsysConfig;
use Aifec\Payments\Redsys\RedsysException;
use Aifec\Payments\Redsys\RedsysNotification;
use Aifec\Payments\Redsys\RedsysSigner;

/**
 * Devolución por la interfaz REST de Redsys: una petición firmada de tipo 3 sobre el número de pedido del cobro original.
 *
 * El entorno es el del cobro, no el que esté elegido ahora: un pedido de pruebas se devuelve contra el banco de pruebas
 * aunque la tienda ya esté en producción, y al revés. La respuesta lleva su propia firma y se verifica igual que un aviso.
 */
final class RedsysRefund
{
    /** Tipo de operación de Redsys para una devolución. */
    private const TRANSACTION_REFUND = '3';

    /** @var App */
    private $app;

    /** @var RedsysSettings */
    private $settings;

    public function __construct(App $app, RedsysSettings $settings)
    {
        $this->app = $app;
        $this->settings = $settings;
    }

    /**
     * @param array<string,mixed> $order el pedido completo, con su lista de cobros
     *
     * @return string la referencia de la devolución, distinta en cada una
     *
     * @throws \RuntimeException si falta el cobro original o la configuración, el banco no contesta o la rechaza
     */
    public function run(array $order, int $amount): string
    {
        if ($amount < 1) {
            throw new \RuntimeException('The refund amount must be greater than zero');
        }
        $environment = !empty($order['test']) ? RedsysConfig::ENV_TEST : RedsysConfig::ENV_LIVE;
        try {
            $config = $this->settings->config($environment);
        } catch (RedsysException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        $reference = $this->chargeReference($order);

        $params = [
            'DS_MERCHANT_AMOUNT' => (string) $amount,
            'DS_MERCHANT_ORDER' => $reference,
            'DS_MERCHANT_MERCHANTCODE' => $config->merchantCode(),
            'DS_MERCHANT_CURRENCY' => $config->currency(),
            'DS_MERCHANT_TRANSACTIONTYPE' => self::TRANSACTION_REFUND,
            'DS_MERCHANT_TERMINAL' => $config->terminal(),
        ];
        try {
            $b64 = base64_encode(json_encode($params, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $signature = RedsysSigner::sign($b64, $reference, $config->secretKey());
        } catch (\JsonException $e) {
            throw new \RuntimeException('The refund request could not be encoded', 0, $e);
        } catch (RedsysException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $url = str_replace('/sis/realizarPago', '/sis/rest/trataPeticionREST', $config->endpoint());
        try {
            $response = $this->app->http()->send(HttpRequest::postJson($url, [
                'Ds_SignatureVersion' => RedsysSigner::VERSION,
                'Ds_MerchantParameters' => $b64,
                'Ds_Signature' => $signature,
            ]));
        } catch (HttpException $e) {
            throw new \RuntimeException('Redsys could not be reached: ' . $e->getMessage(), 0, $e);
        }
        if (!$response->ok()) {
            throw new \RuntimeException('Redsys answered with HTTP ' . $response->status);
        }
        $data = $response->json();
        if (!is_array($data)) {
            throw new \RuntimeException('Redsys sent an answer that cannot be read');
        }
        if (isset($data['errorCode'])) {
            throw new \RuntimeException('Redsys refused the refund: ' . (is_scalar($data['errorCode']) ? (string) $data['errorCode'] : 'error'));
        }
        try {
            $answer = RedsysNotification::fromPost($data, $config);
        } catch (RedsysException $e) {
            throw new \RuntimeException('The answer of Redsys is not valid: ' . $e->getMessage(), 0, $e);
        }
        if (!$answer->authorised() && !$answer->isRefund()) {
            throw new \RuntimeException('Redsys refused the refund: code ' . $answer->responseCode . ' (' . $answer->responseText() . ')');
        }

        // Una referencia distinta en cada devolución: lo devuelto en total crece siempre (el pedido ya cuenta esta).
        $cumulative = isset($order['refunded_total']) ? (int) $order['refunded_total'] : 0;
        if ($cumulative < $amount) {
            $cumulative = $amount;
        }

        return $reference . '-R' . $cumulative;
    }

    /**
     * El número de pedido de Redsys del cobro que se devuelve: la referencia del último cobro bueno por Redsys.
     *
     * @param array<string,mixed> $order
     */
    private function chargeReference(array $order): string
    {
        $found = '';
        foreach (isset($order['payments']) && is_array($order['payments']) ? $order['payments'] : [] as $payment) {
            if (
                is_array($payment) && isset($payment['kind'], $payment['status'], $payment['method'], $payment['reference'])
                && (string) $payment['kind'] === 'charge' && (string) $payment['status'] === 'ok'
                && in_array((string) $payment['method'], [RedsysPayment::CODE_CARD, RedsysPayment::CODE_BIZUM, RedsysGooglePay::CODE], true)
            ) {
                $found = (string) $payment['reference'];
            }
        }
        if ($found === '') {
            throw new \RuntimeException('The order has no payment made through Redsys to refund');
        }

        return $found;
    }
}

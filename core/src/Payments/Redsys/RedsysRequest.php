<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Payments\Redsys;

/**
 * Petición de pago por redirección: devuelve la dirección del banco y los tres campos firmados
 * que el navegador del cliente tiene que enviarle por POST. No hace ninguna llamada de red.
 */
final class RedsysRequest
{
    /** Idiomas de la página del banco (Ds_Merchant_ConsumerLanguage). */
    private const LANGUAGES = [
        'es' => '001',
        'en' => '002',
        'ca' => '003',
        'fr' => '004',
        'de' => '005',
        'it' => '007',
        'pt' => '009',
    ];

    /** Parámetros que acepta build(); cualquier otro es un error de quien llama. */
    private const ALLOWED = [
        'order', 'amount', 'urlNotify', 'urlOk', 'urlKo', 'description', 'titular',
        'language', 'method', 'merchantData', 'transactionType',
    ];

    /**
     * @param array<string,mixed> $p order*, amount* (céntimos), urlNotify*, urlOk*, urlKo*, description, titular,
     *                               language, method (card|bizum|wallets), merchantData, transactionType
     *
     * @return array{url:string,fields:array<string,string>}
     */
    public static function build(RedsysConfig $c, array $p): array
    {
        foreach (array_keys($p) as $key) {
            if (!in_array($key, self::ALLOWED, true)) {
                throw new RedsysException('Unknown request parameter: ' . $key);
            }
        }

        $order = $p['order'] ?? '';
        if (!is_string($order)) {
            throw new RedsysException('The order number must be a string');
        }
        self::assertOrder($order);

        $params = [
            'DS_MERCHANT_AMOUNT' => (string) self::amount($p['amount'] ?? null),
            'DS_MERCHANT_ORDER' => $order,
            'DS_MERCHANT_MERCHANTCODE' => $c->merchantCode(),
            'DS_MERCHANT_CURRENCY' => $c->currency(),
            'DS_MERCHANT_TRANSACTIONTYPE' => self::transactionType($p['transactionType'] ?? null),
            'DS_MERCHANT_TERMINAL' => $c->terminal(),
            'DS_MERCHANT_MERCHANTURL' => self::url($p, 'urlNotify'),
            'DS_MERCHANT_URLOK' => self::url($p, 'urlOk'),
            'DS_MERCHANT_URLKO' => self::url($p, 'urlKo'),
            'DS_MERCHANT_CONSUMERLANGUAGE' => self::language($p['language'] ?? null),
            'DS_MERCHANT_PRODUCTDESCRIPTION' => self::text($p, 'description', 125),
            'DS_MERCHANT_MERCHANTNAME' => self::cut($c->merchantName(), 25),
            'DS_MERCHANT_TITULAR' => self::text($p, 'titular', 60),
            'DS_MERCHANT_MERCHANTDATA' => self::text($p, 'merchantData', 1024),
        ];

        $payMethods = self::payMethods($p['method'] ?? null);
        if ($payMethods !== null) {
            $params['DS_MERCHANT_PAYMETHODS'] = $payMethods;
        }

        try {
            // Solo ASCII (los demás caracteres, escapados como \uXXXX) y sin escapar las barras.
            $json = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RedsysException('The request parameters could not be encoded: ' . $e->getMessage());
        }
        $b64 = base64_encode($json);

        return [
            'url' => $c->endpoint(),
            'fields' => [
                'Ds_SignatureVersion' => RedsysSigner::VERSION,
                'Ds_MerchantParameters' => $b64,
                'Ds_Signature' => RedsysSigner::sign($b64, $order, $c->secretKey()),
            ],
        ];
    }

    /**
     * Número de pedido válido para Redsys a partir de un secuencial: cuatro cifras como mínimo
     * (con ceros a la izquierda) y, si se pide, un sufijo alfanumérico para los reintentos. Máximo 12.
     */
    public static function orderNumber(int $sequence, ?string $suffix = null): string
    {
        if ($sequence < 0) {
            throw new RedsysException('The order sequence cannot be negative');
        }
        $order = sprintf('%04d', $sequence) . ($suffix ?? '');
        self::assertOrder($order);

        return $order;
    }

    /** Un número de pedido de Redsys: de 4 a 12 caracteres, los cuatro primeros cifras y el resto alfanumérico. */
    private static function assertOrder(string $order): void
    {
        if (preg_match('/^[0-9]{4}[0-9A-Za-z]{0,8}$/D', $order) !== 1) {
            throw new RedsysException('Invalid order number: 4 to 12 characters, the first four digits, the rest alphanumeric');
        }
    }

    /** @param mixed $amount */
    private static function amount($amount): int
    {
        if (is_string($amount) && preg_match('/^[0-9]+$/D', $amount) === 1) {
            $amount = (int) $amount;
        }
        if (!is_int($amount) || $amount <= 0) {
            throw new RedsysException('The amount must be a positive integer number of cents');
        }

        return $amount;
    }

    /** @param mixed $type */
    private static function transactionType($type): string
    {
        if ($type === null || $type === '') {
            return '0';
        }
        if (is_int($type)) {
            $type = (string) $type;
        }
        if (!is_string($type) || preg_match('/^[0-9A-Z]{1,2}$/D', $type) !== 1) {
            throw new RedsysException('Invalid transaction type');
        }

        return $type;
    }

    /** @param mixed $language */
    private static function language($language): string
    {
        if (is_string($language) && isset(self::LANGUAGES[$language])) {
            return self::LANGUAGES[$language];
        }

        // Sin idioma o con uno que la página del banco no ofrece: castellano.
        return self::LANGUAGES['es'];
    }

    /**
     * @param mixed $method
     *
     * @return string|null null = la página del banco ofrece todo lo que el comercio tenga activo
     */
    private static function payMethods($method): ?string
    {
        if ($method === null || $method === '' || $method === 'card') {
            return null;
        }
        if ($method === 'bizum') {
            return 'z';
        }
        if ($method === 'wallets') {
            return 'xpay';
        }

        throw new RedsysException('Unknown payment method: use card, bizum or wallets');
    }

    /** @param array<string,mixed> $p */
    private static function url(array $p, string $key): string
    {
        $url = $p[$key] ?? '';
        if (!is_string($url) || preg_match('#^https?://[^\s]+$#D', $url) !== 1) {
            throw new RedsysException('Parameter ' . $key . ' must be an http(s) URL');
        }

        return $url;
    }

    /** @param array<string,mixed> $p */
    private static function text(array $p, string $key, int $max): string
    {
        $value = $p[$key] ?? '';
        if ($value === null) {
            $value = '';
        }
        if (!is_string($value)) {
            throw new RedsysException('Parameter ' . $key . ' must be a string');
        }

        return self::cut($value, $max);
    }

    /** Recorta por caracteres, no por bytes. */
    private static function cut(string $text, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max, 'UTF-8');
        }

        return substr($text, 0, $max);
    }
}

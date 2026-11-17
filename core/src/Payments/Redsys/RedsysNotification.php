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
 * Aviso de Redsys (POST al comercio) o vuelta del navegador (GET a urlOk/urlKo) ya verificado.
 *
 * Solo se puede crear con fromPost(), que comprueba la firma. Los datos se leen como propiedades
 * (`$n->order`, `$n->amount`…) y no se pueden cambiar.
 *
 * @property-read string $order
 * @property-read int $amount importe en céntimos
 * @property-read string $currency
 * @property-read int $responseCode
 * @property-read string $authCode
 * @property-read string $merchantData
 * @property-read string $cardCountry
 * @property-read string $payMethod card, bizum, wallet u other
 * @property-read bool $secure
 * @property-read string $date
 * @property-read array<string,string> $raw
 */
final class RedsysNotification
{
    private const READABLE = [
        'order', 'amount', 'currency', 'responseCode', 'authCode', 'merchantData',
        'cardCountry', 'payMethod', 'secure', 'date', 'raw',
    ];

    private const PAY_METHODS = ['card', 'bizum', 'wallet', 'other'];

    private const RESPONSES = [
        101 => 'Card expired',
        102 => 'Card temporarily suspended or under suspicion of fraud',
        104 => 'Transaction not allowed for this card',
        106 => 'PIN attempts exceeded',
        116 => 'Insufficient funds',
        118 => 'Card not registered',
        125 => 'Card not effective',
        129 => 'Wrong security code (CVV)',
        167 => 'Suspected fraud',
        180 => 'Card not valid for this service',
        184 => 'Cardholder authentication failed',
        190 => 'Declined by the issuer without a reason',
        191 => 'Wrong expiry date',
        202 => 'Card suspended or under suspicion of fraud, retain card',
        900 => 'Transaction approved for refunds and confirmations',
        904 => 'Merchant not registered',
        909 => 'System error',
        913 => 'Duplicated order number',
        944 => 'Wrong session',
        950 => 'Refund not allowed',
        9915 => 'Payment cancelled by the user',
        9998 => 'Transaction waiting for the card data',
        9999 => 'Transaction redirected to the issuer to authenticate',
    ];

    /** @var string */
    private $order = '';

    /** @var int */
    private $amount = 0;

    /** @var string */
    private $currency = '';

    /** @var int */
    private $responseCode = 0;

    /** @var string */
    private $authCode = '';

    /** @var string */
    private $merchantData = '';

    /** @var string */
    private $cardCountry = '';

    /** @var string */
    private $payMethod = 'other';

    /** @var bool */
    private $secure = false;

    /** @var string */
    private $date = '';

    /** @var array<string,string> */
    private $raw = [];

    private function __construct()
    {
    }

    /**
     * Valida la firma y lee el aviso. Lanza RedsysException si falta algún campo o la firma no cuadra.
     *
     * @param array<string,mixed>  $post           $_POST del aviso o $_GET de la vuelta del navegador
     * @param array<string,string> $payMethodCodes código de Ds_ProcessedPayMethod => card|bizum|wallet|other.
     *                                             Sin código en la tabla, una tarjeta con marca es «card» y lo demás «other»;
     *                                             los códigos concretos de Bizum o de las carteras los pone quien los conozca.
     */
    public static function fromPost(array $post, RedsysConfig $c, array $payMethodCodes = []): self
    {
        foreach ($payMethodCodes as $method) {
            if (!is_string($method) || !in_array($method, self::PAY_METHODS, true)) {
                throw new RedsysException('Invalid pay method in the code table');
            }
        }

        $b64 = self::postValue($post, 'Ds_MerchantParameters');
        $signature = self::postValue($post, 'Ds_Signature');
        if ($b64 === '' || $signature === '') {
            throw new RedsysException('Missing Ds_MerchantParameters or Ds_Signature');
        }
        $version = self::postValue($post, 'Ds_SignatureVersion');
        if ($version !== '' && $version !== RedsysSigner::VERSION) {
            throw new RedsysException('Unsupported signature version: ' . $version);
        }
        if (!RedsysSigner::verify($b64, $signature, $c->secretKey())) {
            throw new RedsysException('Invalid signature');
        }

        $params = RedsysSigner::decodeParameters($b64);
        $raw = [];
        foreach ($params as $key => $value) {
            if (is_string($value)) {
                $raw[(string) $key] = urldecode($value);
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $raw[(string) $key] = urldecode((string) $value);
            } elseif ($value === null) {
                $raw[(string) $key] = '';
            } else {
                $raw[(string) $key] = (string) json_encode($value);
            }
        }
        $index = array_change_key_case($raw, CASE_LOWER);
        $get = static function (string $name) use ($index): string {
            return $index[strtolower($name)] ?? '';
        };

        $order = $get('Ds_Order');
        $response = $get('Ds_Response');
        $amount = $get('Ds_Amount');
        if ($order === '') {
            throw new RedsysException('Missing Ds_Order');
        }
        if (preg_match('/^[0-9]{1,10}$/D', $response) !== 1) {
            throw new RedsysException('Missing or invalid Ds_Response');
        }
        if (preg_match('/^[0-9]{1,15}$/D', $amount) !== 1) {
            throw new RedsysException('Missing or invalid Ds_Amount');
        }

        $n = new self();
        $n->order = $order;
        $n->amount = (int) $amount;
        $n->currency = $get('Ds_Currency');
        $n->responseCode = (int) $response;
        $n->authCode = trim($get('Ds_AuthorisationCode'));
        $n->merchantData = $get('Ds_MerchantData');
        $n->cardCountry = $get('Ds_Card_Country');
        $n->payMethod = self::classifyPayMethod($get('Ds_ProcessedPayMethod'), $get('Ds_Card_Brand'), $payMethodCodes);
        $n->secure = $get('Ds_SecurePayment') === '1';
        $n->date = trim($get('Ds_Date') . ' ' . $get('Ds_Hour'));
        $n->raw = $raw;

        return $n;
    }

    /** Autorizada: códigos de respuesta de 0 a 99. */
    public function authorised(): bool
    {
        return $this->responseCode >= 0 && $this->responseCode <= 99;
    }

    /** Respuesta 900: autorización de una devolución o de una confirmación. */
    public function isRefund(): bool
    {
        return $this->responseCode === 900;
    }

    /** Descripción en inglés del código de respuesta. */
    public function responseText(): string
    {
        return self::describe($this->responseCode);
    }

    public static function describe(int $code): string
    {
        if ($code >= 0 && $code <= 99) {
            return 'Transaction approved';
        }

        return self::RESPONSES[$code] ?? 'Unknown response';
    }

    /** @return mixed */
    public function __get(string $name)
    {
        if (!in_array($name, self::READABLE, true)) {
            throw new \LogicException('Unknown notification field: ' . $name);
        }

        return $this->$name;
    }

    public function __isset(string $name): bool
    {
        return in_array($name, self::READABLE, true);
    }

    /** @param mixed $value */
    public function __set(string $name, $value): void
    {
        throw new \LogicException('A Redsys notification is read-only');
    }

    /** @param array<string,mixed> $post */
    private static function postValue(array $post, string $name): string
    {
        $wanted = strtolower($name);
        foreach ($post as $key => $value) {
            if (strtolower((string) $key) === $wanted) {
                return is_string($value) ? $value : '';
            }
        }

        return '';
    }

    /** @param array<string,string> $codes */
    private static function classifyPayMethod(string $processed, string $brand, array $codes): string
    {
        if ($processed !== '' && isset($codes[$processed])) {
            return $codes[$processed];
        }

        return $brand !== '' ? 'card' : 'other';
    }
}

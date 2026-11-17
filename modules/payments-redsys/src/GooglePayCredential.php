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

/**
 * El token de Google Pay de la compra que se está cerrando en ESTA petición. Lo deja quien lo recibe (la API de UCP, al
 * completar una sesión) justo antes de hacer el pedido, y lo usa RedsysGooglePay::start() para cobrar; quien lo dejó lo
 * quita al terminar, pase lo que pase. Sin token no hay forma de pago «Google Pay»: no sale en la caja normal.
 *
 * El token va cifrado por Google para el banco (tipo PAYMENT_GATEWAY): la tienda no lo puede leer y no lo guarda.
 */
final class GooglePayCredential
{
    /** @var array{token:string,brand:string,last_digits:string,description:string}|null */
    private static $staged = null;

    /** Deja el token de la compra de ahora. Un token vacío no cuenta. */
    public static function stage(string $token, string $brand = '', string $lastDigits = '', string $description = ''): void
    {
        $token = trim($token);
        self::$staged = $token === '' ? null : [
            'token' => $token,
            'brand' => strtoupper(preg_replace('/[^A-Za-z]/', '', $brand) ?? ''),
            'last_digits' => (string) preg_replace('/[^0-9]/', '', $lastDigits),
            'description' => mb_substr(trim($description), 0, 80, 'UTF-8'),
        ];
    }

    /** @return array{token:string,brand:string,last_digits:string,description:string}|null */
    public static function current(): ?array
    {
        return self::$staged;
    }

    public static function clear(): void
    {
        self::$staged = null;
    }

    /**
     * El objeto paymentMethodData de Google Pay que Redsys pide (en Base64) en DS_XPAYDATA, rehecho a partir de lo que manda
     * la plataforma: el token tal cual, la red y los últimos dígitos.
     *
     * @param array{token:string,brand:string,last_digits:string,description:string} $credential
     *
     * @return array<string,mixed>
     */
    public static function paymentMethodData(array $credential): array
    {
        $info = [];
        if ($credential['brand'] !== '') {
            $info['cardNetwork'] = $credential['brand'];
        }
        if ($credential['last_digits'] !== '') {
            $info['cardDetails'] = substr($credential['last_digits'], -4);
        }
        $data = ['type' => 'CARD'];
        if ($credential['description'] !== '') {
            $data['description'] = $credential['description'];
        }
        if ($info !== []) {
            $data['info'] = $info;
        }
        $data['tokenizationData'] = ['type' => 'PAYMENT_GATEWAY', 'token' => $credential['token']];

        return $data;
    }
}

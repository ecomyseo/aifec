<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Front;

use Aifec\Geo\Countries;
use Aifec\Geo\Spain;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;

/**
 * Lo que el cliente va escribiendo en la caja, en una sola forma: se lee del formulario (POST) o de lo que el carrito
 * guardó (GET), se guarda en el carrito y se convierte en los datos que espera Orders::place().
 *
 * El estado es un array con estas claves: email, address_id (la dirección guardada que elige un cliente con cuenta; 0 =
 * escribe otra), shipping (dirección de entrega escrita), billing_other (la factura va a otros datos), billing,
 * carrier_id, payment_method, note, accept_terms y marketing. Una dirección tiene first_name, last_name, company,
 * vat_id, phone, line1, line2, postcode, city, region y country.
 *
 * Las dos casillas (condiciones y publicidad) no se guardan en el carrito: el consentimiento se da en cada pedido.
 */
final class CheckoutState
{
    /** Largo máximo de cada campo de una dirección (los de las columnas de la dirección del pedido). */
    private const FIELDS = [
        'first_name' => 80, 'last_name' => 120, 'company' => 160, 'vat_id' => 40, 'phone' => 40, 'line1' => 191,
        'line2' => 191, 'postcode' => 16, 'city' => 100, 'region' => 16, 'country' => 2,
    ];

    /**
     * Una dirección limpia: todas las claves, solo texto, sin caracteres de control y con los largos de las columnas.
     * El país y el código postal van en mayúsculas, y la provincia de España sale del código postal si este vale.
     *
     * @param mixed $raw
     *
     * @return array<string,string>
     */
    public static function address($raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach (self::FIELDS as $key => $max) {
            $value = isset($raw[$key]) && is_scalar($raw[$key]) ? trim((string) $raw[$key]) : '';
            $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
            $value = mb_substr(trim(is_string($clean) ? $clean : $value), 0, $max, 'UTF-8');
            $out[$key] = $key === 'country' || $key === 'postcode' ? strtoupper($value) : $value;
        }
        if ($out['country'] === 'ES' && Spain::validPostcode($out['postcode'])) {
            $out['region'] = Destinations::provinceOf($out['postcode']);
        }

        return $out;
    }

    /**
     * El estado a partir de lo que ha enviado el formulario.
     *
     * @return array<string,mixed>
     */
    public static function fromRequest(Request $request): array
    {
        return [
            'email' => strtolower($request->str('email')),
            'address_id' => max(0, $request->int('address_id')),
            'shipping' => self::address($request->arr('shipping')),
            'billing_other' => $request->bool('billing_other'),
            'billing' => self::address($request->arr('billing')),
            'carrier_id' => max(0, $request->int('carrier_id')),
            'payment_method' => strtolower($request->str('payment_method')),
            'note' => mb_substr($request->str('note'), 0, 2000, 'UTF-8'),
            'accept_terms' => $request->bool('accept_terms'),
            'marketing' => $request->bool('marketing'),
        ];
    }

    /**
     * El estado a partir de lo que el carrito guardó de visitas anteriores, con lo que ya se sabe del destino y de la
     * cuenta como punto de partida.
     *
     * @param array<string,mixed>            $summary   Cart::summary()
     * @param array<string,mixed>|null       $customer
     * @param array<int,array<string,mixed>> $addresses las direcciones guardadas del cliente
     *
     * @return array<string,mixed>
     */
    public static function fromCart(App $app, array $summary, ?array $customer, array $addresses): array
    {
        $map = isset($summary['checkout']) && is_array($summary['checkout']) ? $summary['checkout'] : [];
        $shipping = self::address(isset($map['shipping']) ? $map['shipping'] : []);
        $destination = is_array($summary['destination']) ? $summary['destination'] : [];
        foreach (['country', 'postcode', 'city', 'region'] as $field) {
            if ($shipping[$field] === '' && isset($destination[$field]) && (string) $destination[$field] !== '') {
                $shipping[$field] = $field === 'country' || $field === 'postcode' ? strtoupper((string) $destination[$field]) : (string) $destination[$field];
            }
        }
        if ($shipping['country'] === '') {
            $default = $app->has('shipping') ? $app->service('shipping')->defaultCountry() : 'ES';
            $shipping['country'] = (string) $default;
        }
        if ($customer !== null && trim($shipping['first_name'] . $shipping['last_name']) === '') {
            foreach (['first_name', 'last_name', 'company', 'phone'] as $field) {
                if ($shipping[$field] === '' && isset($customer[$field]) && is_scalar($customer[$field])) {
                    $shipping[$field] = mb_substr(trim((string) $customer[$field]), 0, self::FIELDS[$field], 'UTF-8');
                }
            }
        }
        $billing = self::address(isset($map['billing']) ? $map['billing'] : []);
        if ($billing['country'] === '') {
            $billing['country'] = $shipping['country'];
        }
        $addressId = 0;
        if ($customer !== null && $addresses !== []) {
            $addressId = array_key_exists('address_id', $map) ? (int) $map['address_id'] : (int) $addresses[0]['id'];
        }
        $email = isset($map['email']) && is_string($map['email']) ? $map['email'] : (isset($summary['email']) ? (string) $summary['email'] : '');
        if ($customer !== null && isset($customer['email']) && is_string($customer['email'])) {
            $email = $customer['email'];
        }

        return [
            'email' => $email,
            'address_id' => $addressId,
            'shipping' => $shipping,
            'billing_other' => isset($map['billing_other']) && (string) $map['billing_other'] === '1',
            'billing' => $billing,
            'carrier_id' => $summary['carrier_id'] !== null ? (int) $summary['carrier_id'] : 0,
            'payment_method' => $summary['payment_method'] !== null ? (string) $summary['payment_method'] : '',
            'note' => isset($map['note']) && is_scalar($map['note']) ? (string) $map['note'] : '',
            'accept_terms' => false,
            'marketing' => false,
        ];
    }

    /**
     * Lo que se guarda en el carrito (Cart::setCheckout): todo menos las casillas de consentimiento.
     *
     * @param array<string,mixed> $state
     *
     * @return array<string,mixed>
     */
    public static function stored(array $state): array
    {
        return [
            'email' => (string) $state['email'],
            'address_id' => (string) (int) $state['address_id'],
            'shipping' => $state['shipping'],
            'billing_other' => !empty($state['billing_other']) ? '1' : '0',
            'billing' => $state['billing'],
            'note' => (string) $state['note'],
        ];
    }

    /**
     * La dirección de entrega que vale: la guardada que ha elegido el cliente, o la que ha escrito.
     *
     * @param array<string,mixed>            $state
     * @param array<int,array<string,mixed>> $addresses
     *
     * @return array<string,string>
     */
    public static function chosenShipping(array $state, array $addresses): array
    {
        $saved = self::savedAddress($state, $addresses);

        return $saved !== null ? self::address($saved) : $state['shipping'];
    }

    /**
     * La dirección guardada que ha elegido el cliente, si la hay entre las suyas (la de otro cliente nunca está aquí).
     *
     * @param array<string,mixed>            $state
     * @param array<int,array<string,mixed>> $addresses
     *
     * @return array<string,mixed>|null
     */
    public static function savedAddress(array $state, array $addresses): ?array
    {
        $id = (int) $state['address_id'];
        if ($id > 0) {
            foreach ($addresses as $saved) {
                if ((int) $saved['id'] === $id) {
                    return $saved;
                }
            }
        }

        return null;
    }

    /**
     * La dirección que da el destino de la compra: la de entrega si hay que enviar y, si no, la de facturación.
     *
     * @param array<string,mixed>            $state
     * @param array<int,array<string,mixed>> $addresses
     *
     * @return array<string,string>
     */
    public static function delivery(array $state, array $addresses, bool $needsShipping): array
    {
        return $needsShipping ? self::chosenShipping($state, $addresses) : $state['billing'];
    }

    /**
     * Los datos de la caja tal como los pide Orders::place().
     *
     * Si no hay que enviar, solo hay datos de facturación. Si hay que enviar y la factura va a los mismos datos, la
     * dirección de entrega hace de facturación (con el NIF que se haya escrito) y no se manda otra de envío.
     *
     * @param array<string,mixed>            $state
     * @param array<string,mixed>|null       $customer
     * @param array<int,array<string,mixed>> $addresses
     *
     * @return array<string,mixed>
     */
    public static function forPlace(array $state, ?array $customer, array $addresses, bool $needsShipping): array
    {
        $shipping = [];
        if (!$needsShipping) {
            $billing = $state['billing'];
        } else {
            $chosen = self::chosenShipping($state, $addresses);
            if (!empty($state['billing_other'])) {
                $billing = $state['billing'];
                $shipping = $chosen;
            } else {
                $billing = $chosen;
                if ($billing['vat_id'] === '' && $state['shipping']['vat_id'] !== '') {
                    $billing['vat_id'] = $state['shipping']['vat_id'];
                }
            }
        }

        return [
            'email' => $customer !== null && isset($customer['email']) ? (string) $customer['email'] : (string) $state['email'],
            'billing' => $billing,
            'shipping' => $shipping,
            'note' => (string) $state['note'],
            'accept_terms' => !empty($state['accept_terms']),
            'marketing' => !empty($state['marketing']),
        ];
    }

    /**
     * Lo que se comprueba en la caja antes de crear nada, aparte de lo que ya comprueba Orders::place(): el NIF de quien
     * escribe empresa y la forma del código postal del país.
     *
     * @param array<string,mixed>            $state
     * @param array<string,mixed>|null       $customer
     * @param array<int,array<string,mixed>> $addresses
     *
     * @return array<string,string> campo del formulario => mensaje
     */
    public static function check(array $state, ?array $customer, array $addresses, bool $needsShipping): array
    {
        $errors = [];
        $data = self::forPlace($state, $customer, $addresses, $needsShipping);
        $saved = $needsShipping && self::savedAddress($state, $addresses) !== null;
        if ($data['billing']['company'] !== '' && $data['billing']['vat_id'] === '') {
            if (!$needsShipping || !empty($state['billing_other'])) {
                $field = 'billing.vat_id';
                $errors[$field] = t('Enter the tax ID for the invoice');
            } else {
                $field = $saved ? 'address' : 'shipping.vat_id';
                // La empresa en la dirección de entrega: puede ser quien compra (necesita NIF) o solo el sitio donde se deja el paquete
                // (14/11/2026: dos agentes de compra pusieron ahí una oficina y una asociación y no sabían cómo seguir).
                $errors[$field] = t('A company needs its tax ID for the invoice. If it is only the name of the place where it is delivered, write it in the second line of the address and leave the company empty.');
            }
        }
        if ($needsShipping && !$saved) {
            $address = $state['shipping'];
            if ($address['postcode'] !== '' && $address['country'] !== '' && !Countries::validPostcode($address['country'], $address['postcode'])) {
                $errors['shipping.postcode'] = t('Enter a valid postcode');
            }
        }

        return $errors;
    }

    /**
     * Pone los errores de Orders::place() en el campo del formulario donde se escribió el dato. Con la factura a los
     * mismos datos, lo que falta de «billing» es de la dirección de entrega; con una dirección guardada, de la dirección.
     *
     * @param array<string,string>           $errors
     * @param array<string,mixed>            $state
     * @param array<int,array<string,mixed>> $addresses
     *
     * @return array<string,string>
     */
    public static function mapErrors(array $errors, array $state, array $addresses, bool $needsShipping): array
    {
        $saved = $needsShipping && self::savedAddress($state, $addresses) !== null;
        $out = [];
        foreach ($errors as $key => $message) {
            $key = (string) $key;
            if ($needsShipping && empty($state['billing_other']) && strpos($key, 'billing.') === 0) {
                $key = 'shipping.' . substr($key, 8);
            }
            if ($saved && strpos($key, 'shipping.') === 0) {
                $key = 'address';
            }
            if (!isset($out[$key])) {
                $out[$key] = (string) $message;
            }
        }

        return $out;
    }
}

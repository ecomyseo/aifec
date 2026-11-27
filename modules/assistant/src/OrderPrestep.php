<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Assistant;

use Aifec\Assistant\CustomerData;

/**
 * Lo que hace la tienda con el mensaje de un cliente ANTES de pasárselo a la IA, en la compra dentro del chat.
 *
 * Un mensaje con los datos del cliente (nombre, calle, código postal, teléfono, correo) es el paso más valioso de la compra y el que
 * más rondas de herramientas gastaba: el modelo tenía que deletrear cada dato con una llamada. Aquí lo lee el lector de reglas
 * (CheckoutSession::understand, por la herramienta checkout_set_customer) y se guarda con UNA llamada. Al modelo le llega una nota:
 * «datos guardados: … falta: …», las opciones de envío con su precio en cuanto hay código postal, y lo que cambia del envío si
 * cambió la dirección.
 *
 * Si el cliente ya había dicho qué envío o qué pago quiere («el más rápido», «contra reembolso») y ese paso ya se puede dar, se
 * aplica y se le dice al modelo para que lo diga («he puesto el envío urgente, como pediste»).
 *
 * Nunca hace el pedido ni confirma nada: eso es de la persona. Las notas van en inglés porque las lee el modelo, no el cliente.
 */
final class OrderPrestep
{
    /** @var ToolRunner */
    private $runner;

    /** @var Answer */
    private $answer;

    public function __construct(ToolRunner $runner, Answer $answer)
    {
        $this->runner = $runner;
        $this->answer = $answer;
    }

    /**
     * ¿Trae el mensaje datos de cliente (correo, teléfono, código postal, NIF o una calle)?
     */
    public static function hasData(string $message): bool
    {
        $data = CustomerData::extract($message);

        return isset($data['email']) || isset($data['phone']) || isset($data['postcode']) || isset($data['tax_id']) || isset($data['address']);
    }

    /**
     * @param array<string,string> $prefs lo que el cliente pidió antes del envío (ship) y del pago (pay)
     *
     * @return string[] lo que se le cuenta al modelo
     */
    public function run(string $message, array $prefs): array
    {
        if (!$this->runner->can('checkout_status')) {
            return [];
        }
        $notes = [];
        $saved = false;
        if (self::hasData($message) && !CheckoutDialog::browsing($message, RuleBrain::norm($message))) {
            $status = $this->runner->run('checkout_status', []);
            if (isset($status['error'])) {
                // Sin nada en el carrito no hay pedido en el que guardar los datos.
                return [];
            }
            // El envío de antes solo cuenta si ya había una dirección completa: sin ella, el que traiga el carrito no lo ha elegido nadie.
            $missingBefore = isset($status['missing']) && is_array($status['missing']) ? $status['missing'] : [];
            $hadAddress = !in_array('contact', $missingBefore, true) && !in_array('address', $missingBefore, true);
            $before = $hadAddress && isset($status['shipping']) && is_array($status['shipping']) ? $status['shipping'] : null;
            $set = $this->runner->run('checkout_set_customer', ['text' => $message]);
            if (!isset($set['error'])) {
                $saved = true;
                array_push($notes, ...$this->saved($set, $before));
            }
        }
        if ($saved || $prefs !== []) {
            array_push($notes, ...$this->preferences($prefs, $saved));
        }

        return $notes;
    }

    /**
     * La nota de lo que se guardó.
     *
     * @param array<string,mixed>      $set    lo que devolvió checkout_set_customer
     * @param array<string,mixed>|null $before el envío elegido antes de guardar
     *
     * @return string[]
     */
    private function saved(array $set, ?array $before): array
    {
        $fields = [];
        foreach (isset($set['saved']) && is_array($set['saved']) ? $set['saved'] : [] as $key) {
            $field = (string) $key;
            $pos = strrpos($field, '.');
            $fields[] = $pos === false ? $field : substr($field, $pos + 1);
        }
        $fields = array_values(array_unique($fields));
        $missing = isset($set['missing_fields']) && is_array($set['missing_fields']) ? array_values(array_map('strval', $set['missing_fields'])) : [];
        $notes = ['Details saved from this message: ' . ($fields === [] ? 'none' : implode(', ', $fields)) . '. Still missing: ' . ($missing === [] ? 'nothing' : implode(', ', $missing)) . '.'];
        $errors = isset($set['errors']) && is_array($set['errors']) ? array_values(array_map('strval', $set['errors'])) : [];
        if ($errors !== []) {
            $notes[] = 'Problems with what was written: ' . implode('; ', $errors) . '.';
        }
        $understood = isset($set['understood']) && is_array($set['understood']) ? $set['understood'] : [];
        $postcode = isset($understood['postcode']) ? trim((string) $understood['postcode']) : '';
        if ($postcode !== '') {
            $quote = $this->runner->run('shipping_quote', ['postcode' => $postcode]);
            $options = [];
            foreach (isset($quote['options']) && is_array($quote['options']) ? $quote['options'] : [] as $option) {
                $options[] = (string) $option['name'] . ' ' . (!empty($option['free']) ? 'free' : (string) $option['price']) . ((string) $option['delay'] !== '' ? ' (' . (string) $option['delay'] . ')' : '');
            }
            if ($options !== []) {
                $notes[] = 'Shipping options to ' . $postcode . ': ' . implode('; ', $options) . '. Tell the customer.';
            }
        }
        if ($before !== null) {
            $after = $this->runner->run('checkout_status', []);
            $now = !isset($after['error']) && isset($after['shipping']) && is_array($after['shipping']) ? $after['shipping'] : null;
            if ($now !== null && (string) $now['price'] === (string) $before['price'] && (string) $now['name'] === (string) $before['name']) {
                $notes[] = 'The address changed but the shipping did not: it is still ' . (string) $now['name'] . ', ' . (string) $now['price'] . '. Tell the customer.';
            } else {
                $notes[] = 'The address changed and the shipping changed too: check it with checkout_shipping_options and tell the customer.';
            }
        }

        return $notes;
    }

    /**
     * Lo que el cliente pidió antes del envío y del pago: se aplica lo que ya se puede aplicar (con la dirección completa) y se
     * recuerda al modelo lo que queda para cuando llegue a ese paso.
     *
     * @param array<string,string> $prefs
     *
     * @return string[]
     */
    private function preferences(array $prefs, bool $addressJustSaved): array
    {
        $notes = [];
        $status = $this->runner->run('checkout_status', []);
        if (isset($status['error'])) {
            return [];
        }
        $missing = isset($status['missing']) && is_array($status['missing']) ? $status['missing'] : [];
        $complete = !in_array('contact', $missing, true) && !in_array('address', $missing, true);
        // El envío: se aplica si ya hay dirección y hay donde elegir; si solo hay una opción, ese paso ya está dado.
        $needsShipping = !empty($status['needs_shipping']);
        $shipDone = !$needsShipping;
        if ($complete && $needsShipping) {
            $options = $this->runner->run('checkout_shipping_options', []);
            $list = isset($options['options']) && is_array($options['options']) ? $options['options'] : [];
            $shipDone = count($list) <= 1;
            $choice = isset($prefs['ship']) ? Prefs::shipping($prefs['ship'], $list) : null;
            if ($choice !== null && count($list) > 1 && ($addressJustSaved || $status['shipping'] === null)) {
                $set = $this->runner->run('checkout_set_shipping', ['carrier_id' => (int) $choice['id']]);
                if (!isset($set['error'])) {
                    $shipDone = true;
                    $this->answer->prefsUsed[] = 'ship';
                    $notes[] = 'The customer asked earlier for the ' . $prefs['ship'] . ' shipping and the shop has already set it: ' . (string) $choice['name'] . ', ' . (!empty($choice['free']) ? 'free' : (string) $choice['price']) . '. Say it ("I have set the shipping to ..., as you asked").';
                }
            }
        }
        if (isset($prefs['ship']) && !in_array('ship', $this->answer->prefsUsed, true)) {
            $notes[] = 'The customer asked earlier for the ' . $prefs['ship'] . ' shipping: use it when you reach the shipping step and say so.';
        }
        if (isset($prefs['pay'])) {
            $applied = false;
            if ($complete && $shipDone && (!isset($status['payment']) || $status['payment'] === null)) {
                $options = $this->runner->run('checkout_payment_options', []);
                $choice = Prefs::payment($prefs['pay'], isset($options['options']) && is_array($options['options']) ? $options['options'] : []);
                if ($choice !== null) {
                    $set = $this->runner->run('checkout_set_payment', ['method' => (string) $choice['id']]);
                    if (!isset($set['error'])) {
                        $applied = true;
                        $this->answer->prefsUsed[] = 'pay';
                        $notes[] = 'The customer asked earlier to pay by ' . $prefs['pay'] . ' and the shop has already set it: ' . (string) $choice['name'] . '. Say it ("I have set the payment to ..., as you asked").';
                    }
                }
            }
            if (!$applied) {
                $notes[] = 'The customer asked earlier to pay by ' . $prefs['pay'] . ': use it when you reach the payment step and say so. Ask for the shipping before the payment.';
            }
        }

        return $notes;
    }
}

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

/**
 * La compra dentro del chat en el modo por reglas (sin IA): lleva el pedido entero preguntando lo que falta en su orden
 * (contacto, dirección, envío, pago, resumen) y entiende respuestas libres. Usa las mismas herramientas que la IA
 * (checkout_*), así que el pedido que sale es el mismo y los importes salen del carrito.
 *
 * Lo que recuerda entre un mensaje y el siguiente es la clave «flow» del estado de la conversación: active (hay un pedido
 * empezado) y ship / pay (la persona ya eligió el envío y la forma de pago; si no, se le enseñan las opciones aunque el
 * carrito ya traiga una). Todo lo demás se lee del carrito en cada mensaje.
 *
 * NUNCA hace el pedido: tras el resumen espera la confirmación de la persona, y quien la recoge (el botón o un sí claro) es el
 * motor del asistente, antes de que el mensaje llegue aquí.
 */
final class CheckoutDialog
{
    private const RE_CANCEL = '/\b(?:cancelar|cancela|cancelalo|olvidalo|dejalo|anular|anula|no quiero (?:comprar|pedir|seguir|nada)|mejor no|no gracias|cancel|never mind|forget it)\b/';

    private const RE_CONTINUE = '/\b(?:continuar|continua|continuemos|sigamos|seguir|seguimos|reanudar|continue)\b/';

    private const RE_CHANGE = '/\b(?:cambi\w*|modific\w*|corrig\w*|otro|otra|change|another)\b/';

    private const RE_DETAILS = '/\b(?:direccion|domicilio|datos|nombre|telefono|correo|email|mail|codigo postal|address|name|phone)\b/';

    private const RE_SHIPPING_WORD = '/\b(?:envio|transporte|entrega|transportista|shipping|delivery|carrier)\b/';

    private const RE_PAYMENT_WORD = '/\b(?:pago|pagar|payment|pay)\b/';

    /** Palabras de conversación que, con mayúscula, no son un nombre. */
    private const NOT_A_NAME = ['hola', 'buenas', 'buenos', 'gracias', 'vale', 'claro', 'perfecto', 'adelante', 'si', 'no', 'ok', 'okay', 'hello', 'yes', 'thanks', 'thank', 'please', 'por', 'favor', 'dias', 'tardes', 'noches'];

    /** @var ToolRunner */
    private $runner;

    /** @var Answer */
    private $answer;

    /** @var array<string,mixed> */
    private $flow;

    /** @var array<string,string> lo que el cliente pidió del envío (ship) y del pago (pay) antes de que le tocara elegir */
    private $prefs;

    /** @var string el mensaje que se está atendiendo, normalizado: lo que dice del envío o del pago se aplica en cuanto toca elegir */
    private $hint = '';

    /**
     * El mensaje que se está atendiendo (RuleBrain::norm): si ya dice qué envío o qué pago quiere («envío estándar»), no se le vuelve a
     * preguntar cuando llegue ese paso.
     */
    public function withMessage(string $n): self
    {
        $this->hint = $n;

        return $this;
    }

    /**
     * @param array<string,mixed> $state lo que se recordó de la conversación
     */
    public function __construct(ToolRunner $runner, Answer $answer, array $state)
    {
        $this->runner = $runner;
        $this->answer = $answer;
        $this->flow = isset($state['flow']) && is_array($state['flow']) ? $state['flow'] : [];
        $this->prefs = [];
        foreach (isset($state['prefs']) && is_array($state['prefs']) ? $state['prefs'] : [] as $key => $value) {
            if (($key === 'ship' || $key === 'pay') && is_string($value) && $value !== '') {
                $this->prefs[$key] = $value;
            }
        }
    }

    /** ¿Están las herramientas de la caja? (apagadas con «assistant.checkout», o sin el módulo de pedidos, no hay compra en el chat). */
    public function available(): bool
    {
        return $this->runner->can('checkout_status');
    }

    /** ¿Hay un pedido empezado en esta conversación? */
    public function active(): bool
    {
        return !empty($this->flow['active']);
    }

    /** La persona deja el pedido: se cierra el diálogo y el carrito se queda como estaba. */
    public function cancelled(string $n): bool
    {
        if (!$this->active() || preg_match(self::RE_CANCEL, $n) !== 1) {
            return false;
        }
        $this->setFlow('active', 0);
        $this->say(t('Okay, I will not place the order. Your cart is still there.'));
        $this->answer->suggestions[] = t('Show my cart');

        return true;
    }

    public function answerAddress(string $n): bool
    {
        $id = (int) ($this->flow['offered_address'] ?? 0);
        if (!$this->active() || !$id) {
            return false;
        }
        $other = in_array($n, ['otra', 'otra direccion', 'usar otra direccion', 'a otra direccion', 'use another address', 'another address', 'no'], true);
        $yes = in_array($n, ['si', 'si a esa direccion', 'si usa esa direccion', 'a esa direccion', 'yes', 'yes use that address', 'use that address'], true);
        if (!$other && !$yes) {
            return false;
        }
        $status = $this->runner->run('checkout_status', []);
        if (!in_array($id, array_map('intval', array_column($status['saved_addresses'] ?? [], 'id')), true)) {
            $this->setFlow('offered_address', 0);
            $this->step([], true);
            return true;
        }
        $set = $this->runner->run('checkout_set_customer', ['address_id' => $other ? 0 : $id]);
        $this->setFlow('offered_address', 0);
        if ($other || isset($set['error'])) {
            $this->say(t('Write your new delivery address, including the street, postcode and city.'));
            $this->answer->expect = 'customer';
        } else {
            $this->step([t('I will use your saved delivery address.')]);
        }
        $this->answer->actions[] = ['type' => 'private_turn'];
        return true;
    }

    /** «Continuar con mi pedido»: vuelve a lo que falta. */
    public function resumes(string $n): bool
    {
        if (!$this->active() || preg_match(self::RE_CONTINUE, $n) !== 1) {
            return false;
        }
        $this->step([]);

        return true;
    }

    /**
     * Sigue con el pedido por reglas desde donde esté, diciendo lo que ya se ha guardado en esta vuelta: es la salida cuando la IA no
     * llega a un texto (rondas agotadas, respuesta vacía, tope de tiempo) justo en lo más valioso, la compra. Devuelve false si no
     * hay pedido que seguir (carrito vacío).
     *
     * @param string[]            $lead   lo que se dice antes de preguntar lo que falta
     * @param array<string,bool>  $chosen ship y pay a true si ya se eligieron en esta conversación (con IA el diálogo no lo apunta)
     */
    public function continueOrder(array $lead = [], array $chosen = []): bool
    {
        $status = $this->runner->run('checkout_status', []);
        if (isset($status['error'])) {
            return false;
        }
        $this->setFlow('active', 1);
        foreach (['ship', 'pay'] as $key) {
            if (!empty($chosen[$key])) {
                $this->setFlow($key, 1);
            }
        }
        $this->step($lead);

        return true;
    }

    /**
     * Empieza un pedido en el chat. Devuelve false si no hay nada que pedir (carrito vacío): quien llama lo dice a su manera.
     */
    public function start(): bool
    {
        $status = $this->runner->run('checkout_status', []);
        if (isset($status['error'])) {
            return false;
        }
        $this->setFlow('active', 1);
        $this->setFlow('ship', 0);
        $this->setFlow('pay', 0);
        if (!empty($status['needs_shipping']) && !empty($status['saved_addresses'])) {
            $saved = $status['saved_addresses'][0];
            $this->setFlow('offered_address', (int) $saved['id']);
            $this->say(t('Use your saved delivery address in {city}?', ['city' => $saved['city']]));
            $this->answer->actions[] = ['type' => 'private_turn'];
            $this->answer->suggestions = [t('Yes, use that address'), t('Use another address')];
            return true;
        }
        $this->step([], true);

        return true;
    }

    /**
     * Un mensaje dentro de un pedido empezado. Devuelve true si lo ha atendido (datos, envío o pago elegidos, un cambio); si
     * no es de la compra, false, y se contesta como cualquier otro mensaje.
     *
     * @param array<string,mixed> $data     lo que CustomerData sacó del mensaje
     * @param bool                $question el mensaje es una pregunta
     */
    public function handle(string $message, string $n, array $data, bool $question): bool
    {
        if (!$this->active()) {
            return false;
        }
        $this->hint = $n;
        $status = $this->runner->run('checkout_status', []);
        if (isset($status['error'])) {
            $this->setFlow('active', 0);
            $this->say(t('Your cart is empty.') . ' ' . t('Tell me what you are looking for and I will help you.'));

            return true;
        }
        $missing = is_array($status['missing']) ? $status['missing'] : [];
        $needDetails = in_array('contact', $missing, true) || in_array('address', $missing, true);
        // El envío de antes solo cuenta si ya había una dirección: sin ella, el que traiga el carrito no lo ha elegido nadie.
        $beforeShipping = !$needDetails && isset($status['shipping']) && is_array($status['shipping']) ? $status['shipping'] : null;
        $hasData = isset($data['email']) || isset($data['phone']) || isset($data['postcode']) || isset($data['tax_id']) || isset($data['address']);

        if (!$question && !self::browsing($message, $n) && ($needDetails || $hasData)) {
            $set = $this->runner->run('checkout_set_customer', ['text' => $message]);
            $absent = isset($status['missing_fields']) && is_array($status['missing_fields']) ? $status['missing_fields'] : [];
            if (isset($set['error']) && in_array('name', $absent, true)) {
                // Se le acaba de preguntar el nombre y contesta solo con él («Ana Ruiz»): sin otro dato no se entiende como tal.
                $name = $this->nameOf($message, $n);
                if ($name !== null) {
                    $set = $this->runner->run('checkout_set_customer', ['first_name' => $name[0], 'last_name' => $name[1]]);
                }
            }
            if (!isset($set['error'])) {
                $this->step(array_merge($this->customerLines($set), $this->addressLines($set, $beforeShipping)));

                return true;
            }
        }

        if (preg_match(self::RE_CHANGE, $n) === 1 && !$question) {
            if (preg_match(self::RE_DETAILS, $n) === 1) {
                $this->say(t('Of course. Write me the new details (name, address, phone or email) and I will update the order.'));
                $this->answer->expect = 'customer';

                return true;
            }
            if (preg_match(self::RE_SHIPPING_WORD, $n) === 1) {
                $this->setFlow('ship', 0);
                $this->step([]);

                return true;
            }
            if (preg_match(self::RE_PAYMENT_WORD, $n) === 1) {
                $this->setFlow('pay', 0);
                $this->step([]);

                return true;
            }
        }

        if (!$needDetails) {
            $shipPending = empty($this->flow['ship']) || in_array('shipping', $missing, true);
            if ($shipPending) {
                $options = $this->runner->run('checkout_shipping_options', []);
                $choice = $this->pick(isset($options['options']) && is_array($options['options']) ? $options['options'] : [], $n);
                if ($choice !== null) {
                    $set = $this->runner->run('checkout_set_shipping', ['carrier_id' => (int) $choice['id']]);
                    if (!isset($set['error'])) {
                        $this->setFlow('ship', 1);
                        $this->step([]);

                        return true;
                    }
                }

                return false;
            }
            if (empty($this->flow['pay']) || $status['payment'] === null) {
                $options = $this->runner->run('checkout_payment_options', []);
                $choice = $this->pick(isset($options['options']) && is_array($options['options']) ? $options['options'] : [], $n);
                if ($choice !== null) {
                    $set = $this->runner->run('checkout_set_payment', ['method' => (string) $choice['id']]);
                    if (!isset($set['error'])) {
                        $this->setFlow('pay', 1);
                        $this->step([]);

                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** Un mensaje que no era de la compra, con un pedido a medias: se ofrece volver a él. */
    public static function browsing(string $message, string $normal): bool
    {
        return RuleBrain::isQuestion($message, $normal)
            || preg_match('/^(?:mas informacion|more info(?:rmation)?|material(?:es)?|ingredients?|ingredientes|ver (?:mi |el )?carrito|seguir mirando|view my cart|keep browsing|vivo en|i live in|gastos de envio|shipping costs?)\b/', $normal) === 1;
    }

    public function nudge(): void
    {
        if ($this->active()) {
            array_unshift($this->answer->suggestions, t('Continue my order'));
        }
    }

    // ------------------------------------------------------------------ interior

    /**
     * Lo que sigue: pregunta lo que falta, en su orden, o enseña el resumen para confirmar.
     *
     * @param string[] $lead  lo que se dice antes (lo entendido, lo dudoso)
     * @param bool     $intro es la primera pregunta de este pedido
     */
    private function step(array $lead, bool $intro = false): void
    {
        $status = $this->runner->run('checkout_status', []);
        if (isset($status['error'])) {
            $this->setFlow('active', 0);
            $this->say(t('Your cart is empty.') . ' ' . t('Tell me what you are looking for and I will help you.'));

            return;
        }
        $lines = $lead;
        if (!empty($status['over_limit'])) {
            $this->overLimit($lines);

            return;
        }
        $missing = is_array($status['missing']) ? $status['missing'] : [];

        if (in_array('contact', $missing, true) || in_array('address', $missing, true)) {
            if ($intro) {
                $lines[] = t('Let us place your order here in the chat. Write me your name, delivery address (street, postcode and city), phone and email: all in one message or one by one, as you prefer.');
            }
            $fields = is_array($status['missing_fields']) ? $status['missing_fields'] : [];
            if ($fields !== [] && (!$intro || count($fields) < 4)) {
                $lines[] = t('I still need: {list}.', ['list' => implode(', ', array_map([$this, 'fieldLabel'], $fields))]);
            }
            $this->answer->expect = 'customer';
            $this->say(implode("\n", $lines));

            return;
        }

        $info = [];
        if (in_array('shipping', $missing, true)) {
            $lines[] = t('Sorry, we cannot deliver to that address. Change the address or write to us.');
            $this->say(implode("\n", $lines));

            return;
        }
        if (!empty($status['needs_shipping']) && (empty($this->flow['ship']) || $status['shipping'] === null)) {
            $options = $this->runner->run('checkout_shipping_options', []);
            $list = isset($options['options']) && is_array($options['options']) ? $options['options'] : [];
            $choice = isset($this->prefs['ship']) ? Prefs::shipping($this->prefs['ship'], $list) : null;
            if ($choice === null && count($list) > 1 && $this->hint !== '') {
                // Lo dijo en este mismo mensaje («envío estándar»): se aplica, no se le vuelve a preguntar.
                $choice = $this->pick($list, $this->hint, false);
            }
            if ($choice !== null && count($list) > 1) {
                // Lo pidió antes de que le tocara elegir: se aplica y se dice.
                $set = $this->runner->run('checkout_set_shipping', ['carrier_id' => (int) $choice['id']]);
                if (!isset($set['error'])) {
                    $info[] = OrderReply::named(t('I have set the shipping to {name} ({price}), as you asked.'), ['name' => (string) $choice['name'], 'price' => !empty($choice['free']) ? t('Free') : (string) $choice['price']]);
                    $this->answer->prefsUsed[] = 'ship';
                    $this->setFlow('ship', 1);
                    $list = [];
                }
            }
            if (count($list) > 1) {
                $lines[] = t('How would you like it delivered?');
                foreach ($list as $option) {
                    $delay = (string) $option['delay'] !== '' ? ' (' . (string) $option['delay'] . ')' : '';
                    $lines[] = '• ' . (string) $option['name'] . ': ' . (!empty($option['free']) ? t('Free') : (string) $option['price']) . $delay;
                    $this->answer->suggestions[] = (string) $option['name'];
                }
                $this->answer->expect = 'carrier';
                $this->say(implode("\n", $lines));

                return;
            }
            if (count($list) === 1) {
                $set = $this->runner->run('checkout_set_shipping', ['carrier_id' => (int) $list[0]['id']]);
                if (!isset($set['error'])) {
                    $info[] = OrderReply::named(t('Delivery: {name}, {price}.'), ['name' => (string) $list[0]['name'], 'price' => !empty($list[0]['free']) ? t('Free') : (string) $list[0]['price']]);
                }
            }
            $this->setFlow('ship', 1);
        }

        if (empty($this->flow['pay']) || $status['payment'] === null) {
            $options = $this->runner->run('checkout_payment_options', []);
            $list = isset($options['options']) && is_array($options['options']) ? $options['options'] : [];
            $choice = isset($this->prefs['pay']) ? Prefs::payment($this->prefs['pay'], $list) : null;
            if ($choice === null && count($list) > 1 && $this->hint !== '') {
                $choice = $this->pick($list, $this->hint, false);
            }
            if ($choice !== null) {
                $set = $this->runner->run('checkout_set_payment', ['method' => (string) $choice['id']]);
                if (!isset($set['error'])) {
                    $info[] = OrderReply::named(t('I have set the payment to {name}, as you asked.'), ['name' => (string) $choice['name']]);
                    $this->answer->prefsUsed[] = 'pay';
                    $this->setFlow('pay', 1);
                    $list = [];
                }
            }
            if (count($list) > 1) {
                $lines = array_merge($lines, $info);
                $lines[] = t('How would you like to pay?');
                foreach ($list as $option) {
                    $fee = isset($option['fee']) ? ' (+' . (string) $option['fee'] . ')' : '';
                    $lines[] = '• ' . (string) $option['name'] . $fee;
                    $this->answer->suggestions[] = (string) $option['name'];
                }
                $this->answer->expect = 'payment';
                $this->say(implode("\n", $lines));

                return;
            }
            if (count($list) === 1) {
                $set = $this->runner->run('checkout_set_payment', ['method' => (string) $list[0]['id']]);
                if (!isset($set['error'])) {
                    $info[] = OrderReply::named(t('Payment: {name}'), ['name' => (string) $list[0]['name']]);
                }
            }
            $this->setFlow('pay', 1);
        }

        $lines = array_merge($lines, $info);
        $review = $this->runner->run('checkout_review', []);
        if (isset($review['error'])) {
            $lines[] = (string) $review['error'];
            $this->say(implode("\n", $lines));

            return;
        }
        if (!empty($review['over_limit'])) {
            $this->overLimit($lines);

            return;
        }
        if (empty($review['ready'])) {
            $fields = isset($review['missing_fields']) && is_array($review['missing_fields']) ? $review['missing_fields'] : [];
            $lines[] = $fields !== []
                ? t('I still need: {list}.', ['list' => implode(', ', array_map([$this, 'fieldLabel'], $fields))])
                : t('Something is still missing in your order. Write me what you want to change.');
            $this->answer->expect = 'customer';
            $this->say(implode("\n", $lines));

            return;
        }

        $card = null;
        foreach ($this->answer->actions as $action) {
            if (isset($action['type']) && $action['type'] === 'confirm_order') {
                $card = $action;
            }
        }
        $lines[] = $card !== null ? OrderReply::reviewText($card) : t('Do you confirm the order? Press «Confirm order» or write «yes».');
        $this->answer->suggestions[] = t('Yes, I confirm');
        $this->answer->suggestions[] = t('Cancel');
        $this->say(implode("\n", $lines));
    }

    /**
     * @param string[] $lines
     */
    private function overLimit(array $lines): void
    {
        $lines[] = t('This order is above the amount I can take in the chat. Please finish it on the checkout page.');
        $this->runner->run('checkout_link', []);
        $this->setFlow('active', 0);
        $this->say(implode("\n", $lines));
    }

    /**
     * Lo que se contesta a lo que se acaba de guardar: lo entendido, los errores y las dudas.
     *
     * @param array<string,mixed> $set lo que devolvió checkout_set_customer
     *
     * @return string[]
     */
    private function customerLines(array $set): array
    {
        $lines = [];
        $saved = isset($set['saved']) && is_array($set['saved']) ? $set['saved'] : [];
        if ($saved !== []) {
            $lines[] = t('Thanks, I have saved your details.');
        }
        foreach (isset($set['errors']) && is_array($set['errors']) ? $set['errors'] : [] as $message) {
            $lines[] = (string) $message;
        }
        $doubts = isset($set['doubts']) && is_array($set['doubts']) ? $set['doubts'] : [];
        if (isset($doubts['first_name']) || isset($doubts['last_name'])) {
            $lines[] = t('I am not sure I understood your name correctly. Tell me if it is wrong.');
        }
        if (in_array('ambiguous', $doubts, true)) {
            $lines[] = t('I found more than one email or phone; I kept the first one. Check them in the summary.');
        }
        return $lines;
    }

    /**
     * Si cambió el destino del pedido, lo que se dice del envío: sus opciones con su precio en cuanto hay código postal y, si ya
     * había uno elegido, si sigue siendo el mismo («El envío sigue siendo 4,95 €») o cambia.
     *
     * @param array<string,mixed>      $set    lo que devolvió checkout_set_customer
     * @param array<string,mixed>|null $before el envío elegido antes de guardar (name, price) o null
     *
     * @return string[]
     */
    private function addressLines(array $set, ?array $before): array
    {
        $changed = false;
        foreach (isset($set['saved']) && is_array($set['saved']) ? $set['saved'] : [] as $key) {
            if (preg_match('/(?:^|\.)(?:postcode|city|country|region)$/D', (string) $key) === 1 || $key === 'address_id') {
                $changed = true;
                break;
            }
        }
        if (!$changed) {
            return [];
        }
        $lines = [];
        $after = $this->runner->run('checkout_status', []);
        $now = !isset($after['error']) && isset($after['shipping']) && is_array($after['shipping']) ? $after['shipping'] : null;
        if ($before !== null && $now !== null && (string) $now['price'] === (string) $before['price'] && (string) $now['name'] === (string) $before['name']) {
            // El mismo envío al mismo precio: no hace falta volver a elegirlo.
            $lines[] = t('The shipping is still {price}.', ['price' => (string) $now['price']]);

            return $lines;
        }
        $this->setFlow('ship', 0);
        $understood = isset($set['understood']) && is_array($set['understood']) ? $set['understood'] : [];
        $postcode = isset($understood['postcode']) ? trim((string) $understood['postcode']) : '';
        if ($postcode !== '') {
            $quote = $this->runner->run('shipping_quote', ['postcode' => $postcode]);
            $options = [];
            $names = [];
            foreach (isset($quote['options']) && is_array($quote['options']) ? $quote['options'] : [] as $option) {
                $options[] = (string) $option['name'] . ' ' . (!empty($option['free']) ? t('Free') : (string) $option['price']);
                $names[] = (string) $option['name'];
            }
            if ($before !== null && $names !== [] && !in_array((string) $before['name'], $names, true)) {
                // Lo que había elegido ya no vale con la dirección nueva: se dice por qué se le vuelve a preguntar.
                $lines[] = t('{name} does not reach your postcode: please choose another shipping.', ['name' => (string) $before['name']]);
            }
            if ($options !== []) {
                $lines[] = $before !== null
                    ? t('The shipping to this address changes: {options}.', ['options' => implode(', ', $options)])
                    : t('Shipping to {postcode}: {options}.', ['postcode' => $postcode, 'options' => implode(', ', $options)]);
            }
        }

        return $lines;
    }

    /**
     * La opción que la persona ha elegido con sus palabras: por nombre, «el primero», «el segundo» o «el más barato».
     *
     * @param array<int,array<string,mixed>> $options
     *
     * @return array<string,mixed>|null
     */
    private function pick(array $options, string $n, bool $ordinal = true): ?array
    {
        $options = array_values(array_filter($options, 'is_array'));
        if ($options === [] || $n === '') {
            return null;
        }
        $hits = [];
        foreach ($options as $option) {
            $name = RuleBrain::norm((string) (isset($option['name']) ? $option['name'] : ''));
            if ($name !== '' && (strpos($n, $name) !== false || (mb_strlen($n, 'UTF-8') >= 3 && strpos($name, $n) !== false))) {
                $hits[] = $option;
            }
        }
        if (count($hits) === 1) {
            return $hits[0];
        }
        if (count($hits) > 1) {
            return null;
        }
        // Por lo que distingue a cada opción de las demás («Envío estándar (48-72 h)» frente a «Envío urgente (24 h)»: «estándar»).
        $distinct = self::distinctWords($options);
        $named = [];
        foreach ($options as $i => $option) {
            $words = isset($distinct[$i]) ? $distinct[$i] : [];
            if ($words === []) {
                continue;
            }
            $all = true;
            foreach ($words as $word) {
                if (strpos(' ' . $n . ' ', ' ' . $word . ' ') === false) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $named[] = $option;
            }
        }
        if (count($named) === 1) {
            return $named[0];
        }
        if (!$ordinal) {
            // El mensaje era otra cosa (los datos del cliente: una calle con «1» no es «el primero»): solo vale lo que nombra la opción.
            return null;
        }
        if (preg_match('/\b(?:primer[oa]?|1|first)\b/', $n) === 1) {
            return $options[0];
        }
        if (preg_match('/\b(?:segund[oa]|2|second)\b/', $n) === 1 && isset($options[1])) {
            return $options[1];
        }
        if (preg_match('/\b(?:barat[oa]|economic[oa]|cheapest|cheap)\b/', $n) === 1) {
            $best = $options[0];
            foreach ($options as $option) {
                $cents = isset($option['price_cents']) ? (int) $option['price_cents'] : 0;
                if ($cents < (isset($best['price_cents']) ? (int) $best['price_cents'] : 0)) {
                    $best = $option;
                }
            }

            return $best;
        }

        return null;
    }

    /**
     * Las palabras de cada opción (sin lo que va entre paréntesis ni las palabras de menos de tres letras) que no tienen todas las
     * demás: lo que la distingue.
     *
     * @param array<int,array<string,mixed>> $options
     *
     * @return array<int,string[]>
     */
    private static function distinctWords(array $options): array
    {
        $words = [];
        foreach ($options as $i => $option) {
            $name = (string) preg_replace('/\([^)]*\)/u', ' ', (string) (isset($option['name']) ? $option['name'] : ''));
            $list = [];
            foreach (explode(' ', RuleBrain::norm($name)) as $word) {
                if (mb_strlen($word, 'UTF-8') >= 3) {
                    $list[$word] = $word;
                }
            }
            $words[$i] = array_values($list);
        }
        if (count($words) < 2) {
            return [];
        }
        $common = array_intersect(...array_values($words));
        $out = [];
        foreach ($words as $i => $list) {
            $out[$i] = array_values(array_diff($list, $common));
        }

        return $out;
    }

    /**
     * Un mensaje que es solo un nombre con apellido («Ana Ruiz»): dos o más palabras que empiezan por mayúscula, sin cifras ni
     * arrobas y sin palabras de conversación («Vale», «Muchas gracias»). Lo demás no se toma por un nombre.
     *
     * @return array{0:string,1:string}|null nombre y apellidos
     */
    private function nameOf(string $message, string $n): ?array
    {
        $text = trim($message);
        if ($text === '' || mb_strlen($text, 'UTF-8') > 60 || preg_match('/[\d@]/', $text) === 1) {
            return null;
        }
        if (preg_match('/^\p{Lu}[\p{L}\'-]+(?: (?:de|del|la|las|los|y|van|von|da|di|\p{Lu}[\p{L}\'-]+))+$/u', $text) !== 1) {
            return null;
        }
        foreach (explode(' ', $n) as $word) {
            if (in_array($word, self::NOT_A_NAME, true)) {
                return null;
            }
        }
        $split = \Aifec\Assistant\CustomerData::splitName(explode(' ', $text));

        return [$split['first'], $split['last']];
    }

    /** El nombre de un dato que falta, para la persona. */
    public function fieldLabel(string $field): string
    {
        switch ($field) {
            case 'email':
                return t('your email address');
            case 'name':
                return t('your name');
            case 'street':
                return t('the street and number');
            case 'postcode':
                return t('the postcode');
            case 'city':
                return t('the city');
            case 'phone':
                return t('a phone for the delivery');
            case 'vat_id':
                return t('the tax ID');
            case 'country':
                return t('the country');
            default:
                return $field;
        }
    }

    /**
     * @param mixed $value
     */
    private function setFlow(string $key, $value): void
    {
        $this->flow[$key] = $value;
        $this->answer->flow[$key] = $value;
    }

    private function say(string $text): void
    {
        $this->answer->text = $text;
    }
}

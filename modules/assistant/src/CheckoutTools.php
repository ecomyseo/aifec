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

use Aifec\Geo\Spain;
use Aifec\Kernel\App;
use Aifec\Modules\Orders\Checkout\TextReader;

/**
 * Las herramientas de la caja del asistente: la compra entera dentro de la conversación. Todas trabajan sobre el carrito del
 * contexto y a través del motor «checkout» (CheckoutSession), el mismo de la caja de la web: ninguna acepta un importe y ninguna
 * suma nada, todo importe y todo total salen del carrito.
 *
 * Lo que se le devuelve al modelo no lleva datos personales enteros: el correo, el teléfono, el nombre y la calle salen
 * enmascarados (Masker). La dirección completa solo viaja a la tarjeta del resumen que ve la persona, por el CheckoutTurn.
 *
 * El pedido no lo decide el modelo: checkout_review deja la conversación «esperando confirmación» y checkout_place solo vale
 * si la PERSONA ha confirmado en esta vuelta (el botón o un sí claro); sin eso devuelve un error y no hace nada. La ficha de
 * confirmación no pasa nunca por el modelo: la guarda el CheckoutTurn.
 */
final class CheckoutTools
{
    /** Los campos sueltos que acepta checkout_set_customer y su largo máximo. */
    private const FIELDS = [
        'email' => 191, 'first_name' => 80, 'last_name' => 120, 'company' => 160, 'vat_id' => 40, 'phone' => 40,
        'line1' => 191, 'line2' => 191, 'postcode' => 16, 'city' => 100, 'region' => 16, 'country' => 2,
    ];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function definitions(): array
    {
        $object = static function (array $props, array $required = []): array {
            $schema = ['type' => 'object', 'properties' => $props, 'additionalProperties' => false];
            if ($required !== []) {
                $schema['required'] = $required;
            }

            return $schema;
        };
        $fields = [
            'text' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'ONLY if you cannot separate the details into the fields: what the customer wrote about themselves, exactly as they wrote it. When you pass any separate field, text is ignored.'],
        ];
        $about = [
            'email' => 'The email address, nothing else.',
            'first_name' => 'The first name of the person, nothing else. Never a greeting, a yes or a label: "Yes", "Ok", "Hello", "Address" or "Name" are not names.',
            'last_name' => 'The surname or surnames, nothing else.',
            'company' => 'The company name ONLY when a company buys and needs the invoice in its name ("Invoice to company X", "Factura a nombre de X"); then vat_id is required too. The name of an office, hotel or association where the parcel is just delivered goes in line2, not here. Send "" to remove a company saved before.',
            'vat_id' => 'The tax or VAT ID exactly as given (keep a country prefix such as "ES"), without the label.',
            'phone' => 'The phone number, digits and an optional + prefix.',
            'line1' => 'Street and number only ("Calle Mayor 1", "Carrer de l\'Exemple 9"), without the word "address", the floor or the postcode.',
            'line2' => 'Floor, door or staircase only ("3º A", "2n 1a", "bajo"), if given.',
            'postcode' => 'The postcode only.',
            'city' => 'The city or town only.',
            'region' => 'The province or region, only if given.',
            'country' => 'ISO 3166-1 alpha-2 code ("ES" for Spain).',
        ];
        foreach (self::FIELDS as $name => $max) {
            $fields[$name] = ['type' => 'string', 'maxLength' => $max, 'description' => $about[$name] . ' Only if the customer gave it.'];
        }
        $fields['address_id'] = ['type' => 'integer', 'minimum' => 0, 'description' => 'One of the saved_addresses ids of checkout_status (customers with an account)'];
        $fields['note'] = ['type' => 'string', 'maxLength' => 500, 'description' => 'A note for the order, only if the customer asked for one'];

        return [
            'checkout_status' => [
                'description' => 'Where the purchase is: what is still missing (contact, address, shipping, payment, in the order to ask for it), the cart summary and what is already saved. Personal data come masked. Call it before asking the customer anything about their order.',
                'schema' => $object([]),
                'handler' => [$this, 'status'],
                'writes' => false,
            ],
            'checkout_set_customer' => [
                'description' => 'Save the customer\'s details for this order. Pass every detail in its OWN field, already separated: first_name, last_name, email, phone, line1 (street and number), line2 (floor and door), postcode, city, region, country, company and vat_id. Put only the value in each field, never the label or the sentence around it. Use text only as a last resort, when you cannot tell the details apart. It saves what is valid and returns what it understood, what is wrong and what is still missing. Never invent a value: ask for what was not said.',
                'schema' => $object($fields),
                'handler' => [$this, 'setCustomer'],
                'writes' => false,
            ],
            'checkout_shipping_options' => [
                'description' => 'The delivery options for the order with their price and delay, and the one selected. Needs the delivery address first.',
                'schema' => $object([]),
                'handler' => [$this, 'shippingOptions'],
                'writes' => false,
            ],
            'checkout_set_shipping' => [
                'description' => 'Choose the delivery option (carrier_id from checkout_shipping_options).',
                'schema' => $object(['carrier_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The id of the delivery option, from checkout_shipping_options']], ['carrier_id']),
                'handler' => [$this, 'setShipping'],
                'writes' => false,
            ],
            'checkout_payment_options' => [
                'description' => 'The payment methods offered for the order, with their kind (offline: transfer or cash on delivery; redirect: paid online) and any fee, and the one selected.',
                'schema' => $object([]),
                'handler' => [$this, 'paymentOptions'],
                'writes' => false,
            ],
            'checkout_set_payment' => [
                'description' => 'Choose the payment method (method = the id from checkout_payment_options).',
                'schema' => $object(['method' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40, 'description' => 'The id of the payment method, from checkout_payment_options']], ['method']),
                'handler' => [$this, 'setPayment'],
                'writes' => false,
            ],
            'checkout_review' => [
                'description' => 'The exact order that would be placed: lines, discounts, shipping, taxes, the total (in cents and formatted), the delivery address and the payment method. The shop shows the customer a summary with a Confirm button and the conversation waits for their confirmation. Read the total from here: never calculate it. If something is missing it says what.',
                'schema' => $object([]),
                'handler' => [$this, 'review'],
                'writes' => false,
            ],
            'checkout_place' => [
                'description' => 'Place the order. It only works right after the customer confirmed the reviewed order themselves (the Confirm order button or a clear yes), which the shop handles on its own: you cannot confirm for them. Without their confirmation it returns an error and does nothing. Never say the order is placed unless this returned ok.',
                'schema' => $object([]),
                'handler' => [$this, 'place'],
                'writes' => true,
            ],
        ];
    }

    // ------------------------------------------------------------------ lectura

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function status(array $in, array $ctx): array
    {
        $co = $this->engine($ctx);
        if ($co === null) {
            return ['error' => t('The cart is not available')];
        }
        $r = $co->review();
        if (!is_array($r['lines']) || $r['lines'] === []) {
            return ['error' => t('Your cart is empty')];
        }
        $this->remember($ctx, $r);
        $address = $r['needs_shipping'] ? $r['address'] : $r['billing'];
        $address = is_array($address) ? $address : [];
        $units = 0;
        foreach ($r['lines'] as $line) {
            $units += (int) $line['qty'];
        }
        $max = $this->maxOrder();
        $out = [
            'ready' => (bool) $r['ready'],
            'missing' => array_values($r['missing']),
            'missing_fields' => $this->missingFields(is_array($r['errors']) ? $r['errors'] : []),
            'needs_shipping' => (bool) $r['needs_shipping'],
            'cart' => ['count' => $units, 'total' => (string) $r['total_formatted'], 'total_cents' => (int) $r['total']],
            'email' => (string) $r['email'] !== '' ? Masker::email((string) $r['email']) : '',
            'address' => Masker::address($address),
            'shipping' => $r['shipping'] !== null ? ['id' => (int) $r['shipping']['id'], 'name' => (string) $r['shipping']['name'], 'price' => (string) $r['shipping']['price_formatted']] : null,
            'payment' => $r['payment'],
            'saved_addresses' => $this->savedAddresses($co),
            'over_limit' => $max > 0 && (int) $r['total'] > $max,
        ];
        if (!empty($r['errors']['cart'])) {
            $out['problem'] = (string) $r['errors']['cart'];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function shippingOptions(array $in, array $ctx): array
    {
        $co = $this->engine($ctx);
        if ($co === null) {
            return ['error' => t('The cart is not available')];
        }
        $state = $co->state();
        if ($state['lines'] === []) {
            return ['error' => t('Your cart is empty')];
        }
        if (!$state['needs_shipping']) {
            return ['needs_shipping' => false, 'options' => []];
        }
        if (in_array('contact', $state['missing'], true) || in_array('address', $state['missing'], true)) {
            return ['error' => t('I need the delivery address first')];
        }
        $options = [];
        foreach ($state['shipping']['options'] as $option) {
            $options[] = [
                'id' => (int) $option['id'], 'name' => (string) $option['name'], 'delay' => (string) $option['delay'],
                'price' => (string) $option['price_formatted'], 'price_cents' => (int) $option['price'], 'free' => (bool) $option['free'], 'selected' => (bool) $option['selected'],
            ];
        }

        return ['needs_shipping' => true, 'options' => $options, 'selected' => $state['shipping']['selected']];
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function paymentOptions(array $in, array $ctx): array
    {
        $co = $this->engine($ctx);
        if ($co === null) {
            return ['error' => t('The cart is not available')];
        }
        $state = $co->state();
        if ($state['lines'] === []) {
            return ['error' => t('Your cart is empty')];
        }
        $options = [];
        foreach ($state['payment']['options'] as $option) {
            $row = ['id' => (string) $option['id'], 'name' => (string) $option['name'], 'kind' => (string) $option['kind'], 'selected' => (bool) $option['selected']];
            if ((string) $option['description'] !== '') {
                $row['description'] = mb_substr((string) $option['description'], 0, 200, 'UTF-8');
            }
            if ((int) $option['fee'] > 0) {
                $row['fee'] = (string) $option['fee_formatted'];
                $row['fee_cents'] = (int) $option['fee'];
            }
            $options[] = $row;
        }

        return ['options' => $options, 'selected' => $state['payment']['selected']];
    }

    // ------------------------------------------------------------------ escritura

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function setCustomer(array $in, array $ctx): array
    {
        $co = $this->engine($ctx);
        if ($co === null) {
            return ['error' => t('The cart is not available')];
        }
        $turn = self::turn($ctx);
        if ($turn !== null) {
            $turn->disarm();
        }
        $text = isset($in['text']) ? trim((string) $in['text']) : '';
        $fields = [];
        $doubts = [];
        $errors = [];
        $given = [];
        foreach (array_keys(self::FIELDS) as $name) {
            if (isset($in[$name]) && trim((string) $in[$name]) !== '') {
                $given[$name] = trim((string) preg_replace('/\s+/u', ' ', (string) $in[$name]));
            } elseif (in_array($name, ['company', 'vat_id', 'line2'], true) && array_key_exists($name, $in) && trim((string) $in[$name]) === '') {
                // «company: ""» quita la empresa guardada antes (14/11/2026: un agente puso el nombre de una oficina como empresa, la
                // caja le pedía el NIF y no había forma de quitarla).
                $given[$name] = '';
            }
        }
        if ($given !== []) {
            // Los datos que llegan ya separados se validan y se guardan tal cual: NUNCA pasan por el lector de texto libre, y el
            // texto, si viene también, no se lee (solo es el respaldo cuando el modelo no sabe separarlos).
            $fields = $this->checked($given, $errors);
        } elseif ($text !== '') {
            $understood = $co->understand($text);
            $fields = is_array($understood['fields']) ? $understood['fields'] : [];
            $doubts = is_array($understood['doubts']) ? $understood['doubts'] : [];
        }
        $name = trim((isset($fields['first_name']) ? (string) $fields['first_name'] : '') . ' ' . (isset($fields['last_name']) ? (string) $fields['last_name'] : ''));
        $current = $this->currentName($co);
        if ($name !== '' && !self::personName($name)) {
            // «Yes», «Ok», «Hola» o «Address» no son un nombre: no se guarda y se pide.
            unset($fields['first_name'], $fields['last_name']);
            $errors['first_name'] = t('«{value}» is not a name: tell me your first name and surname.', ['value' => mb_substr($name, 0, 40, 'UTF-8')]);
        } elseif ($name !== '' && self::shorterOf($name, $current)) {
            // «lucia navarro» después de «Lucía Navarro Sanz»: se conserva el nombre más completo.
            unset($fields['first_name'], $fields['last_name']);
        }
        if (isset($in['address_id']) && (int) $in['address_id'] > 0) {
            $fields['address_id'] = (int) $in['address_id'];
        }
        if (isset($in['note']) && trim((string) $in['note']) !== '') {
            $fields['note'] = trim((string) $in['note']);
        }
        if ($fields === []) {
            if ($errors !== []) {
                return ['error' => (string) reset($errors), 'errors' => $errors];
            }

            return ['error' => t('I could not find any personal data in that. Ask the customer for them.')];
        }
        if (isset($fields['line1']) && !isset($fields['line2'])) {
            // Una calle nueva sin piso: el piso de la dirección anterior ya no vale.
            $fields['line2'] = '';
        }

        $fill = $co->fill($fields);
        $r = $co->review();
        $this->remember($ctx, $fields);

        $understoodFields = [];
        foreach ($fields as $name => $value) {
            $understoodFields[$name] = $this->maskField((string) $name, is_scalar($value) ? (string) $value : '');
        }
        $out = [
            'saved' => array_values($fill['saved']),
            'understood' => $understoodFields,
            'errors' => $errors + $fill['errors'],
            'missing' => array_values($r['missing']),
            'missing_fields' => $this->missingFields(is_array($r['errors']) ? $r['errors'] : []),
        ];
        if ($doubts !== []) {
            $out['doubts'] = $doubts;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function setShipping(array $in, array $ctx): array
    {
        $co = $this->engine($ctx);
        if ($co === null) {
            return ['error' => t('The cart is not available')];
        }
        $turn = self::turn($ctx);
        if ($turn !== null) {
            $turn->disarm();
        }
        $result = $co->setShipping((int) $in['carrier_id']);
        if (empty($result['ok'])) {
            return ['error' => (string) $result['error']];
        }
        $r = $co->review();

        return ['ok' => true, 'shipping' => $r['shipping'], 'total' => (string) $r['total_formatted'], 'total_cents' => (int) $r['total']];
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function setPayment(array $in, array $ctx): array
    {
        $co = $this->engine($ctx);
        if ($co === null) {
            return ['error' => t('The cart is not available')];
        }
        $turn = self::turn($ctx);
        if ($turn !== null) {
            $turn->disarm();
        }
        $result = $co->setPayment((string) $in['method']);
        if (empty($result['ok'])) {
            return ['error' => (string) $result['error']];
        }
        $r = $co->review();

        return ['ok' => true, 'payment' => $r['payment'], 'total' => (string) $r['total_formatted'], 'total_cents' => (int) $r['total']];
    }

    // ------------------------------------------------------------------ revisar y confirmar

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function review(array $in, array $ctx): array
    {
        $co = $this->engine($ctx);
        if ($co === null) {
            return ['error' => t('The cart is not available')];
        }
        $turn = self::turn($ctx);
        if ($turn !== null) {
            $turn->disarm();
            $turn->card = null;
        }
        $r = $co->review();
        if (!is_array($r['lines']) || $r['lines'] === []) {
            return ['error' => t('Your cart is empty')];
        }
        $this->remember($ctx, $r);
        if (!$r['ready']) {
            return [
                'ready' => false,
                'missing' => array_values($r['missing']),
                'missing_fields' => $this->missingFields(is_array($r['errors']) ? $r['errors'] : []),
                'errors' => $r['errors'],
            ];
        }

        $max = $this->maxOrder();
        if ($max > 0 && (int) $r['total'] > $max) {
            return [
                'ready' => true,
                'over_limit' => true,
                'total' => (string) $r['total_formatted'],
                'total_cents' => (int) $r['total'],
                'checkout_url' => $this->checkoutUrl(),
                'message' => t('This order is above the amount that can be placed in the chat. Send the customer to the checkout page.'),
            ];
        }

        $card = $this->card($r);
        if ($turn !== null && preg_match('/^[a-f0-9]{64}$/D', (string) $r['confirm_token']) === 1) {
            $turn->arm((string) $r['confirm_token'], (int) $r['total']);
            $turn->card = $card;
        }
        $address = $r['needs_shipping'] ? $r['address'] : $r['billing'];
        $address = is_array($address) ? $address : [];

        return [
            'ready' => true,
            'awaiting_confirmation' => $turn !== null,
            'lines' => $r['lines'],
            'discounts' => $r['discounts'],
            'shipping' => $r['shipping'],
            'fees' => $r['fees'],
            'taxes' => $r['taxes'],
            'total' => (string) $r['total_formatted'],
            'total_cents' => (int) $r['total'],
            'currency' => (string) $r['currency'],
            'prices_include_tax' => (bool) $r['prices_include_tax'],
            'email' => Masker::email((string) $r['email']),
            'address' => Masker::address($address),
            'payment' => $r['payment'],
            'terms_url' => $card['terms_url'],
        ];
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function place(array $in, array $ctx): array
    {
        $turn = self::turn($ctx);
        if ($turn === null || !$turn->confirmed()) {
            // La herramienta no recibe la ficha del modelo y no puede confirmar por la persona. El intento no hace nada y la
            // espera sigue como estaba: el resumen que se le enseñó a la persona sigue valiendo.
            return ['error' => t('Ask the customer to confirm the order first: it is only placed when they press the Confirm button or write a clear yes.')];
        }
        $co = $this->engine($ctx);
        if ($co === null) {
            $turn->disarm();

            return ['error' => t('The cart is not available')];
        }
        $token = $turn->token();
        $total = $turn->total();
        $turn->consume();

        $max = $this->maxOrder();
        if ($max > 0 && $total > $max) {
            return ['error' => t('This order is above the amount that can be placed in the chat. Send the customer to the checkout page.'), 'checkout_url' => $this->checkoutUrl()];
        }
        $conversation = isset($ctx['conversation_id']) ? (int) $ctx['conversation_id'] : 0;
        $result = $co->place($token, [
            'source' => 'assistant',
            'ip' => $this->ip(),
            'accept_terms' => true,
            'marketing' => false,
            'attribution' => ['channel' => 'assistant', 'conversation_id' => $conversation],
        ]);
        if (empty($result['ok'])) {
            $failed = ['error' => (string) $result['error']];
            if (isset($result['id']) && (int) $result['id'] > 0) {
                // El pedido llegó a crearse pero el cobro no pudo empezar: queda fallido y no es el desenlace de la conversación.
                $failed['failed_order_id'] = (int) $result['id'];
            }

            return $failed;
        }

        $pay = is_array($result['payment']) ? $result['payment'] : ['type' => 'none'];
        $type = isset($pay['type']) ? (string) $pay['type'] : 'none';
        $text = $type === 'instructions' && isset($pay['text']) ? ShopTools::plain((string) $pay['text'], 1500) : '';
        $turn->placed = [
            'id' => (int) $result['id'],
            'number' => (string) $result['number'],
            'order_url' => (string) $result['order_url'],
            'total_cents' => $total,
            'total' => $this->fmt($total),
            'payment' => [
                'type' => $type, 'text' => $text,
                'url' => $type === 'redirect' && isset($pay['url']) ? (string) $pay['url'] : '',
                'method' => isset($pay['method']) ? (string) $pay['method'] : 'get',
                'fields' => $type === 'redirect' && isset($pay['fields']) && is_array($pay['fields']) ? $pay['fields'] : [],
            ],
        ];

        return [
            'ok' => true,
            'number' => (string) $result['number'],
            'total' => $this->fmt($total),
            'total_cents' => $total,
            'payment_type' => $type,
            'pending_payment' => $type !== 'none',
            'payment_instructions' => $text,
            'order_page' => $this->abs((string) $result['order_url']),
            'cart' => ['count' => 0, 'total' => $this->fmt(0), 'total_cents' => 0],
        ];
    }

    // ------------------------------------------------------------------ piezas

    /**
     * El motor de la caja sobre el carrito del contexto (o el de la petición si no se da).
     *
     * @param array<string,mixed> $ctx
     */
    private function engine(array $ctx): ?object
    {
        if (!$this->app->has('checkout')) {
            return null;
        }
        $co = $this->app->service('checkout');
        if (!is_object($co)) {
            return null;
        }
        $cart = isset($ctx['cart']) && is_object($ctx['cart']) ? $ctx['cart'] : null;
        if ($cart !== null && method_exists($co, 'forCart') && $this->app->has('cart') && $cart !== $this->app->service('cart')) {
            return $co->forCart($cart);
        }

        return $co;
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private static function turn(array $ctx): ?CheckoutTurn
    {
        return isset($ctx['checkout_turn']) && $ctx['checkout_turn'] instanceof CheckoutTurn ? $ctx['checkout_turn'] : null;
    }

    /**
     * Los datos que llegan ya separados, comprobados uno a uno (el correo y el código postal los vuelve a comprobar la caja al
     * guardarlos): el NIF como se dio, sin espacios, puntos ni guiones; el teléfono en sus cifras; el país en mayúsculas. Lo que no
     * vale no se guarda y queda en $errors con la clave de su campo.
     *
     * @param array<string,string> $given
     * @param array<string,string> $errors
     *
     * @return array<string,string>
     */
    private function checked(array $given, array &$errors): array
    {
        $out = [];
        foreach ($given as $key => $value) {
            switch ($key) {
                case 'email':
                    $out[$key] = strtolower($value);
                    break;
                case 'vat_id':
                    $out[$key] = strtoupper((string) preg_replace('/[\s.\-]+/u', '', $value));
                    break;
                case 'phone':
                    $digits = (string) preg_replace('/\D+/', '', $value);
                    $spanish = Spain::normalizePhone($value);
                    if ($spanish !== '') {
                        $out[$key] = $spanish;
                    } elseif (strlen($digits) >= 6 && strlen($digits) <= 15 && preg_match('/^\+?[\d\s().\-]+$/D', $value) === 1) {
                        $out[$key] = (string) preg_replace('/[^\d+]+/', '', $value);
                    } else {
                        $errors['phone'] = t('Enter a valid phone number');
                    }
                    break;
                case 'country':
                    $out[$key] = strtoupper($value);
                    break;
                case 'postcode':
                    $out[$key] = strtoupper((string) preg_replace('/\s+/', '', $value));
                    break;
                default:
                    $out[$key] = $value;
            }
        }

        return $out;
    }

    /** El nombre que ya tiene guardado la entrega de este pedido («Lucía Navarro Sanz»), o una cadena vacía. */
    private function currentName(object $co): string
    {
        try {
            $state = $co->state();
        } catch (\Throwable $e) {
            return '';
        }
        $address = isset($state['address']) && is_array($state['address']) ? $state['address'] : [];

        return trim((isset($address['first_name']) ? (string) $address['first_name'] : '') . ' ' . (isset($address['last_name']) ? (string) $address['last_name'] : ''));
    }

    /**
     * ¿Puede ser el nombre de una persona? El mismo criterio que el lector de la caja: «Yes», «Ok», «Hola», «Address» o «Name» no lo son.
     */
    private static function personName(string $name): bool
    {
        if (class_exists(TextReader::class)) {
            return TextReader::personName($name);
        }

        return trim($name) !== '' && preg_match('/[\d@]/', $name) !== 1;
    }

    /**
     * ¿Es el nombre nuevo una forma más corta del que ya hay? «lucia navarro» frente a «Lucía Navarro Sanz»: todas sus palabras están
     * en el guardado, sin tildes ni mayúsculas, y el guardado tiene más. Entonces se queda el guardado.
     */
    private static function shorterOf(string $new, string $current): bool
    {
        $fold = static function (string $text): array {
            $words = preg_split('/[^\p{L}\']+/u', RuleBrain::norm($text), -1, PREG_SPLIT_NO_EMPTY);

            return is_array($words) ? $words : [];
        };
        $a = $fold($new);
        $b = $fold($current);

        return $a !== [] && count($b) > count($a) && array_diff($a, $b) === [];
    }

    /** El tope de un pedido por el chat, en céntimos (0 = sin tope). */
    private function maxOrder(): int
    {
        return max(0, $this->app->settings()->int('assistant.max_order_cents', 0));
    }

    /**
     * Apunta lo personal de esta vuelta para enmascararlo al guardar los mensajes.
     *
     * @param array<string,mixed> $source una revisión del motor o los campos recién entendidos
     * @param array<string,mixed> $ctx
     */
    private function remember(array $ctx, array $source): void
    {
        $turn = self::turn($ctx);
        if ($turn === null) {
            return;
        }
        $values = [];
        foreach (['email'] as $key) {
            if (isset($source[$key]) && is_scalar($source[$key])) {
                $values[] = (string) $source[$key];
            }
        }
        $addresses = [$source];
        foreach (['address', 'billing'] as $key) {
            if (isset($source[$key]) && is_array($source[$key])) {
                $addresses[] = $source[$key];
            }
        }
        foreach ($addresses as $address) {
            foreach (['first_name', 'last_name', 'company', 'vat_id', 'phone', 'line1', 'line2'] as $key) {
                if (isset($address[$key]) && is_scalar($address[$key]) && trim((string) $address[$key]) !== '') {
                    $values[] = trim((string) $address[$key]);
                }
            }
            if (isset($address['first_name'], $address['last_name']) && is_scalar($address['first_name']) && is_scalar($address['last_name'])) {
                $values[] = trim((string) $address['first_name'] . ' ' . (string) $address['last_name']);
            }
        }
        foreach ($values as $value) {
            if ($value !== '' && !in_array($value, $turn->personal, true)) {
                $turn->personal[] = $value;
            }
        }
    }

    /**
     * Lo que falta, con los nombres que entiende el modelo y el diálogo: email, name, street, postcode, city, vat_id y country.
     *
     * @param array<string,string> $errors las claves de error del motor (email, shipping.line1, billing.vat_id…)
     *
     * @return string[]
     */
    private function missingFields(array $errors): array
    {
        $map = ['email' => 'email', 'first_name' => 'name', 'line1' => 'street', 'postcode' => 'postcode', 'city' => 'city', 'phone' => 'phone', 'vat_id' => 'vat_id', 'country' => 'country'];
        $found = [];
        foreach (array_keys($errors) as $key) {
            $field = (string) $key;
            $pos = strrpos($field, '.');
            $field = $pos === false ? $field : substr($field, $pos + 1);
            if (isset($map[$field])) {
                $found[$map[$field]] = true;
            }
        }
        $out = [];
        foreach (array_values($map) as $name) {
            if (isset($found[$name])) {
                $out[] = $name;
            }
        }

        return $out;
    }

    private function maskField(string $name, string $value): string
    {
        if ($value === '') {
            return '';
        }
        switch ($name) {
            case 'email':
                return Masker::email($value);
            case 'phone':
                return Masker::phone($value);
            case 'vat_id':
                return Masker::id($value);
            case 'first_name':
            case 'last_name':
            case 'company':
            case 'line1':
            case 'line2':
                return Masker::words($value);
            default:
                return $value;
        }
    }

    /**
     * Las direcciones guardadas de quien ha entrado (vacío para un invitado), enmascaradas.
     *
     * @return array<int,array<string,mixed>>
     */
    private function savedAddresses(object $co): array
    {
        $out = [];
        $saved = $co->savedAddresses();
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $masked = Masker::address($row);
            $out[] = ['id' => (int) $row['id'], 'name' => $masked['name'], 'line1' => $masked['line1'], 'postcode' => $masked['postcode'], 'city' => $masked['city']];
        }

        return array_slice($out, 0, 5);
    }

    /**
     * El resumen del pedido para pintar: lo ve la persona (por eso lleva la dirección entera) y no pasa por el modelo.
     *
     * @param array<string,mixed> $r lo que devuelve CheckoutSession::review()
     *
     * @return array<string,mixed>
     */
    private function card(array $r): array
    {
        $lines = [];
        foreach ($r['lines'] as $line) {
            $name = (string) $line['name'] . ((string) $line['variant_label'] !== '' ? ' (' . (string) $line['variant_label'] . ')' : '');
            $lines[] = ['name' => $name, 'qty' => (int) $line['qty'], 'total' => (string) $line['total_formatted']];
        }
        $discounts = [];
        foreach ($r['discounts'] as $discount) {
            $label = (string) $discount['label'] !== '' ? (string) $discount['label'] : (string) $discount['code'];
            $discounts[] = ['label' => $label, 'amount' => '-' . (string) $discount['amount_formatted']];
        }
        $fees = [];
        foreach ($r['fees'] as $fee) {
            $fees[] = ['label' => (string) $fee['label'], 'amount' => (string) $fee['amount_formatted']];
        }
        $taxes = [];
        foreach ($r['taxes'] as $tax) {
            $taxes[] = ['name' => (string) $tax['name'], 'amount' => (string) $tax['amount_formatted']];
        }
        $address = $r['needs_shipping'] ? $r['address'] : $r['billing'];
        $address = is_array($address) ? $address : [];
        $get = static function (string $key) use ($address): string {
            return isset($address[$key]) && is_scalar($address[$key]) ? trim((string) $address[$key]) : '';
        };
        $place = trim($get('postcode') . ' ' . $get('city'));
        $rows = array_values(array_filter([trim($get('line1') . ' ' . $get('line2')), $place, $get('country')], static function (string $line): bool {
            return $line !== '';
        }));
        // La factura: la empresa y el NIF de la dirección de facturación (la misma de entrega si no se ha dado otra).
        $billing = is_array($r['billing']) ? $r['billing'] : [];
        $bill = static function (string $key) use ($billing): string {
            return isset($billing[$key]) && is_scalar($billing[$key]) ? trim((string) $billing[$key]) : '';
        };

        return [
            'type' => 'confirm_order',
            'lines' => $lines,
            'discounts' => $discounts,
            'shipping' => $r['shipping'] !== null ? ['name' => (string) $r['shipping']['name'], 'price' => (string) $r['shipping']['price_formatted']] : null,
            'fees' => $fees,
            'taxes' => $taxes,
            'total' => (string) $r['total_formatted'],
            'total_cents' => (int) $r['total'],
            'prices_include_tax' => (bool) $r['prices_include_tax'],
            'name' => trim($get('first_name') . ' ' . $get('last_name')),
            'address' => $rows,
            'company' => $bill('company'),
            'vat_id' => $bill('vat_id'),
            'email' => (string) $r['email'],
            'phone' => $get('phone'),
            'payment' => $r['payment'] !== null ? (string) $r['payment']['name'] : '',
            'terms_url' => $this->termsUrl(),
        ];
    }

    /** La página de condiciones de venta de la tienda, con su dominio, o vacío si no hay una elegida que exista. */
    private function termsUrl(): string
    {
        $id = $this->app->settings()->int('orders.terms_page_id', 0);
        if ($id < 1) {
            return '';
        }
        try {
            $slugs = $this->app->slugs();
            if ($slugs->path('page', $id, $this->app->lang()) === null && $slugs->path('page', $id, $this->app->defaultLang()) === null) {
                return '';
            }

            return $this->abs($this->app->url()->entity('page', $id));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function checkoutUrl(): string
    {
        try {
            return $this->abs($this->app->url()->route('checkout'));
        } catch (\Throwable $e) {
            return $this->abs($this->app->url()->to('/' . trim($this->app->settings()->str('urls.checkout', 'finalizar-compra'), '/')));
        }
    }

    private function ip(): string
    {
        try {
            $ip = $this->app->request()->ip;

            return is_string($ip) ? $ip : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function fmt(int $cents): string
    {
        $currency = strtoupper(trim($this->app->settings()->str('shop.currency', 'EUR')));

        return $this->app->i18n()->money($cents, preg_match('/^[A-Z]{3}$/D', $currency) === 1 ? $currency : 'EUR');
    }

    private function abs(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if ($url[0] === '/' && substr($url, 0, 2) !== '//') {
            return $this->app->url()->origin() . $url;
        }

        return $url;
    }
}

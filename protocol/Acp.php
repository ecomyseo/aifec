<?php
/**
 * ACP 2026-04-17: conversión del contrato de compra sobre una caja UCP.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   AFL-3.0
 */

/** Sin dependencias de AIFEC ni de PrestaShop; también se distribuye con ecom_aifec. */
final class AifecCommerceAcp
{
    const VERSION = '2026-04-17';

    /** Descubrimiento de esta implementación; el contrato de checkout utiliza la versión fechada. */
    public static function discovery($apiBaseUrl, $currency)
    {
        return array(
            'protocol' => array('name' => 'acp', 'version' => self::VERSION, 'supported_versions' => array(self::VERSION)),
            'api_base_url' => rtrim((string) $apiBaseUrl, '/'),
            'feed_url' => rtrim((string) $apiBaseUrl, '/') . '/acp/feed.jsonl', 'checkout_sessions_url' => rtrim((string) $apiBaseUrl, '/') . '/checkout_sessions',
            'endpoints' => array(
                'create' => array('method' => 'POST', 'url' => rtrim((string) $apiBaseUrl, '/') . '/checkout_sessions'),
                'retrieve' => array('method' => 'GET', 'url' => rtrim((string) $apiBaseUrl, '/') . '/checkout_sessions/{id}'),
                'update' => array('method' => 'POST', 'url' => rtrim((string) $apiBaseUrl, '/') . '/checkout_sessions/{id}'),
                'complete' => array('method' => 'POST', 'url' => rtrim((string) $apiBaseUrl, '/') . '/checkout_sessions/{id}/complete'),
                'cancel' => array('method' => 'POST', 'url' => rtrim((string) $apiBaseUrl, '/') . '/checkout_sessions/{id}/cancel'),
            ),
            'transports' => array('rest'),
            'capabilities' => array('services' => array('checkout'), 'supported_currencies' => array(strtolower((string) $currency))),
        );
    }

    /** Convierte únicamente los campos del comprador: los precios siempre los calcula la tienda. */
    public static function request(array $body, $operation)
    {
        self::validateBody($body, $operation);
        $out = array();
        if (isset($body['line_items']) || isset($body['items'])) {
            $lines = isset($body['line_items']) ? $body['line_items'] : $body['items'];
            $out['line_items'] = array();
            $indexes = array();
            foreach ($lines as $line) {
                $quantity = isset($line['quantity']) ? (int) $line['quantity'] : 1;
                if (isset($indexes[$line['id']])) {
                    $index = $indexes[$line['id']];
                    $out['line_items'][$index]['quantity'] += $quantity;
                    if ($out['line_items'][$index]['quantity'] > 999) {
                        throw new InvalidArgumentException('The combined item quantity exceeds 999.');
                    }
                } else {
                    $indexes[$line['id']] = count($out['line_items']);
                    $out['line_items'][] = array('item' => array('id' => $line['id']), 'quantity' => $quantity);
                }
            }
        }
        if (isset($body['buyer'])) {
            $out['buyer'] = self::buyer($body['buyer']);
        }
        if (isset($body['currency'])) {
            $out['currency'] = strtoupper($body['currency']);
        }
        $method = array('id' => 'shipping_1', 'type' => 'shipping');
        if (isset($body['fulfillment_details'])) {
            $details = $body['fulfillment_details'];
            if (isset($details['email']) && !isset($out['buyer']['email'])) {
                $out['buyer']['email'] = $details['email'];
            }
            if (isset($details['phone_number']) && !isset($out['buyer']['phone_number'])) {
                $out['buyer']['phone_number'] = $details['phone_number'];
            }
            if (isset($details['address'])) {
                $destination = self::address($details['address']);
                if (isset($details['name']) && !isset($destination['first_name'])) {
                    $destination += self::name($details['name']);
                }
                if (isset($details['phone_number'])) {
                    $destination['phone_number'] = $details['phone_number'];
                }
                $method['destinations'] = array(array('id' => 'dest_1', 'type' => 'shipping_address') + $destination);
                $method['selected_destination_id'] = 'dest_1';
            }
        }
        if (isset($body['selected_fulfillment_options'][0])) {
            $selected = $body['selected_fulfillment_options'][0];
            $option = isset($selected['option_id']) ? $selected['option_id'] : $selected['shipping']['option_id'];
            $method['groups'] = array(array('id' => 'package_1', 'selected_option_id' => $option));
        }
        if (count($method) > 2) {
            $out['fulfillment'] = array('methods' => array($method));
        }
        if (isset($body['discounts'])) {
            $out['discounts'] = array('codes' => isset($body['discounts']['codes']) ? array_values($body['discounts']['codes']) : array());
        } elseif (isset($body['coupons'])) {
            $out['discounts'] = array('codes' => array_values($body['coupons']));
        }

        return $out;
    }

    /** Las cantidades de estos catálogos son enteras: jamás se redondea una cantidad pedida. */
    public static function validateBody(array $body, $operation)
    {
        if (array_key_exists('currency', $body) && (!is_string($body['currency']) || !preg_match('/^[A-Za-z]{3}$/D', $body['currency']))) {
            throw new InvalidArgumentException('currency must be an ISO 4217 code.');
        }
        if ($operation === 'create') {
            if (!isset($body['line_items']) && !isset($body['items'])) {
                throw new InvalidArgumentException('line_items is required.');
            }
            if (!isset($body['currency']) || !is_string($body['currency']) || !preg_match('/^[A-Za-z]{3}$/D', $body['currency'])) {
                throw new InvalidArgumentException('currency must be an ISO 4217 code.');
            }
            if (!array_key_exists('capabilities', $body) || !is_array($body['capabilities'])) {
                throw new InvalidArgumentException('capabilities must be an object.');
            }
        }
        if (isset($body['line_items'], $body['items'])) {
            throw new InvalidArgumentException('Use line_items or the legacy items field, never both.');
        }
        foreach (array('line_items', 'items') as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $lines = $body[$field];
            if (!is_array($lines) || array_values($lines) !== $lines || count($lines) > 50 || ($operation === 'create' && count($lines) === 0)) {
                throw new InvalidArgumentException($field . ' must contain between one and 50 items.');
            }
            foreach ($lines as $line) {
                if (!is_array($line) || !isset($line['id']) || !is_string($line['id']) || !preg_match('/^[0-9]{1,12}(?:-[0-9]{1,12})?$/D', $line['id'])) {
                    throw new InvalidArgumentException('Each line item needs a valid catalogue id.');
                }
                if (array_key_exists('quantity', $line) && (!is_int($line['quantity']) || $line['quantity'] < 1 || $line['quantity'] > 999)) {
                    throw new InvalidArgumentException('This shop accepts integer quantities from one to 999.');
                }
            }
        }
        foreach (array('buyer', 'fulfillment_details', 'payment_data', 'discounts') as $field) {
            if (array_key_exists($field, $body) && !is_array($body[$field])) {
                throw new InvalidArgumentException($field . ' must be an object.');
            }
        }
        if (isset($body['buyer'])) {
            foreach (array('email', 'first_name', 'last_name', 'full_name', 'phone_number') as $key) {
                self::stringField($body['buyer'], $key, 191);
            }
            if (array_key_exists('company', $body['buyer'])) {
                $company = $body['buyer']['company'];
                if (!is_array($company) || !isset($company['name']) || !is_string($company['name'])) {
                    throw new InvalidArgumentException('buyer.company needs its company name.');
                }
                foreach (array('name', 'tax_id', 'department', 'cost_center') as $key) {
                    self::stringField($company, $key, 255);
                }
            }
        }
        if (isset($body['fulfillment_details'])) {
            $details = $body['fulfillment_details'];
            foreach (array('name', 'email', 'phone_number') as $key) {
                self::stringField($details, $key, 191);
            }
            if (isset($details['address'])) {
                self::validateAddress($details['address']);
            }
        }
        if (isset($body['selected_fulfillment_options'])) {
            $options = $body['selected_fulfillment_options'];
            if (!is_array($options) || array_values($options) !== $options || count($options) > 1) {
                throw new InvalidArgumentException('This shop accepts one fulfillment option per checkout.');
            }
            foreach ($options as $option) {
                $id = isset($option['option_id']) ? $option['option_id'] : (isset($option['shipping']['option_id']) ? $option['shipping']['option_id'] : null);
                if (!is_array($option) || !isset($option['type']) || $option['type'] !== 'shipping' || !is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $id)) {
                    throw new InvalidArgumentException('A shipping option needs its option_id.');
                }
            }
        }
        if (isset($body['fulfillment_groups']) && $body['fulfillment_groups'] !== array()) {
            throw new InvalidArgumentException('Multiple fulfillment groups are not supported by this shop.');
        }
        $codes = isset($body['discounts']['codes']) ? $body['discounts']['codes'] : (isset($body['coupons']) ? $body['coupons'] : null);
        if ($codes !== null) {
            if (!is_array($codes) || array_values($codes) !== $codes || count($codes) > 10) {
                throw new InvalidArgumentException('Discount codes must be a list with at most ten codes.');
            }
            foreach ($codes as $code) {
                if (!is_string($code) || strlen($code) > 100) {
                    throw new InvalidArgumentException('A discount code must be a string of at most 100 bytes.');
                }
            }
        }
    }

    public static function response(array $ucp)
    {
        $statuses = array('ready_for_complete' => 'ready_for_payment', 'incomplete' => 'not_ready_for_payment');
        $status = isset($ucp['status']) ? (string) $ucp['status'] : 'not_ready_for_payment';
        $out = array(
            'id' => isset($ucp['id']) ? (string) $ucp['id'] : '', 'protocol' => array('version' => self::VERSION),
            'status' => isset($statuses[$status]) ? $statuses[$status] : $status,
            'currency' => strtolower(isset($ucp['currency']) ? (string) $ucp['currency'] : ''),
            'line_items' => array(), 'totals' => self::totals(isset($ucp['totals']) && is_array($ucp['totals']) ? $ucp['totals'] : array()),
            'fulfillment_options' => array(), 'messages' => array(), 'links' => array(),
            'capabilities' => array('payment' => array('handlers' => self::handlers($ucp))),
        );
        foreach (isset($ucp['line_items']) && is_array($ucp['line_items']) ? $ucp['line_items'] : array() as $line) {
            $item = isset($line['item']) && is_array($line['item']) ? $line['item'] : array();
            $converted = array(
                'id' => (string) $line['id'],
                'item' => array('id' => isset($item['id']) ? (string) $item['id'] : ''),
                'quantity' => (int) $line['quantity'],
                'totals' => self::totals(isset($line['totals']) && is_array($line['totals']) ? $line['totals'] : array()),
            );
            if (isset($item['title'])) {
                $converted['item']['name'] = (string) $item['title'];
                $converted['name'] = (string) $item['title'];
            }
            if (isset($item['price'])) {
                $converted['item']['unit_amount'] = (int) $item['price'];
                $converted['unit_amount'] = (int) $item['price'];
            }
            if (isset($item['image_url']) && is_string($item['image_url']) && $item['image_url'] !== '') {
                $converted['images'] = array($item['image_url']);
            }
            $out['line_items'][] = $converted;
        }
        if (isset($ucp['buyer']) && is_array($ucp['buyer']) && isset($ucp['buyer']['email'])) {
            $out['buyer'] = array_intersect_key($ucp['buyer'], array_flip(array('first_name', 'last_name', 'email', 'phone_number')));
            if (isset($ucp['buyer']['company']) && is_array($ucp['buyer']['company']) && isset($ucp['buyer']['company']['name'])) {
                $out['buyer']['company'] = array_intersect_key($ucp['buyer']['company'], array_flip(array('name', 'tax_id', 'department', 'cost_center')));
            }
        }
        foreach (array('continue_url', 'expires_at', 'created_at', 'updated_at') as $key) {
            if (isset($ucp[$key]) && is_string($ucp[$key]) && $ucp[$key] !== '') {
                $out[$key] = $ucp[$key];
            }
        }
        foreach (isset($ucp['links']) && is_array($ucp['links']) ? $ucp['links'] : array() as $link) {
            if (!is_array($link) || !isset($link['type'], $link['url'])) {
                continue;
            }
            $type = $link['type'] === 'terms_of_service' ? 'terms_of_use' : (string) $link['type'];
            if (in_array($type, array('terms_of_use', 'privacy_policy', 'return_policy', 'shipping_policy', 'contact_us', 'about_us', 'faq', 'support'), true)) {
                $out['links'][] = array('type' => $type, 'url' => (string) $link['url']);
            }
        }
        foreach (isset($ucp['messages']) && is_array($ucp['messages']) ? $ucp['messages'] : array() as $message) {
            $out['messages'][] = self::message($message);
        }
        $method = isset($ucp['fulfillment']['methods'][0]) && is_array($ucp['fulfillment']['methods'][0]) ? $ucp['fulfillment']['methods'][0] : array();
        foreach (isset($method['groups']) && is_array($method['groups']) ? $method['groups'] : array() as $group) {
            foreach (isset($group['options']) && is_array($group['options']) ? $group['options'] : array() as $option) {
                $entry = array('type' => 'shipping', 'id' => (string) $option['id'], 'title' => isset($option['title']) ? (string) $option['title'] : (string) $option['id'], 'totals' => self::totals(isset($option['totals']) && is_array($option['totals']) ? $option['totals'] : array()));
                if (isset($option['description'])) {
                    $description = $option['description'];
                    $entry['description'] = is_string($description) ? $description : (isset($description['text']) ? (string) $description['text'] : '');
                }
                $out['fulfillment_options'][] = $entry;
            }
            if (isset($group['selected_option_id'])) {
                $out['selected_fulfillment_options'][] = array('type' => 'shipping', 'option_id' => (string) $group['selected_option_id'], 'item_ids' => isset($group['line_item_ids']) && is_array($group['line_item_ids']) ? array_values($group['line_item_ids']) : array());
            }
        }
        if (isset($ucp['order']) && is_array($ucp['order'])) {
            $order = $ucp['order'];
            $out['order'] = array('id' => isset($order['id']) ? (string) $order['id'] : '', 'checkout_session_id' => $out['id'], 'permalink_url' => isset($order['permalink_url']) ? (string) $order['permalink_url'] : '');
            if (isset($order['label'])) {
                $out['order']['order_number'] = (string) $order['label'];
            }
        }

        return $out;
    }

    public static function error($code, $message, $status = 400)
    {
        return array('type' => $status >= 500 ? ($status === 503 ? 'service_unavailable' : 'processing_error') : 'invalid_request', 'code' => (string) $code, 'message' => (string) $message);
    }

    private static function stringField(array $in, $key, $limit)
    {
        if (array_key_exists($key, $in) && (!is_string($in[$key]) || strlen($in[$key]) > $limit)) {
            throw new InvalidArgumentException($key . ' must be a bounded string.');
        }
    }

    private static function validateAddress($address)
    {
        if (!is_array($address)) {
            throw new InvalidArgumentException('The address must be an object.');
        }
        foreach (array('name', 'line_one', 'line_two', 'city', 'state', 'country', 'postal_code') as $key) {
            self::stringField($address, $key, 191);
        }
    }

    private static function buyer(array $buyer)
    {
        $out = array_intersect_key($buyer, array_flip(array('first_name', 'last_name', 'email', 'phone_number')));
        if (isset($buyer['full_name'])) {
            $out += self::name($buyer['full_name']);
        }
        if (isset($buyer['company']) && is_array($buyer['company'])) {
            $out['company'] = array_intersect_key($buyer['company'], array_flip(array('name', 'tax_id', 'department', 'cost_center')));
        }

        return $out;
    }

    private static function name($name)
    {
        $parts = preg_split('/\s+/u', trim((string) $name), 2);

        return array('first_name' => isset($parts[0]) ? $parts[0] : '', 'last_name' => isset($parts[1]) ? $parts[1] : '');
    }

    private static function address(array $address)
    {
        $out = isset($address['name']) ? self::name($address['name']) : array();
        foreach (array('line_one' => 'street_address', 'line_two' => 'extended_address', 'city' => 'address_locality', 'state' => 'address_region', 'country' => 'address_country', 'postal_code' => 'postal_code') as $from => $to) {
            if (isset($address[$from])) {
                $out[$to] = $address[$from];
            }
        }

        return $out;
    }

    private static function totals(array $totals)
    {
        $out = array();
        foreach ($totals as $total) {
            if (is_array($total) && isset($total['type'], $total['amount'])) {
                $out[] = array('type' => (string) $total['type'], 'amount' => (int) $total['amount'], 'display_text' => isset($total['display_text']) ? (string) $total['display_text'] : ucfirst(str_replace('_', ' ', (string) $total['type'])));
            }
        }

        return $out;
    }

    private static function handlers(array $ucp)
    {
        $out = array();
        $groups = isset($ucp['ucp']['payment_handlers']) && is_array($ucp['ucp']['payment_handlers']) ? $ucp['ucp']['payment_handlers'] : array();
        foreach ($groups as $name => $handlers) {
            foreach (is_array($handlers) ? $handlers : array() as $handler) {
                if (!is_array($handler) || !isset($handler['id'], $handler['spec'], $handler['version'], $handler['schema'], $handler['config'])) {
                    continue;
                }
                $gateway = isset($handler['config']['allowed_payment_methods'][0]['tokenization_specification']['parameters']['gateway']) ? $handler['config']['allowed_payment_methods'][0]['tokenization_specification']['parameters']['gateway'] : $name;
                $out[] = array('id' => (string) $handler['id'], 'name' => (string) $name, 'version' => (string) $handler['version'], 'spec' => (string) $handler['spec'], 'requires_delegate_payment' => false, 'requires_pci_compliance' => false, 'psp' => (string) $gateway, 'config_schema' => (string) $handler['schema'], 'instrument_schemas' => array(), 'config' => $handler['config']);
            }
        }

        return $out;
    }

    private static function message(array $in)
    {
        $code = isset($in['code']) ? (string) $in['code'] : 'invalid';
        $map = array('missing_buyer_info' => 'missing', 'missing_fulfillment_info' => 'missing', 'payment_ineligible' => 'unsupported', 'item_unavailable' => 'out_of_stock', 'quantity_invalid_limit_exceeded' => 'quantity_exceeded', 'amount_limit_exceeded' => 'maximum_exceeded', 'address_undeliverable' => 'invalid');
        $allowed = array('missing', 'invalid', 'out_of_stock', 'payment_declined', 'requires_sign_in', 'requires_3ds', 'low_stock', 'quantity_exceeded', 'coupon_invalid', 'coupon_expired', 'minimum_not_met', 'maximum_exceeded', 'region_restricted', 'age_verification_required', 'approval_required', 'unsupported', 'not_found', 'conflict', 'rate_limited', 'expired', 'intervention_required');
        $code = isset($map[$code]) ? $map[$code] : (in_array($code, $allowed, true) ? $code : 'invalid');
        $out = array('type' => 'error', 'code' => $code, 'content_type' => 'plain', 'content' => isset($in['content']) ? (string) $in['content'] : 'The checkout needs attention.', 'resolution' => isset($in['severity']) && $in['severity'] === 'unrecoverable' ? 'requires_buyer_review' : 'recoverable');
        if (isset($in['path']) && is_string($in['path'])) {
            $out['param'] = $in['path'];
        }

        return $out;
    }
}

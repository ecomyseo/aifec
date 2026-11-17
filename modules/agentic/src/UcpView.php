<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Agentic;

use Aifec\Kernel\App;

/**
 * La sesión de compra de UCP tal como la ve la plataforma: líneas, comprador, entrega, totales, avisos, enlaces y estado.
 *
 * Todo sale de la caja de la tienda (los mismos precios, impuestos, envíos y avisos que en la web). Los importes van en
 * céntimos. Con los precios con IVA incluido (España) el subtotal ya lleva el impuesto y no hay línea de impuestos aparte,
 * que es como lo pide UCP para esos mercados.
 */
final class UcpView
{
    public const INCOMPLETE = 'incomplete';
    public const ESCALATION = 'requires_escalation';
    public const READY = 'ready_for_complete';
    public const COMPLETED = 'completed';
    public const CANCELED = 'canceled';

    /**
     * La sesión de ahora, con el carrito de la petición.
     *
     * @param array<string,mixed>            $row      la fila de ucp_checkouts
     * @param array<int,array<string,mixed>> $messages avisos que añade quien llama (lo que no se ha podido aplicar)
     *
     * @return array<string,mixed>
     */
    public static function live(App $app, array $row, array $messages = []): array
    {
        $co = $app->service('checkout');
        $summary = $app->service('cart')->summary();
        $state = $co->state();
        $review = $co->review();

        $amounts = [];
        foreach ($review['lines'] as $line) {
            $amounts[(string) $line['key']] = $line;
        }
        $lines = [];
        $ids = [];
        $subtotal = 0;
        foreach (array_values($summary['lines']) as $i => $line) {
            $key = (string) $line['key'];
            $shown = isset($amounts[$key]) ? $amounts[$key] : ['unit_price' => 0, 'total' => 0];
            $id = 'line_' . ($i + 1);
            $ids[] = $id;
            $title = (string) $line['name'] . ((string) $line['variant_label'] !== '' ? ' - ' . (string) $line['variant_label'] : '');
            $item = ['id' => (int) $line['product_id'] . ((int) $line['variant_id'] > 0 ? '-' . (int) $line['variant_id'] : ''), 'title' => $title, 'price' => (int) $shown['unit_price']];
            $image = self::imageOf($app, $line['image']);
            if ($image !== '') {
                $item['image_url'] = $image;
            }
            $total = (int) $shown['total'];
            $subtotal += $total;
            $lines[] = ['id' => $id, 'item' => $item, 'quantity' => (int) $line['qty'], 'totals' => [['type' => 'subtotal', 'amount' => $total], ['type' => 'total', 'amount' => $total]]];
        }

        $gross = !empty($summary['display_with_tax']);
        $totals = [['type' => 'subtotal', 'amount' => $subtotal] + ($gross ? ['display_text' => t('Subtotal (including taxes)')] : [])];
        foreach ($review['discounts'] as $discount) {
            $label = (string) $discount['label'] !== '' ? (string) $discount['label'] : (string) $discount['code'];
            $totals[] = ['type' => 'discount', 'amount' => -(int) $discount['amount'], 'display_text' => $label];
        }
        if (is_array($review['shipping'])) {
            $totals[] = ['type' => 'fulfillment', 'amount' => (int) $review['shipping']['price'], 'display_text' => (string) $review['shipping']['name']];
        }
        foreach ($review['fees'] as $fee) {
            $totals[] = ['type' => 'fee', 'amount' => (int) $fee['amount'], 'display_text' => (string) $fee['label']];
        }
        if (!$gross) {
            $tax = 0;
            foreach ($review['taxes'] as $t) {
                $tax += (int) $t['amount'];
            }
            $totals[] = ['type' => 'tax', 'amount' => $tax];
        }
        $totals[] = ['type' => 'total', 'amount' => (int) $review['total']];

        $missing = array_values(array_diff($state['missing'], ['payment']));
        foreach ($missing as $what) {
            $fields = $what === 'address' ? self::addressMessages($review['errors']) : [];
            if ($fields !== []) {
                // Cada dato que falta de la entrega, con su campo de UCP (el teléfono, que lo exige el transporte, también).
                $messages = array_merge($messages, $fields);
                continue;
            }
            $messages[] = self::missingMessage($what);
        }
        foreach ($state['problems'] as $problem) {
            $messages[] = ['type' => 'error', 'code' => 'item_unavailable', 'content' => (string) $problem, 'severity' => 'recoverable'];
        }
        if ($lines === [] || $missing !== [] || $state['problems'] !== []) {
            $status = self::INCOMPLETE;
        } elseif (!Ucp::canPay($app)) {
            $status = self::ESCALATION;
            $messages[] = ['type' => 'warning', 'code' => 'payment_ineligible', 'content' => t('This shop takes the payment on its own checkout page: open the continue address to pay.'), 'severity' => 'recoverable'];
        } else {
            $status = self::READY;
        }

        $view = [
            'ucp' => Ucp::envelope($app),
            'id' => (string) $row['public_id'],
            'status' => $status,
            'currency' => (string) $review['currency'],
            'line_items' => $lines,
            'buyer' => self::buyer($row, $state),
            'totals' => $totals,
            'messages' => self::unique($messages),
            'links' => Ucp::links($app),
            'continue_url' => self::continueUrl($app, (string) $row['public_id']),
        ];
        if (!empty($state['needs_shipping'])) {
            $view['fulfillment'] = self::fulfillment($state, $ids);
        }

        return $view;
    }

    /**
     * Una sesión terminada (pedido hecho o cancelada): la foto que se guardó, con su estado y el pedido.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public static function stored(App $app, array $row): array
    {
        $snapshot = is_string($row['snapshot']) && $row['snapshot'] !== '' ? json_decode($row['snapshot'], true) : null;
        $view = is_array($snapshot) ? $snapshot : ['id' => (string) $row['public_id'], 'currency' => '', 'line_items' => [], 'totals' => []];
        $view = ['ucp' => Ucp::envelope($app)] + $view;
        $view['status'] = (string) $row['status'] === UcpCheckouts::COMPLETED ? self::COMPLETED : self::CANCELED;
        $view['messages'] = [];
        unset($view['continue_url']);
        if ($view['status'] === self::COMPLETED && (string) $row['order_number'] !== '') {
            $view['order'] = [
                'id' => (string) $row['order_number'],
                'label' => (string) $row['order_number'],
                'permalink_url' => (string) $row['order_url'],
            ];
        }

        return $view;
    }

    /** La dirección que abre esta compra en la caja de la tienda, en el navegador de la persona. */
    public static function continueUrl(App $app, string $id): string
    {
        return $app->url()->origin() . $app->url()->to('/ucp/continue/' . UcpCheckouts::cartToken($id), [], '');
    }

    /**
     * @param array<string,mixed> $state
     * @param string[]            $ids
     *
     * @return array<string,mixed>
     */
    private static function fulfillment(array $state, array $ids): array
    {
        $address = is_array($state['address']) ? $state['address'] : [];
        $text = static function (string $key) use ($address): string {
            return isset($address[$key]) && is_scalar($address[$key]) ? trim((string) $address[$key]) : '';
        };
        $method = ['id' => 'shipping_1', 'type' => 'shipping', 'line_item_ids' => $ids, 'destinations' => []];
        if ($text('line1') !== '') {
            $destination = ['id' => 'dest_1', 'type' => 'shipping_address'];
            foreach ([
                'first_name' => 'first_name', 'last_name' => 'last_name', 'street_address' => 'line1', 'extended_address' => 'line2',
                'address_locality' => 'city', 'address_region' => 'region', 'postal_code' => 'postcode', 'address_country' => 'country', 'phone_number' => 'phone',
            ] as $ucp => $ours) {
                if ($text($ours) !== '') {
                    $destination[$ucp] = $text($ours);
                }
            }
            $method['destinations'][] = $destination;
            $method['selected_destination_id'] = 'dest_1';
        }
        $options = [];
        $selected = null;
        foreach ($state['shipping']['options'] as $option) {
            $entry = ['id' => 'carrier_' . (int) $option['id'], 'title' => (string) $option['name'], 'totals' => [['type' => 'total', 'amount' => (int) $option['price']]]];
            if ((string) $option['delay'] !== '') {
                $entry['description'] = (string) $option['delay'];
            }
            $options[] = $entry;
            if (!empty($option['selected'])) {
                $selected = $entry['id'];
            }
        }
        $group = ['id' => 'package_1', 'line_item_ids' => $ids, 'options' => $options];
        if ($selected !== null) {
            $group['selected_option_id'] = $selected;
        }
        $method['groups'] = [$group];

        return ['methods' => [$method]];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $state
     *
     * @return array<string,string>|\stdClass
     */
    private static function buyer(array $row, array $state)
    {
        $saved = is_string($row['buyer']) && $row['buyer'] !== '' ? json_decode($row['buyer'], true) : null;
        $buyer = [];
        foreach (is_array($saved) ? $saved : [] as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $buyer[$key] = $value;
            }
        }
        $email = isset($state['contact']['email']) ? (string) $state['contact']['email'] : '';
        if ($email !== '') {
            $buyer['email'] = $email;
        }

        return $buyer === [] ? new \stdClass() : $buyer;
    }

    /** @return array<string,string> */
    private static function missingMessage(string $what): array
    {
        if ($what === 'contact') {
            return ['type' => 'error', 'code' => 'missing_buyer_info', 'path' => '$.buyer.email', 'content' => t('The email of the buyer is required'), 'severity' => 'recoverable'];
        }
        if ($what === 'shipping') {
            return ['type' => 'error', 'code' => 'missing_fulfillment_info', 'path' => '$.fulfillment.methods[0].groups[0].selected_option_id', 'content' => t('Choose a delivery option'), 'severity' => 'recoverable'];
        }

        return ['type' => 'error', 'code' => 'missing_fulfillment_info', 'path' => '$.fulfillment.methods[0].destinations', 'content' => t('The delivery address is required'), 'severity' => 'recoverable'];
    }

    /**
     * Los datos de la entrega que faltan o no valen, uno por campo de UCP, con el aviso de la caja.
     *
     * @param mixed $errors los errores de la caja (campo => aviso)
     *
     * @return array<int,array<string,string>>
     */
    private static function addressMessages($errors): array
    {
        $paths = [
            'first_name' => 'first_name', 'last_name' => 'last_name', 'line1' => 'street_address', 'postcode' => 'postal_code',
            'city' => 'address_locality', 'country' => 'address_country', 'region' => 'address_region', 'phone' => 'phone_number',
        ];
        $out = [];
        foreach (is_array($errors) ? $errors : [] as $key => $text) {
            if (preg_match('/^(?:shipping|billing)\.([a-z0-9_]+)$/D', (string) $key, $m) !== 1 || !isset($paths[$m[1]])) {
                continue;
            }
            $out[] = [
                'type' => 'error', 'code' => 'missing_fulfillment_info', 'path' => '$.fulfillment.methods[0].destinations[0].' . $paths[$m[1]],
                'content' => (string) $text, 'severity' => 'recoverable',
            ];
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     *
     * @return array<int,array<string,mixed>>
     */
    private static function unique(array $messages): array
    {
        $seen = [];
        $out = [];
        foreach ($messages as $message) {
            $key = (string) json_encode($message);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $message;
            }
        }

        return $out;
    }

    /** @param mixed $image */
    private static function imageOf(App $app, $image): string
    {
        $url = '';
        if (is_string($image)) {
            $url = $image;
        } elseif (is_array($image)) {
            foreach (['url', 'src', 'large', 'medium'] as $key) {
                if (isset($image[$key]) && is_string($image[$key]) && $image[$key] !== '') {
                    $url = $image[$key];
                    break;
                }
            }
        }

        return $url === '' ? '' : Ucp::absolute($app, $url);
    }
}

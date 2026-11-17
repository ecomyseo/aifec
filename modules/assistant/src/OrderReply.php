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
 * Los textos y las acciones de la compra dentro del chat que no dependen de quién los escribe: el resumen del pedido para
 * confirmar y lo que se le dice a la persona cuando el pedido ya está hecho. Los usan el modo por reglas y el motor del asistente
 * (que hace el pedido sin pasar por la IA cuando la persona confirma).
 */
final class OrderReply
{
    /**
     * El resumen del pedido en texto, con la tarjeta que prepara checkout_review.
     *
     * @param array<string,mixed> $card
     */
    public static function reviewText(array $card): string
    {
        $out = [t('This is your order:')];
        foreach (is_array($card['lines']) ? $card['lines'] : [] as $line) {
            $out[] = '• ' . (int) $line['qty'] . ' × ' . (string) $line['name'] . ' — ' . (string) $line['total'];
        }
        foreach (is_array($card['discounts']) ? $card['discounts'] : [] as $discount) {
            $out[] = t('Discount {label}: {amount}', ['label' => (string) $discount['label'], 'amount' => (string) $discount['amount']]);
        }
        if (is_array($card['shipping'])) {
            $out[] = self::named(t('Shipping: {name}, {price}'), ['name' => (string) $card['shipping']['name'], 'price' => (string) $card['shipping']['price']]);
        }
        foreach (is_array($card['fees']) ? $card['fees'] : [] as $fee) {
            $out[] = (string) $fee['label'] . ': ' . (string) $fee['amount'];
        }
        $out[] = t('Total: {total}', ['total' => (string) $card['total']]);
        $where = array_merge([(string) $card['name']], is_array($card['address']) ? array_map('strval', $card['address']) : []);
        $where = array_values(array_filter($where, static function (string $part): bool {
            return trim($part) !== '';
        }));
        if ($where !== []) {
            $out[] = t('Deliver to: {address}', ['address' => implode(', ', $where)]);
        }
        $invoice = self::invoiceLine($card);
        if ($invoice !== '') {
            $out[] = $invoice;
        }
        if ((string) $card['payment'] !== '') {
            $out[] = self::named(t('Payment: {name}'), ['name' => (string) $card['payment']]);
        }
        $out[] = t('Do you confirm the order? Press «Confirm order» or write «yes».');
        $out[] = t('By confirming you accept the terms and conditions.');

        return implode("\n", $out);
    }

    /**
     * El resumen del pedido siempre dice a dónde va: si la respuesta lleva la tarjeta de confirmar y su texto no trae la dirección
     * (la IA no puede escribirla), se le añade la línea «Entregar en» con el nombre y la dirección completa, tal como la dio la persona:
     * es quien la acaba de escribir en esta conversación. Y si en esta respuesta se cambió la dirección, también dice a dónde va ahora y
     * el envío que sale con ella.
     *
     * @param bool $changed se guardó una dirección nueva en esta respuesta
     */
    public static function withDelivery(Answer $answer, bool $changed): void
    {
        $card = null;
        foreach ($answer->actions as $action) {
            if (isset($action['type']) && $action['type'] === 'confirm_order') {
                $card = $action;
            }
        }
        if ($card === null) {
            return;
        }
        $rows = array_values(array_filter(is_array($card['address']) ? array_map('strval', $card['address']) : [], static function (string $part): bool {
            return trim($part) !== '';
        }));
        $address = implode(', ', $rows);
        $where = trim(implode(', ', array_filter(array_merge([(string) $card['name']], $rows), static function (string $part): bool {
            return trim($part) !== '';
        })));
        if ($where === '') {
            return;
        }
        $text = rtrim($answer->text);
        $had = $address !== '' && strpos($text, $address) !== false;
        if ($changed && !$had && $address !== '') {
            $line = t('It now goes to {address}.', ['address' => $address]);
            if (is_array($card['shipping'])) {
                $line .= ' ' . self::named(t('Shipping {name}: {price}'), ['name' => (string) $card['shipping']['name'], 'price' => (string) $card['shipping']['price']]);
            }
            $text .= ($text !== '' ? "\n" : '') . $line;
        } elseif (!$had) {
            $text .= ($text !== '' ? "\n" : '') . t('Deliver to: {address}', ['address' => $where]);
        }
        // Y a quién se factura, si hay empresa o NIF: el modelo no lo escribe (son datos personales).
        $invoice = self::invoiceLine($card);
        $company = isset($card['company']) ? trim((string) $card['company']) : '';
        if ($invoice !== '' && strpos($text, $invoice) === false && ($company === '' || strpos($text, $company) === false)) {
            $text .= "\n" . $invoice;
        }
        $answer->text = $text;
    }

    /**
     * La línea de la factura del resumen: «Factura a: Berg Studio SL · NIF ESB00000000». Vacía si el pedido no lleva ni empresa
     * ni NIF (se factura a la persona de la entrega y no hace falta decirlo).
     *
     * @param array<string,mixed> $card
     */
    public static function invoiceLine(array $card): string
    {
        $company = isset($card['company']) && is_scalar($card['company']) ? trim((string) $card['company']) : '';
        $vat = isset($card['vat_id']) && is_scalar($card['vat_id']) ? trim((string) $card['vat_id']) : '';
        if ($company === '' && $vat === '') {
            return '';
        }
        $parts = [$company !== '' ? $company : trim((string) (isset($card['name']) ? $card['name'] : ''))];
        if ($vat !== '') {
            $parts[] = t('Tax ID {id}', ['id' => $vat]);
        }

        return t('Invoice to: {who}', ['who' => implode(' · ', array_filter($parts, static function (string $part): bool {
            return $part !== '';
        }))]);
    }

    /**
     * Una plantilla ya traducida con «{name}» en la que la palabra de delante no se repite si el nombre empieza por ella: con
     * «Envío: {name}» y «Envío estándar» sale «Envío estándar», no «Envío: Envío estándar»; con «He puesto el envío {name}», «He
     * puesto el Envío estándar». Vale en los dos idiomas porque mira la plantilla traducida.
     *
     * @param array<string,string> $vars name y lo demás que lleve la plantilla
     */
    public static function named(string $template, array $vars): string
    {
        $name = isset($vars['name']) ? (string) $vars['name'] : '';
        $pos = strpos($template, '{name}');
        if ($pos !== false && $name !== '') {
            $before = substr($template, 0, $pos);
            if (preg_match('/(\p{L}+)[\s:]*$/u', $before, $m, PREG_OFFSET_CAPTURE) === 1) {
                $words = explode(' ', RuleBrain::norm($name));
                if (RuleBrain::norm($m[1][0]) === $words[0]) {
                    $template = substr($before, 0, (int) $m[0][1]) . substr($template, $pos);
                }
            }
        }
        $replace = [];
        foreach ($vars as $key => $value) {
            $replace['{' . $key . '}'] = (string) $value;
        }

        return strtr($template, $replace);
    }

    /**
     * Lo que se contesta cuando se ha intentado hacer el pedido (por el botón o por un sí claro): el resultado de la
     * herramienta checkout_place y lo que dejó el pedido en el CheckoutTurn.
     *
     * @param array<string,mixed> $result lo que devolvió checkout_place
     */
    public static function placed(Answer $answer, array $result, CheckoutTurn $turn): void
    {
        if (isset($result['error'])) {
            $answer->text = (string) $result['error'];
            $answer->suggestions[] = t('Show my cart');
            if (isset($result['checkout_url']) && (string) $result['checkout_url'] !== '') {
                $answer->actions[] = ['type' => 'checkout', 'url' => (string) $result['checkout_url']];
                $answer->checkout = true;
            }

            return;
        }
        $placed = is_array($turn->placed) ? $turn->placed : [];
        $payment = isset($placed['payment']) && is_array($placed['payment']) ? $placed['payment'] : ['type' => 'none'];
        $lines = [t('Done! Your order {number} is placed. Total: {total}.', ['number' => (string) $result['number'], 'total' => (string) $result['total']])];
        $type = (string) $payment['type'];
        if ($type === 'instructions') {
            // Vale igual para una transferencia (se paga ahora) que para un contra reembolso (se paga al recibir): no dice «esperando».
            $lines[] = t('This is how you pay it:');
            if ((string) $payment['text'] !== '') {
                $lines[] = (string) $payment['text'];
            }
        } elseif ($type === 'redirect') {
            $lines[] = t('The order is made and it is pending payment: press «Pay now» to pay it.');
            $action = ['type' => 'pay', 'url' => (string) $payment['url'], 'method' => (string) $payment['method']];
            if ($action['method'] === 'post') {
                $action['fields'] = $payment['fields'];
            }
            $answer->actions[] = $action;
        } else {
            $lines[] = t('Thank you for your order.');
        }
        $lines[] = t('You can follow it on your order page.');
        $answer->text = implode("\n", $lines);
        $answer->actions[] = ['type' => 'order', 'url' => (string) (isset($placed['order_url']) ? $placed['order_url'] : '')];
        $answer->checkout = true;
        $answer->flow = ['active' => 0];
    }
}

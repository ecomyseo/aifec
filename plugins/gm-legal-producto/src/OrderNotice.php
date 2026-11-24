<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\LegalProducto;

use Aifec\Kernel\App;

/**
 * Lo que el pedido lleva de este plugin: los textos legales en el correo (soporte duradero) y la tarjeta de la ficha del pedido.
 *
 * Los textos van DENTRO del correo de confirmación del pedido, como bloques (filtro «order.mail.blocks»): solo los marcados para el
 * correo y lo que el cliente contestó a la renuncia. Al ponerlos ahí se apunta en `gm_legal_orders.mailed_at`, y entonces no sale
 * el correo aparte («legal_notice»), que queda solo de recambio para cuando el correo del pedido no los llevó. Un correo que no sale
 * (sin servicio de correo, modo silencioso) no se marca como enviado.
 */
final class OrderNotice
{
    /**
     * El filtro «order.mail.blocks»: los textos legales y la respuesta a la renuncia, en el correo de confirmación del pedido.
     *
     * @param mixed $blocks   los bloques que ya hay
     * @param mixed $order    el pedido completo
     * @param mixed $template el nombre de la plantilla
     *
     * @return mixed
     */
    public static function blocks($blocks, $order, $template, App $app)
    {
        if (!is_array($blocks) || $template !== 'order_confirmed' || !is_array($order) || !isset($order['id']) || $app->silenced()) {
            return $blocks;
        }
        $rows = self::mailRows($order, $app);
        $db = $app->db();
        $orderId = (int) $order['id'];
        $state = $db->row('SELECT waiver, mailed_at, kind, text, created_at FROM {gm_legal_orders} WHERE order_id = ?', [$orderId]);
        if ($rows === [] && $state === null) {
            return $blocks;
        }
        if ($state === null) {
            $db->insertIgnore('gm_legal_orders', ['order_id' => $orderId, 'waiver' => null, 'version' => '', 'created_at' => $app->clock()->sql()]);
        }
        // Se marca como mandado con una sola sentencia: así, cuando llegue el aviso de pago, no sale otro correo aparte.
        $db->run('UPDATE {gm_legal_orders} SET mailed_at = ? WHERE order_id = ? AND mailed_at IS NULL', [$app->clock()->sql(), $orderId]);
        foreach (Blocks::view($rows) as $block) {
            $blocks[] = ['title' => $block['title'], 'lines' => $block['paragraphs']];
        }
        $lines = self::waiverLines($app, $state);
        if ($lines !== []) {
            $blocks[] = ['title' => t('Right of withdrawal'), 'lines' => $lines];
        }

        return $blocks;
    }

    /**
     * La confirmación de lo que el cliente contestó a la casilla, con la fecha y el texto exacto que vio: lo que el vendedor tiene que
     * confirmarle por escrito. Vacío si no se le preguntó nada.
     *
     * @param array<string,mixed>|null $state la fila de gm_legal_orders (waiver, kind, text, created_at)
     *
     * @return string[]
     */
    public static function waiverLines(App $app, ?array $state): array
    {
        if ($state === null || !isset($state['waiver']) || $state['waiver'] === null) {
            return [];
        }
        $kind = isset($state['kind']) && is_string($state['kind']) && $state['kind'] !== '' ? $state['kind'] : Checkout::DOWNLOAD;
        $text = isset($state['text']) && is_string($state['text']) && $state['text'] !== '' ? $state['text'] : Checkout::text($app, $kind);
        $date = substr((string) ($state['created_at'] ?? ''), 0, 16) . ' UTC';
        $download = $kind === Checkout::DOWNLOAD || $kind === Checkout::BOTH;
        $service = $kind === Checkout::SERVICE || $kind === Checkout::BOTH;
        $lines = [];
        if ((int) $state['waiver'] === 1) {
            $lines[] = t('On {date} you ticked the box:', ['date' => $date]);
            $lines[] = '«' . $text . '»';
            if ($download) {
                $lines[] = t('So the download starts now and you lose your right of withdrawal on it once it starts.');
            }
            if ($service) {
                $lines[] = t('So the service starts now and, if you withdraw after it has started, you will pay the proportional part of what has been provided.');
            }

            return $lines;
        }
        $lines[] = t('On {date} you did not tick the box:', ['date' => $date]);
        $lines[] = '«' . $text . '»';
        $lines[] = t('You did not waive your right of withdrawal, so you keep it.');
        if ($download) {
            $lines[] = t('The download is held while your right of withdrawal lasts. It is released on {date}, or earlier if you write to the shop and ask for it to start now.', ['date' => date('Y-m-d', DownloadGate::releaseAt($app, (string) ($state['created_at'] ?? '')))]);
        }

        return $lines;
    }

    /**
     * Los bloques marcados para el correo que corresponden a lo que lleva el pedido.
     *
     * @param array<string,mixed> $order
     *
     * @return array<int,array<string,mixed>>
     */
    private static function mailRows(array $order, App $app): array
    {
        $productIds = [];
        foreach (isset($order['items']) && is_array($order['items']) ? $order['items'] : [] as $item) {
            if (is_array($item) && isset($item['kind'], $item['product_id']) && $item['kind'] === 'product') {
                $productIds[] = (int) $item['product_id'];
            }
        }

        return array_values(array_filter((new Blocks($app))->forProducts($productIds), static function (array $row): bool {
            return (int) $row['in_mail'] === 1;
        }));
    }

    /**
     * La acción «order.paid»: el correo aparte, de recambio. Si el correo del pedido ya llevó los textos (se apuntó en mailed_at),
     * no hace nada.
     *
     * @param mixed $order
     */
    public static function paid($order, App $app): void
    {
        if (!is_array($order) || !isset($order['id']) || !$app->has('mailer') || $app->silenced()) {
            return;
        }
        $productIds = [];
        foreach (isset($order['items']) && is_array($order['items']) ? $order['items'] : [] as $item) {
            if (is_array($item) && isset($item['kind'], $item['product_id']) && $item['kind'] === 'product') {
                $productIds[] = (int) $item['product_id'];
            }
        }
        $rows = array_values(array_filter((new Blocks($app))->forProducts($productIds), static function (array $row): bool {
            return (int) $row['in_mail'] === 1;
        }));
        $db = $app->db();
        $orderId = (int) $order['id'];
        $state = $db->row('SELECT waiver, mailed_at, kind, text, created_at FROM {gm_legal_orders} WHERE order_id = ?', [$orderId]);
        if ($rows === [] && $state === null) {
            return;
        }
        if ($state === null) {
            $db->insertIgnore('gm_legal_orders', ['order_id' => $orderId, 'waiver' => null, 'version' => '', 'created_at' => $app->clock()->sql()]);
        }
        // Se marca antes de enviar, con una sola sentencia: dos avisos de pago a la vez no mandan dos correos.
        if ($db->run('UPDATE {gm_legal_orders} SET mailed_at = ? WHERE order_id = ? AND mailed_at IS NULL', [$app->clock()->sql(), $orderId]) !== 1) {
            return;
        }
        $sent = 0;
        try {
            $billing = isset($order['billing']) && is_array($order['billing']) ? $order['billing'] : [];
            $name = trim((isset($billing['first_name']) ? (string) $billing['first_name'] : '') . ' ' . (isset($billing['last_name']) ? (string) $billing['last_name'] : ''));
            $waiver = $state !== null && $state['waiver'] !== null ? ((int) $state['waiver'] === 1 ? 'yes' : 'no') : '';
            $waiverLines = self::waiverLines($app, $state);
            $sent = (int) $app->service('mailer')->queue('legal_notice', (string) $order['email'], [
                'number' => (string) $order['number'],
                'name' => $name !== '' ? $name : (string) $order['email'],
                'blocks' => Blocks::view($rows),
                'waiver' => $waiver,
                'waiver_lines' => $waiverLines,
            ], ['locale' => (string) $order['lang'], 'to_name' => $name, 'related' => 'order:' . $orderId]);
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'gm-legal-producto.mail');
        }
        if ($sent < 1) {
            $db->update('gm_legal_orders', ['mailed_at' => null], ['order_id' => $orderId]);
        }
    }

    /**
     * El hueco «admin.order.side»: qué contestó el cliente a la renuncia al desistimiento.
     *
     * @param mixed $order
     */
    public static function side($order, App $app): string
    {
        if (!is_array($order) || !isset($order['id'])) {
            return '';
        }
        $row = $app->db()->row('SELECT waiver, version, created_at, mailed_at FROM {gm_legal_orders} WHERE order_id = ?', [(int) $order['id']]);
        if ($row === null || $row['waiver'] === null) {
            return '';
        }

        return $app->view()->render('gm-legal-producto::admin/order-side', [
            'waiver' => (int) $row['waiver'] === 1, 'version' => (string) $row['version'], 'at' => (string) $row['created_at'],
            'mailed' => $row['mailed_at'] !== null ? (string) $row['mailed_at'] : '',
        ]);
    }
}

<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders;

use Aifec\Kernel\App;

/**
 * Los correos de un pedido (plantillas declaradas en emails.php, vistas en views/emails/).
 *
 * Se componen en el idioma del pedido (columna lang), no en el del empleado que pulsa el botón. A la cola del correo
 * solo va lo imprescindible del pedido (sin cliente, sin historial, sin datos de cobro): el contenido se vacía solo
 * con el tiempo, pero mientras dura no tiene por qué llevar más de lo que ve el destinatario.
 *
 * Un fallo del correo (dirección inválida, servicio caído) se anota y nunca tumba lo que se estaba haciendo: un cobro
 * confirmado no se deshace porque no salga el aviso.
 */
final class OrderMails
{
    /** Los bloques de plugins que caben en un correo, y las líneas de cada uno. */
    public const MAX_BLOCKS = 6;
    public const MAX_LINES = 12;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Encola un correo al cliente del pedido. Devuelve el id del mensaje, o 0 si no hay servicio de correo, está en
     * modo silencioso o ha fallado.
     *
     * @param array<string,mixed> $order  el pedido completo (Orders::find)
     * @param array<string,mixed> $extra  datos de la plantilla: instructions, downloads, refund…
     * @param string              $secret ficha en claro del pedido (solo si se acaba de crear): entra en el enlace
     */
    public function send(array $order, string $template, array $extra = [], string $secret = ''): int
    {
        if (!$this->app->has('mailer')) {
            return 0;
        }
        try {
            $billing = isset($order['billing']) && is_array($order['billing']) ? $order['billing'] : [];
            $name = trim((isset($billing['first_name']) ? (string) $billing['first_name'] : '') . ' ' . (isset($billing['last_name']) ? (string) $billing['last_name'] : ''));

            $options = ['locale' => (string) $order['lang'], 'to_name' => $name, 'related' => 'order:' . (int) $order['id']];
            // Otros módulos adjuntan ficheros a un correo del pedido (la factura, en la confirmación). Cada adjunto es
            // ['name' => …, 'content' => bytes, 'mime' => …]. Si un filtro falla, el correo sale sin lo suyo.
            $attachments = $this->app->hooks()->apply('order.mail_attachments', [], $order, $template);
            if (is_array($attachments) && $attachments !== []) {
                $options['attachments'] = array_values($attachments);
            }
            // Una descarga que un plugin frena no lleva su enlace directo: el correo lleva a la página del pedido, que explica el motivo.
            if (isset($extra['downloads']) && is_array($extra['downloads']) && $extra['downloads'] !== []) {
                $extra['downloads'] = $this->downloadLinks($order, $extra['downloads'], $secret);
            }

            return (int) $this->app->service('mailer')->queue($template, (string) $order['email'], $this->payload($order, $extra, $secret, $template), $options);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'orders.mail:' . $template);

            return 0;
        }
    }

    /**
     * Avisa a la tienda de un pedido nuevo (ajuste store.email), en el idioma principal del sitio.
     *
     * @param array<string,mixed> $order
     */
    public function admin(array $order): int
    {
        $to = trim($this->app->settings()->str('store.email'));
        if ($to === '' || !$this->app->has('mailer')) {
            return 0;
        }
        try {
            return (int) $this->app->service('mailer')->queue('admin_new_order', $to, $this->payload($order, [], '', 'admin_new_order'), [
                'locale' => $this->app->defaultLang(),
                'related' => 'order:' . (int) $order['id'],
            ]);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'orders.mail:admin_new_order');

            return 0;
        }
    }

    /**
    /**
     * Los enlaces de las descargas para el correo: los de siempre, salvo los de una descarga que un plugin frena (filtro sellado
     * order.download.allowed), que apuntan a la página del pedido. Sin esa página (o sin ficha) la descarga frenada no se enseña.
     *
     * @param array<string,mixed>            $order
     * @param array<int,array<string,mixed>> $downloads id, name y url de lo concedido
     *
     * @return array<int,array<string,mixed>>
     */
    private function downloadLinks(array $order, array $downloads, string $secret): array
    {
        if (!$this->app->hooks()->has(Downloads::GATE)) {
            return $downloads;
        }
        $gate = $this->app->service('orders')->downloads();
        $page = $this->orderUrl($order, $secret);
        $out = [];
        foreach ($downloads as $download) {
            if (!is_array($download) || !isset($download['id'])) {
                continue;
            }
            if (!$gate->verdictOf($order, (int) $download['id'])['allowed']) {
                if ($page === '') {
                    continue;
                }
                $download['url'] = $page;
            }
            $out[] = $download;
        }

        return $out;
    }

    /**
     * Los bloques que los plugins añaden a un correo del pedido (filtro order.mail.blocks, firma
     * (array $blocks, array $order, string $template)): cada uno es ['title' => string, 'lines' => string[]], texto llano
     * que el correo escapa y pinta con su estilo. Se pide con el idioma del pedido puesto, para que lo que el plugin traduzca
     * salga en él. Salen como mucho MAX_BLOCKS bloques y MAX_LINES líneas por bloque; lo que sobra se corta y se apunta en el
     * registro (una sola línea por correo).
     *
     * @param array<string,mixed> $order
     *
     * @return array<int,array{title:string,lines:string[]}>
     */
    public function blocks(array $order, string $template, string $locale): array
    {
        $hooks = $this->app->hooks();
        if (!$hooks->has('order.mail.blocks')) {
            return [];
        }
        $i18n = $this->app->i18n();
        $previous = $i18n->locale();
        $i18n->setLocale($locale);
        try {
            $raw = $hooks->apply('order.mail.blocks', [], $order, $template);
        } finally {
            $i18n->setLocale($previous);
        }
        $blocks = [];
        $cutBlocks = 0;
        $cutLines = 0;
        foreach (is_array($raw) ? $raw : [] as $block) {
            if (!is_array($block)) {
                continue;
            }
            $title = isset($block['title']) && is_scalar($block['title']) ? self::plain((string) $block['title']) : '';
            $lines = [];
            foreach (isset($block['lines']) && is_array($block['lines']) ? $block['lines'] : [] as $line) {
                $text = is_scalar($line) ? self::plain((string) $line) : '';
                if ($text !== '') {
                    $lines[] = $text;
                }
            }
            if ($title === '' && $lines === []) {
                continue;
            }
            if (count($blocks) >= self::MAX_BLOCKS) {
                ++$cutBlocks;
                continue;
            }
            if (count($lines) > self::MAX_LINES) {
                $cutLines += count($lines) - self::MAX_LINES;
                $lines = array_slice($lines, 0, self::MAX_LINES);
            }
            $blocks[] = ['title' => $title, 'lines' => $lines];
        }
        if ($cutBlocks > 0 || $cutLines > 0) {
            $this->app->log()->warning('order.mail.blocks: the blocks were cut to the limit', [
                'template' => $template, 'order' => (string) $order['number'], 'blocks_cut' => $cutBlocks, 'lines_cut' => $cutLines,
                'max_blocks' => self::MAX_BLOCKS, 'max_lines' => self::MAX_LINES,
            ]);
        }

        return $blocks;
    }

    /** Texto llano: sin saltos ni caracteres de control, con los espacios recogidos. */
    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1f\x7f\s]+/u', ' ', $text));
    }

    /**
     * Lo que ven las plantillas. Las claves sueltas (number, name, order_url) también sirven en el asunto.
     *
     * Con el nombre de la plantilla se piden además los bloques de los plugins (order.mail.blocks), que van en $order['blocks'].
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    public function payload(array $order, array $extra = [], string $secret = '', string $template = ''): array
    {
        $lines = [];
        $extras = [];
        $discounts = [];
        foreach (isset($order['items']) && is_array($order['items']) ? $order['items'] : [] as $item) {
            $kind = (string) $item['kind'];
            $data = isset($item['data']) && is_array($item['data']) ? $item['data'] : [];
            // Los importes van «tal como se escriben» (con o sin impuestos según el pedido), como el desglose de abajo.
            if ($kind === 'product') {
                $lines[] = [
                    'name' => (string) $item['name'], 'variant_label' => (string) $item['variant_label'], 'sku' => (string) $item['sku'],
                    'qty' => (int) $item['qty'], 'unit_price' => (int) $item['unit_price'], 'amount' => (int) $item['unit_price'] * (int) $item['qty'],
                ];
            } elseif ($kind === 'discount' && !empty($data['informational'])) {
                $discounts[] = ['name' => (string) $item['name'], 'amount' => -(int) $item['line_total']];
            } else {
                $extras[] = ['kind' => $kind, 'name' => (string) $item['name'], 'amount' => (int) $item['line_total']];
            }
        }
        $billing = isset($order['billing']) && is_array($order['billing']) ? $order['billing'] : [];
        $shipping = isset($order['shipping']) && is_array($order['shipping']) ? $order['shipping'] : [];
        $name = trim((isset($billing['first_name']) ? (string) $billing['first_name'] : '') . ' ' . (isset($billing['last_name']) ? (string) $billing['last_name'] : ''));
        $url = $this->orderUrl($order, $secret);

        $summary = [
            'number' => (string) $order['number'],
            'status' => (string) $order['status'],
            'currency' => (string) $order['currency'],
            'lang' => (string) $order['lang'],
            'email' => (string) $order['email'],
            'placed_at' => (string) $order['placed_at'],
            'payment_title' => (string) $order['payment_title'],
            'carrier_title' => (string) $order['carrier_title'],
            'tracking_code' => (string) $order['tracking_code'],
            'customer_note' => (string) $order['customer_note'],
            'prices_include_tax' => !empty($order['prices_include_tax']),
            'items_total' => (int) $order['items_total'],
            'discount_total' => (int) $order['discount_total'],
            'shipping_total' => (int) $order['shipping_total'],
            'fee_total' => (int) $order['fee_total'],
            'total_net' => (int) $order['total_net'],
            'total_tax' => (int) $order['total_tax'],
            'total' => (int) $order['total'],
            'taxes' => isset($order['taxes']) && is_array($order['taxes']) ? array_values($order['taxes']) : [],
            'billing' => $billing,
            'shipping' => $shipping,
            'lines' => $lines,
            'extras' => $extras,
            'discounts' => $discounts,
            'blocks' => $template !== '' ? $this->blocks($order, $template, $template === 'admin_new_order' ? $this->app->defaultLang() : (string) $order['lang']) : [],
        ];

        return $extra + [
            'number' => (string) $order['number'],
            'name' => $name !== '' ? $name : (string) $order['email'],
            'order_url' => $url,
            'order' => $summary,
        ];
    }

    /**
     * El enlace para ver el pedido: la ruta «order.thanks» con el número y la ficha en claro. La ficha se puede pasar
     * (al crear el pedido) o se vuelve a calcular; un pedido cuya ficha no es la del sitio no tiene enlace. Sin ruta, ''.
     *
     * @param array<string,mixed> $order
     */
    public function orderUrl(array $order, string $secret = ''): string
    {
        if (!$this->app->router()->has('order.thanks')) {
            return '';
        }
        if ($secret === '') {
            // Fuera de la petición que creó el pedido, la ficha se vuelve a calcular (ver Orders::viewToken()).
            $secret = $this->app->service('orders')->viewTokenOf((string) $order['number']);
        }
        if ($secret === '') {
            return '';
        }
        $params = ['number' => (string) $order['number'], 'secret' => $secret];
        try {
            return $this->app->url()->origin() . $this->app->url()->route('order.thanks', $params);
        } catch (\InvalidArgumentException $e) {
            return '';
        }
    }

    /**
     * Un pedido de ejemplo para la vista previa de las plantillas en el panel.
     *
     * @return array<string,mixed>
     */
    public static function sample(): array
    {
        return [
            'number' => '2026-000123',
            'name' => 'Ana Ruiz',
            'order_url' => 'https://tienda.test/pedido/2026-000123',
            'instructions' => '<p>IBAN ES00 0000 0000 0000 0000 0000, concepto 2026-000123</p>',
            'downloads' => [['name' => 'Guía.pdf', 'url' => 'https://tienda.test/descarga/abc']],
            'refund' => ['amount' => 1500],
            'order' => [
                'number' => '2026-000123', 'status' => 'processing', 'currency' => 'EUR', 'lang' => 'es', 'email' => 'ana@cliente.test',
                'placed_at' => '2026-11-12 12:00:00', 'payment_title' => 'Transferencia', 'carrier_title' => 'Estándar', 'tracking_code' => 'ES123456',
                'customer_note' => '', 'prices_include_tax' => true, 'items_total' => 2000, 'discount_total' => 0, 'shipping_total' => 495,
                'fee_total' => 0, 'total_net' => 2061, 'total_tax' => 434, 'total' => 2495,
                'taxes' => [['name' => 'IVA 21 %', 'rate' => 2100, 'base' => 2061, 'amount' => 434]],
                'billing' => ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Mayor 1', 'postcode' => '35001', 'city' => 'Las Palmas', 'country' => 'ES'],
                'shipping' => ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Mayor 1', 'postcode' => '35001', 'city' => 'Las Palmas', 'country' => 'ES'],
                'lines' => [['name' => 'Producto de ejemplo', 'variant_label' => '', 'sku' => 'EJ-1', 'qty' => 2, 'unit_price' => 1000, 'amount' => 2000]],
                'extras' => [['kind' => 'shipping', 'name' => 'Estándar', 'amount' => 495]],
                'discounts' => [],
            ],
        ];
    }
}

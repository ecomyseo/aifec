<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Invoices;

use Aifec\Db\Db;
use Aifec\Kernel\App;
use Aifec\Modules\Orders\Sequence;

/**
 * Publicado como el servicio «invoices»: facturas y facturas rectificativas con numeración correlativa sin huecos.
 *
 * Una sola factura por pedido y una sola rectificativa por devolución (lo garantiza la columna unique_key). El número sale del
 * contador «invoice:<serie>» de la tabla sequences DENTRO de la misma transacción que crea la fila: si la fila no se crea, el
 * contador vuelve atrás con ella y no queda un hueco. Un pedido de pruebas (test) no gasta numeración real: usa la serie TEST.
 *
 * Los datos de la factura (emisor, receptor, líneas, impuestos por tipo, totales) se congelan al emitirla; el PDF se hace la primera
 * vez que se pide a partir de esos datos y se guarda en la carpeta privada del sitio.
 *
 * Ajustes: invoices.series («F») y invoices.format («{series}{year}-{number:5}») para las facturas; invoices.credit_series («R»)
 * y invoices.credit_format para las rectificativas; invoices.legal_text; invoices.attach. Las opciones del formato son las del
 * número de pedido ({year}, {number:5}) más {series}.
 *
 * Acción: invoice.issued (array $invoice) al crear una factura o una rectificativa.
 */
final class Invoices
{
    /** Serie de las facturas de pedidos de pruebas. */
    public const TEST_SERIES = 'TEST';

    public const DEFAULT_FORMAT = '{series}{year}-{number:5}';

    private App $app;

    private Sequence $sequence;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->sequence = new Sequence($app);
    }

    // ------------------------------------------------------------------ emitir

    /**
     * La factura de un pedido. Si ya existe, devuelve esa misma (no gasta otro número).
     *
     * @return array<string,mixed> la factura (ver find())
     *
     * @throws \InvalidArgumentException pedido que no existe o cancelado
     */
    public function issue(int $orderId): array
    {
        $existing = $this->byKey('invoice:' . $orderId);
        if ($existing !== null) {
            return $existing;
        }
        $order = $this->order($orderId);
        if (in_array((string) $order['status'], ['cancelled', 'failed'], true)) {
            throw new \InvalidArgumentException('The order was not completed');
        }

        return $this->create($order, 'invoice', InvoiceData::invoice($this->app, $order), 'invoice:' . $orderId, null, null);
    }

    /**
     * La factura rectificativa de una devolución, con su propia serie, los importes en negativo y la referencia a la factura
     * original (que se emite antes si el pedido aún no la tenía). Si ya existe, devuelve esa misma.
     *
     * @return array<string,mixed>
     *
     * @throws \InvalidArgumentException pedido o devolución que no existen, o que no son de ese pedido
     */
    public function credit(int $orderId, int $refundId): array
    {
        $existing = $this->byKey('credit:' . $refundId);
        if ($existing !== null) {
            if ($existing['order_id'] !== $orderId) {
                throw new \InvalidArgumentException('Unknown refund');
            }

            return $existing;
        }
        $order = $this->order($orderId);
        $refund = null;
        foreach (isset($order['refunds']) && is_array($order['refunds']) ? $order['refunds'] : [] as $candidate) {
            if ((int) $candidate['id'] === $refundId) {
                $refund = $candidate;
            }
        }
        if ($refund === null) {
            throw new \InvalidArgumentException('Unknown refund');
        }
        $original = $this->issue($orderId);

        return $this->create($order, 'credit', InvoiceData::credit($this->app, $order, $refund, $original), 'credit:' . $refundId, $refundId, (int) $original['id']);
    }

    // ------------------------------------------------------------------ leer

    /**
     * Una factura con los tipos puestos y los datos congelados descodificados (data), o null.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->app->db()->find('invoices', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByNumber(string $fullNumber): ?array
    {
        $row = $this->app->db()->find('invoices', ['full_number' => trim($fullNumber)]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Las facturas de un pedido: primero la factura y después sus rectificativas, por orden de emisión.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forOrder(int $orderId): array
    {
        $out = [];
        foreach ($this->app->db()->select("SELECT * FROM {invoices} WHERE order_id = ? ORDER BY CASE type WHEN 'invoice' THEN 0 ELSE 1 END, id", [$orderId]) as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    /**
     * La dirección de descarga de una factura (la ficha del pedido da acceso sin cuenta), o '' si el pedido no tiene una ficha de
     * este sitio (por ejemplo, traído de otra tienda).
     *
     * @param array<string,mixed> $invoice
     */
    public function url(array $invoice, bool $absolute = false): string
    {
        $number = $this->app->db()->value('SELECT number FROM {orders} WHERE id = ?', [(int) $invoice['order_id']]);
        if ($number === null || !$this->app->router()->has('invoices.download')) {
            return '';
        }
        $secret = $this->app->service('orders')->viewTokenOf((string) $number);
        if ($secret === '') {
            return '';
        }
        $path = $this->app->url()->route('invoices.download', ['number' => (string) $invoice['full_number'], 'secret' => $secret]);

        return $absolute ? $this->app->url()->origin() . $path : $path;
    }

    // ------------------------------------------------------------------ PDF

    /**
     * La ruta completa del PDF de una factura. Se genera la primera vez que se pide, a partir de los datos congelados, y se guarda
     * en la carpeta privada del sitio; si el fichero se perdió, se vuelve a hacer igual.
     *
     * @throws \InvalidArgumentException si la factura no existe
     * @throws \RuntimeException         si no se puede escribir en la carpeta privada
     */
    public function pdf(int $invoiceId): string
    {
        $invoice = $this->find($invoiceId);
        if ($invoice === null) {
            throw new \InvalidArgumentException('Unknown invoice');
        }
        if ((string) $invoice['pdf_path'] !== '') {
            $existing = $this->absolute((string) $invoice['pdf_path']);
            if ($existing !== null && is_file($existing)) {
                return $existing;
            }
        }
        $bytes = InvoicePdf::render($this->app, $invoice);
        $relative = 'invoices/' . (int) $invoice['id'] . '-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice['full_number']) . '.pdf';
        $dir = $this->app->site()->path('private/invoices');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('The private folder is not writable');
        }
        foreach (['.htaccess' => "Require all denied\n", 'index.html' => ''] as $name => $content) {
            if (!is_file($dir . '/' . $name)) {
                file_put_contents($dir . '/' . $name, $content);
            }
        }
        $target = $this->app->site()->path('private/' . $relative);
        $part = $target . '.part';
        if (file_put_contents($part, $bytes) === false || !rename($part, $target)) {
            if (is_file($part)) {
                @unlink($part);
            }
            throw new \RuntimeException('The invoice file could not be written');
        }
        $this->app->db()->update('invoices', ['pdf_path' => $relative, 'updated_at' => $this->app->clock()->sql()], ['id' => (int) $invoice['id']]);

        return $target;
    }

    /**
     * Los adjuntos de un correo del pedido: con el ajuste invoices.attach encendido y la plantilla «order_confirmed», el PDF de la
     * factura del pedido (se emite si aún no existe). Es el filtro «order.mail_attachments» (adjuntos, pedido, plantilla).
     *
     * @param array<int,array<string,string>> $attachments
     * @param array<string,mixed>             $order
     *
     * @return array<int,array<string,string>>
     */
    public function mailAttachments(array $attachments, array $order, string $template): array
    {
        // Sin emisión automática las facturas las hace el ERP de la tienda (InvoiceNotice lo dice así al cliente): AIFEC no emite.
        $settings = $this->app->settings();
        if ($template !== 'order_confirmed' || !$settings->bool('invoices.auto', true) || !$settings->bool('invoices.attach', true) || !isset($order['id'])) {
            return $attachments;
        }
        try {
            $invoice = $this->issue((int) $order['id']);
            $content = file_get_contents($this->pdf((int) $invoice['id']));
            if ($content !== false) {
                $attachments[] = ['name' => (string) $invoice['full_number'] . '.pdf', 'content' => $content, 'mime' => 'application/pdf'];
            }
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'invoices.attach');
        }

        return $attachments;
    }

    // ------------------------------------------------------------------ interno

    /**
     * Crea la fila con su número, todo dentro de una transacción. Si otra petición creó la misma factura a la vez, devuelve la suya.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function create(array $order, string $type, array $data, string $key, ?int $refundId, ?int $originalId): array
    {
        $test = !empty($order['test']);
        list($series, $format) = $this->seriesFor($type, $test);
        $now = $this->app->clock()->now();
        $year = (int) (new \DateTimeImmutable('@' . $now))->setTimezone($this->timezone())->format('Y');
        $sql = $this->app->clock()->sql($now);
        $db = $this->app->db();
        try {
            $id = (int) $db->transaction(function (Db $db) use ($order, $type, $data, $key, $refundId, $originalId, $series, $format, $year, $sql, $test): int {
                $number = $this->sequence->next('invoice:' . $series);
                $full = self::fullNumber($format, $series, $number, $year);

                return $db->insert('invoices', [
                    'unique_key' => $key, 'order_id' => (int) $order['id'], 'type' => $type, 'series' => $series, 'number' => $number, 'full_number' => $full,
                    'issued_at' => $sql, 'refund_id' => $refundId, 'original_id' => $originalId, 'currency' => (string) $order['currency'],
                    'net' => (int) $data['totals']['net'], 'tax' => (int) $data['totals']['tax'], 'total' => (int) $data['totals']['total'],
                    'test' => $test ? 1 : 0, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                    'pdf_path' => null, 'created_at' => $sql, 'updated_at' => $sql,
                ]);
            });
        } catch (\Throwable $e) {
            // Dos peticiones a la vez: gana la primera. La segunda ya ha deshecho su número (la transacción vuelve atrás).
            $again = $this->byKey($key);
            if ($again !== null) {
                return $again;
            }
            throw $e;
        }
        $invoice = $this->find($id);
        if ($invoice === null) {
            throw new \LogicException('The invoice that was just created cannot be read');
        }
        $this->app->hooks()->fire('invoice.issued', $invoice);

        return $invoice;
    }

    /**
     * La serie y el formato de un tipo de factura. Un pedido de pruebas va siempre a la serie TEST.
     *
     * @return array{0:string,1:string}
     */
    private function seriesFor(string $type, bool $test): array
    {
        if ($test) {
            return [self::TEST_SERIES, self::TEST_SERIES . '-{number:5}'];
        }
        $settings = $this->app->settings();
        $credit = $type === 'credit';
        $series = preg_replace('/[^A-Za-z0-9]/', '', $settings->str($credit ? 'invoices.credit_series' : 'invoices.series', $credit ? 'R' : 'F'));
        $series = is_string($series) ? substr($series, 0, 10) : '';
        if ($series === '') {
            $series = $credit ? 'R' : 'F';
        }
        $format = trim($settings->str($credit ? 'invoices.credit_format' : 'invoices.format', self::DEFAULT_FORMAT));

        return [$series, $format !== '' ? $format : self::DEFAULT_FORMAT];
    }

    /**
     * El número que se imprime: el formato con {series}, {year} y {number:5}. Solo letras, cifras, punto, guion y guion bajo (el
     * número va en la dirección de descarga): lo demás se cambia por un guion.
     */
    public static function fullNumber(string $format, string $series, int $number, int $year): string
    {
        $full = Sequence::format(str_replace('{series}', $series, $format), $number, $year);
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $full);

        return is_string($clean) && $clean !== '' ? $clean : $series . '-' . $number;
    }

    private function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->app->site()->timezone());
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function order(int $orderId): array
    {
        $order = $this->app->service('orders')->find($orderId);
        if ($order === null) {
            throw new \InvalidArgumentException('Unknown order');
        }

        return $order;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function byKey(string $key): ?array
    {
        $row = $this->app->db()->find('invoices', ['unique_key' => $key]);

        return $row === null ? null : $this->hydrate($row);
    }

    private function absolute(string $relative): ?string
    {
        if ($relative === '' || strpos($relative, "\0") !== false || strpos($relative, '\\') !== false || $relative[0] === '/'
            || preg_match('#(^|/)\.\.?(/|$)#', $relative) === 1) {
            return null;
        }

        return $this->app->site()->path('private/' . $relative);
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        foreach (['id', 'order_id', 'number', 'net', 'tax', 'total'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['refund_id', 'original_id'] as $key) {
            $row[$key] = $row[$key] !== null && (int) $row[$key] > 0 ? (int) $row[$key] : null;
        }
        $row['test'] = !empty($row['test']);
        $row['pdf_path'] = $row['pdf_path'] !== null ? (string) $row['pdf_path'] : '';
        $decoded = is_string($row['data']) ? json_decode($row['data'], true) : null;
        $row['data'] = is_array($decoded) ? $decoded : [];

        return $row;
    }
}

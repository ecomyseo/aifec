<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Writers;

use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\WriteContext;
use Aifec\Text\HtmlSanitizer;

/**
 * Las opiniones de producto. Se escriben por la capa de datos con la fecha del origen: no se lanza «opinión publicada» ni se
 * pide ninguna valoración por correo. La media y el número de opiniones del producto se recalculan como lo hace el módulo
 * (una consulta de suma sobre las publicadas), nunca sumando a mano.
 *
 * El texto es texto plano (se guarda sin etiquetas y siempre se pinta escapado). Una opinión sin producto traído no entra. El
 * módulo guarda una opinión por producto y correo: la segunda del mismo correo para el mismo producto no entra y se dice. Si el
 * origen no dice el correo (una opinión anónima), se usa el del cliente y, si tampoco hay, uno inventado que nunca recibe nada.
 */
final class ReviewsWriter extends WriterBase
{
    private const STATUS = ['approved' => 'published', 'pending' => 'pending', 'rejected' => 'rejected'];

    public function type(): string
    {
        return 'reviews';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $productId = $ctx->localId('products', (string) $record['product_id']);
        if ($productId === null || $db->value('SELECT id FROM {products} WHERE id = ?', [$productId]) === null) {
            return $this->result(self::SKIPPED, null, 'The product of the review was not brought');
        }
        $rating = isset($record['rating']) ? (int) $record['rating'] : 0;
        if ($rating < 1 || $rating > 5) {
            return $this->result(self::SKIPPED, null, 'The rating is not between 1 and 5');
        }
        $customer = null;
        if (isset($record['customer_id']) && is_string($record['customer_id']) && $record['customer_id'] !== '') {
            $customerId = $ctx->localId('customers', $record['customer_id']);
            $customer = $customerId !== null ? $db->find('customers', ['id' => $customerId]) : null;
        }
        $email = $this->email($record, $customer, $id);

        $found = $this->resolve('reviews', $record, $ctx, static function (int $localId) use ($db): ?array {
            return $db->find('reviews', ['id' => $localId]);
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($action === self::CONFLICT) {
            return $this->conflict((int) $row['id']);
        }
        $same = $db->row('SELECT id FROM {reviews} WHERE product_id = ? AND email = ?', [$productId, $email]);
        if ($same !== null && ($row === null || (int) $same['id'] !== (int) $row['id'])) {
            return $this->result(self::SKIPPED, null, 'There is already a review of this customer for this product here');
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }

        $now = $this->now();
        $status = isset($record['status']) && is_string($record['status']) && isset(self::STATUS[$record['status']]) ? self::STATUS[$record['status']] : 'pending';
        $at = $this->sqlDate(isset($record['at']) ? $record['at'] : null);
        $name = isset($record['author']) && is_string($record['author']) ? $this->cut($record['author'], 80) : '';
        if ($name === '' && $customer !== null) {
            $name = $this->cut(trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name']), 80);
        }
        $title = isset($record['title']) && is_string($record['title']) ? $this->cut(HtmlSanitizer::text($record['title']), 100) : '';
        $body = isset($record['body']) && is_string($record['body']) ? $this->plain($record['body']) : '';
        $reply = isset($record['reply']) && is_string($record['reply']) ? $this->plain($record['reply']) : '';
        $locale = isset($record['locale']) && is_string($record['locale']) ? $this->shortLang($record['locale']) : '';
        $verified = !empty($record['verified']);
        $data = [
            'product_id' => $productId, 'customer_id' => $customer !== null ? (int) $customer['id'] : null, 'name' => $name !== '' ? $name : 'Anonymous',
            'email' => $email, 'rating' => $rating, 'title' => $title !== '' ? $title : null, 'body' => $body,
            'lang' => in_array($locale, $ctx->languages(), true) ? $locale : $this->app->defaultLang(), 'status' => $status, 'verified' => $verified ? 1 : 0,
            'reply' => $reply !== '' ? mb_substr($reply, 0, 1000, 'UTF-8') : null, 'replied_at' => $reply !== '' ? ($at ?: $now) : null,
            'published_at' => $status === 'published' ? ($at ?: $now) : null, 'updated_at' => $now,
        ];
        $data['order_id'] = $verified && $this->app->has('reviews') ? $this->app->service('reviews')->verifiedOrder($email, $productId) : null;
        if ($row === null) {
            $localId = $db->insert('reviews', $data + ['created_at' => $at ?: $now]);
        } else {
            $localId = (int) $row['id'];
            $db->update('reviews', $data, ['id' => $localId]);
            if ((int) $row['product_id'] !== $productId) {
                $this->recalculate((int) $row['product_id']);
            }
        }
        $this->recalculate($productId);
        $ctx->remember('reviews', $id, $localId, Neutral::hash($record));

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    public function checksums(WriteContext $ctx): array
    {
        return ['reviews_count' => $this->mappedCount('reviews', 'reviews')];
    }

    /**
     * El correo de la opinión: el que trae el registro (en «extra»), el del cliente o uno inventado que no recibe nada.
     *
     * @param array<string,mixed>      $record
     * @param array<string,mixed>|null $customer
     */
    private function email(array $record, ?array $customer, string $id): string
    {
        $extra = isset($record['extra']) && is_array($record['extra']) ? $record['extra'] : [];
        foreach ([isset($extra['email']) ? $extra['email'] : '', $customer !== null ? $customer['email'] : ''] as $candidate) {
            $mail = is_string($candidate) ? strtolower(trim($candidate)) : '';
            if ($mail !== '' && strlen($mail) <= 191 && filter_var($mail, FILTER_VALIDATE_EMAIL) !== false) {
                return $mail;
            }
        }

        return $this->cut('review-' . preg_replace('/[^A-Za-z0-9]+/', '-', $id) . '@import.invalid', 191);
    }

    /** Texto plano de varias líneas: sin etiquetas, con los saltos normalizados y hasta 2.000 letras. */
    private function plain(string $text): string
    {
        $text = HtmlSanitizer::text($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return mb_substr(trim($text), 0, 2000, 'UTF-8');
    }

    /** La suma y la cuenta de las opiniones publicadas del producto, como lo hace el módulo. */
    private function recalculate(int $productId): void
    {
        if ($this->app->has('reviews')) {
            $this->app->service('reviews')->recalculate($productId);
        }
    }
}

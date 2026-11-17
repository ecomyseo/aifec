<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Reviews;

use Aifec\Db\Db;
use Aifec\Kernel\App;

/**
 * Publicado como el servicio «reviews»: las opiniones de los compradores con moderación.
 *
 * Una opinión entra «pending» (o «published» si es de compra verificada y el ajuste reviews.auto_publish_verified está
 * encendido). Publicar o retirar una opinión recalcula la suma y la cuenta de ese producto (products.rating_sum y
 * rating_count) con una consulta de suma, nunca sumando y restando a mano, e invalida la ficha (etiqueta product:<id>).
 *
 * El texto de una opinión es texto plano: se guarda tal como llega (sin controles raros) y SIEMPRE se pinta escapado.
 */
final class Reviews
{
    public const PER_PAGE = 5;
    public const MIN_BODY = 10;
    public const MAX_BODY = 2000;
    public const MAX_TITLE = 100;
    public const MAX_NAME = 60;
    public const MAX_REPLY = 1000;

    private App $app;

    private ReviewRequests $requests;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->requests = new ReviewRequests($app);
    }

    public function requests(): ReviewRequests
    {
        return $this->requests;
    }

    // ------------------------------------------------------------------ leer

    /**
     * Las opiniones publicadas de un producto, las de compra verificada primero, con la media y el reparto por estrellas.
     *
     * Devuelve items (id, name, rating, title, body, lang, verified, reply, replied_at, published_at), total, page, pages,
     * per_page, count, average (0.0 si no hay) y distribution ([5 => n, 4 => n, … 1 => n]).
     *
     * @return array<string,mixed>
     */
    public function forProduct(int $productId, int $page = 1): array
    {
        $db = $this->app->db();
        $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $sum = 0;
        $total = 0;
        $rows = $db->select("SELECT rating, COUNT(*) AS n FROM {reviews} WHERE product_id = ? AND status = 'published' GROUP BY rating", [$productId]);
        foreach ($rows as $row) {
            $rating = (int) $row['rating'];
            if (isset($distribution[$rating])) {
                $distribution[$rating] = (int) $row['n'];
                $sum += $rating * (int) $row['n'];
                $total += (int) $row['n'];
            }
        }
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));
        $items = [];
        if ($total > 0) {
            $found = $db->select(
                "SELECT id, name, rating, title, body, lang, verified, reply, replied_at, published_at FROM {reviews} WHERE product_id = ? AND status = 'published' "
                . 'ORDER BY verified DESC, published_at DESC, id DESC LIMIT ? OFFSET ?',
                [$productId, self::PER_PAGE, ($page - 1) * self::PER_PAGE]
            );
            foreach ($found as $row) {
                $items[] = [
                    'id' => (int) $row['id'], 'name' => (string) $row['name'], 'rating' => (int) $row['rating'],
                    'title' => (string) ($row['title'] !== null ? $row['title'] : ''), 'body' => (string) $row['body'], 'lang' => (string) $row['lang'],
                    'verified' => !empty($row['verified']), 'reply' => (string) ($row['reply'] !== null ? $row['reply'] : ''),
                    'replied_at' => $row['replied_at'] !== null ? (string) $row['replied_at'] : null,
                    'published_at' => $row['published_at'] !== null ? (string) $row['published_at'] : null,
                ];
            }
        }

        return [
            'items' => $items, 'total' => $total, 'count' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => self::PER_PAGE,
            'average' => $total > 0 ? round($sum / $total, 1) : 0.0, 'distribution' => $distribution,
        ];
    }

    /**
     * Una opinión tal cual está en la tabla, con los tipos puestos.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->app->db()->find('reviews', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    // ------------------------------------------------------------------ enviar

    /**
     * Recibe una opinión. Devuelve ['ok','error','errors' => [campo => mensaje],'id','status','verified'].
     *
     * $data: product_id, name, email, rating, title, body, lang. Con 'token' (la ficha de un solo uso del correo) el producto, el
     * correo y la compra salen de la petición que se guardó al completarse el pedido, y la ficha se gasta al guardar.
     *
     * Valida el texto (de 10 a 2.000 caracteres, sin HTML que se interprete: es texto plano), la nota (1 a 5) y que no haya ya
     * una opinión de ese correo para ese producto. Es «compra verificada» si hay un pedido pagado de ese correo con ese producto.
     * Con un error de validación no se ha tocado nada.
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public function submit(array $data): array
    {
        $db = $this->app->db();
        $token = isset($data['token']) && is_string($data['token']) ? trim($data['token']) : '';
        $request = null;
        if ($token !== '') {
            $request = $this->requests->open($token);
            if ($request === null) {
                return $this->failure([], t('This review link is not valid or has already been used.'));
            }
            $data['product_id'] = (int) $request['product_id'];
            $data['email'] = (string) $request['email'];
        }

        $productId = isset($data['product_id']) && is_numeric($data['product_id']) ? (int) $data['product_id'] : 0;
        $product = $productId > 0 ? $this->app->service('catalog')->product($productId) : null;
        if ($product === null) {
            return $this->failure([], t('This product is not available.'));
        }

        $errors = [];
        $name = self::oneLine(self::text($data, 'name'));
        if ($name === '' && $request !== null && (string) $request['name'] !== '') {
            $name = (string) $request['name'];
        }
        if ($name === '' || mb_strlen($name, 'UTF-8') > self::MAX_NAME) {
            $errors['name'] = $name === '' ? t('Enter your name.') : t('The name is too long.');
        }
        $email = strtolower(self::oneLine(self::text($data, 'email')));
        if ($email === '' || mb_strlen($email, 'UTF-8') > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = t('Enter a valid email address.');
        }
        $rating = isset($data['rating']) && is_numeric($data['rating']) ? (int) $data['rating'] : 0;
        if ($rating < 1 || $rating > 5) {
            $errors['rating'] = t('Choose a rating from 1 to 5 stars.');
        }
        $title = self::oneLine(self::text($data, 'title'));
        if (mb_strlen($title, 'UTF-8') > self::MAX_TITLE) {
            $errors['title'] = t('The title is too long.');
        }
        $body = self::plain(self::text($data, 'body'));
        $length = mb_strlen($body, 'UTF-8');
        if ($length < self::MIN_BODY) {
            $errors['body'] = t('Write at least {n} characters.', ['n' => self::MIN_BODY]);
        } elseif ($length > self::MAX_BODY) {
            $errors['body'] = t('The review is too long: {n} characters at most.', ['n' => self::MAX_BODY]);
        }
        if ($errors === [] && $db->value('SELECT id FROM {reviews} WHERE product_id = ? AND email = ?', [$productId, $email]) !== null) {
            $errors['email'] = t('You have already reviewed this product.');
        }
        if ($errors !== []) {
            return $this->failure($errors, t('Check the highlighted fields.'));
        }

        $lang = isset($data['lang']) && is_string($data['lang']) && preg_match('/^[a-z]{2}(?:-[A-Za-z]{2})?$/', $data['lang']) === 1 ? $data['lang'] : $this->app->lang();
        if ($request !== null && (string) $request['lang'] !== '') {
            $lang = (string) $request['lang'];
        }
        $orderId = $this->verifiedOrder($email, $productId);
        $verified = $orderId !== null;
        $status = $verified && $this->app->settings()->bool('reviews.auto_publish_verified', false) ? 'published' : 'pending';
        $customerId = null;
        if ($this->app->has('customers')) {
            $customer = $this->app->service('customers')->findByEmail($email);
            $customerId = $customer !== null ? (int) $customer['id'] : null;
        }
        $now = $this->app->clock()->sql();
        $row = [
            'product_id' => $productId, 'order_id' => $orderId, 'customer_id' => $customerId, 'name' => $name, 'email' => $email, 'rating' => $rating,
            'title' => $title !== '' ? $title : null, 'body' => $body, 'lang' => $lang, 'status' => $status, 'verified' => $verified ? 1 : 0,
            'published_at' => $status === 'published' ? $now : null, 'created_at' => $now, 'updated_at' => $now,
        ];

        try {
            $id = (int) $db->transaction(function (Db $db) use ($row, $request): int {
                $id = (int) $db->insert('reviews', $row);
                if ($request !== null && !$this->requests->consume((int) $request['id'], $id)) {
                    throw new \RuntimeException('The review link was used at the same time');
                }

                return $id;
            });
        } catch (\Throwable $e) {
            // Dos envíos a la vez: gana el primero y el otro se entera de que ya está.
            if ($db->value('SELECT id FROM {reviews} WHERE product_id = ? AND email = ?', [$productId, $email]) !== null) {
                return $this->failure(['email' => t('You have already reviewed this product.')], t('Check the highlighted fields.'));
            }
            if ($request !== null) {
                return $this->failure([], t('This review link is not valid or has already been used.'));
            }
            throw $e;
        }

        if ($status === 'published') {
            $this->afterPublished($id);
        }

        return ['ok' => true, 'error' => '', 'errors' => [], 'id' => $id, 'status' => $status, 'verified' => $verified];
    }

    // ------------------------------------------------------------------ moderar

    /**
     * Publica una opinión (de «pending» o de «rejected»). False si no existe o ya estaba publicada: solo la primera vez
     * recalcula, invalida y avisa con la acción review.published.
     */
    public function publish(int $id): bool
    {
        $now = $this->app->clock()->sql();
        $changed = $this->app->db()->run(
            "UPDATE {reviews} SET status = 'published', published_at = ?, updated_at = ? WHERE id = ? AND status <> 'published'",
            [$now, $now, $id]
        );
        if ($changed !== 1) {
            return false;
        }
        $this->afterPublished($id);

        return true;
    }

    /**
     * Rechaza una opinión; si estaba publicada, la retira de la ficha y de la media. False si no existe o ya estaba rechazada.
     */
    public function reject(int $id): bool
    {
        $row = $this->app->db()->find('reviews', ['id' => $id]);
        if ($row === null) {
            return false;
        }
        $now = $this->app->clock()->sql();
        $changed = $this->app->db()->run("UPDATE {reviews} SET status = 'rejected', updated_at = ? WHERE id = ? AND status <> 'rejected'", [$now, $id]);
        if ($changed !== 1) {
            return false;
        }
        if ((string) $row['status'] === 'published') {
            $this->recalculate((int) $row['product_id']);
        }

        return true;
    }

    /**
     * La respuesta de la tienda a una opinión (texto plano, hasta 1.000 caracteres). Vacía, la borra. False si no existe.
     *
     * @throws \InvalidArgumentException si pasa de 1.000 caracteres
     */
    public function reply(int $id, string $text): bool
    {
        $row = $this->app->db()->find('reviews', ['id' => $id]);
        if ($row === null) {
            return false;
        }
        $text = self::plain($text);
        if (mb_strlen($text, 'UTF-8') > self::MAX_REPLY) {
            throw new \InvalidArgumentException('The reply is too long');
        }
        $now = $this->app->clock()->sql();
        $this->app->db()->update('reviews', [
            'reply' => $text !== '' ? $text : null, 'replied_at' => $text !== '' ? $now : null, 'updated_at' => $now,
        ], ['id' => $id]);
        if ((string) $row['status'] === 'published') {
            $this->app->invalidate(['product:' . (int) $row['product_id']]);
        }

        return true;
    }

    /**
     * Vuelve a calcular la suma y la cuenta de las opiniones publicadas de un producto, con una consulta de suma, y deja la
     * ficha sin copia en la caché.
     */
    public function recalculate(int $productId): void
    {
        $db = $this->app->db();
        $row = $db->row("SELECT COALESCE(SUM(rating), 0) AS total, COUNT(*) AS n FROM {reviews} WHERE product_id = ? AND status = 'published'", [$productId]);
        $db->update('products', [
            'rating_sum' => $row !== null ? (int) $row['total'] : 0,
            'rating_count' => $row !== null ? (int) $row['n'] : 0,
        ], ['id' => $productId]);
        $this->app->invalidate(['product:' . $productId]);
    }

    /**
     * El pedido pagado de ese correo con ese producto (el más reciente), o null. Un pedido sin cobrar o devuelto del todo no vale.
     */
    public function verifiedOrder(string $email, int $productId): ?int
    {
        $db = $this->app->db();
        if (!$db->tableExists('orders') || !$db->tableExists('order_items')) {
            return null;
        }
        $id = $db->value(
            'SELECT o.id FROM {orders} o INNER JOIN {order_items} i ON i.order_id = o.id '
            . "WHERE LOWER(o.email) = ? AND i.product_id = ? AND i.kind = 'product' AND o.payment_status IN ('paid', 'partially_refunded') "
            . 'ORDER BY o.id DESC',
            [strtolower(trim($email)), $productId]
        );

        return $id !== null ? (int) $id : null;
    }

    // ------------------------------------------------------------------ interno

    private function afterPublished(int $id): void
    {
        $review = $this->find($id);
        if ($review === null) {
            return;
        }
        $this->recalculate((int) $review['product_id']);
        $this->app->hooks()->fire('review.published', $review);
    }

    /**
     * @param array<string,string> $errors
     *
     * @return array<string,mixed>
     */
    private function failure(array $errors, string $error): array
    {
        return ['ok' => false, 'error' => $error, 'errors' => $errors, 'id' => 0, 'status' => '', 'verified' => false];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        foreach (['id', 'product_id', 'rating'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['order_id', 'customer_id'] as $key) {
            $row[$key] = $row[$key] !== null && (int) $row[$key] > 0 ? (int) $row[$key] : null;
        }
        $row['verified'] = !empty($row['verified']);
        foreach (['title', 'reply'] as $key) {
            $row[$key] = $row[$key] !== null ? (string) $row[$key] : '';
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        return isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : '';
    }

    /** Una sola línea: sin saltos ni controles, con los espacios seguidos reducidos a uno. */
    private static function oneLine(string $text): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
        $clean = preg_replace('/\s+/u', ' ', is_string($clean) ? $clean : '');

        return trim(is_string($clean) ? $clean : '');
    }

    /** Texto plano de varias líneas: saltos normalizados, sin caracteres de control y sin más de dos saltos seguidos. */
    private static function plain(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $clean = preg_replace("/\n{3,}/", "\n\n", is_string($clean) ? $clean : '');

        return trim(is_string($clean) ? $clean : '');
    }
}

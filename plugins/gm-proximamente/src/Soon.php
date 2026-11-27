<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Proximamente;

use Aifec\Kernel\App;

/**
 * Los productos «próximamente» y las peticiones de aviso.
 *
 * Qué productos están en ese estado se lee de una lista pequeña guardada en la caché (etiqueta «gm-proximamente»): un listado de
 * 24 tarjetas no hace 24 consultas. Al pasar un producto a disponible se encola UN aviso por petición hecha en los últimos 90
 * días; las más antiguas no se tocan nunca (y la tarea diaria las borra).
 */
final class Soon
{
    /** Días durante los que una petición de aviso sigue viva. */
    public const REQUEST_DAYS = 90;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Los productos que ahora mismo están «próximamente».
     *
     * @return int[]
     */
    public static function ids(App $app): array
    {
        try {
            $ids = $app->cache()->remember('gm_soon.ids', 300, static function () use ($app): array {
                return array_map('intval', $app->db()->column('SELECT product_id FROM {gm_soon_products} WHERE soon = 1'));
            }, ['gm-proximamente']);
        } catch (\Throwable $e) {
            // Sin la tabla (plugin a medio activar) no hay productos marcados: la tienda sigue como si nada.
            return [];
        }

        return is_array($ids) ? $ids : [];
    }

    public static function isSoon(App $app, int $productId): bool
    {
        return $productId > 0 && in_array($productId, self::ids($app), true);
    }

    /**
     * @return array{soon:bool,release_at:string}
     */
    public static function canSubscribe(App $app, array $product): bool
    {
        return ($product['availability']['status'] ?? '') === 'out_of_stock' || self::isSoon($app, (int) $product['id']);
    }

    public function state(int $productId): array
    {
        $row = $this->app->db()->row('SELECT soon, release_at FROM {gm_soon_products} WHERE product_id = ?', [$productId]);

        return ['soon' => $row !== null && (int) $row['soon'] === 1, 'release_at' => $row !== null && $row['release_at'] !== null ? (string) $row['release_at'] : ''];
    }

    /**
     * Marca o desmarca un producto. Si estaba «próximamente» y deja de estarlo, avisa a quien lo pidió. Devuelve cuántos
     * avisos se encolaron.
     */
    public function set(int $productId, bool $soon, string $releaseAt = ''): int
    {
        if ($productId < 1) {
            return 0;
        }
        $was = $this->state($productId)['soon'];
        $now = $this->app->clock()->sql();
        $data = ['product_id' => $productId, 'soon' => $soon ? 1 : 0, 'release_at' => $soon && $releaseAt !== '' ? $releaseAt : null, 'updated_at' => $now];
        $this->app->db()->upsert('gm_soon_products', $data, ['product_id']);
        $this->forget($productId);

        return $was && !$soon ? $this->release($productId) : 0;
    }

    /** Tarea: pasa a disponibles los productos cuya fecha de salida ya llegó. Devuelve cuántos. */
    public function releaseDue(): int
    {
        $due = $this->app->db()->column('SELECT product_id FROM {gm_soon_products} WHERE soon = 1 AND release_at IS NOT NULL AND release_at <= ?', [$this->app->clock()->sql()]);
        foreach ($due as $productId) {
            $this->set((int) $productId, false);
        }

        // También se atienden las reposiciones normales de productos con una solicitud vigente.
        $since = $this->app->clock()->sql($this->app->clock()->now() - self::REQUEST_DAYS * 86400);
        $waiting = $this->app->db()->column('SELECT DISTINCT product_id FROM {gm_soon_requests} WHERE notified_at IS NULL AND created_at >= ?', [$since]);
        foreach (array_chunk($waiting, 50) as $ids) {
            foreach ($this->app->service('catalog')->cards(array_map('intval', $ids)) as $item) {
                if (in_array($item['availability']['status'] ?? '', ['in_stock', 'low_stock'], true) && !self::isSoon($this->app, (int) $item['id'])) {
                    $this->release((int) $item['id']);
                }
            }
        }
        return count($due);
    }

    /**
     * Guarda una petición de aviso. Una por correo y producto: repetirla no crea otra. Una ya avisada que se vuelve a pedir
     * (el producto volvió a «próximamente») empieza de nuevo.
     */
    public function request(int $productId, string $email, string $lang): void
    {
        $db = $this->app->db();
        $now = $this->app->clock()->sql();
        $row = $db->row('SELECT id, notified_at FROM {gm_soon_requests} WHERE product_id = ? AND email = ?', [$productId, $email]);
        if ($row === null) {
            $db->insert('gm_soon_requests', ['product_id' => $productId, 'email' => $email, 'lang' => substr($lang, 0, 8), 'consented_at' => $now, 'created_at' => $now]);

            return;
        }
        if ($row['notified_at'] !== null) {
            $db->update('gm_soon_requests', ['notified_at' => null, 'created_at' => $now, 'consented_at' => $now, 'lang' => substr($lang, 0, 8)], ['id' => (int) $row['id']]);
        }
    }

    /**
     * Encola el aviso de «ya está disponible»: uno por petición de los últimos 90 días, y cada petición se marca antes de enviar
     * (una sentencia condicionada: dos tareas a la vez no avisan dos veces). Si el correo no sale (modo silencioso, sin servicio),
     * la petición se queda como estaba. Devuelve cuántos avisos se encolaron.
     */
    public function release(int $productId): int
    {
        if (!$this->app->has('mailer') || $this->app->silenced()) {
            return 0;
        }
        $db = $this->app->db();
        $clock = $this->app->clock();
        $since = $clock->sql($clock->now() - self::REQUEST_DAYS * 86400);
        $rows = $db->select('SELECT id, email, lang FROM {gm_soon_requests} WHERE product_id = ? AND notified_at IS NULL AND created_at >= ? ORDER BY id', [$productId, $since]);
        $sent = 0;
        foreach ($rows as $row) {
            $claimed = $db->run('UPDATE {gm_soon_requests} SET notified_at = ? WHERE id = ? AND notified_at IS NULL', [$clock->sql(), (int) $row['id']]);
            if ($claimed !== 1) {
                continue;
            }
            $lang = (string) $row['lang'] !== '' ? (string) $row['lang'] : $this->app->defaultLang();
            $id = 0;
            try {
                $product = $this->app->service('catalog')->product($productId, $lang);
                if ($product !== null) {
                    $id = (int) $this->app->service('mailer')->queue('soon_available', (string) $row['email'], [
                        'name' => (string) $row['email'],
                        'product_name' => (string) $product['name'],
                        'product_url' => $this->app->url()->origin() . $this->app->url()->entity('product', $productId, $lang),
                    ], ['locale' => $lang, 'related' => 'soon:' . (int) $row['id']]);
                }
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'gm-proximamente.release');
            }
            if ($id > 0) {
                ++$sent;
            } else {
                $db->update('gm_soon_requests', ['notified_at' => null], ['id' => (int) $row['id']]);
            }
        }

        return $sent;
    }

    /** Tarea diaria: borra las peticiones de más de 90 días (ya no se avisaría por ellas). Devuelve cuántas. */
    public function purge(): int
    {
        $clock = $this->app->clock();

        return $this->app->db()->run('DELETE FROM {gm_soon_requests} WHERE created_at < ?', [$clock->sql($clock->now() - self::REQUEST_DAYS * 86400)]);
    }

    /** Vacía lo que la caché sabe de un producto y de la lista de «próximamente». */
    public function forget(int $productId): void
    {
        $this->app->invalidate(['gm-proximamente', 'product:' . $productId]);
    }
}

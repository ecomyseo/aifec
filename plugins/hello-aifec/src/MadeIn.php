<?php
/**
 * AIFEC — Hello AIFEC: dónde se guarda el país de fabricación de cada producto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Plugins\HelloAifec;

use Aifec\Kernel\App;

/**
 * El país de cada producto vive en la tabla propia del plugin (hello_aifec_made_in), declarada en plugin.json. Si un
 * producto no tiene el suyo se usa el del ajuste «hello_aifec.default_country».
 */
final class MadeIn
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** El país de un producto, o «» si no tiene ninguno. */
    public function get(int $productId): string
    {
        $default = $this->app->settings()->str('hello_aifec.default_country');
        if ($productId < 1) {
            return $default;
        }
        $row = $this->app->db()->row('SELECT country FROM {hello_aifec_made_in} WHERE product_id = ?', [$productId]);

        return $row !== null && trim((string) $row['country']) !== '' ? (string) $row['country'] : $default;
    }

    /** El país que tiene guardado el propio producto, sin el del ajuste (lo que enseña el campo del panel). */
    public function own(int $productId): string
    {
        $row = $productId > 0 ? $this->app->db()->row('SELECT country FROM {hello_aifec_made_in} WHERE product_id = ?', [$productId]) : null;

        return $row !== null ? trim((string) $row['country']) : '';
    }

    /** Guarda el país de un producto; vacío lo quita. */
    public function set(int $productId, string $country): void
    {
        $db = $this->app->db();
        if ($productId < 1) {
            return;
        }
        if ($country === '') {
            $db->delete('hello_aifec_made_in', ['product_id' => $productId]);

            return;
        }
        $db->upsert('hello_aifec_made_in', ['product_id' => $productId, 'country' => mb_substr($country, 0, 80), 'updated_at' => $this->app->clock()->sql()], ['product_id']);
    }
}

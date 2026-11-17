<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Sites;

use Aifec\Kernel\App;

/**
 * Carga en un sitio los transportistas (con sus zonas y tarifas), los cupones y las valoraciones de un paquete.
 *
 * Casa por código, así que repetir la carga no duplica: el transportista y el cupón por su código, la zona por su nombre.
 * Las valoraciones se cuentan siempre desde el paquete (la media de cada producto se escribe, no se suma).
 *
 * Los impuestos no se inventan: los tipos de cada país son los que trae de serie el módulo de impuestos y el paquete
 * solo elige ajustes (precios con IVA dentro, país de la tienda) y la clase de cada producto.
 */
final class CommerceLoader
{
    /** @var App */
    private $app;

    /** @var Package */
    private $pkg;

    /** @var CatalogLoader */
    private $catalog;

    /** @var array<string,int> */
    private $created = ['zones' => 0, 'carriers' => 0, 'coupons' => 0, 'reviews' => 0];

    /** @var string[] */
    private $warnings = [];

    public function __construct(App $app, Package $pkg, CatalogLoader $catalog)
    {
        $this->app = $app;
        $this->pkg = $pkg;
        $this->catalog = $catalog;
    }

    /**
     * @return array<string,int>
     */
    public function created(): array
    {
        return $this->created;
    }

    /**
     * @return string[]
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** Envíos (con sus tarifas): el paso de «impuestos y envíos» del orden de carga. */
    public function loadShipping(): void
    {
        if (!$this->pkg->has('carriers.json')) {
            return;
        }
        $db = $this->app->db();
        $data = $this->pkg->data('carriers.json');
        $zoneIds = [];
        foreach ($db->select('SELECT id, name FROM {shipping_zones}') as $row) {
            $zoneIds[(string) $row['name']] = (int) $row['id'];
        }
        foreach (array_values(isset($data['zones']) && is_array($data['zones']) ? $data['zones'] : []) as $zone) {
            $name = (string) $zone['name'];
            if (isset($zoneIds[$name])) {
                continue;
            }
            $zoneIds[$name] = $db->insert('shipping_zones', ['name' => $name, 'active' => 1, 'position' => count($zoneIds)]);
            foreach (isset($zone['locations']) && is_array($zone['locations']) ? $zone['locations'] : [] as $location) {
                $db->insert('zone_locations', [
                    'zone_id' => $zoneIds[$name], 'country' => (string) $location['country'],
                    'region' => isset($location['region']) && is_string($location['region']) ? $location['region'] : '',
                    'postcodes' => isset($location['postcodes']) && is_string($location['postcodes']) ? $location['postcodes'] : '',
                ]);
            }
            ++$this->created['zones'];
        }
        $now = $this->app->clock()->sql();
        foreach (array_values(isset($data['carriers']) && is_array($data['carriers']) ? $data['carriers'] : []) as $i => $carrier) {
            $code = (string) $carrier['code'];
            if ($db->value('SELECT id FROM {carriers} WHERE code = ?', [$code]) !== null) {
                continue;
            }
            $taxClass = null;
            if (isset($carrier['tax_class']) && is_string($carrier['tax_class'])) {
                $found = $db->value('SELECT id FROM {tax_classes} WHERE code = ?', [$carrier['tax_class']]);
                $taxClass = $found !== null ? (int) $found : null;
            }
            $id = $db->insert('carriers', [
                'code' => $code, 'name' => (string) $carrier['name'],
                'delay' => isset($carrier['delay']) && is_string($carrier['delay']) ? $carrier['delay'] : null,
                'handler' => isset($carrier['handler']) ? (string) $carrier['handler'] : 'rates',
                'settings' => null, 'tax_class_id' => $taxClass,
                'free_from' => isset($carrier['free_from']) ? (int) $carrier['free_from'] : null,
                'max_weight' => isset($carrier['max_weight']) ? (int) $carrier['max_weight'] : 0,
                'min_days' => isset($carrier['min_days']) ? (int) $carrier['min_days'] : null,
                'max_days' => isset($carrier['max_days']) ? (int) $carrier['max_days'] : null,
                'tracking_url' => isset($carrier['tracking_url']) && is_string($carrier['tracking_url']) ? $carrier['tracking_url'] : null,
                'media_id' => null, 'active' => 1, 'position' => $i, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach (isset($carrier['rates']) && is_array($carrier['rates']) ? $carrier['rates'] : [] as $rate) {
                $zone = (string) $rate['zone'];
                if ($zone === '*') {
                    $targets = array_values($zoneIds);
                } elseif (isset($zoneIds[$zone])) {
                    $targets = [$zoneIds[$zone]];
                } else {
                    throw new \RuntimeException('The carrier «' . $code . '» uses the zone «' . $zone . '», which does not exist in this shop');
                }
                foreach ($targets as $zoneId) {
                    $db->insert('carrier_rates', [
                        'carrier_id' => $id, 'zone_id' => $zoneId, 'basis' => (string) $rate['basis'],
                        'min' => (int) $rate['min'], 'max' => (int) $rate['max'], 'price' => (int) $rate['price'],
                    ]);
                }
            }
            ++$this->created['carriers'];
        }
    }

    /** Cupones y valoraciones: los dos últimos pasos del orden de carga. */
    public function loadMarketing(): void
    {
        $this->coupons();
        $this->reviews();
    }

    private function coupons(): void
    {
        $db = $this->app->db();
        $now = $this->app->clock()->sql();
        foreach (array_values($this->pkg->data('coupons.json')) as $c) {
            $code = (string) $c['code'];
            if ($db->value('SELECT id FROM {coupons} WHERE code = ?', [$code]) !== null) {
                continue;
            }
            $db->insert('coupons', [
                'code' => $code, 'kind' => (string) $c['kind'],
                'percent' => isset($c['percent']) ? (int) $c['percent'] : 0,
                'amount' => isset($c['amount']) ? (int) $c['amount'] : 0,
                'min_subtotal' => isset($c['min_subtotal']) ? (int) $c['min_subtotal'] : 0,
                'starts_at' => isset($c['starts_at']) && is_string($c['starts_at']) ? $c['starts_at'] : null,
                'ends_at' => isset($c['ends_at']) && is_string($c['ends_at']) ? $c['ends_at'] : null,
                'max_uses' => isset($c['max_uses']) ? (int) $c['max_uses'] : 0,
                'max_uses_per_customer' => isset($c['max_uses_per_customer']) ? (int) $c['max_uses_per_customer'] : 0,
                'uses' => 0, 'first_order_only' => 0, 'exclude_sale' => !empty($c['exclude_sale']) ? 1 : 0, 'combinable' => 0,
                'customer_id' => null, 'products' => null, 'categories' => null,
                'note' => isset($c['note']) && is_string($c['note']) ? $c['note'] : null,
                'active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            ++$this->created['coupons'];
        }
    }

    /**
     * Las valoraciones del paquete. La media y el recuento de cada producto se escriben siempre (columnas del catálogo);
     * las filas de la tabla del módulo de valoraciones solo si ese módulo está instalado, y cada una se adapta a las
     * columnas que tenga. Lo que no se pueda guardar se anota como aviso y no corta la carga.
     */
    private function reviews(): void
    {
        $reviews = $this->pkg->data('reviews.json');
        if ($reviews === []) {
            return;
        }
        $db = $this->app->db();
        $sums = [];
        foreach (array_values($reviews) as $r) {
            $productId = $this->catalog->id('product', (string) $r['sku']);
            if ($productId === null) {
                continue;
            }
            $sums[$productId][0] = (isset($sums[$productId][0]) ? $sums[$productId][0] : 0) + (int) $r['rating'];
            $sums[$productId][1] = (isset($sums[$productId][1]) ? $sums[$productId][1] : 0) + 1;
        }
        foreach ($sums as $productId => $pair) {
            $db->update('products', ['rating_sum' => $pair[0], 'rating_count' => $pair[1]], ['id' => (int) $productId]);
        }
        $this->app->invalidate(['catalog']);

        if (!$db->tableExists('reviews')) {
            $this->warnings[] = 'The reviews module is not installed: the ratings are shown but the review texts were not stored';

            return;
        }
        $columns = $db->columns('reviews');
        $skipped = 0;
        foreach (array_values($reviews) as $r) {
            $productId = $this->catalog->id('product', (string) $r['sku']);
            $row = $productId === null ? [] : $this->reviewRow($columns, $r, $productId);
            if ($row === [] || $this->reviewExists($row)) {
                if ($row === []) {
                    ++$skipped;
                }
                continue;
            }
            try {
                $db->transaction(static function ($db) use ($row): void {
                    $db->insert('reviews', $row);
                });
                ++$this->created['reviews'];
            } catch (\Throwable $e) {
                ++$skipped;
            }
        }
        if ($skipped > 0) {
            $this->warnings[] = $skipped . ' review(s) could not be stored in the reviews table';
        }
    }

    /**
     * La fila de una valoración con las columnas que de verdad tiene la tabla.
     *
     * @param string[]            $columns
     * @param array<string,mixed> $r
     *
     * @return array<string,mixed> vacío si la tabla no tiene ni siquiera las columnas básicas
     */
    private function reviewRow(array $columns, array $r, int $productId): array
    {
        $now = $this->app->clock()->sql();
        $date = isset($r['date']) && is_string($r['date']) ? (strlen($r['date']) === 10 ? $r['date'] . ' 10:00:00' : $r['date']) : $now;
        $wanted = [
            'product_id' => [$productId],
            'name' => [(string) $r['name'], ['name', 'author_name', 'display_name', 'author']],
            'rating' => [(int) $r['rating'], ['rating', 'score', 'stars']],
            'title' => [isset($r['title']) && is_string($r['title']) ? $r['title'] : '', ['title']],
            'body' => [(string) $r['text'], ['body', 'text', 'content', 'review']],
            'lang' => [$this->app->defaultLang(), ['lang', 'locale']],
            'status' => ['published', ['status']],
            'verified' => [0, ['verified', 'is_verified']],
            'email' => ['', ['email', 'author_email']],
            'created' => [$date, ['created_at']],
            'updated' => [$date, ['updated_at']],
            'published' => [$date, ['published_at']],
        ];
        $row = [];
        foreach ($wanted as $key => $spec) {
            $names = $key === 'product_id' ? ['product_id'] : $spec[1];
            foreach ($names as $name) {
                if (in_array($name, $columns, true)) {
                    $row[$name] = $spec[0];
                    break;
                }
            }
        }

        return isset($row['product_id']) && count($row) >= 4 ? $row : [];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function reviewExists(array $row): bool
    {
        $where = [];
        $params = [];
        foreach (['product_id', 'name', 'author_name', 'display_name', 'author', 'title'] as $column) {
            if (isset($row[$column])) {
                $where[] = $this->app->db()->ident($column) . ' = ?';
                $params[] = $row[$column];
            }
        }

        return $where !== [] && (int) $this->app->db()->value('SELECT COUNT(*) FROM {reviews} WHERE ' . implode(' AND ', $where), $params) > 0;
    }
}

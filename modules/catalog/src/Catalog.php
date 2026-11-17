<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog;

use Aifec\Kernel\App;

/**
 * Servicio «catalog»: una sola función que dice todo lo que se sabe de un producto y un listado rápido con filtros.
 * De aquí beben la tienda, los datos para máquinas, la API y el asistente. Las firmas son las del contrato de
 * comercio (§ 4); este es solo la fachada: cada cosa la hace una clase pequeña (Pricing, Stock, Listing, Categories…).
 *
 * Qué producto se ve: publicado (status = published y published_at nulo o pasado). Con visibility = hidden no sale en
 * listados ni en búsquedas, pero sí se puede abrir por su dirección (product() lo devuelve).
 *
 * Caché: la ficha en «product:<id>:<idioma>» y la tarjeta en «card:<id>:<idioma>», con las etiquetas
 * «product:<id>», «catalog» y «tax». Nada personal dentro (el precio es el del destino por defecto de la tienda; para otro
 * destino o cliente, price()). Una entrada vive como mucho una hora y, antes, hasta el próximo cambio que le toque
 * (empieza o acaba una oferta, se publica el producto, deja de ser «nuevo»).
 */
final class Catalog
{
    /** @var App */
    private $app;

    /** @var Pricing */
    private $pricing;

    /** @var Stock */
    private $stock;

    /** @var ProductLoader */
    private $loader;

    /** @var ProductData */
    private $data;

    /** @var Attributes */
    private $attributes;

    /** @var Listing|null se crea la primera vez que hace falta */
    private $listing;

    /** @var Categories */
    private $categories;

    /** @var Brands|null se crea la primera vez que hace falta */
    private $brands;

    /** @var Purchasables|null se crea la primera vez que hace falta */
    private $purchasables;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->attributes = new Attributes($app);
        $this->pricing = new Pricing($app);
        $this->stock = new Stock($app);
        $this->loader = new ProductLoader($app);
        $this->data = new ProductData($app, $this->pricing, $this->stock, $this->attributes);
        $this->categories = new Categories($app);
        // El listado, las marcas y lo comprable se crean al pedirlos: una ficha servida desde la caché no carga sus clases.
    }

    private function listingService(): Listing
    {
        if ($this->listing === null) {
            $this->listing = new Listing($this->app, $this, $this->attributes);
        }

        return $this->listing;
    }

    private function brandsService(): Brands
    {
        if ($this->brands === null) {
            $this->brands = new Brands($this->app);
        }

        return $this->brands;
    }

    private function purchasablesService(): Purchasables
    {
        if ($this->purchasables === null) {
            $this->purchasables = new Purchasables($this->app, $this->pricing, $this->attributes, $this->stock);
        }

        return $this->purchasables;
    }

    // ------------------------------------------------------------------ productos

    /**
     * La ficha completa de un producto (ProductData), o null si no existe o no está publicado (salvo con $any).
     *
     * @return array<string,mixed>|null
     */
    public function product(int $id, ?string $lang = null, bool $any = false): ?array
    {
        $lang = $this->lang($lang);
        $entries = $this->entries([$id], $lang, true);
        if (!isset($entries[$id]) || !$this->visible($entries[$id], $any)) {
            return null;
        }

        return $entries[$id]['data'];
    }

    /**
     * Varias fichas a la vez, en el orden pedido y sin los productos que no existan o no se vean.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>> id => ProductData
     */
    public function products(array $ids, ?string $lang = null): array
    {
        return $this->visibleData($ids, $this->lang($lang), true);
    }

    /**
     * Lo justo para una tarjeta de listado (ProductCard); null si no existe o no está publicado.
     *
     * @return array<string,mixed>|null
     */
    public function card(int $id, ?string $lang = null): ?array
    {
        $cards = $this->cards([$id], $lang);

        return isset($cards[$id]) ? $cards[$id] : null;
    }

    /**
     * Varias tarjetas a la vez. Las que no están en la caché se cargan juntas, con un número fijo de consultas.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>> id => ProductCard
     */
    public function cards(array $ids, ?string $lang = null): array
    {
        return $this->visibleData($ids, $this->lang($lang), false);
    }

    /**
     * El listado con filtros, orden, paginación y facetas.
     *
     * @param array<string,mixed> $query category_id, brand_id, tag, ids, q, filters, price_min, price_max, on_sale,
     *                                   in_stock, featured, sort, page, per_page, lang
     *
     * @return array<string,mixed>
     */
    public function listing(array $query): array
    {
        return $this->listingService()->run($query);
    }

    // ------------------------------------------------------------------ categorías y marcas

    /**
     * @return array<string,mixed>|null
     */
    public function category(int $id, ?string $lang = null): ?array
    {
        return $this->categories->category($id, $this->lang($lang));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function tree(?string $lang = null): array
    {
        return $this->categories->tree($this->lang($lang));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function brand(int $id): ?array
    {
        return $this->brandsService()->brand($id, $this->lang(null));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function brands(): array
    {
        return $this->brandsService()->all($this->lang(null));
    }

    // ------------------------------------------------------------------ compra

    /**
     * Lo que necesita el carrito de un producto o de una variante; null si el producto no existe.
     *
     * @return array<string,mixed>|null
     */
    public function purchasable(int $productId, int $variantId = 0, ?string $lang = null): ?array
    {
        return $this->purchasablesService()->get($productId, $variantId, $this->lang($lang));
    }

    /**
     * Suma o resta existencias; false si no hay bastantes (ver Stock::adjust()).
     */
    public function adjustStock(int $productId, int $variantId, int $delta, string $reason, ?string $reference = null): bool
    {
        return $this->stock->adjust($productId, $variantId, $delta, $reason, $reference);
    }

    /**
     * El bloque de precio de lo que devuelve purchasable(), para enseñarlo (con el destino y el cliente que se den).
     *
     * @param array<string,mixed>      $purchasable
     * @param array<string,mixed>|null $destination
     * @param array<string,mixed>|null $customer
     *
     * @return array<string,mixed>
     */
    public function price(array $purchasable, ?array $destination = null, ?array $customer = null): array
    {
        return $this->pricing->fromPurchasable($purchasable, $destination, $customer);
    }

    /**
     * El idioma pedido si es uno de los de la tienda; si no, el de la petición.
     */
    public function lang(?string $lang): string
    {
        if ($lang === null || $lang === '') {
            return $this->app->lang();
        }
        $lang = strtolower($lang);

        return in_array($lang, $this->app->languages(), true) ? $lang : $this->app->defaultLang();
    }

    // ------------------------------------------------------------------ caché

    /**
     * ¿Se puede ver este producto? Publicado y con la fecha de publicación ya pasada (o sin ella).
     *
     * @param array{status:string,published_at:?string,data:array<string,mixed>} $entry
     */
    private function visible(array $entry, bool $any): bool
    {
        if ($any) {
            return true;
        }

        return $entry['status'] === 'published'
            && ($entry['published_at'] === null || $entry['published_at'] <= $this->app->clock()->sql());
    }

    /**
     * Los datos de los productos que se ven, en el orden pedido.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>>
     */
    private function visibleData(array $ids, string $lang, bool $full): array
    {
        $entries = $this->entries($ids, $lang, $full);
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (isset($entries[$id]) && $this->visible($entries[$id], false)) {
                $out[$id] = $entries[$id]['data'];
            }
        }

        return $out;
    }

    /**
     * Las entradas de varios productos: de la caché las que están y, de la base de datos, todas las que faltan de una vez.
     *
     * @param int[] $ids
     *
     * @return array<int,array{status:string,published_at:?string,data:array<string,mixed>}>
     */
    private function entries(array $ids, string $lang, bool $full): array
    {
        $cache = $this->app->cache();
        // Una tarjeta que deja elegir combinación y unidades lleva más datos que la de siempre: se guarda con otra clave (así
        // encender o apagar el ajuste, o cambiar el tope de variantes, no enseña tarjetas guardadas con el otro).
        $options = !$full && ProductData::cardOptions($this->app);
        $prefix = $full ? 'product:' : ($options ? 'cardo' . ProductData::cardOptionsMax($this->app) . ':' : 'card:');
        $found = [];
        $missing = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0 || isset($found[$id])) {
                continue;
            }
            $hit = $cache->get($prefix . $id . ':' . $lang);
            if (is_array($hit) && isset($hit['data'], $hit['status'])) {
                $found[$id] = $hit;
            } else {
                $missing[$id] = $id;
            }
        }
        if ($missing !== []) {
            foreach ($this->loader->load(array_values($missing), $lang, $full, $options) as $id => $bundle) {
                $entry = $this->data->entry($bundle, $lang, $full);
                $ttl = $this->data->ttl($bundle['row']);
                if ($ttl !== null) {
                    $cache->set($prefix . $id . ':' . $lang, $entry, $ttl, ['product:' . $id, 'catalog', 'tax']);
                }
                $found[$id] = $entry;
            }
        }

        return $found;
    }
}
